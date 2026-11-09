# 11 — Pré-audit OWASP ASVS 5.0 niveau 2 (8 novembre 2026)

Revue interne réalisée avant le test d'intrusion indépendant (SEC-07), pour que le prestataire trouve le moins possible. Elle **ne remplace pas** ce test.

## Méthode

Quatre revues du code et de la configuration, chapitre par chapitre :

- authentification, sessions et jetons (V6, V7, V9) ;
- autorisations, API et logique métier (V8, V4, V2) ;
- fichiers, encodage et sécurité côté navigateur (V5, V1, V3) ;
- cryptographie, communications, configuration, protection des données et journalisation (V11 à V16).

Chaque constat a été vérifié dans le code avant d'être corrigé. Chaque correction applicative est couverte par un test : `AsvsAuthenticationTest` (6 tests), `AsvsAuthorizationTest` (5 tests) et des contrôles de pré-production ajoutés à `PreflightTest`.

Bilan :

- **aucun constat de gravité élevée** ;
- 15 constats de gravité moyenne et une vingtaine de gravité faible ;
- après correction, la suite complète passe : **244 / 244**.

## Points déjà conformes (extrait)

- **Connexion** : message identique et temps de réponse égalisé, que le compte existe ou non.
- **Limitation des tentatives** : par compte, par adresse IP et sur l'étape du second facteur.
- **Second facteur (TOTP)** : code non rejouable ; secret chiffré.
- **Sessions** : régénérées à la connexion, fermées au changement de mot de passe, à la désactivation du compte et au retrait de droits.
- **Jetons** (lecteurs, comptes de service, flux iCalendar) : conservés sous forme d'empreinte SHA-256, révocables, avec une date d'expiration.
- **Permissions** : contrôlées sur chaque route ; les objets sont vérifiés à l'unité (exports, documents, réservations confidentielles, téléchargements des lecteurs).
- **Accès aux données** : requêtes SQL toujours paramétrées ; tri limité à une liste blanche.
- **Exports** : PDF générés sans accès réseau ni PHP ; neutralisation des formules dans les CSV et XLSX.
- **Sources dynamiques** : protégées contre la falsification de requêtes côté serveur (SSRF) : HTTPS obligatoire, adresses privées refusées, résolution DNS figée, pas de redirection.
- **Fichiers déposés** : type réel vérifié, antivirus qui bloque en cas de panne (échec sûr), clés de stockage générées par le serveur.
- **Frontend et lecteur** : aucune insertion de HTML brut ; pdf.js à jour, sans `eval` ; versions du lecteur vérifiées par empreinte.
- **Journal d'audit** : en ajout seul, secrets masqués, identifiant de corrélation.

## Constats corrigés

