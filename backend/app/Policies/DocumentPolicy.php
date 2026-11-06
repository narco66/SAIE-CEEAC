<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\DocumentAccess;

/**
 * SEC-02 : droits sur un document et sur chaque téléchargement (DOC-04, DOC-07).
 */
class DocumentPolicy
{
    public function __construct(private DocumentAccess $documents) {}

    public function view(User $user, Document $document): bool
    {
        return $this->documents->canView($document, $user);
    }

    public function manage(User $user, Document $document): bool
    {
        return $this->documents->canManage($document, $user);
    }

    /**
     * Téléchargement réautorisé à chaque appel : la version courante pour un lecteur autorisé, toute version pour un gestionnaire.
     */
    public function download(User $user, Document $document, DocumentVersion $version): bool
    {
        return $this->view($user, $document) && ($version->id === $document->current_version_id || $this->manage($user, $document));
    }
}
