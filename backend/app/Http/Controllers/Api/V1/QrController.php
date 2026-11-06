<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccessLink;
use App\Models\Content;
use App\Models\Document;
use App\Models\User;
use App\Services\ContentAccess;
use App\Services\DocumentAccess;
use App\Support\BusinessException;
use App\Support\Present;
use Illuminate\Http\JsonResponse;

/**
 * Résolution d'un QR code (DOC-05, DOC-07) : un QR public n'ouvre qu'un contenu public ; un QR interne exige une session
 * et réévalue les droits à chaque accès. Aucune URL de stockage n'est renvoyée.
 */
class QrController extends Controller
{
    public function show(string $code, DocumentAccess $documents): JsonResponse
    {
        $link = AccessLink::query()->where('code', $code)->first();
        if (! $link || ($link->expires_at && $link->expires_at->isPast())) {
            throw new BusinessException('NOT_FOUND', 'Ce lien n’est plus disponible.', 404);
        }
        $link->increment('hits');
        $user = auth('sanctum')->user();

        if ($link->target_type === 'document') {
            $document = Document::query()->with(['owner', 'author', 'category', 'currentVersion.assetVersion'])->find($link->target_id);
            if (! $document || $document->status !== 'published') {
                throw new BusinessException('NOT_FOUND', 'Ce document n’est plus publié.', 404);
            }
            if ($document->classification === 'public') {
                return response()->json(['data' => ['type' => 'document', 'public' => true, 'document' => array_merge(Present::document($document), ['author' => null])]]);
            }
            if (! $user instanceof User) {
                throw new BusinessException('UNAUTHENTICATED', 'Authentification requise.', 401, ['redirect' => "/documents/{$document->id}"]);
            }
            if (! $documents->canView($document, $user)) {
                throw new BusinessException('FORBIDDEN', 'Vous n’avez pas accès à ce document.', 403);
            }

            return response()->json(['data' => ['type' => 'document', 'public' => false, 'redirect' => "/documents/{$document->id}"]]);
        }

        // DOC-05 : le QR d'un contenu ne mène qu'à un contenu encore diffusable ; hors public, il faut une session et le droit de le voir.
        $content = Content::query()->find($link->target_id);
        if (! $content || ! in_array($content->status, ['approved', 'scheduled', 'broadcasting'], true)) {
            throw new BusinessException('NOT_FOUND', 'Ce contenu n’est plus diffusé.', 404);
        }
        if ($content->classification !== 'public') {
            if (! $user instanceof User) {
                throw new BusinessException('UNAUTHENTICATED', 'Authentification requise.', 401);
            }
            if (! app(ContentAccess::class)->scope(Content::query()->whereKey($content->id), $user)->exists()) {
                throw new BusinessException('FORBIDDEN', 'Vous n’avez pas accès à ce contenu.', 403);
            }
        }

        return response()->json(['data' => ['type' => 'content', 'public' => $content->classification === 'public', 'title' => $content->title]]);
    }
}
