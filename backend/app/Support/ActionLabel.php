<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Libellés lisibles des actions du journal, utilisés dans les éditions PDF et l'interface.
 */
class ActionLabel
{
    private const LABELS = [
        'auth.login' => 'Connexion', 'auth.logout' => 'Déconnexion', 'auth.login_failed' => 'Échec de connexion', 'auth.login_locked' => 'Compte verrouillé',
        'auth.mfa_failed' => 'Échec du second facteur', 'auth.mfa_locked' => 'Compte bloqué (second facteur, déblocage par un administrateur)', 'auth.password_reset' => 'Mot de passe réinitialisé', 'auth.reset_requested' => 'Réinitialisation demandée',
        'access.denied' => 'Accès refusé',
        'content.created' => 'Contenu créé', 'content.updated' => 'Contenu modifié', 'content.submitted' => 'Version soumise', 'content.approved' => 'Version approuvée',
        'content.returned' => 'Version retournée pour correction', 'content.reassigned' => 'Validation réaffectée', 'content.archived' => 'Contenu archivé',
        'content.expired' => 'Contenu expiré', 'content.duplicated' => 'Contenu dupliqué', 'content.self_validation_denied' => 'Auto-validation refusée',
        'campaign.created' => 'Campagne créée', 'campaign.updated' => 'Campagne modifiée', 'campaign.published' => 'Campagne programmée', 'campaign.suspended' => 'Campagne suspendue', 'campaign.expired' => 'Campagne expirée',
        'alert.published' => 'Alerte publiée', 'alert.extended' => 'Alerte prolongée', 'alert.revoked' => 'Alerte levée',
        'reservation.created' => 'Réservation créée', 'reservation.approved' => 'Réservation approuvée', 'reservation.refused' => 'Réservation refusée',
        'reservation.modified' => 'Réservation modifiée', 'reservation.cancelled' => 'Réservation annulée', 'reservation.no_show' => 'Non-présentation déclarée',
        'reservation.hold_expired' => 'Délai de validation dépassé', 'reservation.agenda_updated' => 'Ordre du jour modifié', 'reservation.priority_changed' => 'Priorité de la réunion modifiée', 'reservation.series_partial' => 'Série créée en mode partiel',
        'reservation.presence_confirmed' => 'Présence confirmée', 'reservation.auto_released' => 'Salle libérée automatiquement',
        'device.paired' => 'Lecteur appairé', 'device.revoked' => 'Lecteur révoqué', 'device.screenshot_requested' => 'Capture demandée', 'device.asset_denied' => 'Ressource refusée au lecteur',
        'document.created' => 'Document déposé', 'document.published' => 'Document publié', 'document.unpublished' => 'Document dépublié', 'document.version_added' => 'Nouvelle version',
        'document.broadcast' => 'Document diffusé par courriel', 'document.downloaded' => 'Document téléchargé', 'document.access_denied' => 'Accès au document refusé',
        'asset.uploaded' => 'Média déposé', 'asset.ready' => 'Média déclaré sain', 'asset.rejected' => 'Média rejeté', 'asset.trashed' => 'Média mis à la corbeille',
        'user.created' => 'Compte créé', 'user.deactivated' => 'Compte désactivé', 'user.reactivated' => 'Compte réactivé', 'role.assigned' => 'Rôle attribué', 'role.revoked' => 'Rôle retiré',
        'power.granted' => 'Pouvoir étendu attribué', 'power.revoked' => 'Pouvoir étendu retiré', 'role.permissions_changed' => 'Permissions modifiées',
        'export.requested' => 'Export demandé', 'export.generated' => 'Export généré', 'export.downloaded' => 'Export téléchargé', 'audit.exported' => 'Extrait du journal exporté',
        'welcome.created' => 'Message de bienvenue créé', 'approval.escalated' => 'Validation escaladée', 'setting.updated' => 'Paramètre modifié',
        'account.mfa_enabled' => 'Second facteur activé', 'account.password_changed' => 'Mot de passe modifié', 'account.preferences_updated' => 'Préférences modifiées',
        'account.push_subscribed' => 'Notifications push activées', 'account.push_unsubscribed' => 'Notifications push désactivées', 'account.session_revoked' => 'Session fermée',
        'user.created_cli' => 'Compte créé en ligne de commande', 'user.sessions_revoked' => 'Sessions fermées', 'user.updated' => 'Compte modifié', 'user.unlocked' => 'Compte débloqué', 'user.mfa_reset' => 'Second facteur réinitialisé',
        'asset.updated' => 'Média modifié', 'document.updated' => 'Document modifié', 'import.committed' => 'Import appliqué',
        'template.created' => 'Modèle créé', 'template.updated' => 'Modèle modifié', 'template.duplicated' => 'Modèle dupliqué', 'template.archived' => 'Modèle archivé',
        'fallback.created' => 'Contenu de repli créé', 'fallback.disabled' => 'Contenu de repli désactivé',
        'screen.created' => 'Écran créé', 'group.created' => 'Groupe d’écrans créé', 'group.updated' => 'Groupe d’écrans modifié', 'group.deleted' => 'Groupe d’écrans supprimé',
        'incident.created' => 'Incident ouvert', 'device.channel_changed' => 'Canal de version modifié',
        'device.command_requested' => 'Commande à distance demandée', 'device.command_executed' => 'Commande exécutée par le lecteur', 'device.command_failed' => 'Commande en échec',
        'device.command_expired' => 'Commande expirée sans compte rendu', 'device.command_cancelled' => 'Commande annulée', 'device.command_refused' => 'Commande refusée (indisponible)',
        'device.version_changed' => 'Version du lecteur changée', 'screen.deactivated' => 'Écran désactivé', 'screen.commissioned' => 'Écran mis en service', 'device.updated' => 'Fiche du lecteur modifiée', 'device.enrollment_refused' => 'Enrôlement refusé (liste blanche)', 'playlist.assigned' => 'Liste de lecture affectée directement', 'device.agent_token_issued' => 'Jeton d’agent délivré', 'device.rotation_requested' => 'Renouvellement des identifiants demandé',
        'device.release_pinned' => 'Version épinglée sur le lecteur', 'player.release_auto_promoted' => 'Version promue automatiquement en stable', 'screen.reactivated' => 'Écran réactivé', 'device.suspended' => 'Lecteur suspendu', 'device.resumed' => 'Lecteur rétabli', 'player.release_rollout' => 'Vague de déploiement modifiée',
        'device.reassigned' => 'Lecteur réaffecté à un autre écran', 'screen.device_removed' => 'Lecteur retiré (réaffecté)',
        'screen.archived' => 'Écran archivé', 'screen.updated' => 'Écran modifié', 'screen.window_changed' => 'Plage de fonctionnement modifiée',
        'incident.resolve' => 'Incident résolu', 'incident.close' => 'Incident clos', 'incident.assign' => 'Incident affecté', 'incident.acknowledge' => 'Incident acquitté', 'incident.diagnose' => 'Diagnostic consigné',
        'player.release_published' => 'Version du lecteur publiée', 'player.release_withdrawn' => 'Version du lecteur retirée',
        'room.created' => 'Salle créée', 'room.updated' => 'Salle modifiée', 'room.blocked' => 'Salle bloquée', 'room.unblocked' => 'Blocage de salle levé',
        'resource.created' => 'Ressource mobile créée', 'service.checklist_updated' => 'Liste de contrôle mise à jour',
        'visit.created' => 'Visite créée', 'visit.updated' => 'Visite modifiée',
        'source.created' => 'Source dynamique déclarée', 'source.updated' => 'Source dynamique modifiée', 'source.tested' => 'Source dynamique testée',
        'service_account.created' => 'Compte de service créé', 'service_account.rotated' => 'Jeton de service renouvelé', 'service_account.revoked' => 'Compte de service révoqué',
        'integration.read' => 'Lecture par l’API d’intégration',
    ];

    public static function for(string $action): string
    {
        return self::LABELS[$action] ?? Str::ucfirst(str_replace(['.', '_'], ' ', $action));
    }
}
