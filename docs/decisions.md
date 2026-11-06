# Registre des décisions et hypothèses de réalisation

Ce registre complète l'annexe D du cahier des charges. Chaque entrée indique la question, la décision, sa justification et son impact. Les valeurs chiffrées restent des propositions à valider par la maîtrise d'ouvrage.

## Contradictions entre sources

| N° | Écart | Décision |
| --- | --- | --- |
| C1 | La maquette nomme le produit « Système d'information » ; le cahier « Système d'affichage institutionnel électronique ». | Libellé de la maquette conservé dans la barre latérale (fidélité visuelle), libellé du cahier dans les écrans de connexion, PDF et lecteur. Nom définitif à confirmer (§25.2). |
| C2 | La maquette regroupe programmation et listes de lecture ; le cahier parle de campagnes. | Une campagne porte une liste de lecture ordonnée (`campaign_items`) : les deux notions coïncident. |
| C3 | La maquette affiche des chiffres figés (12/14 écrans, 7 tâches…). | Tous les chiffres sont calculés par l'API (UX-03). La note « Données fictives » n'apparaît qu'en mode démonstration (`SAIE_DEMO_MODE`). |
| C4 | Annexe A : la signature est un champ du manifeste. | Enveloppe `{payload, signature}` : la signature Ed25519 porte sur la chaîne JSON exacte, ce qui évite toute ambiguïté de sérialisation entre PHP et JavaScript. Le contenu du manifeste reprend tous les champs de l'annexe A. |
| C5 | La maquette propose « Préparer une visite » sans réservation obligatoire ; ACC-02 exige une réservation confirmée non confidentielle. | Une visite peut être enregistrée sans réservation, mais le message de bienvenue n'est créé que depuis une réservation confirmée non confidentielle (règle du cahier). |

## Décisions techniques

