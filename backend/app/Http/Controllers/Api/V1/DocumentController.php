<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccessLink;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Content;
use App\Models\Document;
use App\Models\DocumentBroadcast;
use App\Models\DocumentRead;
use App\Models\Setting;
use App\Models\Structure;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Services\AssetService;
use App\Services\DocumentAccess;
use App\Services\Notifier;
use App\Support\BusinessException;
use App\Support\Present;
use App\Support\Reference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bibliothèque documentaire (V13, V14) et routes de DOC-07. Le téléchargement est réautorisé à chaque appel.
 */
class DocumentController extends Controller
{
    public function __construct(private DocumentAccess $documents) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->documents->scope(Document::query(), $this->user())
            ->with(['owner', 'author', 'category', 'currentVersion.assetVersion'])
            ->when($request->query('classification'), fn ($q, $c) => $q->where('classification', $c))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('category_id'), fn ($q, $c) => $q->where('category_id', $c))
            ->when($request->query('owner_structure_id'), fn ($q, $s) => $q->where('owner_structure_id', $s))
            ->when($request->boolean('expiring'), fn ($q) => $q->whereBetween('expires_at', [now()->toDateString(), now()->addDays(30)->toDateString()]));
        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $query->where(fn ($q) => $q->whereRaw("search_vector @@ websearch_to_tsquery('french', ?)", [$term])->orWhere('reference', 'ilike', "%$term%"));
        }
        $this->applySort($query, $request, ['updated_at', 'title', 'reference', 'expires_at'], 'updated_at');

        $response = $this->paginated($query, $request, fn (Document $d) => Present::document($d));
        $base = $this->documents->scope(Document::query(), $this->user());
        $payload = $response->getData(true);
        $payload['meta']['stats'] = [
            'active' => (clone $base)->where('status', 'published')->count(),
            'internal' => (clone $base)->where('classification', 'interne')->count(),
            'expiring' => (clone $base)->whereBetween('expires_at', [now()->toDateString(), now()->addDays(30)->toDateString()])->count(),
            'scanning' => (clone $base)->whereHas('currentVersion.assetVersion', fn ($q) => $q->whereIn('scan_status', ['quarantine', 'scanning']))->count(),
        ];

        return response()->json($payload);
    }

    public function show(Document $document): JsonResponse
    {
        $this->assertView($document);
        $document->load(['owner', 'author', 'category', 'currentVersion.assetVersion', 'versions.assetVersion', 'versions.creator', 'audiences']);
        $canManage = $this->documents->canManage($document, $this->user());
        $data = Present::document($document);
        $data['versions'] = $document->versions->sortByDesc('version')->map(fn ($v) => ['id' => $v->id, 'version' => $v->version, 'notes' => $v->notes, 'created_at' => Present::date($v->created_at), 'creator' => Present::userRef($v->creator), 'file' => Present::assetVersion($v->assetVersion)])->values();
        $data['audiences'] = $document->audiences->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]);
        $data['can_manage'] = $canManage;
        // PUB-06 : avec la double validation, l'auteur demande la publication au lieu de publier.
        $data['can_publish'] = $canManage && ! (Setting::effective('documents.four_eyes') && $document->author_id === $this->user()->id);
        $data['qr'] = AccessLink::query()->where('target_type', 'document')->where('target_id', $document->id)->first(['code', 'classification']);
        $data['history'] = WorkflowEvent::query()->with('actor')->where('object_type', 'document')->where('object_id', $document->id)->orderByDesc('created_at')->get()
            ->map(fn ($e) => ['action' => $e->action, 'actor' => $e->actor?->name, 'reason' => $e->reason, 'at' => Present::date($e->created_at)]);

        return response()->json(['data' => $data]);
    }

    public function store(Request $request, AssetService $assets): JsonResponse
    {
        $this->authorizePermission('documents.manage');
        $data = $this->validated($request, true);
        $owner = $data['owner_structure_id'] ?? $this->user()->structure_id;
        $scope = $this->access()->structureScope($this->user(), 'documents.manage');
        if ($scope !== null && ! in_array($owner, $scope, true)) {
            $this->deny('Structure propriétaire hors périmètre');
        }

        $document = DB::transaction(function () use ($request, $assets, $data, $owner): Document {
            $version = $assets->store($request->file('file'), ['title' => $data['title'], 'classification' => $data['classification'], 'owner_structure_id' => $owner, 'language' => $data['language'] ?? 'fr', 'category_id' => $data['category_id'] ?? null], $this->user());
            $document = Document::query()->create(collect($data)->except(['file', 'audience_ids'])->all() + [
                'reference' => Reference::next('DOC'), 'owner_structure_id' => $owner, 'author_id' => $this->user()->id, 'status' => 'draft',
            ]);
            $documentVersion = $document->versions()->create(['version' => 1, 'asset_version_id' => $version->id, 'notes' => $data['notes'] ?? 'Version initiale', 'created_by' => $this->user()->id]);
            $document->forceFill(['current_version_id' => $documentVersion->id])->save();
            $document->audiences()->sync($data['audience_ids'] ?? []);
            $this->event($document, 'created');
            $this->audit()->log('document.created', $document);

            return $document;
        });

        return response()->json(['data' => Present::document($document->load(['owner', 'author', 'category', 'currentVersion.assetVersion']))], 201);
    }

    public function update(Request $request, Document $document): JsonResponse
    {
        $this->assertManage($document);
        $this->assertNotTrashed($document);
        $this->expectVersion($request, $document);
        $data = $this->validated($request, false);
        // ASVS 5.0 V8.2 : un document ne peut être transféré qu'à une structure du périmètre de gestion de l'auteur de la modification.
        if (isset($data['owner_structure_id']) && $data['owner_structure_id'] !== $document->owner_structure_id) {
            $scope = $this->access()->structureScope($this->user(), 'documents.manage');
            if ($scope !== null && ! in_array($data['owner_structure_id'], $scope, true)) {
                $this->deny('Structure propriétaire hors périmètre');
            }
        }
        $before = $document->only(array_keys($data));
        // PUB-06 : sous double validation, reclasser ou changer l'audience d'un document publié exige une nouvelle publication.
        $republish = $document->status === 'published' && Setting::effective('documents.four_eyes')
            && ((isset($data['classification']) && $data['classification'] !== $document->classification) || array_key_exists('audience_ids', $data));
        $document->update(collect($data)->except('audience_ids')->all() + ['version' => $document->version + 1] + ($republish ? ['status' => 'draft'] : []));
        if ($republish) {
            $this->event($document, 'republication_required', 'Classification ou audience modifiée : nouvelle publication par un autre gestionnaire');
        }
        if (isset($data['classification'])) {
            // DOC-04 : le fichier suit la classification du document ; un document reclassé « restreint » ne peut plus être diffusé.
            Asset::query()->whereIn('id', AssetVersion::query()->whereIn('id', $document->versions()->select('asset_version_id'))->select('asset_id'))
                ->update(['classification' => $data['classification'], 'updated_at' => now()]);
        }
        if (array_key_exists('audience_ids', $data)) {
            $document->audiences()->sync($data['audience_ids']);
        }
        $this->audit()->log('document.updated', $document, before: $before, after: $data);

        return $this->show($document->refresh());
    }

    public function addVersion(Request $request, Document $document, AssetService $assets): JsonResponse
    {
        $this->assertManage($document);
        $this->assertNotTrashed($document);
        $data = $request->validate(['file' => ['required', 'file'], 'notes' => ['nullable', 'string', 'max:500']]);
        DB::transaction(function () use ($request, $document, $assets, $data): void {
            $asset = $document->currentVersion?->assetVersion?->asset;
            $version = $assets->store($request->file('file'), ['title' => $document->title, 'classification' => $document->classification, 'owner_structure_id' => $document->owner_structure_id], $this->user(), $asset);
            $number = (int) $document->versions()->max('version') + 1;
            $documentVersion = $document->versions()->create(['version' => $number, 'asset_version_id' => $version->id, 'notes' => $data['notes'] ?? null, 'created_by' => $this->user()->id]);
            // PUB-06 : sous double validation, une nouvelle version d'un document publié repasse par une publication.
            if ($document->status === 'published' && Setting::effective('documents.four_eyes')) {
                $document->forceFill(['status' => 'draft'])->save();
                $this->event($document, 'republication_required', 'Nouvelle version : publication par un autre gestionnaire');
            }
            // Document publié : la version précédente reste servie jusqu'à ce que la nouvelle soit déclarée saine.
            if ($document->status !== 'published' || $version->refresh()->scan_status === 'ready') {
                $document->forceFill(['current_version_id' => $documentVersion->id] + ($document->status === 'published' ? ['search_text' => $version->text_content] : []))->save();
            }
            $this->event($document, 'version_added', $data['notes'] ?? null);
            $this->audit()->log('document.version_added', $document, version: $number);
        });

        return $this->show($document->refresh());
    }

    /**
     * PUB-06 : demande de publication adressée aux autres gestionnaires de documents habilités pour ce document.
     */
    public function requestPublication(Document $document, Notifier $notifier): JsonResponse
    {
        $this->assertManage($document);
        $this->assertNotTrashed($document);
        if ($document->status === 'published') {
            throw new BusinessException('DOCUMENT_PUBLISHED', 'Le document est déjà publié.', 409);
        }
        $reviewers = User::query()->whereKeyNot($this->user()->id)->where('status', 'active')->get()
            ->filter(fn (User $u) => Gate::forUser($u)->allows('manage', $document))->values();
        $notifier->notify($reviewers, 'document.publication_requested', 'Publication de document à vérifier', $document->title, "/documents/{$document->id}", 'document', $document->id, 'pubreq-'.$document->id.'-'.now()->timestamp);
        $this->event($document, 'publication_requested');
        $this->audit()->log('document.publication_requested', $document, after: ['reviewers' => $reviewers->count()]);

        return response()->json(['data' => ['reviewers' => $reviewers->count()]]);
    }

    public function publish(Document $document): JsonResponse
    {
        $this->assertManage($document);
        // PUB-02, DOC-06 : un document à la corbeille n'est pas republié (une republication d'un document publié reste admise).
        if ($document->status === 'trashed') {
            throw new BusinessException('INVALID_TRANSITION', 'Ce document est à la corbeille : restaurez-le avant de le publier.', 409);
        }
        // PUB-06 (activée le 8 novembre, réglage « Double validation des documents ») : avec la double validation, l'auteur du document ne le publie pas lui-même.
        if (Setting::effective('documents.four_eyes') && $document->author_id === $this->user()->id) {
            $this->audit()->log('access.denied', $document, 'Auto-publication d’un document', outcome: 'denied');
            throw new BusinessException('FOUR_EYES_REQUIRED', 'Un autre gestionnaire de documents doit publier ce document : utilisez « Demander la publication ».', 403);
        }
        $file = $document->currentVersion?->assetVersion;
        if (! $file || $file->scan_status !== 'ready') {
            throw new BusinessException('FILE_NOT_READY', 'Le fichier n’est pas déclaré sain : publication impossible.', 422);
        }
        if ($document->expires_at && $document->expires_at->isPast()) {
            throw new BusinessException('DOCUMENT_EXPIRED', 'Le document est expiré.', 422);
        }
        $document->forceFill(['status' => 'published', 'published_at' => now(), 'search_text' => $file->text_content])->save();
        if ($document->classification !== 'restreint') {
            $link = AccessLink::query()->firstOrCreate(['target_type' => 'document', 'target_id' => $document->id], ['code' => Str::lower(Str::random(10)), 'classification' => $document->classification, 'created_by' => $this->user()->id]);
            // Une republication réactive le QR déjà imprimé (expiré à la dépublication) avec la classification courante.
            $link->forceFill(['expires_at' => null, 'classification' => $document->classification])->save();
        }
        $this->event($document, 'published');
        $this->audit()->log('document.published', $document);

        return $this->show($document->refresh());
    }

    /**
     * La dépublication arrête la diffusion : les liens QR sont désactivés et les manifestes ne référencent plus le document (DOC-06).
     */
    public function unpublish(Request $request, Document $document): JsonResponse
    {
        $this->assertManage($document);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        if ($document->status !== 'published') {
            throw new BusinessException('INVALID_TRANSITION', 'Seul un document publié peut être dépublié.', 409);
        }
        $document->forceFill(['status' => 'unpublished', 'unpublished_at' => now()])->save();
        AccessLink::query()->where('target_type', 'document')->where('target_id', $document->id)->update(['expires_at' => now()]);
        $this->event($document, 'unpublished', $data['reason']);
        $this->audit()->log('document.unpublished', $document, $data['reason']);

        return $this->show($document->refresh());
    }

    /**
     * Mise à la corbeille contrôlée (DOC-06) : motif obligatoire, document dépublié au préalable, refus tant qu'un contenu
     * actif y renvoie par son QR. Le document disparaît des listes et de la recherche ; ses versions et son journal sont conservés.
     */
    public function trash(Request $request, Document $document): JsonResponse
    {
        $this->assertManage($document);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        if ($document->status === 'published') {
            throw new BusinessException('DOCUMENT_PUBLISHED', 'Dépubliez d’abord le document : sa diffusion doit être arrêtée avant la corbeille.', 409);
        }
        $usages = Content::query()->where('status', '!=', 'archived')->where('qr->document_id', $document->id)->get(['id', 'reference', 'title']);
        if ($usages->isNotEmpty()) {
            throw new BusinessException('DOCUMENT_IN_USE', 'Des contenus renvoient à ce document par leur QR : modifiez-les d’abord.', 409, ['usages' => $usages->map(fn ($c) => ['type' => 'content', 'id' => $c->id, 'reference' => $c->reference, 'title' => $c->title])->all()]);
        }
        $document->forceFill(['status' => 'trashed'])->save();
        AccessLink::query()->where('target_type', 'document')->where('target_id', $document->id)->update(['expires_at' => now()]);
        $this->event($document, 'trashed', $data['reason']);
        $this->audit()->log('document.trashed', $document, $data['reason']);

        return response()->json(null, 204);
    }

    public function download(Request $request, Document $document, int $version): StreamedResponse
    {
        $this->assertView($document);
        $documentVersion = $document->versions()->where('version', $version)->firstOrFail();
        if (Gate::forUser($this->user())->denies('download', [$document, $documentVersion])) {
            $this->deny('Version archivée réservée au propriétaire');
        }
        /** @var AssetVersion $file */
        $file = $documentVersion->assetVersion;
        abort_unless($file->scan_status === 'ready', 409, 'Fichier non disponible.');
        if ($document->classification === 'interne' && $document->audiences()->exists()) {
            DocumentRead::query()->firstOrCreate(['document_version_id' => $documentVersion->id, 'user_id' => $this->user()->id], ['document_id' => $document->id, 'first_opened_at' => now()]);
        }
        if ($document->classification !== 'public') {
            $this->audit()->log('document.downloaded', $document, version: $version);
        }
        $inline = $request->boolean('inline') && in_array($file->extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true);

        return Storage::disk('local')->response($file->storage_key, $file->original_name, [
            'Content-Type' => $file->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'self'",
            'X-Frame-Options' => 'SAMEORIGIN',
        ], $inline ? 'inline' : 'attachment');
    }

    /**
     * Accusés de lecture (DOC-08, P1) : visibles du seul propriétaire, jamais pour un document public.
     */
    public function reads(Document $document): JsonResponse
    {
        $this->assertManage($document);
        if ($document->classification === 'public') {
            return response()->json(['data' => [], 'meta' => ['tracking' => false, 'reason' => 'Aucun suivi nominatif pour un document public.']]);
        }
        $reads = DocumentRead::query()->with('user.structure')->where('document_version_id', $document->current_version_id)->orderBy('first_opened_at')->get();
        $audienceIds = $document->audiences()->pluck('structures.id');
        $expected = $audienceIds->isEmpty() ? null : User::query()->whereIn('structure_id', $audienceIds)->where('status', 'active')->count();

        return response()->json(['data' => $reads->map(fn ($r) => ['user' => $r->user->name, 'structure' => $r->user->structure?->name, 'first_opened_at' => Present::date($r->first_opened_at)]), 'meta' => ['tracking' => true, 'expected' => $expected, 'read' => $reads->count()]]);
    }

    private function assertView(Document $document): void
    {
        if (Gate::forUser($this->user())->denies('view', $document)) {
            $this->audit()->log('document.access_denied', $document, outcome: 'denied');
            abort(404);
        }
    }

    /**
     * Historique des diffusions par courriel du document (DOC-09).
     */
    public function broadcasts(Document $document): JsonResponse
    {
        $this->assertManage($document);
        $broadcasts = DocumentBroadcast::query()->with('creator')->where('document_id', $document->id)->orderByDesc('created_at')->limit(50)->get();

        return response()->json(['data' => $broadcasts->map(fn (DocumentBroadcast $b) => $this->presentBroadcast($b))]);
    }

    /**
     * Diffusion par courriel d'un document publié à des structures (DOC-09) : notification et courriel par la file NOT-02,
     * lien authentifié vers la fiche, jamais de pièce jointe. Seuls les agents habilités à lire le document sont destinataires ;
     * un document restreint est annoncé sans son titre.
     */
    public function broadcast(Request $request, Document $document, Notifier $notifier): JsonResponse
    {
        $this->assertManage($document);
        $data = $request->validate([
            'structure_ids' => ['required', 'array', 'min:1', 'max:50'], 'structure_ids.*' => ['uuid', 'distinct', 'exists:structures,id'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($document->status !== 'published') {
            throw new BusinessException('DOCUMENT_NOT_PUBLISHED', 'Seul un document publié peut être diffusé par courriel.', 422);
        }
        $candidates = User::query()->whereIn('structure_id', $data['structure_ids'])->where('status', 'active')->get();
        $recipients = $candidates->filter(fn (User $user) => $this->documents->canView($document, $user))->values();
        if ($recipients->isEmpty()) {
            throw new BusinessException('NO_AUTHORIZED_RECIPIENT', 'Aucun agent actif de ces structures n’est habilité à lire ce document.', 422);
        }
        $restricted = $document->classification === 'restreint';
        $title = $restricted ? 'Un document à diffusion restreinte vous est adressé' : 'Document diffusé : '.$document->title;
        $body = trim(($data['message'] ?? '')."\n\nRéférence : {$document->reference}. Consultation après authentification ; aucune pièce jointe n’est transmise.");

        $broadcast = DB::transaction(function () use ($document, $data, $recipients, $candidates, $notifier, $title, $body): DocumentBroadcast {
            $broadcast = DocumentBroadcast::query()->create([
                'document_id' => $document->id, 'structure_ids' => $data['structure_ids'], 'message' => $data['message'] ?? null,
                'recipients_count' => $recipients->count(), 'excluded_count' => $candidates->count() - $recipients->count(), 'created_by' => $this->user()->id,
            ]);
            $notifier->notify($recipients, 'document.broadcast', $title, $body, "/documents/{$document->id}", 'document', $document->id, 'broadcast-'.$broadcast->id);
            $this->audit()->log('document.broadcast', $document, after: ['structures' => $data['structure_ids'], 'recipients' => $recipients->count()]);

            return $broadcast;
        });

        return response()->json(['data' => $this->presentBroadcast($broadcast->load('creator'))], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBroadcast(DocumentBroadcast $broadcast): array
    {
        return [
            'id' => $broadcast->id, 'created_at' => Present::date($broadcast->created_at), 'message' => $broadcast->message,
            'structures' => Structure::query()->whereIn('id', $broadcast->structure_ids)->orderBy('name')->pluck('name')->all(),
            'recipients_count' => $broadcast->recipients_count, 'excluded_count' => $broadcast->excluded_count, 'created_by' => Present::userRef($broadcast->creator),
        ];
    }

    /**
     * DOC-06 : un document à la corbeille n'est plus modifié ni republié tant qu'il n'a pas été restauré.
     */
    private function assertNotTrashed(Document $document): void
    {
        if ($document->status === 'trashed') {
            throw new BusinessException('INVALID_TRANSITION', 'Ce document est à la corbeille : restaurez-le avant de le modifier.', 409);
        }
    }

    /**
     * Restauration depuis la corbeille (DOC-06) : retour en brouillon, motif tracé.
     */
    public function restore(Request $request, Document $document): JsonResponse
    {
        $this->authorizePermission('documents.manage');
        if (Gate::forUser($this->user())->denies('manage', $document)) {
            $this->deny('Document hors périmètre');
        }
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        if ($document->status !== 'trashed') {
            throw new BusinessException('INVALID_TRANSITION', 'Ce document n’est pas à la corbeille.', 409);
        }
        $document->forceFill(['status' => 'draft', 'version' => $document->version + 1])->save();
        $this->event($document, 'restored', $data['reason']);
        $this->audit()->log('document.restored', $document, $data['reason']);

        return response()->json(['data' => ['id' => $document->id, 'status' => $document->status]]);
    }

    private function assertManage(Document $document): void
    {
        if (Gate::forUser($this->user())->denies('manage', $document)) {
            $this->deny('Document hors périmètre');
        }
    }

    private function event(Document $document, string $action, ?string $reason = null): void
    {
        WorkflowEvent::query()->create(['object_type' => 'document', 'object_id' => $document->id, 'action' => $action, 'actor_id' => $this->user()->id, 'reason' => $reason, 'created_at' => now()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $max = (int) config('saie.uploads.max_document_mb') * 1024;

        return $request->validate([
            'file' => $creating ? ['required', 'file', "max:$max"] : ['prohibited'],
            'title' => [$req, 'string', 'max:200'], 'summary' => ['nullable', 'string', 'max:2000'],
            'classification' => [$req, 'in:public,interne,restreint'], 'category_id' => ['nullable', 'uuid', 'exists:content_categories,id'],
            'owner_structure_id' => ['nullable', 'uuid', 'exists:structures,id'], 'language' => ['nullable', 'in:fr,en,pt,es'],
            'keywords' => ['nullable', 'array', 'max:20'], 'keywords.*' => ['string', 'max:40'],
            'valid_from' => ['nullable', 'date'], 'expires_at' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'notes' => ['nullable', 'string', 'max:500'], 'audience_ids' => ['nullable', 'array'], 'audience_ids.*' => ['uuid', 'exists:structures,id'],
        ]);
    }
}
