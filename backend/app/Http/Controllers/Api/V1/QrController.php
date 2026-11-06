<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccessLink;
use App\Models\Content;
use App\Models\Document;
use App\Models\User;
use App\Services\ContentAccess;
use App\Services\DocumentAccess;
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
            return response()->json(['code' => 'NOT_FOUND', 'message' => 'Ce lien n’est plus disponible.', 'errors' => (object) []], 404);
        }
        $link->increment('hits');
        $user = auth('sanctum')->user();

        if ($link->target_type === 'document') {
            $document = Document::query()->with(['owner', 'author', 'category', 'currentVersion.assetVersion'])->find($link->target_id);
            if (! $document || $document->status !== 'published') {
                return response()->json(['code' => 'NOT_FOUND', 'message' => 'Ce document n’est plus publié.', 'errors' => (object) []], 404);
            }
            if ($document->classification === 'public') {
                return response()->json(['data' => ['type' => 'document', 'public' => true, 'document' => array_merge(Present::document($document), ['author' => null])]]);
            }
            if (! $user instanceof User) {
                return response()->json(['code' => 'UNAUTHENTICATED', 'message' => 'Authentification requise.', 'errors' => (object) [], 'details' => ['redirect' => "/documents/{$document->id}"]], 401);
            }
            if (! $documents->canView($document, $user)) {
                return response()->json(['code' => 'FORBIDDEN', 'message' => 'Vous n’avez pas accès à ce document.', 'errors' => (object) []], 403);
            }

            return response()->json(['data' => ['type' => 'document', 'public' => false, 'redirect' => "/documents/{$document->id}"]]);
        }

        // DOC-05 : le QR d'un contenu ne mène qu'à un contenu encore diffusable ; hors public, il faut une session et le droit de le voir.
        $content = Content::query()->find($link->target_id);
        if (! $content || ! in_array($content->status, ['approved', 'scheduled', 'broadcasting'], true)) {
            return response()->json(['code' => 'NOT_FOUND', 'message' => 'Ce contenu n’est plus diffusé.', 'errors' => (object) []], 404);
        }
        if ($content->classification !== 'public') {
            if (! $user instanceof User) {
                return response()->json(['code' => 'UNAUTHENTICATED', 'message' => 'Authentification requise.', 'errors' => (object) []], 401);
            }
            if (! app(ContentAccess::class)->scope(Content::query()->whereKey($content->id), $user)->exists()) {
                return response()->json(['code' => 'FORBIDDEN', 'message' => 'Vous n’avez pas accès à ce contenu.', 'errors' => (object) []], 403);
            }
        }

        return response()->json(['data' => ['type' => 'content', 'public' => $content->classification === 'public', 'title' => $content->title]]);
    }
}
