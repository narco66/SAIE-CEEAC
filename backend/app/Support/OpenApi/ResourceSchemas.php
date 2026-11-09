<?php

namespace App\Support\OpenApi;

use App\Services\RemoteCommands;

/**
 * Schémas de réponse des ressources du tableau du chapitre 17 (API-02, API-06), alignés sur App\Support\Present.
 *
 * Les propriétés requises sont celles que le présentateur renvoie toujours ; un champ supplémentaire reste permis
 * (évolution compatible). La suite de tests valide chaque réponse produite contre ces schémas.
 */
class ResourceSchemas
{
    /** Opérations dont l'élément renvoyé (dans data, ou chaque élément d'une liste) suit un schéma de ressource. */
    private const OPERATIONS = [
        'Me' => ['meShow'],
        'Screen' => ['screenIndex', 'screenShow', 'screenStore', 'screenUpdate', 'screenArchive'],
        'Device' => ['screenPair', 'screenRevoke'],
        'Room' => ['roomIndex', 'roomShow', 'roomStore', 'roomUpdate'],
        'Availability' => ['roomAvailability'],
        'Asset' => ['assetIndex', 'assetShow', 'assetStore'],
        'Document' => ['documentIndex', 'documentShow', 'documentStore', 'documentUpdate', 'documentPublish', 'documentUnpublish', 'documentAddVersion'],
        'Content' => ['contentIndex', 'contentShow', 'contentStore', 'contentUpdate', 'contentLanguages', 'contentSubmit', 'contentApprove', 'contentArchive', 'contentDuplicate'],
        'Campaign' => ['campaignIndex', 'campaignShow', 'campaignStore', 'campaignUpdate', 'campaignPublish', 'campaignSuspend'],
        'ScreenGroup' => ['screenGroups', 'screenStoreGroup'],
        'ScreenshotRequest' => ['screenRequestScreenshot'],
        'AssetStatus' => ['assetStatus'],
        'Template' => ['templateIndex', 'templateStore'],
        'Acknowledgement' => ['notificationRead', 'notificationReadAll'],
        'Alert' => ['alertIndex', 'alertShow', 'alertStore', 'alertExtend', 'alertRevoke'],
        'Reservation' => ['reservationIndex', 'reservationShow', 'reservationStore', 'reservationApprove', 'reservationRefuse', 'reservationPriority', 'reservationCancel', 'reservationNoShow', 'reservationCheckIn', 'reservationModify', 'reservationModifyFollowing'],
        'Export' => ['exportIndex', 'exportShow', 'exportStore'],
        'Notification' => ['notificationIndex'],
        'AuditLog' => ['auditIndex'],
        'ImportReport' => ['importSimulate', 'importCommit'],
        'QrResolution' => ['qrShow'],
        'ManifestEnvelope' => ['deviceApiManifest'],
        'HeartbeatAck' => ['deviceApiHeartbeat'],
        'DeviceCommand' => ['deviceCommandIndex', 'deviceCommandStore', 'deviceCommandCancel'],
        'CommandAck' => ['deviceApiAcknowledgeCommand'],
        'ReceiptAck' => ['deviceApiReceipts'],
    ];

    /** Listes non paginées : data est un tableau d'éléments. */
    private const LISTS = ['screenGroups', 'templateIndex'];

