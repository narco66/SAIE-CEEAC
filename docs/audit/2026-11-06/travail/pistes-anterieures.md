# Pistes issues d'audits partiels antérieurs (non revérifiées — à confirmer ou infirmer, jamais à recopier)

## Sécurité / habilitations (backend)
- S1 SEC-01 : MFA et changement de mot de passe obligatoires non imposés côté serveur (AuthController::completeLogin ~l.117-126 ; aucun middleware).
- S2 HAB-02 : admin_tech (users.manage) peut changer l'e-mail d'un autre admin + resetMfa → prise de contrôle (UserController ~l.59-72, 119-129).
- S3 HAB-01 : site_id / zone_id des affectations enregistrés mais jamais appliqués (RoleController ~232,242 ; Access.php ~118-135).
- S4 SEC-05 : middleware Idempotent stocke le corps des réponses (secret TOTP, codes de secours, jetons de comptes de service) en clair (Idempotent.php ~282).
- S5 SEC-01 : pas de verrouillage sur échecs du 2e facteur ; pas d'anti-rejeu TOTP (AuthController ~51-72, Totp::verify).
- S6 SEC-01 : MFA non exigé des titulaires de pouvoirs étendus (config/saie.php mfa_roles).
- S7 : reset/changement de mot de passe ne coupent pas toutes les sessions ni remember_token.
- S8 HAB-01 : admin_fonc exempté de preventEscalation (RoleController ~217, 327).
- S9 HAB-03 : pouvoir étendu attribuable sans périmètre = tout (RoleController ~271, Access ~169).
- S10 : PDF03/PDF04 sans contrôle d'objet (ExportService::assertAllowed ~58-62).
- S11 : visits.manage modifie toute visite (VisitController ~44-50).
- S12 GOV-02 : durées de conservation non appliquées (retention.* inutilisés, purge absente).
- S13 : fichiers d'exports expirés jamais supprimés (ExportController ~147, SaieTick ~109).
- S14 SEC-02 : aucune Policy Laravel (app/Policies absent).
- S15 : throttle `api` défini mais non appliqué ; jetons Sanctum sans expiration ; portée Idempotent tronquée à 60 caractères.

## Lecteur / infrastructure
- L1 PERF : médias servis par PHP-FPM (response()->file), pas de X-Accel-Redirect ; pm.max_children 16.
- L2 PLY-03 : alertes bloquées derrière le téléchargement de tous les médias (player/src/engine.ts ~216-262) ; 403 si recompilation pendant téléchargement.
- L3 : aucun timeout sur les fetch du lecteur.
- L4 PLY-08 : ignore_holidays non transmis au manifeste (ManifestBuilder ~134-137).
- L5 PLY-08 : pas de plage par groupe.
- L6 PLY-12 : retour arrière cassé si seule version du canal retirée.
- L7 : seul index.html vérifié (pas de SRI/signature du build).
- L8 : jeton lecteur sans expiration ; rotation de clé de signature non gérée ; verify() ne contrôle ni valid_until ni anti-retour.
- L9 PLY-05 : agenda/panneau/bienvenue affichés hors ligne sans limite (schedule.ts ~190).
- L10 ACC-02/T05 : bienvenues non filtrées par cible/date (ManifestBuilder ~376-381).
- L11 CONT-01 : pas de sauvegarde ni WAL dans la pile Docker ; procédure orientée Windows.
- L12 EXP-01 : supervision des files lit la table jobs alors que la prod est Redis.
- L13 : images Docker non figées ; saie:tick monolithique ; manifests jamais purgés ; compilation à chaque requête (N+1).
- L14 : agent local de dalle CORS `*` par défaut ; bouton « nouvel appairage » public sur écran révoqué ; pas d'ErrorBoundary.
