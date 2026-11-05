# Limites restantes

État au 5 novembre 2026.

## Poste de développement

- **PHP par défaut de Laragon en 8.3.** Le projet exige PHP 8.4 (`composer.json`) : sélectionner `php-8.4.7` dans Laragon, sinon `artisan` s’arrête au contrôle de plateforme de Composer.

## Fonctions dépendantes du matériel ou d’accès externes

- Pilotage physique des dalles (PLY-08, PLY-09) : l’agent local `player/agent/panel-agent.mjs` est fourni (HDMI-CEC, commandes RS-232 ou API constructeur) mais n’a été essayé qu’en pilote `simulate` ; à valider sur le matériel retenu au pilote.
- Conversion bureautique et transcodage vidéo : implémentés, actifs seulement si LibreOffice et ffmpeg sont installés sur le serveur (`SAIE_SOFFICE_PATH`, `SAIE_FFMPEG_PATH`) ; sinon les bureautiques restent téléchargeables et les vidéos doivent être déposées en H.264/AAC.
- Antivirus : le pilote `clamd` est éprouvé contre un démon de test (`ClamdScannerTest`), pas encore contre un ClamAV réel.
- SSO OIDC/LDAP et connecteurs d’agenda Exchange/Google : non fournis (paramètres présents et désactivés). L’export iCalendar couvre l’usage de base de SAL-11.
- Notifications push : exigent des clés VAPID propres à l’environnement (`php artisan saie:vapid`), l’activation du canal en V39 et une page servie en HTTPS.
- Mode événement (PUB-12) : il remplace le programme ordinaire mais ne modifie pas la plage de fonctionnement des écrans ; une session tardive exige d’élargir la plage des écrans concernés (V08).

## Vérifications restant à exécuter

- Premier passage de la chaîne CI : le dépôt git ne contient pas encore le code (un seul fichier versionné).
- Charge (R15) et restauration (R14) mesurées sur ce poste (voir `docs/tests/rapport.md`) : à refaire sur l’environnement cible avec les volumes réels, l’archivage WAL et 10 lecteurs physiques.
- Mesures non réalisables sur ce poste : arrêt du SMTP institutionnel, audit d’accessibilité avec lecteur d’écran, ASVS niveau 2 et test d’intrusion, coupure électrique réelle.

## Points d’exploitation

- Les comptes de démonstration partagent un mot de passe généré ; il ne doit jamais servir hors d’un poste de développement.
- Le simulateur `saie:demo-fleet` maintient artificiellement le contact des lecteurs fictifs pour les démonstrations ; il est refusé en production.