    /** Réponses non enveloppées dans data (protocole du lecteur, accusés). */
    private const UNWRAPPED = ['ManifestEnvelope', 'HeartbeatAck', 'ReceiptAck', 'Acknowledgement'];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function components(): array
    {
        $string = ['type' => 'string'];
        $nullableString = ['type' => ['string', 'null']];
        $uuid = ['type' => 'string', 'format' => 'uuid'];
        $int = ['type' => 'integer'];
        $bool = ['type' => 'boolean'];
        $instant = ['type' => ['string', 'null'], 'format' => 'date-time'];
        $userRef = ['type' => ['object', 'null'], 'required' => ['id', 'name'], 'properties' => ['id' => $uuid, 'name' => $string, 'initials' => $string]];
        $commandNames = array_keys(RemoteCommands::CATALOGUE);
        $named = ['type' => ['object', 'null'], 'required' => ['id', 'name'], 'properties' => ['id' => $uuid, 'name' => $string]];
        $classification = ['enum' => ['public', 'interne', 'restreint']];
        $object = fn (array $required, array $properties) => ['type' => 'object', 'required' => $required, 'properties' => $properties];

        $device = $object(['id', 'code', 'status', 'network_status', 'last_seen_at', 'paired_at'], [
            'id' => $uuid, 'code' => $string, 'status' => $string, 'hardware_id' => $nullableString, 'network_status' => $string,
            'last_seen_at' => $instant, 'last_sync_at' => $instant, 'app_version' => $nullableString, 'clock_skew_ms' => ['type' => ['integer', 'null']],
            'active_manifest_version' => ['type' => ['integer', 'null']], 'paired_at' => $instant, 'revoked_at' => $instant, 'revoke_reason' => $nullableString,
        ]);
        $assetVersion = ['type' => ['object', 'null'], 'required' => ['id', 'version', 'mime', 'size', 'sha256', 'scan_status'], 'properties' => [
            'id' => $uuid, 'version' => $int, 'original_name' => $nullableString, 'mime' => $nullableString, 'size' => $int, 'sha256' => $nullableString,
            'scan_status' => $string, 'has_thumbnail' => $bool, 'has_consultation_pdf' => $bool, 'created_at' => $instant, 'processed_at' => $instant,
        ]];
        $occurrence = $object(['id', 'sequence', 'status', 'version', 'starts_at', 'ends_at', 'room'], [
            'id' => $uuid, 'sequence' => $int, 'status' => ['enum' => ['draft', 'pending', 'confirmed', 'refused', 'cancelled', 'in_progress', 'completed', 'no_show']], 'version' => $int,
            'starts_at' => $instant, 'ends_at' => $instant, 'blocking_start' => $instant, 'blocking_end' => $instant, 'hold_until' => $instant,
            'room' => $object(['id'], ['id' => $uuid, 'code' => $string, 'name' => $string, 'capacity' => $int]), 'services' => ['type' => 'array'],
        ]);

        return [
            'Me' => $object(['id', 'name', 'email', 'roles', 'permissions', 'powers', 'validation_languages', 'mfa_enabled', 'server_time'], [
                'id' => $uuid, 'name' => $string, 'email' => ['type' => 'string', 'format' => 'email'], 'roles' => ['type' => 'array'],
                'permissions' => ['type' => 'array', 'items' => $string],
                'powers' => ['type' => 'array', 'items' => $object(['power', 'ends_at', 'active'], ['power' => ['enum' => array_keys((array) config('saie.powers'))], 'scope' => ['type' => ['object', 'array', 'null']], 'ends_at' => $instant, 'active' => $bool])],
                'validation_languages' => ['type' => 'array', 'items' => $string], 'mfa_enabled' => $bool, 'must_change_password' => $bool, 'server_time' => $instant,
            ]),
            'Screen' => $object(['id', 'version', 'code', 'name', 'kind', 'orientation', 'status', 'operating_window', 'panel_state', 'location', 'groups', 'device'], [
                'id' => $uuid, 'version' => $int, 'code' => $string, 'name' => $string, 'kind' => $string, 'orientation' => $string, 'resolution' => $nullableString, 'status' => $string,
                'operating_window' => ['type' => 'object'], 'audio_enabled' => $bool, 'panel_state' => $string, 'panel_state_at' => $instant,
                'location' => ['type' => ['object', 'null']], 'room' => $named, 'groups' => ['type' => 'array', 'items' => $object(['id', 'name'], ['id' => $uuid, 'name' => $string])],
                'device' => ['oneOf' => [['type' => 'null'], ['$ref' => '#/components/schemas/Device']]],
            ]),
            'Device' => $device,
            'Room' => $object(['id', 'version', 'code', 'name', 'capacity', 'floor', 'approval_mode', 'open_from', 'open_to', 'status', 'equipment'], [
                'id' => $uuid, 'version' => $int, 'code' => $string, 'name' => $string, 'capacity' => $int, 'floor' => ['type' => ['object', 'null']], 'manager' => $userRef,
                'approval_mode' => ['enum' => ['auto', 'manual']], 'min_minutes' => $int, 'max_minutes' => $int, 'prep_minutes' => $int, 'cleanup_minutes' => $int,
                'open_from' => ['type' => 'string', 'pattern' => '^\d{2}:\d{2}$'], 'open_to' => ['type' => 'string', 'pattern' => '^\d{2}:\d{2}$'],
                'status' => ['enum' => ['active', 'inactive']], 'auto_release_enabled' => $bool, 'equipment' => ['type' => 'array'],
            ]),
            'Availability' => $object(['room', 'busy', 'rules'], [
                'room' => $object(['id', 'name'], ['id' => $uuid, 'name' => $string]),
                'busy' => ['type' => 'array', 'description' => 'Intervalles occupés, sans titre de réunion confidentielle (SAL-09)'], 'rules' => ['type' => 'object'],
            ]),
            'Asset' => $object(['id', 'version', 'reference', 'title', 'kind', 'classification', 'status', 'current_version'], [
                'id' => $uuid, 'version' => $int, 'reference' => $string, 'title' => $string, 'kind' => $string, 'classification' => $classification, 'language' => $nullableString,
                'status' => $string, 'owner' => $named, 'creator' => $userRef, 'current_version' => $assetVersion, 'created_at' => $instant, 'updated_at' => $instant,
            ]),
            'Document' => $object(['id', 'version', 'reference', 'title', 'classification', 'status', 'keywords', 'current_version', 'diffusion_allowed'], [
                'id' => $uuid, 'version' => $int, 'reference' => $string, 'title' => $string, 'summary' => $nullableString, 'classification' => $classification, 'status' => $string,
                'language' => $nullableString, 'keywords' => ['type' => 'array', 'items' => $string], 'owner' => $named, 'author' => $userRef,
                'valid_from' => ['type' => ['string', 'null'], 'format' => 'date'], 'expires_at' => ['type' => ['string', 'null'], 'format' => 'date'],
                'current_version' => ['type' => ['object', 'null']], 'diffusion_allowed' => $bool, 'published_at' => $instant, 'updated_at' => $instant,
            ]),
            'Content' => $object(['id', 'reference', 'title', 'kind', 'status', 'classification', 'priority', 'version', 'languages'], [
                'id' => $uuid, 'reference' => $string, 'title' => $string, 'kind' => $string, 'status' => $string, 'classification' => $classification,
                'priority' => $int, 'version' => $int, 'author' => $userRef, 'owner' => $named, 'valid_from' => $instant, 'expires_at' => $instant, 'is_permanent' => $bool,
                'languages' => ['type' => 'array', 'items' => $object(['id', 'lang', 'revision', 'status'], ['id' => $uuid, 'lang' => ['enum' => ['fr', 'en', 'pt', 'es']], 'revision' => $int, 'title' => $nullableString, 'status' => $string, 'submitted_at' => $instant])],
                'created_at' => $instant, 'updated_at' => $instant,
            ]),
            'Campaign' => $object(['id', 'reference', 'name', 'status', 'version', 'starts_at', 'ends_at', 'languages', 'items', 'targets'], [
                'id' => $uuid, 'reference' => $string, 'name' => $string, 'kind' => $string, 'status' => $string, 'priority' => $int, 'version' => $int,
                'starts_at' => $instant, 'ends_at' => $instant, 'days' => ['type' => 'array'], 'slide_duration_s' => ['type' => ['integer', 'null']], 'suspend_reason' => $nullableString,
                'creator' => $userRef, 'published_at' => $instant, 'languages' => ['type' => 'array'], 'items' => ['type' => 'array'],
                'targets' => ['type' => 'array', 'items' => $object(['type', 'id'], ['type' => $string, 'id' => $uuid])],
            ]),
            'Alert' => $object(['id', 'reference', 'title', 'message', 'severity', 'status', 'starts_at', 'expires_at', 'author', 'justification', 'targets'], [
                'id' => $uuid, 'reference' => $string, 'title' => $string, 'message' => $string, 'severity' => $string,
                'status' => ['enum' => ['pending', 'scheduled', 'active', 'expired', 'revoked']], 'starts_at' => $instant, 'expires_at' => $instant, 'author' => $userRef, 'justification' => $string,
                'revoked_at' => $instant, 'revoker' => $userRef, 'revoke_reason' => $nullableString, 'extensions' => ['type' => 'array'], 'target_count' => ['type' => ['integer', 'null']],
                'targets' => ['type' => 'array'], 'receipts' => ['type' => ['object', 'null'], 'properties' => ['received' => $int, 'activated' => $int]],
            ]),
            'Occurrence' => $occurrence,
            'Reservation' => $object(['id', 'reference', 'confidential', 'version', 'subject', 'participant_count', 'occurrences', 'can_manage'], [
                'id' => $uuid, 'reference' => $string, 'confidential' => $bool, 'version' => $int,
                'subject' => ['type' => 'string', 'description' => '« Réservée » pour un tiers si la réunion est confidentielle ou encore en attente (SAL-02, SAL-09)'],
                'organizer' => $userRef, 'participant_count' => $int, 'is_priority' => $bool, 'created_at' => $instant,
                'occurrences' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Occurrence']], 'can_manage' => $bool,
            ]),
            'Export' => $object(['id', 'type', 'format', 'status', 'progress', 'created_at'], [
                'id' => $uuid, 'type' => $string, 'label' => $string, 'format' => $string, 'status' => $string, 'progress' => $int,
                'file_name' => $nullableString, 'size' => ['type' => ['integer', 'null']], 'error' => $nullableString,
                'created_at' => $instant, 'completed_at' => $instant, 'expires_at' => $instant,
            ]),
            'Notification' => $object(['id', 'event', 'title', 'read_at', 'created_at'], [
                'id' => $uuid, 'event' => $string, 'title' => $string, 'body' => $nullableString, 'link' => $nullableString, 'object_type' => $nullableString,
                'read_at' => $instant, 'created_at' => $instant,
            ]),
            'AuditLog' => $object(['id', 'occurred_at', 'actor', 'actor_type', 'action', 'outcome', 'request_id'], [
                'id' => $int, 'occurred_at' => $instant, 'actor' => $nullableString, 'actor_type' => $string, 'role' => $nullableString, 'power' => $nullableString,
                'action' => $string, 'object_type' => $nullableString, 'object_id' => $nullableString, 'reason' => $nullableString,
                'outcome' => ['enum' => ['success', 'denied', 'failure']], 'request_id' => $nullableString,
            ]),
            'ScreenGroup' => $object(['id', 'version', 'code', 'name', 'operating_window'], ['id' => $uuid, 'version' => $int, 'code' => $string, 'name' => $string, 'description' => $nullableString, 'screens_count' => $int, 'operating_window' => ['type' => ['object', 'null']], 'screens' => ['type' => 'array'], 'active_campaigns' => $int]),
            'ScreenshotRequest' => $object(['id', 'device_id', 'requested_at'], ['id' => $uuid, 'status' => $nullableString, 'device_id' => $uuid, 'requested_at' => $instant]),
            'AssetStatus' => $object(['status', 'version'], ['status' => $string, 'version' => $assetVersion]),
            'Template' => $object(['id', 'code', 'name', 'orientation', 'version', 'status'], [
                'id' => $uuid, 'code' => $string, 'name' => $string, 'orientation' => $string, 'purpose' => $nullableString, 'layout' => ['type' => ['object', 'array', 'null']],
                'version' => $int, 'status' => $string, 'is_institutional' => $bool, 'updated_at' => $instant, 'used_by' => $int,
            ]),
            'Acknowledgement' => $object(['ok'], ['ok' => ['const' => true]]),
            'ImportReport' => $object(['summary', 'rows'], ['summary' => ['type' => 'object'], 'rows' => ['type' => 'array']]),
            'QrResolution' => $object(['type', 'public'], ['type' => ['enum' => ['document', 'content', 'screen']], 'public' => $bool, 'title' => $string, 'redirect' => $string, 'document' => ['type' => 'object']]),
            // La révocation est signifiée par un refus 401 sur tout appel du lecteur, pas par un champ de la réponse.
            'HeartbeatAck' => $object(['server_time', 'clock_skew_ms', 'next_heartbeat_s', 'commands'], [
                'server_time' => $instant, 'clock_skew_ms' => ['type' => ['integer', 'null']], 'next_heartbeat_s' => $int, 'screenshot_requests' => ['type' => 'array'],
                'commands' => ['type' => 'array', 'items' => $object(['id', 'command', 'expires_at'], ['id' => $uuid, 'command' => ['enum' => $commandNames], 'params' => ['type' => ['object', 'null']], 'expires_at' => $instant])],
            ]),
            'DeviceCommand' => $object(['id', 'device_id', 'command', 'label', 'status', 'requested_at', 'expires_at'], [
                'id' => $uuid, 'device_id' => $uuid, 'screen_id' => ['type' => ['string', 'null']], 'command' => ['enum' => $commandNames], 'label' => $string,
                'status' => ['enum' => ['pending', 'sent', 'received', 'executed', 'failed', 'expired', 'cancelled']], 'reason' => $nullableString, 'requested_by' => $userRef,
                'requested_at' => $instant, 'sent_at' => $instant, 'received_at' => $instant, 'completed_at' => $instant, 'expires_at' => $instant,
                'result_message' => $nullableString, 'result_data' => ['type' => ['object', 'array', 'null']], 'screenshot_id' => ['type' => ['string', 'null']],
            ]),
            'CommandAck' => $object(['id', 'status'], ['id' => $uuid, 'status' => ['enum' => ['pending', 'sent', 'received', 'executed', 'failed', 'expired', 'cancelled']]]),
            'ReceiptAck' => $object(['accepted', 'duplicates'], ['accepted' => $int, 'duplicates' => $int]),
        ];
    }

    /**
     * Schéma de l'élément renvoyé par une opération, si elle est décrite.
     *
     * @return array<string, mixed>|null
     */
    public static function forOperation(string $operationId): ?array
    {
        foreach (self::OPERATIONS as $schema => $operations) {
            if (in_array($operationId, $operations, true)) {
                return ['$ref' => "#/components/schemas/$schema"];
            }
        }

        return null;
    }

    public static function isList(string $operationId): bool
    {
        return in_array($operationId, self::LISTS, true);
    }

    /**
     * Le protocole du lecteur renvoie certaines ressources sans enveloppe data.
     */
    public static function isUnwrapped(string $operationId): bool
    {
        foreach (self::UNWRAPPED as $schema) {
            if (in_array($operationId, self::OPERATIONS[$schema], true)) {
                return true;
            }
        }

        return false;
    }
}