| Chapitre | Constat | Correction |
|---|---|---|
| V6.2 | Règles de composition imposées (majuscules, chiffres) ; aucune longueur maximale (bcrypt tronque au-delà de 72 octets) | De 12 à 64 caractères, phrases de passe acceptées, au moins 6 caractères différents, identifiant du compte refusé dans le mot de passe ; mêmes règles affichées dans l'interface |
| V7.3 | « Se souvenir de moi » accepté par le serveur (cookie d'environ 400 jours, sans second facteur) ; aucune durée maximale de session | Option supprimée ; durée maximale de 12 h, même pour une session active (`SAIE_SESSION_ABSOLUTE_HOURS`) |
| V6.3 | Existence d'un compte décelable au temps de réponse de « mot de passe oublié » | Courriel envoyé en file d'attente |
| V6.3 | Vérification du mot de passe actuel sans limite (recherche possible depuis une session volée) | 5 essais par minute ; chaque échec est journalisé |
| V6.3 | Après plusieurs échecs, le second facteur se débloquait toujours seul | Après 20 échecs consécutifs, seul un administrateur peut débloquer le compte |
| V6.5 | Codes de secours chiffrés de façon réversible et comparés en temps variable | Seules leurs empreintes sont conservées, avec migration des codes existants ; leur usage est journalisé ; codes plus longs |
| V7.5 | Second facteur liable au compte sans redemander le mot de passe | Mot de passe exigé à l'activation |
| V14.2 | Jeton de réinitialisation dans l'adresse ; jeton iCalendar écrit dans les journaux d'accès | Jeton placé après `#`, jamais envoyé au serveur ; jeton masqué dans les journaux Nginx |
| V9 | Jeton d'appairage non retiré conservé ; demandes de réinitialisation jamais purgées | Effacés automatiquement |
| V8.2 | Document ou contenu transférable vers une structure hors du périmètre de gestion | Transfert refusé |
| V2.3 / PUB-06 | Document publié reclassé, réouvert à une autre audience ou doté d'une nouvelle version sans seconde relecture | Sous double validation, retour en brouillon et nouvelle publication par un autre gestionnaire |
| V2.4 | Rapports et exports sans limite de période, paramètres non validés, exports en nombre illimité | Paramètres typés ; période d'un an au plus ; 10 exports par minute et 5 en attente au plus par personne |
| V8.2 | Rapport de diffusion couvrant tout le parc, quel que soit le périmètre | Limité aux écrans du périmètre ; campagne vérifiée |
| V8.2 | Canal de version d'un lecteur et emplacement d'un nouvel écran modifiables hors périmètre | Refusés hors périmètre |
| V8.2 | Contenu ou réunion pouvant référencer un document ou un média invisible pour son auteur | Refusé |
| V8.3 | Auteur conservant l'édition de ses contenus après le retrait de sa permission | Permission revérifiée |
| V8 | Déblocage de compte et retrait de pouvoir sans les garde-fous des actions voisines | Mêmes garde-fous, dont l'interdiction d'agir sur soi-même |
| V3.4 | CSP sans `object-src`, `base-uri` ni `form-action` | Ajoutées sur l'application, le lecteur et les versions du lecteur |
| V3.2 | Médias servis par Nginx (X-Accel-Redirect) sans en-têtes de sécurité | En-têtes et CSP `default-src 'none'` redéclarés |
| V3.7 | Notification push pouvant ouvrir un site externe (`//…`) | Liens internes uniquement |
| V3.3 | Cookie de session sans préfixe `__Host-` | `SESSION_COOKIE=__Host-saie_session` en production, avec un contrôle de pré-production |
| V12.1 | Suites TLS par défaut (CBC, SHA-1) | Suites « intermediate » de Mozilla |
| V12.3 | STARTTLS facultatif vers le serveur de messagerie | `MAIL_REQUIRE_TLS=true` en production, avec un contrôle de pré-production |
| V13 | Mots de passe de la base montés dans les conteneurs applicatifs ; Redis sans mot de passe et joignable hors du réseau interne | Seule la clé de signature est montée ; Redis protégé par mot de passe ; Redis et PostgreSQL limités au réseau interne ; `no-new-privileges` ; contrôles de pré-production |
| V16 | Journal applicatif falsifiable par saut de ligne (fausses entrées dans la vue des erreurs) | Une entrée par ligne |
| V1.2 / V5 | Nom de fichier `.ics` construit à partir d'un code libre ; ffmpeg sans restriction de protocole | En-tête encodé ; ffmpeg limité aux fichiers locaux |

## Restant à décider (maîtrise d'ouvrage ou DSI)

| Point | Enjeu | Proposition |
|---|---|---|
| Mots de passe divulgués (V6.2) | Le contrôle « Have I Been Pwned » exige un accès Internet du serveur (seul un préfixe d'empreinte est transmis) | L'activer si la DSI autorise ce flux sortant, sinon fournir une liste locale des mots de passe courants |
| Administration limitée à une structure | Un administrateur des rôles limité à une structure peut attribuer un rôle ou un pouvoir sans limite de périmètre | Confirmer que seuls des administrateurs à périmètre global existent ; sinon, borner leurs attributions à leur périmètre |
| Gestionnaire de salle approuvant sa propre réservation | Pas de seconde personne | Accepter ou interdire |
| Désactivation de la double validation des documents | Un seul administrateur peut la désactiver | Accepter (l'action est journalisée) ou exiger deux personnes |
| Préfixe du cookie anti-CSRF (`XSRF-TOKEN`) | Nom imposé par Sanctum et lu par le frontend | Accepter le risque : le cookie de session, lui, est préfixé |

## Restant à faire sur le serveur (DSI)

- **Limites de taille de ClamAV.** La limite par défaut de `StreamMaxLength` (de l'ordre de 25 Mo, à confirmer sur l'image utilisée) est inférieure à la limite de 500 Mo des vidéos. Les grandes vidéos seraient donc **refusées**, jamais acceptées sans analyse, puisque l'antivirus bloque en cas d'échec. Régler `StreamMaxLength`, `MaxFileSize` et `MaxScanSize` à 520M, puis vérifier avec une vidéo de plus de 25 Mo ([fiche d'intervention](../exploitation/fiche-intervention-recette.md) § 3.3).
- **Worker des conversions.** Il traite des fichiers non fiables mais reçoit toute la configuration et le compte applicatif. Lui retirer les secrets inutiles (clé d'application, messagerie) et lui donner un compte PostgreSQL limité aux tables des médias et des tâches.
- **Journaux.** Les envoyer vers le système de journalisation central de la DSI, hors du serveur.
- **Sauvegardes.** Chiffrer la copie hors site avec l'outil de la DSI.
- **Rotation de `APP_KEY`.** Écrire la procédure : `APP_PREVIOUS_KEYS`, puis rechiffrement des secrets du second facteur.
- **Durcissement des conteneurs.** Envisager des systèmes de fichiers en lecture seule et des capacités réduites.
- **Liaison avec PostgreSQL.** Elle n'est pas chiffrée, ce qui est acceptable sur un hôte unique. Consigner ce choix.

## Écarts de faible gravité non traités

- schéma détaillé des champs JSON libres des contenus (`delegation`, `proposed_targets`) ;
- courriel d'avertissement à l'activation du second facteur (l'activation est journalisée) ;
- résolution d'un QR non public par un compte qui doit encore changer son mot de passe ;
- agrafage OCSP (exigence de niveau 3).

## Conséquences pour l'exploitation

- **Nouvelles variables obligatoires dans `infra/.env`** : `REDIS_PASSWORD`, `MAIL_REQUIRE_TLS=true` et `SESSION_COOKIE=__Host-saie_session`. Le contrôle de pré-production bloque tant qu'elles manquent.
- **Mots de passe** : les utilisateurs peuvent choisir une phrase de passe. Les mots de passe déjà en place restent valables.
- **Sessions** : une reconnexion, donc le second facteur, est demandée au plus toutes les 12 heures.
- **Second facteur** : l'activation demande le mot de passe actuel. Les codes de secours déjà remis restent valables.
