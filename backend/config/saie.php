<?php

/*
 * Paramètres métier SAIE-CEEAC. Les valeurs chiffrées reprennent les propositions du cahier des charges v3.0
 * et restent configurables (elles ne deviennent contractuelles qu'après validation de la maîtrise d'ouvrage).
 */
return [

    'timezone' => env('SAIE_TIMEZONE', 'Africa/Libreville'),

    // §10.22 : flux temps réel (SSE). Chaque flux occupe un processus PHP pendant `stream_seconds` puis le navigateur
    // se reconnecte ; prévoir des processus PHP-FPM en conséquence (un par poste de supervision ouvert).
    'realtime' => [
        'enabled' => (bool) env('SAIE_REALTIME', true),
        'stream_seconds' => (int) env('SAIE_REALTIME_STREAM_S', 25),
        'poll_ms' => (int) env('SAIE_REALTIME_POLL_MS', 1000),
    ],

    'demo_mode' => (bool) env('SAIE_DEMO_MODE', false),

    'security' => [
        'mfa_enforced' => (bool) env('SAIE_MFA_ENFORCED', true),
        'mfa_roles' => ['admin_tech', 'admin_fonc', 'validateur', 'technicien', 'responsable_parc'],
        'max_failed_logins' => 5,
        'lockout_minutes' => 15,
        // Paliers « nombre d'échecs consécutifs => minutes de blocage » (décision D6 à valider).
        'lockout_steps' => [5 => 1, 10 => 5, 15 => 15],
        // Tentatives de connexion par adresse IP et par minute, tous comptes confondus.
        'login_per_ip_per_minute' => (int) env('SAIE_LOGIN_PER_IP_PER_MINUTE', 30),
        // Durée maximale d'une session, même active (ASVS 5.0 V7.3).
        'session_absolute_hours' => (int) env('SAIE_SESSION_ABSOLUTE_HOURS', 12),
        // Échecs consécutifs du second facteur après lesquels seul un administrateur peut débloquer le compte.
        'mfa_admin_unlock_after' => 20,
    ],

    // Seuils de détection d'anomalie des lecteurs (saie:tick).
    'anomalies' => [
        'storage_ratio' => (float) env('SAIE_ANOMALY_STORAGE_RATIO', 0.9),
        'clock_skew_ms' => (int) env('SAIE_ANOMALY_CLOCK_SKEW_MS', 30000),
        'sync_stalled_minutes' => (int) env('SAIE_ANOMALY_SYNC_STALLED_MINUTES', 60),
    ],

    'device' => [
        // Préfixe d'emplacement interne Nginx (X-Accel-Redirect) ; vide : fichier servi par PHP (poste de développement).
        'accel_redirect' => env('SAIE_ACCEL_REDIRECT'),
        // PLY-02 : durée de vie d'un jeton de lecteur ; le lecteur le renouvelle de lui-même à mi-vie.
        'token_ttl_days' => (int) env('SAIE_DEVICE_TOKEN_TTL_DAYS', 90),
        // §10.1 : délais de supervision paramétrables (secondes). Hors ligne = aucun contact depuis `unavailable_after_s`.
        'heartbeat_s' => (int) env('SAIE_DEVICE_HEARTBEAT_S', 30),
        'suspect_after_s' => (int) env('SAIE_DEVICE_SUSPECT_AFTER_S', 90),
        'unavailable_after_s' => (int) env('SAIE_DEVICE_UNAVAILABLE_AFTER_S', 300),
        // Une erreur signalée par le lecteur maintient l'écran « en anomalie » pendant cette durée (minutes).
        'error_window_min' => (int) env('SAIE_DEVICE_ERROR_WINDOW_MIN', 10),
        'pairing_code_ttl_min' => 10,
        // §7.2-c : serveurs vers lesquels un profil peut rattacher les lecteurs (liste séparée par des virgules ; vide = aucun).
        'allowed_servers' => array_values(array_filter(array_map('trim', explode(',', (string) env('SAIE_DEVICE_SERVERS', ''))))),
        'manifest_validity_public_h' => (int) env('SAIE_OFFLINE_PUBLIC_H', 24),
        'manifest_validity_internal_h' => (int) env('SAIE_OFFLINE_INTERNAL_H', 1),
        'signing_key_path' => env('SAIE_MANIFEST_KEY_PATH', storage_path('app/private/keys/manifest-ed25519.pem')),
        'signing_key_id' => env('SAIE_MANIFEST_KEY_ID', 'mk-local'),
        // Rotation de la clé de signature (PLY-03) : clé publique suivante (32 octets en base64) annoncée dans les manifestes
        // signés par la clé courante, avant la bascule ; les lecteurs n'ont pas à être réappairés.
        'next_signing_key_id' => env('SAIE_MANIFEST_NEXT_KEY_ID'),
        'next_signing_key_public' => env('SAIE_MANIFEST_NEXT_KEY_PUBLIC'),
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

    // Images de la pile Docker figées par version et empreinte (prérequis de production n° 5), lues depuis infra/.env.
    'deployment' => [
        'images' => [
            'SAIE_IMAGE_PHP' => env('SAIE_IMAGE_PHP'), 'SAIE_IMAGE_NODE' => env('SAIE_IMAGE_NODE'), 'SAIE_IMAGE_NGINX' => env('SAIE_IMAGE_NGINX'),
            'SAIE_IMAGE_POSTGRES' => env('SAIE_IMAGE_POSTGRES'), 'SAIE_IMAGE_REDIS' => env('SAIE_IMAGE_REDIS'), 'SAIE_IMAGE_CLAMAV' => env('SAIE_IMAGE_CLAMAV'),
        ],
    ],

    'documents' => [
        // PUB-06, activée le 8 novembre : celui qui dépose un document ne le publie pas lui-même (« Demander la publication »).
        'four_eyes' => (bool) env('SAIE_DOCUMENTS_FOUR_EYES', true),
    ],

    'alerts' => [
        'default_duration_min' => 30,
        'max_duration_min' => 240,
        // Décision D1 validée le 7 novembre : une alerte critique s'affiche même hors plage de fonctionnement.
        'critical_overrides_window' => (bool) env('SAIE_CRITICAL_ALERT_OVERRIDES_WINDOW', true),
        // Double validation (décision D3 validée le 7 novembre) : niveaux soumis à l'accord d'un second titulaire du pouvoir « urgence »
        // lorsqu'ils visent tout le parc. Les alertes critiques et les avertissements restent immédiats.
        'four_eyes_severities' => array_values(array_filter(explode(',', (string) env('SAIE_ALERT_FOUR_EYES_SEVERITIES', 'info')))),
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

    // Durées de conservation (GOV-02). Valeurs proposées (décision D5 à valider) : journal 5 ans, télémétrie 90 jours,
    // notifications lues 180 jours. La purge quotidienne (saie:retention --apply) n'est exécutée que si elle est activée.
    'retention' => [
        'audit_months' => (int) env('SAIE_RETENTION_AUDIT_MONTHS', 60),
        'trash_days' => 30,
        'telemetry_days' => (int) env('SAIE_RETENTION_TELEMETRY_DAYS', 90),
        'notifications_days' => (int) env('SAIE_RETENTION_NOTIFICATIONS_DAYS', 180),
        'export_hours' => 72,
        'auto_purge_enabled' => (bool) env('SAIE_RETENTION_AUTO_PURGE', false),
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
        'devices.command' => ['Envoyer des commandes à distance aux lecteurs', 'Parc'],
        'devices.pair' => ['Appairer un lecteur', 'Parc'],
        'devices.revoke' => ['Révoquer un lecteur', 'Parc'],
        'rooms.view' => ['Consulter salles et disponibilités', 'Salles'],
        'rooms.manage' => ['Administrer les salles affectées', 'Salles'],
        'reservations.create' => ['Demander une réservation', 'Salles'],
        'reservations.approve' => ['Valider, refuser, déplacer, déclarer une non-présentation', 'Salles'],
        'services.process' => ['Traiter les prestations (pouvoir « prestations »)', 'Salles'],
        'visits.manage' => ['Préparer les visites et messages de bienvenue', 'Accueil'],
        'reports.view' => ['Consulter les rapports', 'Pilotage'],
        // §13.3-k : rapports du parc d'écrans, accordables rôle par rôle indépendamment des autres rapports.
        'reports.fleet' => ['Consulter les rapports du parc d’écrans', 'Pilotage'],
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
        'player_releases.manage' => ['Publier et retirer les versions du lecteur', 'Exploitation'],
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
        // §14-1 : profil « Administrateur SAIE » unique (union des deux administrations), à attribuer sur décision de la DSI.
        'admin_saie' => ['Administrateur SAIE', [
            'dashboard.view', 'users.manage', 'roles.manage', 'referentials.manage', 'imports.run', 'settings.technical', 'settings.manage', 'templates.view', 'templates.manage',
            'screens.view', 'screens.manage', 'groups.manage', 'devices.pair', 'devices.revoke', 'devices.command', 'supervision.view', 'incidents.manage', 'player_releases.manage',
            'reports.view', 'reports.fleet', 'exports.create', 'campaigns.view', 'alerts.view', 'contents.view', 'media.view', 'rooms.view', 'rooms.manage', 'documents.view',
        ]],
        'admin_tech' => ['Administrateur technique DSI', [
            'dashboard.view', 'users.manage', 'settings.technical', 'settings.manage', 'screens.view', 'screens.manage', 'groups.manage', 'devices.pair', 'devices.revoke', 'devices.command',
            'supervision.view', 'incidents.manage', 'player_releases.manage', 'reports.view', 'reports.fleet', 'exports.create', 'campaigns.view', 'alerts.view', 'contents.view', 'media.view', 'rooms.view', 'documents.view',
        ]],
        'admin_fonc' => ['Administrateur fonctionnel', [
            'dashboard.view', 'users.manage', 'roles.manage', 'referentials.manage', 'imports.run', 'settings.manage', 'templates.view', 'templates.manage', 'rooms.view', 'rooms.manage',
            'reports.view', 'reports.fleet', 'exports.create', 'contents.view', 'media.view', 'documents.view', 'campaigns.view', 'screens.view',
        ]],
        'contributeur' => ['Contributeur', [
            'dashboard.view', 'contents.view', 'contents.create', 'media.view', 'media.upload', 'documents.view', 'documents.manage', 'templates.view', 'visits.manage', 'rooms.view', 'reservations.create',
        ]],
        'validateur' => ['Validateur éditorial', [
            'dashboard.view', 'contents.view', 'contents.validate', 'contents.archive', 'media.view', 'documents.view', 'templates.view', 'rooms.view', 'reservations.create',
        ]],
        'diffuseur' => ['Diffuseur', [
            'dashboard.view', 'campaigns.view', 'campaigns.manage', 'fallback.manage', 'alerts.view', 'alerts.manage', 'contents.view', 'media.view', 'templates.view', 'screens.view', 'groups.manage',
            'reports.view', 'reports.fleet', 'exports.create', 'rooms.view', 'reservations.create', 'documents.view',
        ]],
        'gestionnaire_salle' => ['Gestionnaire de salle', [
            'dashboard.view', 'rooms.view', 'rooms.manage', 'reservations.create', 'reservations.approve', 'services.process', 'documents.view', 'reports.view', 'reports.fleet', 'exports.create',
        ]],
        'agent' => ['Agent', ['dashboard.view', 'documents.view', 'rooms.view', 'reservations.create']],
        'auditeur' => ['Auditeur', ['dashboard.view', 'audit.view', 'reports.view', 'reports.fleet', 'exports.create']],
        // Rôles ajoutés (décision D4 / ROL-01 à valider) : séparation de la maintenance et de l'administration des comptes,
        // gestion des contenus sans validation ni programmation, consultation sans réservation.
        // §14 : responsable du parc (inventaire, groupes, lecteurs, affectations) et superviseur (consultation des états).
        'responsable_parc' => ['Responsable du parc', [
            'dashboard.view', 'screens.view', 'screens.manage', 'groups.manage', 'devices.pair', 'devices.revoke', 'devices.command',
            'supervision.view', 'incidents.manage', 'reports.view', 'reports.fleet', 'exports.create', 'campaigns.view',
        ]],
        'superviseur' => ['Superviseur du parc', [
            'dashboard.view', 'screens.view', 'supervision.view', 'reports.view', 'reports.fleet', 'exports.create',
        ]],
        'technicien' => ['Technicien de maintenance', [
            'dashboard.view', 'screens.view', 'supervision.view', 'incidents.manage', 'player_releases.manage', 'devices.pair', 'devices.command', 'reports.view', 'reports.fleet', 'exports.create',
        ]],
        'gestionnaire_contenus' => ['Gestionnaire des contenus', [
            'dashboard.view', 'contents.view', 'contents.create', 'contents.archive', 'media.view', 'media.upload', 'templates.view', 'documents.view', 'documents.manage', 'campaigns.view', 'rooms.view',
        ]],
        'consultation' => ['Consultation (lecture seule)', ['dashboard.view', 'documents.view', 'rooms.view', 'contents.view', 'campaigns.view', 'screens.view']],
    ],
];
