<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\AssetVersion;
use App\Models\CampaignItem;
use App\Models\Content;
use App\Models\ContentLanguage;
use App\Models\ContentVersion;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Support\BusinessCalendar;
use App\Support\BusinessException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Cycle éditorial PUB-01/PUB-02 et validation par langue PUB-08, avec séparation auteur/validateur (HAB-02)
 * et échéances HAB-04.
 */
class ContentWorkflow
{
    /** Limites de texte par orientation, contrôlées côté serveur en plus de la mesure de rendu du client. */
    private const LIMITS = ['landscape' => ['title' => 90, 'body' => 420], 'portrait' => ['title' => 70, 'body' => 320]];

    public function __construct(private Access $access, private Audit $audit, private Notifier $notifier) {}

    /**
     * Statut agrégé d'un contenu, recalculé à chaque transition.
     */
    public function refreshStatus(Content $content): void
    {
        if (in_array($content->status, ['archived', 'suspended'], true)) {
            return;
        }
        if (! $content->is_permanent && $content->expires_at && $content->expires_at->isPast()) {
            $status = 'expired';
        } else {
            $langs = $content->languages()->pluck('status');
            $campaignStatuses = CampaignItem::query()->join('campaigns', 'campaigns.id', '=', 'campaign_items.campaign_id')
                ->where('campaign_items.content_id', $content->id)->pluck('campaigns.status');
            $status = match (true) {
                $campaignStatuses->contains('active') => 'broadcasting',
                $campaignStatuses->contains('scheduled') => 'scheduled',
                $langs->contains('approved') => 'approved',
                $langs->contains('submitted') => 'submitted',
                default => 'draft',
            };
        }
        if ($status !== $content->status) {
            $content->forceFill(['status' => $status])->save();
        }
    }

    /**
     * Applique une modification. Toute modification d'une version approuvée ou soumise crée une révision à revalider (PUB-01).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, mixed>>  $languages
     */
    public function update(Content $content, array $attributes, array $languages, User $user): Content
    {
        return DB::transaction(function () use ($content, $attributes, $languages, $user): Content {
            Content::query()->whereKey($content->id)->lockForUpdate()->first();
            $sharedChanged = false;
            foreach (['classification', 'media', 'template_id', 'expires_at', 'is_permanent', 'qr'] as $key) {
                if (array_key_exists($key, $attributes) && json_encode($content->getAttribute($key)) !== json_encode($this->castValue($content, $key, $attributes[$key]))) {
                    $sharedChanged = true;
                }
            }
            $content->fill(Arr::except($attributes, ['status', 'author_id', 'reference']));
            $content->version++;
            $content->save();

            foreach ($languages as $lang => $data) {
                /** @var ContentLanguage|null $line */
                $line = $content->languages()->where('lang', $lang)->first();
                if (! $line) {
                    $content->languages()->create(['lang' => $lang, 'title' => $data['title'] ?? '', 'body' => $data['body'] ?? null, 'extra' => $data['extra'] ?? null, 'overflow' => (bool) ($data['overflow'] ?? false), 'subtitles_asset_version_id' => $data['subtitles_asset_version_id'] ?? null, 'status' => 'draft']);

                    continue;
                }
                $changes = Arr::only($data, ['title', 'body', 'extra', 'overflow', 'subtitles_asset_version_id']);
                $line->fill($changes);
                if ($line->isDirty()) {
                    $this->reopen($line, $user, 'Révision du texte');
                }
                $line->save();
            }
            if ($sharedChanged) {
                foreach ($content->languages()->get() as $line) {
                    $this->reopen($line, $user, 'Révision du média, de la classification ou de l’expiration');
                    $line->save();
                }
            }
            $this->audit->log('content.updated', $content, version: $content->version);
            $this->refreshStatus($content);

            return $content->refresh();
        });
    }