| N° | Sujet | Décision et justification |
| --- | --- | --- |
| T1 | Organisation du dépôt | Monodépôt à dossiers frères : `backend/` (Laravel 13, déplacé depuis la racine le 4 novembre 2026 à la demande du maître d'ouvrage), `frontend/`, `player/`, `docs/`, `infra/`. `/room-panel` n'est pas un projet séparé : le panneau de salle (T03) et l'orientation (T05) sont des modes du même lecteur (ACC-01 l'exige pour T05). Les fichiers communs (`README.md`, `CLAUDE.md`, `.editorconfig`, `.gitattributes`) restent à la racine. |
| T2 | Versions | Laravel 13.39, PHP 8.4.7, PostgreSQL 18.6, React 19.3, Vite 8.3, TanStack Query 5, React Router **7.9** et TypeScript **5.9** (les majeures 8 et 7 récemment publiées n'ont pas été retenues faute de recul ; à réévaluer selon EXP-05). |
| T3 | Signature des manifestes | Ed25519 via l'extension OpenSSL de PHP 8.4 (sodium n'est pas chargé) ; vérification côté lecteur par `tweetnacl`. Clé générée automatiquement hors production dans `backend/storage/app/private/keys`, obligatoire et fournie en production. |
| T4 | Authentification | Sanctum en mode SPA (cookies de session HttpOnly, CSRF) pour l'administration ; jetons Sanctum individuels de portée `device` pour les lecteurs. Aucun jeton utilisateur dans le stockage du navigateur (API-05). |
| T5 | MFA | TOTP RFC 6238 implémenté sans dépendance (`App\Support\Totp`), avec codes de secours. Obligatoire pour les rôles `admin_tech`, `admin_fonc`, `validateur` quand `SAIE_MFA_ENFORCED=true` (défaut de `.env.example`). Désactivé dans le `.env` local de démonstration. |
| T6 | Antivirus | Pilote `clamd` (INSTREAM) pour la production ; pilote `signature` en développement, qui ne détecte que le fichier de test EICAR et l'indique dans le détail d'analyse. Aucun fichier n'est publiable avant l'état « prêt ». |
| T7 | Conversion documentaire | Pas de conversion bureautique côté serveur (LibreOffice absent) : les fichiers DOCX/PPTX/XLSX sont contrôlés (archive, exécutables, ratio de compression) et proposés au téléchargement, jamais diffusés. Les PDF sont rendus sur le lecteur par `pdf.js` à partir du fichier vérifié ; le texte est extrait côté serveur (`smalot/pdfparser`) pour la recherche. Miniatures d'images par GD. Les vidéos ne sont pas transcodées (H.264/AAC exigé au dépôt). |
| T8 | Files et cache | `database` pour les files et le cache en développement ; Redis recommandé en production (configuration fournie). Redis n'est jamais une source de vérité. |
| T9 | Statut réseau des lecteurs | Calculé à la lecture à partir de `last_seen_at` (90 s suspect, 5 min indisponible), et matérialisé par la tâche `saie:tick` qui ouvre/résout les incidents automatiques. |
| T10 | Agenda des écrans (PUB-09) | Le manifeste est recompilé à chaque interrogation (ETag = empreinte du contenu) : toute création, modification ou annulation de réservation est visible au cycle suivant (≤ 30 s), sans file dédiée. |
| T11 | Délai de blocage d'une demande de salle | HAB-04 (escalade à 24 h) et SAL-03 (blocage proposé 24 h) se recouvrent. Décision : échéance de validation 24 h avec escalade au suppléant ; blocage du créneau paramétrable par salle, 48 h par défaut ; à l'échéance du blocage, la demande est refusée automatiquement avec motif, jamais approuvée. |
| T12 | Validateur assigné | À la soumission, le premier validateur actif habilité pour la langue et la structure (hors auteur) est assigné ; réaffectation possible avec trace. |
| T13 | Durée d'autorisation hors ligne | 24 h pour le public et 1 h pour l'interne (PLY-05), portées par le manifeste (`authorization`) ; le manifeste est régénéré à mi-vie. Paramètres `SAIE_OFFLINE_PUBLIC_H` / `SAIE_OFFLINE_INTERNAL_H`. |
| T14 | Fuseau | Instants stockés en `timestamptz` UTC ; Africa/Libreville (UTC+1, sans heure d'été) pour l'affichage, les plages de fonctionnement, les jours ouvrés et les créneaux. |
| T15 | Polices | DM Sans et Manrope (polices de la maquette) auto-hébergées via `@fontsource` au lieu de Google Fonts : l'hébergement est local et les écrans doivent fonctionner sans Internet. |
| T16 | Capture de rendu (PLY-11, P1) | L'API et l'interface de demande existent ; le lecteur web ne sait pas capturer son propre rendu sans agent natif. Fonction signalée comme non opérationnelle (voir limites). |
| T17 | Comptes de démonstration | Mot de passe aléatoire généré au moment du seed et écrit dans `backend/storage/app/private/demo-accounts.txt` (non versionné). Aucun mot de passe dans le code. |
| T18 | Audit en ajout seul | Déclencheur PostgreSQL `audit_logs_append_only` qui refuse UPDATE/DELETE ; idem pour `content_versions` (versions approuvées immuables). Le journal n'est pas qualifié d'« inviolable » (SEC-04). |
| T19 | Présence en salle | SAL-10 (P1) n'est pas activé : « Présence non mesurée » partout, aucun taux de présence calculé (SAL-12). |
| T21 | Annuaire dans les référentiels (audit A-06) | `GET /referentials` renvoie à tout agent connecté la liste des agents actifs, limitée à l'identifiant, au nom et à la structure : tout agent peut réserver une salle et doit pouvoir inviter des collègues (SAL-02). Aucune autre donnée personnelle n'y figure (GOV-02). À réexaminer si l'annuaire institutionnel est raccordé. |
| T22 | Étendue des pouvoirs étendus (audit A-05) | « Urgence » et « prestations » exigent un périmètre (zones ou types). L'étendue totale reste possible mais seulement par un choix explicite (« Tout le parc », « Tous les types »), tracé avec le motif ; une omission est refusée. |
| T23 | Rédacteurs exclus de la validation (audit A-03) | Ne peuvent valider un contenu ni être désignés pour le faire : son auteur, toute personne qui l'a modifié ou dupliqué (journal) et tout soumissionnaire d'une de ses versions. |
| T20 | Simulateur de parc de démonstration | Les lecteurs de démonstration sont des enregistrements marqués `is_demo` ; leur historique de télémétrie (14 jours) est généré par le seeder et étiqueté « simulé ». Le fonctionnement réel est démontré avec le lecteur `/player` appairé par code. |

## Hypothèses (lacunes mineures)

- H-a : quatre zones de diffusion de démonstration (accueil public avec audio, paliers internes, entrées de salles publiques) ; la classification par zone réelle reste à fixer (§25.2).
- H-b : les drapeaux des États membres sont représentés par leur code ISO à deux lettres en attendant la bibliothèque officielle validée par le Protocole (ACC-02).
- H-c : sources dynamiques (météo, actualités, PUB-10) et intégrations (SSO, calendrier externe, API-07) : tables, paramètres et interface présents, désactivés par défaut (P1, accès non disponibles).
