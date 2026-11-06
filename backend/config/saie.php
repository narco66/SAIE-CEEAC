<?php

/*
 * Paramètres métier SAIE-CEEAC. Les valeurs chiffrées reprennent les propositions du cahier des charges v3.0
 * et restent configurables (elles ne deviennent contractuelles qu'après validation de la maîtrise d'ouvrage).
 */
return [

    'timezone' => env('SAIE_TIMEZONE', 'Africa/Libreville'),

    'demo_mode' => (bool) env('SAIE_DEMO_MODE', false),

    'security' => [
        'mfa_enforced' => (bool) env('SAIE_MFA_ENFORCED', true),
        'mfa_roles' => ['admin_tech', 'admin_fonc', 'validateur'],
        'max_failed_logins' => 5,
        'lockout_minutes' => 15,
    ],

    'device' => [
        // Préfixe d'emplacement interne Nginx (X-Accel-Redirect) ; vide : fichier servi par PHP (poste de développement).
        'accel_redirect' => env('SAIE_ACCEL_REDIRECT'),
        // PLY-02 : durée de vie d'un jeton de lecteur ; le lecteur le renouvelle de lui-même à mi-vie.
        'token_ttl_days' => (int) env('SAIE_DEVICE_TOKEN_TTL_DAYS', 90),
        'heartbeat_s' => 30,
        'suspect_after_s' => 90,
        'unavailable_after_s' => 300,
        'pairing_code_ttl_min' => 10,
        'manifest_validity_public_h' => (int) env('SAIE_OFFLINE_PUBLIC_H', 24),
        'manifest_validity_internal_h' => (int) env('SAIE_OFFLINE_INTERNAL_H', 1),
        'signing_key_path' => env('SAIE_MANIFEST_KEY_PATH', storage_path('app/private/keys/manifest-ed25519.pem')),
        'signing_key_id' => env('SAIE_MANIFEST_KEY_ID', 'mk-local'),
        'screenshot_retention_days' => 7,
    ],

    'operating_window' => [
        'days' => [1, 2, 3, 4, 5],
        'on' => '08:00',
        'off' => '18:00',
        'wake_before_min' => 5,
    ],

    'workflow' => [
        'content_due_business_days' => 2,
        'reservation_due_hours' => 24,
    ],

    /*
     * Notifications push du navigateur (NOT-03). Clés VAPID générées par « php artisan saie:vapid ».
     * Seuls ces événements sont poussés ; le contenu poussé ne porte jamais de titre confidentiel.
     */
    'push' => [
        'public_key' => env('SAIE_VAPID_PUBLIC_KEY'),
        'private_key' => env('SAIE_VAPID_PRIVATE_KEY'),
        'subject' => env('SAIE_VAPID_SUBJECT', 'mailto:dsi@ceeac-eccas.org'),
        'events' => ['reservation.confirmed', 'reservation.refused', 'reservation.reminder', 'content.returned'],
    ],

    'alerts' => [
        'default_duration_min' => 30,
        'max_duration_min' => 240,
    ],

    'rooms' => [
        'max_recurrence_months' => 6,
        'max_occurrences' => 100,
    ],

    'uploads' => [
        'max_document_mb' => (int) env('SAIE_MAX_DOCUMENT_MB', 50),
        'max_video_mb' => (int) env('SAIE_MAX_VIDEO_MB', 500),
        'allowed' => [
            'jpg' => ['kind' => 'image', 'mimes' => ['image/jpeg']],
            'jpeg' => ['kind' => 'image', 'mimes' => ['image/jpeg']],
            'png' => ['kind' => 'image', 'mimes' => ['image/png']],
            'webp' => ['kind' => 'image', 'mimes' => ['image/webp']],
            'mp4' => ['kind' => 'video', 'mimes' => ['video/mp4']],
            'webm' => ['kind' => 'video', 'mimes' => ['video/webm']],
            'pdf' => ['kind' => 'pdf', 'mimes' => ['application/pdf']],
            'docx' => ['kind' => 'office', 'mimes' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip']],
            'pptx' => ['kind' => 'office', 'mimes' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip']],
            'xlsx' => ['kind' => 'office', 'mimes' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip']],
            'vtt' => ['kind' => 'subtitle', 'mimes' => ['text/vtt', 'text/plain']],
        ],
    ],

    /*
     * Binaires de conversion (DOC-01, SEC-03) — optionnels ; à exécuter dans le worker « media » isolé sans réseau.
     */
    'converters' => [
        'soffice' => env('SAIE_SOFFICE_PATH'),
        'ffprobe' => env('SAIE_FFPROBE_PATH'),
        'ffmpeg' => env('SAIE_FFMPEG_PATH'),
    ],

    'antivirus' => [
        'driver' => env('SAIE_AV_DRIVER', 'signature'),
        'clamd_host' => env('SAIE_CLAMD_HOST', '127.0.0.1'),
        'clamd_port' => (int) env('SAIE_CLAMD_PORT', 3310),
    ],

    'retention' => [
        'audit_months' => 12,
        'trash_days' => 30,
        'telemetry_days' => 30,
        'export_hours' => 72,
        'auto_purge_enabled' => false,
    ],

    /*
     * Permissions applicatives. Un refus est appliqué par défaut à toute permission non listée (HAB-01).
     */
    'permissions' => [
        'dashboard.view' => ['Consulter le tableau de bord', 'Général'],
        'contents.view' => ['Consulter les contenus', 'Publication'],
        'contents.create' => ['Créer et modifier ses contenus', 'Publication'],
        'contents.validate' => ['Valider les versions linguistiques', 'Publication'],
        'contents.archive' => ['Archiver les contenus', 'Publication'],
        'media.view' => ['Consulter la médiathèque', 'Publication'],
        'media.upload' => ['Déposer des médias', 'Publication'],
        'documents.view' => ['Consulter les documents autorisés', 'Documents'],
        'documents.manage' => ['Déposer et publier des documents', 'Documents'],
        'templates.view' => ['Consulter les modèles', 'Publication'],
        'templates.manage' => ['Créer et archiver des modèles (pouvoir « modèles »)', 'Publication'],
        'campaigns.view' => ['Consulter la programmation', 'Diffusion'],
        'campaigns.manage' => ['Programmer et suspendre des campagnes', 'Diffusion'],
        'fallback.manage' => ['Gérer les contenus de repli', 'Diffusion'],
        'alerts.view' => ['Consulter les alertes', 'Diffusion'],
        'alerts.manage' => ['Publier, prolonger, révoquer des alertes (pouvoir « urgence »)', 'Diffusion'],
        'screens.view' => ['Consulter le parc', 'Parc'],
        'screens.manage' => ['Administrer écrans et plages', 'Parc'],
        'groups.manage' => ['Gérer les groupes de diffusion', 'Parc'],
        'devices.pair' => ['Appairer un lecteur', 'Parc'],
        'devices.revoke' => ['Révoquer un lecteur', 'Parc'],
        'rooms.view' => ['Consulter salles et disponibilités', 'Salles'],
        'rooms.manage' => ['Administrer les salles affectées', 'Salles'],
        'reservations.create' => ['Demander une réservation', 'Salles'],
        'reservations.approve' => ['Valider, refuser, déplacer, déclarer une non-présentation', 'Salles'],
        'services.process' => ['Traiter les prestations (pouvoir « prestations »)', 'Salles'],
        'visits.manage' => ['Préparer les visites et messages de bienvenue', 'Accueil'],
        'reports.view' => ['Consulter les rapports', 'Pilotage'],
        'exports.create' => ['Générer des exports CSV/PDF', 'Pilotage'],
        'users.manage' => ['Gérer les comptes', 'Administration'],
        'roles.manage' => ['Gérer rôles, délégations et pouvoirs', 'Administration'],
        'referentials.manage' => ['Gérer les référentiels', 'Administration'],
        'imports.run' => ['Importer des référentiels CSV', 'Administration'],
        'settings.manage' => ['Modifier les paramètres fonctionnels', 'Administration'],
        'settings.technical' => ['Modifier les paramètres techniques', 'Administration'],
        'audit.view' => ['Consulter le journal', 'Administration'],
        'supervision.view' => ['Consulter la supervision', 'Exploitation'],
        'incidents.manage' => ['Traiter les incidents', 'Exploitation'],
    ],

    /*
     * Pouvoirs étendus nominatifs (HAB-03) : la permission n'est effective qu'avec le pouvoir actif.
     */
    'powers' => [
        'urgence' => ['role' => 'diffuseur', 'permissions' => ['alerts.manage'], 'label' => 'Urgence'],
        'modeles' => ['role' => 'admin_fonc', 'permissions' => ['templates.manage'], 'label' => 'Modèles'],
        'prestations' => ['role' => 'gestionnaire_salle', 'permissions' => ['services.process'], 'label' => 'Prestations'],
    ],

    'roles' => [
        'admin_tech' => ['Administrateur technique DSI', [
            'dashboard.view', 'users.manage', 'settings.technical', 'settings.manage', 'screens.view', 'screens.manage', 'groups.manage', 'devices.pair', 'devices.revoke',
            'supervision.view', 'incidents.manage', 'reports.view', 'exports.create', 'campaigns.view', 'alerts.view', 'contents.view', 'media.view', 'rooms.view', 'documents.view',
        ]],
        'admin_fonc' => ['Administrateur fonctionnel', [
            'dashboard.view', 'users.manage', 'roles.manage', 'referentials.manage', 'imports.run', 'settings.manage', 'templates.view', 'templates.manage', 'rooms.view', 'rooms.manage',
            'reports.view', 'exports.create', 'contents.view', 'media.view', 'documents.view', 'campaigns.view', 'screens.view',
        ]],
        'contributeur' => ['Contributeur', [
            'dashboard.view', 'contents.view', 'contents.create', 'media.view', 'media.upload', 'documents.view', 'documents.manage', 'templates.view', 'visits.manage', 'rooms.view', 'reservations.create',
        ]],
        'validateur' => ['Validateur éditorial', [
            'dashboard.view', 'contents.view', 'contents.validate', 'contents.archive', 'media.view', 'documents.view', 'templates.view', 'rooms.view', 'reservations.create',
        ]],
        'diffuseur' => ['Diffuseur', [
            'dashboard.view', 'campaigns.view', 'campaigns.manage', 'fallback.manage', 'alerts.view', 'alerts.manage', 'contents.view', 'media.view', 'templates.view', 'screens.view', 'groups.manage',
            'reports.view', 'exports.create', 'rooms.view', 'reservations.create', 'documents.view',
        ]],
        'gestionnaire_salle' => ['Gestionnaire de salle', [
            'dashboard.view', 'rooms.view', 'rooms.manage', 'reservations.create', 'reservations.approve', 'services.process', 'documents.view', 'reports.view', 'exports.create',
        ]],
        'agent' => ['Agent', ['dashboard.view', 'documents.view', 'rooms.view', 'reservations.create']],
        'auditeur' => ['Auditeur', ['dashboard.view', 'audit.view', 'reports.view', 'exports.create']],
    ],
];