    /**
     * @param  list<string>  $langs
     */
    public function submit(Content $content, array $langs, User $user): void
    {
        DB::transaction(function () use ($content, $langs, $user): void {
            $content->load('languages', 'template');
            $this->assertSubmittable($content);
            foreach ($langs as $lang) {
                /** @var ContentLanguage|null $line */
                $line = $content->languages->firstWhere('lang', $lang);
                if (! $line) {
                    throw new BusinessException('LANGUAGE_MISSING', "La version $lang n’existe pas.", 422);
                }
                if (! in_array($line->status, ['draft', 'returned'], true)) {
                    throw new BusinessException('INVALID_TRANSITION', "La version $lang n’est pas en brouillon.", 409);
                }
                $this->assertLanguageComplete($content, $line);

                $validator = $this->pickValidator($content, $lang, $user);
                $line->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $user->id])->save();
                $approval = Approval::query()->create([
                    'object_type' => 'content', 'object_id' => $content->id, 'lang' => $lang, 'revision' => $line->revision,
                    'title' => $content->title, 'requested_by' => $user->id, 'assignee_id' => $validator?->id, 'backup_id' => $validator?->backup_user_id,
                    'due_at' => BusinessCalendar::addBusinessDays(now(), (int) config('saie.workflow.content_due_business_days')),
                ]);
                $this->event($content, 'submitted', 'draft', 'submitted', $user, null, $lang, $line->revision);
                $this->notifier->notify($validator, 'content.submitted', 'Nouvelle validation assignée', $content->title.' · version '.strtoupper($lang), "/affichage/contenus/{$content->id}/validation?lang=$lang", 'content', $content->id, "rev{$line->revision}-$lang");
                $this->audit->log('content.submitted', $content, lang: $lang, version: $line->revision, after: ['approval_id' => $approval->id]);
            }
            $this->refreshStatus($content);
        });
    }

    public function decide(Content $content, string $lang, string $decision, ?string $reason, int $expectedRevision, User $user, ?User $reassignTo = null): void
    {
        DB::transaction(function () use ($content, $lang, $decision, $reason, $expectedRevision, $user, $reassignTo): void {
            /** @var ContentLanguage|null $line */
            $line = $content->languages()->where('lang', $lang)->lockForUpdate()->first();
            if (! $line) {
                throw new BusinessException('LANGUAGE_MISSING', 'Version linguistique introuvable.', 404);
            }
            $this->assertCanValidate($content, $lang, $user);
            if ($line->status !== 'submitted') {
                throw new BusinessException('INVALID_TRANSITION', 'Cette version n’est pas en attente de validation.', 409);
            }
            if ($line->revision !== $expectedRevision) {
                throw new BusinessException('VERSION_CONFLICT', 'Une révision plus récente a été soumise. Rechargez la page.', 409, ['current_revision' => $line->revision]);
            }
            $approval = Approval::query()->where('object_type', 'content')->where('object_id', $content->id)->where('lang', $lang)->where('status', 'open')->latest()->first();

            if ($decision === 'reassign') {
                if (! $reassignTo || $reassignTo->id === $content->author_id || ! in_array($lang, $this->access->validationLanguages($reassignTo), true)) {
                    throw new BusinessException('INVALID_ASSIGNEE', 'Le validateur choisi n’est pas habilité pour cette langue.', 422);
                }
                $approval?->forceFill(['assignee_id' => $reassignTo->id, 'backup_id' => $reassignTo->backup_user_id])->save();
                $this->event($content, 'reassigned', 'submitted', 'submitted', $user, $reason, $lang, $line->revision);
                $this->notifier->notify($reassignTo, 'content.reassigned', 'Validation réaffectée', $content->title, "/affichage/contenus/{$content->id}/validation?lang=$lang", 'content', $content->id, "reassign{$line->revision}-$lang-".now()->timestamp);
                $this->audit->log('content.reassigned', $content, $reason, lang: $lang, version: $line->revision, after: ['assignee' => $reassignTo->id]);

                return;
            }

            if ($decision === 'approve') {
                $content->load('template');
                $payload = $this->snapshot($content, $line);
                ContentVersion::query()->create([
                    'content_id' => $content->id, 'lang' => $lang, 'revision' => $line->revision, 'payload' => $payload,
                    'classification' => $content->classification, 'template_id' => $content->template_id, 'author_id' => $content->author_id,
                    'approved_by' => $user->id, 'approved_at' => now(), 'payload_hash' => hash('sha256', json_encode($payload)),
                ]);
                $line->forceFill(['status' => 'approved', 'last_return_reason' => null])->save();
                $to = 'approved';
            } else {
                if (! $reason || mb_strlen(trim($reason)) < 5) {
                    throw new BusinessException('REASON_REQUIRED', 'Un motif précis est obligatoire pour un retour.', 422);
                }
                $line->forceFill(['status' => 'returned', 'last_return_reason' => $reason])->save();
                $to = 'returned';
            }
            $approval?->forceFill(['status' => 'decided', 'decision' => $decision === 'approve' ? 'approved' : 'returned', 'reason' => $reason, 'decided_by' => $user->id, 'decided_at' => now()])->save();
            $this->event($content, $to, 'submitted', $to, $user, $reason, $lang, $line->revision);
            $this->notifier->notify($content->author, 'content.'.$to, $to === 'approved' ? 'Version approuvée' : 'Version retournée pour correction', $content->title.' · '.strtoupper($lang), "/affichage/contenus/{$content->id}/edition", 'content', $content->id, "$to{$line->revision}-$lang");
            $this->audit->log('content.'.$to, $content, $reason, lang: $lang, version: $line->revision);
            $this->refreshStatus($content);
        });
    }

    public function archive(Content $content, User $user, ?string $reason): void
    {
        $content->forceFill(['status' => 'archived', 'archived_at' => now()])->save();
        Approval::query()->where('object_type', 'content')->where('object_id', $content->id)->where('status', 'open')->update(['status' => 'cancelled', 'updated_at' => now()]);
        $this->event($content, 'archived', null, 'archived', $user, $reason);
        $this->audit->log('content.archived', $content, $reason);
    }

    public function assertCanValidate(Content $content, string $lang, User $user): void
    {
        if ($content->author_id === $user->id) {
            $this->audit->log('content.self_validation_denied', $content, lang: $lang, outcome: 'denied');
            throw new AuthorizationException('Un auteur ne peut pas valider sa propre publication.');
        }
        if (! in_array($lang, $this->access->validationLanguages($user), true)) {
            throw new AuthorizationException('Vous n’êtes pas habilité pour cette langue.');
        }
        $scope = $this->access->structureScope($user, 'contents.validate');
        if ($scope !== null && ! in_array($content->owner_structure_id, $scope, true)) {
            throw new AuthorizationException('Contenu hors de votre périmètre.');
        }
    }

    /**
     * Instantané immuable d'une version linguistique approuvée.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Content $content, ContentLanguage $line): array
    {
        $media = collect($content->media ?? [])->map(function (array $item): ?array {
            $version = AssetVersion::query()->with('asset')->find($item['asset_version_id'] ?? null);

            return $version ? [
                'asset_version_id' => $version->id, 'kind' => $version->asset->kind, 'mime' => $version->mime, 'sha256' => $version->sha256, 'size' => $version->size,
                'pages' => $item['pages'] ?? null, 'page_duration_s' => $item['page_duration_s'] ?? null, 'role' => $item['role'] ?? 'main', 'width' => $version->width, 'height' => $version->height,
            ] : null;
        })->filter()->values()->all();

        return [
            'title' => $line->title, 'body' => $line->body, 'extra' => $line->extra, 'lang' => $line->lang,
            'category' => $content->category?->name, 'kind' => $content->kind, 'media' => $media, 'qr' => $content->qr,
            'subtitles_asset_version_id' => $line->subtitles_asset_version_id,
            'template' => $content->template ? ['id' => $content->template->id, 'orientation' => $content->template->orientation, 'layout' => $content->template->layout, 'version' => $content->template->version] : null,
            'delegation' => $content->delegation,
        ];
    }

    private function reopen(ContentLanguage $line, User $user, string $reason): void
    {
        if (in_array($line->status, ['approved', 'submitted'], true)) {
            $previous = $line->status;
            $line->revision++;
            $line->status = 'draft';
            Approval::query()->where('object_type', 'content')->where('object_id', $line->content_id)->where('lang', $line->lang)->where('status', 'open')
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->event($line->content, 'revised', $previous, 'draft', $user, $reason, $line->lang, $line->revision);
        } elseif ($line->status === 'returned') {
            $line->status = 'draft';
        }
    }

    private function assertSubmittable(Content $content): void
    {
        if (in_array($content->status, ['archived', 'expired'], true)) {
            throw new BusinessException('INVALID_TRANSITION', 'Un contenu archivé ou expiré ne peut pas être soumis.', 409);
        }
        if (! $content->is_permanent && ! $content->expires_at) {
            throw new BusinessException('EXPIRY_REQUIRED', 'Une date de fin est obligatoire pour une annonce temporaire.', 422);
        }
        foreach ($content->media ?? [] as $item) {
            $version = AssetVersion::query()->with('asset')->find($item['asset_version_id'] ?? null);
            if (! $version || $version->scan_status !== 'ready') {
                throw new BusinessException('MEDIA_NOT_READY', 'Un média n’est pas déclaré sain : soumission impossible.', 422);
            }
            $rank = ['public' => 0, 'interne' => 1, 'restreint' => 2];
            if (($rank[$version->asset->classification] ?? 2) > ($rank[$content->classification] ?? 2)) {
                throw new BusinessException('CLASSIFICATION_MISMATCH', 'Le média « '.$version->asset->title.' » est plus classifié que le contenu ('.$version->asset->classification.' pour un contenu '.$content->classification.') : il ne peut pas l’illustrer.', 422);
            }
        }
        if ($content->classification === 'restreint') {
            throw new BusinessException('RESTRICTED_CONTENT', 'Un contenu restreint est exclu de tous les écrans collectifs.', 422);
        }
    }

    private function assertLanguageComplete(Content $content, ContentLanguage $line): void
    {
        $orientation = $content->template?->orientation ?? 'landscape';
        $limits = self::LIMITS[$orientation];
        if (trim($line->title) === '') {
            throw new BusinessException('LANGUAGE_INCOMPLETE', 'Le titre de la version '.strtoupper($line->lang).' est obligatoire.', 422);
        }
        if ($line->overflow || mb_strlen($line->title) > $limits['title'] || mb_strlen((string) $line->body) > $limits['body']) {
            throw new BusinessException('TEXT_OVERFLOW', 'Le texte de la version '.strtoupper($line->lang).' déborde du modèle : raccourcissez-le.', 422);
        }
        $hasVideo = collect($content->media ?? [])->contains(fn (array $m): bool => AssetVersion::query()->whereKey($m['asset_version_id'] ?? null)->whereHas('asset', fn ($q) => $q->where('kind', 'video'))->exists());
        if ($hasVideo && ! $line->subtitles_asset_version_id) {
            throw new BusinessException('SUBTITLES_REQUIRED', 'Une vidéo diffusée sur écran muet exige des sous-titres en '.strtoupper($line->lang).' (PLY-10).', 422);
        }
        if ($line->subtitles_asset_version_id && AssetVersion::query()->whereKey($line->subtitles_asset_version_id)->value('scan_status') !== 'ready') {
            throw new BusinessException('MEDIA_NOT_READY', 'Le fichier de sous-titres n’est pas encore déclaré sain.', 422);
        }
    }

    private function pickValidator(Content $content, string $lang, User $author): ?User
    {
        $candidates = User::query()->where('status', 'active')->where('id', '!=', $author->id)
            ->whereHas('roleAssignments', fn ($q) => $q->whereNull('revoked_at')->where('starts_at', '<=', now())
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                ->whereHas('role', fn ($r) => $r->where('code', 'validateur')))
            ->orderBy('name')->get();

        $eligible = $candidates->filter(function (User $candidate) use ($content, $lang): bool {
            $scope = $this->access->structureScope($candidate, 'contents.validate');

            return in_array($lang, $this->access->validationLanguages($candidate), true)
                && ($scope === null || in_array($content->owner_structure_id, $scope, true));
        });

        return $eligible->sortBy(fn (User $candidate): array => $this->validatorRank($candidate, $content, $lang))->first();
    }

    /**
     * Rang d'affectation : habilitation explicite pour la langue, périmètre le plus proche de la structure propriétaire,
     * charge de validations ouvertes, puis nom (affectation déterministe).
     *
     * @return array{0: int, 1: int, 2: int, 3: string}
     */
    public function validatorRank(User $candidate, Content $content, string $lang): array
    {
        $assignments = $this->access->assignments($candidate)->filter(fn ($a) => $a->role?->code === 'validateur');
        $explicit = $assignments->contains(fn ($a) => in_array($lang, $a->languages ?? [], true)) ? 0 : 1;
        $scope = $this->access->structureScope($candidate, 'contents.validate');
        $proximity = $scope === null ? 999 : count($scope);
        $load = Approval::query()->where('status', 'open')->where('assignee_id', $candidate->id)->count();

        return [$explicit, $proximity, $load, $candidate->name];
    }

    private function event(Content $content, string $action, ?string $from, ?string $to, User $user, ?string $reason = null, ?string $lang = null, ?int $revision = null): void
    {
        WorkflowEvent::query()->create([
            'object_type' => 'content', 'object_id' => $content->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to,
            'lang' => $lang, 'revision' => $revision, 'actor_id' => $user->id, 'actor_role' => mb_substr($this->access->primaryRoleLabel($user), 0, 40), 'reason' => $reason, 'created_at' => now(),
        ]);
    }

    private function castValue(Content $content, string $key, mixed $value): mixed
    {
        $clone = $content->replicate();
        $clone->setAttribute($key, $value);

        return $clone->getAttribute($key);
    }
}
