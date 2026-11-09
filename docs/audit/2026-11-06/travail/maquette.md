# Fidélité à la maquette et au cahier — lot D (interface, accessibilité)

Audit du 2026-11-06, branche `mise-en-conformite`, commit `9804168` + modifications non commitées. Lecture seule.

**Méthode : analyse statique uniquement.** Aucune comparaison de rendu au navigateur, aucun build, aucun test exécuté. Les rapports de contraste sont calculés à partir des valeurs hexadécimales des feuilles CSS (formule WCAG 2.x de luminance relative). Les constats sur le rendu (débordements, superpositions) ne sont donc pas vérifiables ici et sont signalés « Non vérifiable (rendu) » quand ils en dépendent.

Références : cahier `docs/SAIE-CEEAC_Cahier_des_charges_v3.0.md` (CDC) §13 l.389-409, §26 l.762-785, §27 l.786-834, §28 l.835-858, §29 l.859-877, §30 l.878-895. Maquette : `docs/Maquette FIGMA SAIE-CEEAC/src/App.tsx` (abrégé `App.tsx`), `src/index.css` (abrégé `index.css`).

## 0. Nature de la maquette

- La maquette est une application monopage sans routeur : 15 « pages » (`App.tsx:3` type `Page`), sélectionnées par état (`App.tsx:4195`, `App.tsx:4365-4379`) ; aucune URL, aucune route du §27.
- Les parcours sont des assistants modaux simulés (`flowConfig` `App.tsx:2999-3040`, `FlowModal` `App.tsx:3042-3108`) : 8 flux `content`, `validation`, `pdf`, `device`, `room`, `visit`, `alert`, `report`.
- Toutes les données sont codées en dur (« Données fictives de démonstration », `App.tsx:336-343`).
- La page « Cadrage & couverture » (`App.tsx:2697-2963`) affirme couvrir V01–V42, T01–T05, PDF01–PDF10 (`App.tsx:2761-2783`) : **annoncé**, non réalisé visuellement pour la majorité des vues (voir tableaux). Aucun cadre ne porte d'identifiant de vue, de rôle, de format ou d'état, contrairement au §26.1 (CDC l.768).
- Conséquence : la maquette n'est pas « exhaustive » au sens du §26 ; pour de nombreuses vues, il n'existe pas de référence visuelle et la fidélité n'est évaluable qu'au cahier.

## 1. Fondations visuelles

### 1.1 Logo
| Point | Maquette | Application | Constat |
| --- | --- | --- | --- |
| Fichier | `public/ceeac-logo.jpg` | `frontend/public/ceeac-logo.jpg`, `player/public/ceeac-logo.jpg` | Fichiers identiques octet par octet (`cmp`) côté frontend. Fidèle. |
| En-tête admin | `App.tsx:4211` `alt="Logo officiel de la CEEAC"` | `frontend/src/components/Layout.tsx:113` même alt | Fidèle. |
| Connexion | absent (pas de V01) | `frontend/src/pages/account/Login.tsx:55` | Application seule. |
| Lecteur / panneau | `App.tsx:2659`, `App.tsx:2672` `<img>` **sans alt** | voir §T01–T05 | — |
| Validation du logo | — | — | CDC l.776 et l.861 : « logo fourni par la Commission » et « validé ». Aucune preuve de validation dans le dépôt : Non vérifiable. |

### 1.2 Palette (jetons `:root`)
L'application reprend `index.css` dans `frontend/src/maquette.css` en retirant les deux `@import` (Google Fonts et Tailwind, `index.css:1-2`) et en **assombrissant 25 couleurs** pour atteindre 4,5:1. Écarts relevés par `diff` :

| Jeton / sélecteur | Maquette (`index.css`) | Application (`maquette.css`) | Commentaire |
| --- | --- | --- | --- |
| `--navy` | #0b1f3a (l.5) | #0b1f3a (l.3) | Identique |
| `--blue` | #0969a8 | #0969a8 | Identique |
| `--green` | #168a65 (l.9) | **#158461** (l.7) | Assombri : texte blanc 4,32→4,66:1 |
| `--muted` | #667386 (l.17) | **#637083** (l.15) | Assombri : 4,45→4,64:1 sur fond #f4f6f9 |
| `--amber` / `--red` / `--violet` / `--ink` / `--line` / `--bg` | #c67a10 / #c83d48 / #7858a6 / #1b2738 / #dfe5ec / #f4f6f9 | identiques | — |
| `.nav-group > small` | #6f839b | #7689a0 (l.38) | 4,24→4,61:1 |
| `th` | #717e8d | #677380 | 3,92→4,58:1 |
| `.flow-progress > div` | #9aa4af | #6c737a | 2,53→4,80:1 |
| `.status.warning` | #a4660d | #9f630d | 4,40→4,62:1 |
| 17 autres gris (breadcrumb, kbd, pagination, td small, chips…) | — | assombris | Écart volontaire d'accessibilité, non documenté comme décision de charte |

**Conformité cahier** : le §26.3 (CDC l.782) propose fond #F6F8FA, texte #182230, principale #165A48 (vert), accent #B18A35. Ni la maquette ni l'application n'utilisent cette palette (couleur principale bleue #0969a8, fond #f4f6f9, encre #1b2738). Le cahier qualifie ces valeurs de « proposées à valider » (CDC l.764) ; aucune trace de validation d'une charte alternative (UX-01 exige « une charte validée », CDC l.404) : Non vérifiable / écart à arbitrer (D-01).

### 1.3 Polices
| Point | Maquette | Application | Constat |
| --- | --- | --- | --- |
| Familles | DM Sans (corps) et Manrope (titres), `index.css:1`, `index.css:24` | Identiques, `maquette.css:23`, `maquette.css:32` | Fidèle à la maquette ; **écart au cahier** qui propose Inter + repli système (CDC l.780). |
| Chargement | `@import url('https://fonts.googleapis.com/…')` (`index.css:1`) : dépend d'Internet | Paquets auto-hébergés `@fontsource/dm-sans` et `@fontsource/manrope` ~5.2.0 (`frontend/package.json` dépendances), importés `frontend/src/main.tsx:5-11` (DM Sans 400/500/600/700, Manrope 600/700/800) ; fichiers woff2 empaquetés par Vite dans le build | **Disponible hors ligne / sans Internet en production** (aucun appel à un CDN, aucune `<link>` dans `frontend/index.html`). Conforme à l'exigence d'autonomie réseau. |
| Lecteur | — | `player/src/main.tsx:2-5` : DM Sans 400/700, Manrope 700/800 ; cache par service worker réseau-d'abord `player/public/sw.js:16-29` | Disponible hors ligne après le premier chargement. **Graisse 500 utilisée** (`player/src/player.css`, 2 occurrences, ex. l.20) **mais non importée** : rendu synthétique 400 (D-14, mineur). |
| Repli | `sans-serif` | `sans-serif` | Pas de pile système explicite (CDC l.780 demande une « solution de repli système ») : mineur. |
| Tailles | 7,5 à 11 px très fréquents (`.chips span` 7,5 px, `.cal-head` 8 px, `th` 8,5 px, `.status` 9,5 px, `.link` 10,5 px) | **Identiques** (`maquette.css` non modifié sur les tailles) ; `app.css` ajoute 9–12 px (`.field-error` 10,5 px l.30, `.lang-pill span` 9,5 px l.59, `.cal-month .slot` 9 px l.86) ; corps de formulaire 12 px (`app.css:33`) | **Écart au cahier** : « corps 14 à 16 px, libellés au moins 12 px » (CDC l.780). La majorité des libellés, en-têtes de tableau, badges d'état, aides et messages d'erreur sont sous 12 px (D-02). |

### 1.4 Gabarits
| Point | CDC §26.3 | Maquette | Application | Constat |
| --- | --- | --- | --- | --- |
| Menu | 248 px | 254 px (`index.css` `.sidebar`) | 254 px (`maquette.css` `.sidebar`, repris) | Écart mineur indicatif |
| En-tête | 64 px | 66 px (`.topbar`) | 66 px | Écart mineur indicatif |
| Points de rupture | bureau 1440, tablette 1024, mobile 390 | 1100 / 900 / 640 px | identiques `maquette.css:604`, `:613`, `:633` + `app.css:77` (900 px) | Fidèle à la maquette ; menu en tiroir < 900 px (`maquette.css:614-618`). Rendu tablette/mobile Non vérifiable (rendu). |
| Grille 12 colonnes / base 8 px | prescrit | non (grilles ad hoc) | non | Indicatif (CDC l.780) : mineur |

### 1.5 Navigation globale (CDC §26.2 l.774-776)
| Point | Maquette | Application | Constat |
| --- | --- | --- | --- |
| Groupes | Vue générale / Publication / Espaces / Pilotage (`App.tsx:209-248`) | Mêmes groupes + Administration, `Layout.tsx:10-48` | Fidèle à la maquette, **écart à l'arborescence du cahier** (« Affichage » avec Écrans, Groupes, Modèles, Contenus, Campagnes, Alertes et Diffusion ; « Documents » ; « Salles » avec Calendrier, Réservations, Salles, Ressources, Prestations). « Appairage » (V09) et « Supervision des diffusions » (V24) n'ont pas d'entrée de menu ; « Ressources » absent (P1). Mineur (D-03). |
| Filtrage par droits | non (menu statique) | `Layout.tsx:122-123` masque les entrées sans permission ; `Guard` affiche un état 403 (`frontend/src/lib/auth.tsx:91-95`) | Conforme à « ne pas afficher un menu non habilité » (CDC l.774). |
| Section active | `className active` `App.tsx:4226` | `NavLink` + `active` `Layout.tsx:128` ; pas d'`aria-current` explicite (NavLink le pose par défaut en React Router 7 : présent dans le code de la bibliothèque, non vérifié ici) | Fidèle |
| Fil d'Ariane | 2 niveaux statiques `App.tsx:4252-4256` | 2 niveaux `Layout.tsx:150-154`, libellé déduit du menu (`findNavLabel` l.50-54) ; pas de `<nav aria-label>` ; les pages filles (ex. `/affichage/ecrans/:id`) affichent le libellé du parent | Partiel : pas de hiérarchie réelle (CDC l.776 « fil d'Ariane, titre, description courte et action principale ») (D-03). |
| Recherche | bouton factice `App.tsx:4257-4261` | Recherche réelle `/search` `Layout.tsx:71`, `Layout.tsx:175-191`, raccourci Ctrl K `Layout.tsx:77-87` | Réel |
| Notifications | 3 notifications factices `App.tsx:4281-4328` | API `/notifications` `Layout.tsx:69`, ouverture via `/notifications/{id}/open` l.94-103 | Réel |
| Menu de compte | statique `App.tsx:4330-4361` | réel, déconnexion `Layout.tsx:89-93` | Réel |
| Réduction du menu | non | non (seulement tiroir mobile) | CDC l.776 « peut être réduit » : Absent (mineur) |
| Lien direct après reconnexion | — | `auth.tsx:72-74` (`?retour=`), `Login.tsx:22-26` | Conforme |
| Aide | bouton inerte `App.tsx:4243-4246` | `/aide` `Layout.tsx:143` | Réel |

### 1.6 Composants obligatoires (CDC l.784)
| Composant | Application | Constat |
| --- | --- | --- |
| Boutons principal / secondaire / destructif | `components/ui.tsx:82-89`, `app.css:53-56` | Présents ; variantes chargement (`busy`) et désactivé |
| Champ + aide + erreur | `Field` `ui.tsx:295-304` | Présent ; erreur dans le `<label>` (voir §3) |
| Liste déroulante avec recherche, multisélection | `Select` `ui.tsx:128-140` = `<select>` natif sans recherche | Liste « avec recherche » absente au niveau du kit (mineur) |
| Tableau paginé | `Pagination` `ui.tsx:142-159` | Présent, `aria-current` |
| Badge d'état | `Status`/`StatusOf` `ui.tsx:55-80` | Présent |
| Onglets | `Tabs` `ui.tsx:306-317` | Présent (ARIA incomplet, §3) |
| Boîte de confirmation | `ConfirmDialog` `ui.tsx:249-293` | Présente : objet, conséquence, motif, libellé d'action (conforme CDC l.894) |
| Notification éphémère | `ToastProvider` `ui.tsx:332-352` | Présente (6 s, §3) |
| Message d'erreur / états | `Loading`, `EmptyState`, `ErrorState` `ui.tsx:161-212` | Présents ; 403, 404, réseau, erreur + réessai + identifiant de diagnostic |
| Indicateur de progression | `app.css:102-103` `.progress` | Présent |
| Chronologie | `app.css:78-80` `.timeline-list` | Présent |
| Sélecteur de période, de langue, calendrier, dépôt, aperçu fichier, panneau latéral | spécifiques aux pages | Voir vues |

## 2. Vues web V01–V42

Légende des colonnes : « Maquette » = preuve dans `App.tsx` ; « Application » = route (`frontend/src/routes.tsx:ligne`) + composant (`frontend/src/pages/…:ligne`). Les états sont lus dans le code (composants `Loading` / `EmptyState` / `ErrorState` de `frontend/src/components/ui.tsx:161-212`) ; « Accès refusé » = `Guard` (`frontend/src/lib/auth.tsx:91-95`) ou 403 de l'API ; « Déconnexion » = redirection globale sur 401 (`frontend/src/lib/api.ts:116`, `auth.tsx:72-74`), commune à toutes les vues authentifiées, qui **ne conserve pas la saisie en cours** (D-09). Interaction « réelle » = appel à l'API (`frontend/src/lib/api.ts`). Aucune page de l'application ne contient les données codées en dur de la maquette (grep « Nadia », « CEMAC », « Kivu », « 1 284 », « 96,8 » : aucune occurrence dans `frontend/src` ni `player/src`).

| Élément | Dans la maquette | Dans l'application | Correspondance | Conformité cahier | Interaction réelle ou simulée | États chargement/vide/erreur/accès refusé/déconnexion | Défauts |
| --- | --- | --- | --- | --- | --- | --- | --- |
| V01 Connexion | absent (l'application démarre connectée, `App.tsx:4195`) | `/connexion` `routes.tsx:15` → `pages/account/Login.tsx:8-104` | Absent (maquette) | Conforme : afficher/masquer l.83, « Mot de passe oublié » l.96, erreur générique serveur l.74, session expirée l.73, retour au lien direct l.22-26 | Réelle (`/auth/login`, `/auth/mfa` l.35-41) | Chargement (bouton `busy`), erreur l.74, session expirée l.73 | Libellé « Identifiant professionnel » mais `type="email"` l.78 ; mise en page en styles en ligne l.52-67 |
| V02 Réinitialisation et second facteur | absent | `/compte/verification` `routes.tsx:16` → `account/Verification.tsx:29-110` ; MFA à l'étape 2 de `Login.tsx:89-93` | Absent (maquette) | Partielle : réinitialisation et réponse neutre présentes ; « code MFA et code de secours sur étapes séparées » non respecté : un seul champ pour les deux (`Login.tsx:71`, l.90-92) | Réelle | Erreurs par champ l.82-94 | D-10 |
| V03 Mon compte | entrées de menu inertes « Mon profil », « Sécurité & sessions », « Préférences » `App.tsx:4344-4355` (boutons sans `onClick`) | `/mon-compte` `routes.tsx:23` → `account/MyAccount.tsx:46-410` (identité l.82-86, préférences l.152-178, mot de passe l.214-223, MFA l.281-324, sessions l.336-362, push l.369-404) | Partielle (maquette = simple menu) | Conforme : identité annuaire non modifiable l.82, révocation de session confirmée l.359-362, MFA, push | Réelle | `Loading`/`ErrorState` ×2 ; sessions vides l.353 | — |
| V04 Accueil par rôle | `Dashboard` `App.tsx:381-700` (parc l.461, actions l.499, diffusions l.545, réunions l.596, projets l.630, infos pratiques l.670) | `/accueil` `routes.tsx:22` → `account/Dashboard.tsx:35-222` | Partielle : structure et classes reprises (cartes l.74, parc l.86, tâches l.104, diffusions l.122, réunions l.142) ; « Suivi des projets » et « Informations pratiques » non repris ; ajout GOV-01 l.159, incidents l.187 | Partielle : « erreur partielle par widget » absente (une seule requête, la page entière tombe en erreur l.40-41) ; « plage 08:00–18:00 » codée en dur l.122 (UX-03) | Réelle | Chargement l.40 ; erreur globale l.41 | D-11 ; ligne cliquable `div onClick` inaccessible au clavier l.211 (D-06) |
| V05 Mes tâches | `Tasks` `App.tsx:2117-2230` (cartes de filtre, colonnes l.2145-2149) | `/mes-taches` `routes.tsx:24` → `account/Tasks.tsx:22-101` | Fidèle (cartes l.46-56, colonnes l.64) | Partielle : filtres en retard / escaladées / à traiter présents l.11-15 ; colonnes type, langue, étape, rôle attendu absentes | Réelle | Chargement, vide contextualisé, erreur + réessai l.59-60 | D-12 |
| V06 Notifications | menu déroulant factice `App.tsx:4281-4328` ; pas de page | pop-over `components/Layout.tsx:192-207` + `/notifications` `routes.tsx:25` → `account/Notifications.tsx:21-126` | Partielle (page ajoutée) | Conforme : ouverture revérifiée l.61, cible inaccessible l.86 et `Layout.tsx:100-102`, onglet Non lues l.93 | Réelle | États l.95-96 | Pop-over sans Échap ni retour du focus (D-05) |
| V07 Parc des écrans | `Screens` `App.tsx:1383-1506` (synthèse l.1395-1416, synoptique l.1421, lecteurs à surveiller l.1443) | `/affichage/ecrans` `routes.tsx:28` → `display/Screens.tsx:19-261` | Fidèle (synthèse l.65-69, synoptique l.105-127, filtres l.134-139) | Conforme : réseau et dalle distincts (colonnes l.148), export PDF06 l.50-53, création l.179 | Réelle | États ×2 ; synoptique vide l.127 | `<th>` sans `scope` l.148 (mineur) |
| V08 Fiche écran | absent | `/affichage/ecrans/:id` `routes.tsx:29` → `display/ScreenDetail.tsx:26-400`, onglets l.51-53 | Absent (maquette) | Conforme : 5 onglets, capture l.214-230, révocation motivée l.235-241, plage + dérogation motivée l.300-347, canal de pilotage l.320 | Réelle | États ; brouillon protégé l.293 | — |
| V09 Appairage terminal | assistant `device` `App.tsx:3020-3023`, `DeviceStep` `App.tsx:3532-3663` (code l.3536, lecteur détecté l.3559, affectation l.3587, synchro l.3625) — simulé | `/affichage/appairage` `routes.tsx:30` → `display/Pairing.tsx:17-193` | Fidèle (mêmes étapes, l.92-182) | Conforme : codes inconnu/expiré l.12-14, aucun jeton affiché l.100, remplacement révoque l.144 | Réelle | Erreur ; vide sans objet | Pas d'entrée de menu (D-03) |
| V10 Groupes de diffusion | absent (seulement « Écran / groupe » `App.tsx:1346`) | `/affichage/groupes` `routes.tsx:31` → `display/Groups.tsx:10-216` | Absent (maquette) | Conforme : cibles effectives l.53, hors ligne signalés l.188-194 | Réelle | États ×2 | — |
| V11 Médiathèque | `Media` `App.tsx:893-1019` (bascule `view-switch` l.966, `media-grid` l.976, badge l.1005) | `/mediatheque` `routes.tsx:45` → `publication/Media.tsx:38-260` | Fidèle (bascule l.62-65, filtres l.67-70) | Conforme : quarantaine/analyse/prêt/rejeté filtrables l.69 | Réelle | États + vide avec action l.73-74 | — |
| V12 Fiche média et dépôt | dépôt simulé `PdfStep` `App.tsx:3430-3531` (« 50 Mo maximum » l.3436) | `/mediatheque/:id` `routes.tsx:46` → `publication/MediaDetail.tsx:27-240` ; dépôt `Media.tsx:216` | Partielle | **Écart** : progression et reprise de dépôt absentes (envoi par `fetch`, `lib/api.ts:85-105`, sans suivi de progression) ; limites affichées avant dépôt `Media.tsx:16-23` ; sous-titres WebVTT `ContentEditor.tsx:264-276` | Réelle | Chargement/erreur | D-13 |
| V13 Bibliothèque documentaire | `Documents` `App.tsx:1020-1163` (colonnes l.1063-1068) | `/documents` `routes.tsx:47` → `publication/Documents.tsx:20-195` | Fidèle | Conforme (plein texte et compteurs selon droits, l.20 ; filtrage serveur hors lot) | Réelle | États présents | — |
| V14 Fiche document | absent | `/documents/:id` `routes.tsx:48` → `publication/DocumentDetail.tsx:23-321` | Absent (maquette) | Conforme : QR l.78, accusés P1 l.180, dépublier l.83, version retirée l.89, PDF10 | Réelle | États ×2 | — |
| V15 Modèles d'affichage | `Layouts` `App.tsx:1164-1290` (onglets l.1172-1186, « Archivés » inerte l.1184) | `/affichage/modeles` `routes.tsx:32` → `display/Templates.tsx:31-157` | Partielle (liste séparée de l'éditeur) | Conforme : dupliquer l.88, archiver l.89 | Réelle | États présents | — |
| V16 Éditeur de modèle | même écran `Layouts` (blocs l.1189, canevas l.1224, propriétés l.1256) — simulé | `/affichage/modeles/:id/edition` `routes.tsx:33` → `display/TemplateEditor.tsx:23-318` | Fidèle (bibliothèque, canevas, inspecteur) | Partielle : annuler/rétablir l.61-64, l.219 ; aligner l.74, l.223 ; grille et marge sûre l.215 ; **aperçu par langue absent** (seule l'orientation varie l.254) | Réelle ; zones manipulables au clavier l.235-239 | Chargement/erreur ; brouillon protégé l.47 | D-12 |
| V17 Liste des contenus | `Contents` `App.tsx:784-892` (onglets d'état l.798-816, colonnes l.835-839) | `/affichage/contenus` `routes.tsx:37` → `publication/Contents.tsx:18-154` | Fidèle | Conforme : dupliquer l.137, état par langue, valider absent pour l'auteur l.55 | Réelle | États présents | — |
| V18 Composition de contenu | assistant modal `content` `App.tsx:3000-3011`, `ContentStep` `App.tsx:3110-3337` (7 étapes : l.3114, l.3155, l.3181, l.3212, l.3241, l.3275, l.3312) — simulé | `/affichage/contenus/nouveau` et `/:id/edition` `routes.tsx:38-39` → `publication/ContentEditor.tsx:90-729` (étapes l.294) | Partielle : page pleine au lieu d'une modale, 4 étapes (programmation et aperçu renvoyés à V21/V22, conforme au cahier) | Conforme : débordement par langue l.190, sous-titres obligatoires l.191, QR l.340, brouillon l.274, soumission par langue l.520 | Réelle | États ×3 ; brouillon protégé l.167 (fermeture d'onglet seulement) | D-09 |
| V19 Validation éditoriale | assistant `validation` `App.tsx:3012-3015`, `ValidationStep` `App.tsx:3338-3429` (décision l.3378, retour motivé l.3412) — simulé | `/affichage/contenus/:id/validation` `routes.tsx:40` → `publication/ContentValidation.tsx:22-234` | Partielle (page au lieu de modale) | Conforme : approuver l.153, retourner avec motif l.156 | Réelle | Chargement, erreur ×2 | — |
| V20 Campagnes | page `Schedule` `App.tsx:1291-1382` (onglets inertes l.1298-1303, sans `onClick`) | `/affichage/campagnes` `routes.tsx:41` → `publication/Campaigns.tsx:29-211` | Partielle | Conforme : suspendre avec motif l.75, taux de reçus l.105-110 | Réelle | États ×2 | Barre `aria-hidden` l.133 : vérifier l'équivalent textuel (mineur) |
| V21 Assistant campagne | absent en propre (étape « Programmation » `App.tsx:3275`) | `/affichage/campagnes/nouvelle` `routes.tsx:42` → `publication/CampaignWizard.tsx:60-516` (étapes l.234) | Partielle | Conforme : dédoublonnage l.394, conflits l.481 | Réelle | États ×4 ; brouillon protégé l.105 | — |
| V22 Programme et prévisualisation | `Schedule` `App.tsx:1291-1382` (piste horaire, date figée « 13 novembre 2026 » l.1310) | `/affichage/programme` `routes.tsx:34` → `display/Program.tsx:32-274` | Partielle | Conforme : simulation date/heure/écran, fuseau affiché l.100, repli par zone l.19-32 | Réelle | États ×3 | — |
| V23 Centre des alertes | `Alerts` `App.tsx:1994-2116` (l.2053, historique l.2062-2072) + `AlertStep` `App.tsx:3912-4037` | `/affichage/alertes` `routes.tsx:35` → `display/Alerts.tsx:33-422` | Fidèle | Conforme : prolonger l.141, révoquer, hors ligne l.104, hors plage l.368, portée réelle l.261-263, l.362 | Réelle | États ×4 | — |
| V24 Suivi diffusion | onglet inerte « Suivi de diffusion » `App.tsx:1301` ; suivi d'alerte `App.tsx:4002` | `/affichage/diffusions/:id` `routes.tsx:44` → `publication/Delivery.tsx:47-138` | Partielle | Conforme : Reçu / Téléchargé / Activé / Déclarée / Capture / « Non mesuré » l.120-125, export CSV l.76 | Réelle | États présents | Pas d'entrée de menu (D-03) |
| V25 Calendrier des salles | `RoomCalendar` `App.tsx:1625-1719` (semaine l.1633) | `/salles/calendrier` `routes.tsx:51` → `rooms/RoomCalendar.tsx:16-267` | Fidèle et étendue | Conforme : jour/semaine/mois/liste l.13, PDF02 l.89 | Réelle | États présents | — |
| V26 Mes réservations | absent | `/mes-reservations` `routes.tsx:53` → `rooms/MyReservations.tsx:12-79` | Absent (maquette) | Conforme (passées/futures/récurrentes) | Réelle | États présents | — |
| V27 Nouvelle réservation | assistant `room` `App.tsx:3024-3027`, `RoomStep` `App.tsx:3664-3804` (l.3668, l.3701, conflit l.3732, alternative l.3760, confirmation l.3790) — simulé | `/reservations/nouvelle` `routes.tsx:54` → `rooms/NewReservation.tsx:40-588` | Partielle (page au lieu de modale) | Conforme : participants externes l.390, ressources mobiles P1 l.376, capacité l.435 | Réelle | États ; brouillon protégé l.70 | — |
| V28 Détail réservation | étape « Réservation confirmée » `App.tsx:3790-3800` | `/reservations/:id` `routes.tsx:56` → `rooms/ReservationDetail.tsx:17-394` | Partielle | Conforme : PDF01 l.57, non honorée l.131, l.214, déplacement l.261 | Réelle | Chargement/erreur ; pas de `Guard` (API) | — |
| V29 Validation réservation | `RoomApprovals` `App.tsx:1823-1883` (colonnes l.1831-1836) | `/reservations/a-valider` `routes.tsx:55` → `rooms/ReservationApprovals.tsx:14-98` | Fidèle | Conforme : approuver/refuser/déplacer l.65-67 | Réelle | États présents | — |
| V30 Catalogue des salles | onglet « Catalogue » de `Rooms` `App.tsx:1507-1624` (cartes l.1590) | `/salles` `routes.tsx:50` → `rooms/Rooms.tsx:13-279` | Fidèle (cartes + liste l.66) | Conforme : disponibilité datée l.13 | Réelle | États ×3 | — |
| V31 Fiche et édition salle | absent | `/salles/:id` `routes.tsx:52` → `rooms/RoomDetail.tsx:16-402` (onglets l.39-45) | Absent (maquette) | Conforme : 6 onglets + aperçu panneau | Réelle | États ×4 | — |
| V32 Prestations de réunion | absent (« Prestations » colonne de pouvoir `App.tsx:2405`) | `/prestations` `routes.tsx:57` → `rooms/Services.tsx:18-175` | Absent (maquette) | Conforme : accepter / préciser / prêt / impossible l.62-65, PDF03 l.68 | Réelle | États présents | — |
| V33 Rapports | `Reports` `App.tsx:2231-2341` (« Présence non mesurée » l.2277) + `ReportStep` `App.tsx:4038-4136` | `/rapports` `routes.tsx:60` → `admin/Reports.tsx:30-353`, modale l.275 | Fidèle | Conforme : présence non mesurée l.103, l.202 ; PDF/CSV l.310 | Réelle | États ×2 | Modale sans focus initial ni retour du focus (D-05) |
| V34 Utilisateurs et affectations | onglet « Utilisateurs » `App.tsx:2342-2453` (colonnes l.2374-2379) | `/administration/utilisateurs` `routes.tsx:63` → `admin/Users.tsx:44-525` | Fidèle | Conforme : suppléant l.88, l.375 ; révoquer sessions l.223 ; désactiver l.227 | Réelle | États ×3 | — |
| V35 Rôles et délégations | **gabarit vide** « Cette vue applique… » `App.tsx:2455-2477` | `/administration/roles` `routes.tsx:64` → `admin/Roles.tsx:11-209` | Absent (placeholder) | Partielle : matrice et comparaison des droits l.181-198 ; « consulter historique » non trouvé (grep « Historique » / « history » : 0 occurrence) | Réelle | États ×2 | D-12 |
| V36 Référentiels | gabarit vide `App.tsx:2455-2487` | `/administration/referentiels` `routes.tsx:65` → `admin/Referentials.tsx:59-324` | Absent (placeholder) | Conforme (désactivation, l.59) | Réelle | États présents | — |
| V37 Imports | absent | `/administration/imports` `routes.tsx:66` → `admin/Imports.tsx:21-191` | Absent (maquette) | Conforme : modèle / simuler / confirmer l.100, erreurs l.121, historique l.166 | Réelle | États présents | — |
| V38 Journal et traçabilité | onglet « Journal d'audit » = gabarit vide `App.tsx:2356`, l.2455-2487 | `/administration/journaux` `routes.tsx:67` → `admin/Audit.tsx:19-124` | Absent (placeholder) | Conforme : lecture seule, détail l.89, export PDF09/CSV l.43 | Réelle | États présents | — |
| V39 Paramètres | gabarit vide, mots-clés Identité / Diffusion / Fonctionnement / Salles / Conservation / Intégrations `App.tsx:2478-2485` | `/administration/parametres` `routes.tsx:68` → `admin/Settings.tsx:41-152`, `SettingsIntegrations.tsx` | Partielle | **Écart** : onglet « Conservation » absent (`Settings.tsx:15`) ; conservation seulement dans `backend/config/saie.php:98` ; plage par défaut saisie en JSON brut `Settings.tsx:23` | Réelle ; secrets jamais relus l.14 ; test de connexion `SettingsIntegrations.tsx:74` | États présents | D-15 |
| V40 Supervision et incidents | absent | `/supervision` `routes.tsx:62` → `admin/Supervision.tsx:42-316` (+ `PlayerReleases.tsx`, l.10) | Absent (maquette) | Conforme : assigner / acquitter / clore l.28-31, PDF08 l.55 | Réelle | États ×2 | — |
| V41 Exports et traitements | étape « Génération » `App.tsx:4115` | `/traitements` `routes.tsx:61` → `admin/Exports.tsx:12-160`, `admin/common.tsx:156-165` | Partielle | Conforme : relancer / annuler l.87-88, `progressbar` ARIA `common.tsx:165` | Réelle | États présents | Route sans `Guard` alors que le menu exige `dashboard.view` (`Layout.tsx:37`) — mineur |
| V42 Aide et accès indisponible | bouton inerte « Aide & documentation » `App.tsx:4243-4246` | `/aide` et `*` `routes.tsx:26`, l.69 → `account/Help.tsx:75-173` (404 l.110-112 ; 401/403/404/5xx l.68-72 ; diagnostic copiable l.84-99) | Absent (maquette) | Partielle : la page 404 n'existe que dans la zone authentifiée (route `*` enfant du `Layout`) | Réelle | — | Mineur |

**Bilan V01–V42.** Maquette : 20 vues représentées par un écran au moins partiel (V04, V05, V07, V09, V11, V13, V15, V16, V17, V18, V19, V20, V22, V23, V25, V29, V30, V33, V34, plus V06 en pop-over) ; 6 réduites à une étape d'assistant ou à un onglet inerte (V12, V21, V24, V27, V28, V41) ; 4 gabarits vides (V35, V36, V38, V39) ; 12 absentes (V01, V02, V03, V08, V10, V14, V26, V31, V32, V37, V40, V42). Application : **42 routes sur 42**, toutes branchées sur l'API.

## 3. Terminaux T01–T05

Application : `player/src/Player.tsx` (aiguillage des états l.26-97) et `player/src/views.tsx`. Maquette : page « Vues de diffusion » `App.tsx:2493-2564` (sélecteur Paysage / Portrait / Panneau de salle l.2501, états Normal / Alerte / Hors ligne / Sans programme l.2513-2518, formats 1920 × 1080, 1080 × 1920, 1280 × 800 et marge sûre 5 % l.2545-2560). Ces états de la maquette sont simulés par une liste déroulante.

| Élément | Dans la maquette | Dans l'application | Correspondance | Conformité cahier | Interaction réelle ou simulée | États | Défauts |
| --- | --- | --- | --- | --- | --- | --- | --- |
| T01 Lecteur paysage | `InstitutionalDisplay` `App.tsx:2565-2632` (bandeau logo l.2575, ruban hors ligne l.2588-2591, « Réunions du jour » l.2619-2622, météo l.2623, indicateur « FR » l.2627) ; `AlertDisplay` l.2633-2654 ; `FallbackDisplay` l.2655-2665 | `Player.tsx:50-97`, `views.tsx` : `Header` l.27-37, `SlideView` l.135-190 (agenda l.160, QR l.176, indicateur de langue l.187), `AlertView` l.192-204, `NeutralFallback` l.206-215, `OfflineRibbon` l.40-42 | Fidèle (mêmes blocs : bandeau, corps, agenda en pied, langue) | Conforme : diffusion normale, alerte plein écran l.76-77, téléchargement initial l.39-41, contenu expiré → repli l.84-89, panne réseau l.94, hors plage = écran noir l.71, appairage l.34 ; marge sûre `.safe { inset: 5% }` `player/src/player.css:7` ; aucun contrôle d'administration (`cursor: none` l.3) | Réelle (manifeste, cache) | Les 8 états prescrits sont présents dans le code ; « cache vide » = téléchargement initial sans manifeste l.35-45 | Météo de la maquette non reprise (non exigée) ; logo de bandeau `alt="CEEAC"` (sans effet sur kiosque) |
| T02 Lecteur portrait | même composant, classe `portrait` `App.tsx:2573`, scène `portrait-stage` l.2526 | `SlideView` : zones recomposées en portrait si le contenu n'a pas de modèle `views.tsx:136-143` ; modèles portrait dans V15/V16 | Fidèle | Conforme sur le principe (pas d'étirement : zones empilées l.138) ; débordement bloqué à la validation (`ContentEditor.tsx:190`) ; rendu aux résolutions réelles Non vérifiable (rendu, matériel) | Réelle | idem T01 | — |
| T03 Panneau de salle | `RoomDisplay` `App.tsx:2666-2696` (« LIBRE », prochaine réservation l.2684-2689, hors ligne « Statut non actualisé · dernier contact » l.2691-2693) | `RoomPanelView` `views.tsx:240-270` | Fidèle (même structure : en-tête, nom, état, prochaine réservation, pied hors ligne) | Conforme : nom + capacité l.256, heure l.253, état en texte et couleur l.258 (`player.css:84-87`), fin / prochaine disponibilité l.250, prochaines réservations l.263-264, confidentiel = libellé fourni par le serveur (masquage côté serveur, hors lot), hors ligne l.267 ; aucune annulation anonyme | Réelle | Libre / occupée / indisponible / hors ligne ; variante portrait : Non vérifiable (CSS non lu en détail) | Pas d'état « préparation » sur le panneau (tampon) : mineur |
| T04 Appairage et maintenance | `DeviceStep` étape 1 « Code d'appairage temporaire » `App.tsx:3536` (vu côté administration) | `PairingView` `views.tsx:217-227` (code, expiration, renouvellement automatique l.224), `RevokedView` l.229-238 | Partielle (la maquette ne montre pas l'écran du terminal) | Conforme : code temporaire, expiration, aucun secret durable, terminal révoqué avec message neutre ; « diagnostic technique pour opérateur authentifié » : aucun écran de diagnostic dans le lecteur (grep « diagnostic » dans `player/src` : 0), donc pas de raccourci public — conforme par absence | Réelle | Appairage, révoqué, hors ligne (« Demande d'un code… » l.222) | — |
| T05 Orientation (P1) | « Orientation visiteurs » dans `Protocol` `App.tsx:1965` (vue d'administration, pas d'écran de terminal) ; étape « Orientation » `App.tsx:3609`, « Orientation accueil · RDC · Écran portrait » l.3879 | `OrientationView` `views.tsx:272-305` | Partielle | Partielle : directions et salles par étage l.289-300, réunions du jour l.301, FR/EN alternés (`Player.tsx:24`), variante Bienvenue l.275-281 ; **« plan de niveau schématique » absent** (liste textuelle par étage) | Réelle | Normal / sans réunion (`AgendaLine` l.50) / hors ligne (ruban `Player.tsx:94`) / hors plage (l.71) | D-16 (mineur, P1) |

## 4. Documents PDF01–PDF10

Application : `backend/app/Services/ExportService.php` (catalogue l.37-48, modèles l.90-300, rendu Dompdf l.334-353) et gabarit unique `backend/resources/views/pdf/document.blade.php`. Maquette : un seul aperçu PDF, `PdfPreview` `App.tsx:4137-4192` (rapport de diffusion, en-tête logo + « COMMISSION DE LA CEEAC » + classification « INTERNE », pied « Généré le … · Africa/Libreville · Page 1 sur 4 »). Les 9 autres PDF n'ont **aucune maquette** (le cahier l.861 demande pourtant qu'ils soient « maquettés séparément »).

Règles communes vérifiées dans le gabarit : A4 portrait / paysage selon le modèle (`ExportService.php:345`) ; marges 18/15/20/15 mm (`document.blade.php:7`) — conforme (15 à 20 mm) ; police DejaVu Sans incorporée (`document.blade.php:8`, `ExportService.php:341`) — conforme « police incorporable », écart visuel assumé avec DM Sans ; en-tête logo + titre + référence + classification (`document.blade.php:40-52`) ; pied « Généré le … · fuseau · CLASSIFICATION · Page n sur total » (`ExportService.php:349-350`) — conforme ; en-têtes de tableau répétés (`thead { display: table-header-group }` l.28) et lignes non coupées (l.31) — conforme ; titres solidaires (`page-break-after: avoid` l.26) ; état vide par section (l.82-83) ; mention « aucune signature, cachet ou approbation » (l.102) ; ressources distantes désactivées (`isRemoteEnabled=false` l.340). **Écart commun** : corps 9,5 pt (l.9), tableaux 8 pt (l.30), en-têtes 7,5 pt (l.29), notes 7,5 pt (l.34), pied 7,5 pt (`ExportService.php:350`) — sous le minimum de 10 à 11 pt du cahier (CDC l.861) (D-07).

| Élément | Dans la maquette | Dans l'application | Correspondance | Conformité cahier | Interaction réelle ou simulée | États | Défauts |
| --- | --- | --- | --- | --- | --- | --- | --- |
| PDF01 Confirmation de réservation | absent | `ExportService.php:38`, modèle l.~115-137 ; déclenché `ReservationDetail.tsx:57` | Absent (maquette) | Partielle : statut, objet, organisateur, salle/étage, capacité, participants en nombre, confidentialité, fuseau, équipements, occurrences, prestations et ressources, historique, bannière « Demande non confirmée » l.123, mention GOV-02 l.135, QR vers fiche authentifiée l.136 ; **classification toujours « interne »** même pour une réservation confidentielle (`$r->confidential ? 'interne' : 'interne'`, l.122) | Réelle (file asynchrone V41) | Vide par section | D-08 |
| PDF02 Planning des salles | absent | l.39, l.~140-155 | Absent | Conforme : version publique sans titres ni organisateurs l.152, confidentiel « Réservée » l.146, préparation l.146, paysage l.151 | Réelle | Vide « Aucune réservation sur la période » l.153 | — |
| PDF03 Fiche de préparation | absent | l.40, l.~158-174 | Absent | Partielle : lieu, créneau, montage, participants, matériel, ressources mobiles, prestations, liste de contrôle, aucun lien privé l.172 ; **statut de prestation brut** (`$s->status`, l.168 : codes techniques non traduits) | Réelle | Vide par section | D-17 |
| PDF04 Bon de programmation | absent | l.41, l.~176-191 | Absent | Partielle : versions et langues, validateur par langue, date de décision, classification, cibles en annexe, créneaux, fuseau, priorité, ordre de rotation, date de fin, mention « Bon ≠ signature » l.190 ; **auteur absent** (seul « Programmée par » l.185) ; **miniatures par langue absentes** | Réelle | — | D-17 |
| PDF05 Rapport de diffusion | `PdfPreview` `App.tsx:4137-4192` (synthèse 3 chiffres l.4148-4158, tableau Écran / Programmé / Reçu / Activé / Dalle l.4161-4167) | l.42, l.~194-214 | Partielle (même logique : synthèse + tableau par écran ; colonnes enrichies) | Partielle : programmé, reçus, activations, lectures déclarées, dalle ou « Non mesuré » l.202, dernier contact, erreurs, définitions et limites l.212, aucune estimation d'audience ; **« captures disponibles » et « incidents » absents** du tableau | Réelle | — | D-17 |
| PDF06 Inventaire des équipements | absent | l.43, `inventory()` l.216-231 ; déclenché `Screens.tsx:50-53` | Absent | Partielle : colonnes prescrites présentes l.225, salles l.229 ; « séparer fiche technique et inventaire synthétique » : un seul document | Réelle | — | Mineur |
| PDF07 Bilan d'utilisation des salles | absent | l.44, `roomUsage()` l.235-245 | Absent | Partielle : taux réservé et règle l.243, annulations, non-présentations, prestations, présence non mesurée ; **horaires d'ouverture retenus et fermetures exclues non listés** dans l'en-tête (l.241) | Réelle | — | D-17 |
| PDF08 Rapport incidents | absent | l.45, `incidents()` l.247-262 | Absent | Partielle : référence, détection, équipement, impact, responsable, rétablissement, durée mesurée, note sur délai déclaratif l.260 ; **événements, actions et clôture absents** ; gravité et statut bruts (`$i->severity`, `$i->status`, l.255-257) | Réelle | — | D-17 |
| PDF09 Extrait du journal | absent | l.46, `auditExtract()` l.~264-274 | Absent | Conforme : filtres, période, auteur, date serveur l.270, mention « Extrait et non journal intégral » l.272, export journalisé l.266 | Réelle | — | — |
| PDF10 Fiche documentaire | absent | l.47, l.~278-290 | Absent | Partielle : référence, titre, classification, validité, catégorie, accusés l.284, résumé l.286, versions l.287, mention « copie de consultation » l.289 ; « décisions autorisées » non distinguées des versions | Réelle | — | Mineur |

## 5. Parcours P01–P09

La maquette simule huit assistants modaux (`flowConfig` `App.tsx:2999-3040`) qui avancent par « Continuer » sans aucune donnée réelle ; l'application enchaîne des pages réelles. Le cahier (l.880) attend des parcours enchaînant plusieurs vues ; la vérification de bout en bout relève de la recette (non exécutée ici).

| Élément | Dans la maquette | Dans l'application | Correspondance | Conformité cahier | Interaction réelle ou simulée | États | Défauts |
| --- | --- | --- | --- | --- | --- | --- | --- |
| P01 Publier une annonce | flux `content` (7 étapes, `App.tsx:3000-3011`) + `validation` l.3012-3015, même utilisateur (« Nadia K. ») | V04 → V17 → V18 (`ContentEditor.tsx`) → V19 (`ContentValidation.tsx`) → V21 (`CampaignWizard.tsx`) → V22 (`Program.tsx`) → V24 (`Delivery.tsx`) → T01 ; PDF04/PDF05 via `ExportService.php:41-42` | Partielle (découpage en pages) | Toutes les vues existent ; deux comptes distincts imposés côté serveur (hors lot) | Maquette simulée / application réelle | — | — |
| P02 Consulter un document | aucun flux ; liste `Documents` `App.tsx:1020` | V13 → V14 → téléchargement ; QR `/q/:code` `routes.tsx:17` → `account/QrResolve.tsx` → V01 → retour | Partielle | Conforme dans le code (QR → connexion avec `retour`) | Réelle | — | — |
| P03 Réserver une salle | flux `room` (Besoin / Créneau / Conflit / Alternative / Confirmation `App.tsx:3024-3027`) | V25 → V27 → V28 → V29 → V06 → PDF01 | Fidèle dans l'enchaînement | Conforme dans le code ; saisie conservée en conflit (`NewReservation.tsx:40`) | Réelle | — | — |
| P04 Réserver une série | absent (aucune récurrence dans `RoomStep`) | V27 (aperçu des occurrences) → V28 + `rooms/OccurrenceEditor.tsx` (occurrence seule / futures) | Absent (maquette) | Présent dans le code | Réelle | — | — |
| P05 Diffuser une urgence | flux `alert` (Message / Portée / Aperçu / Confirmation / Suivi `App.tsx:3032-3035`) | V23 (`Alerts.tsx`) → T01/T02 (`AlertView`) → suivi → prolongation / révocation | Fidèle | Conforme dans le code | Réelle | — | — |
| P06 Installer un lecteur | flux `device` (Code / Association / Affectation / Synchronisation `App.tsx:3020-3023`) | V07 → V08 → V09 → T04 → T01 → V40 | Fidèle | Conforme dans le code ; variantes matérielles (coupure, extinction) Non vérifiable (matériel) | Réelle | — | — |
| P07 Préparer une réunion | `MeetingAgendas` `App.tsx:1720-1822` (agenda, ordre du jour) ; pas de flux | V28 → V32 (`Services.tsx`) → PDF03 → T03 → agenda T01 | Partielle | Conforme dans le code | Réelle | — | — |
| P08 Administrer et auditer | aucun flux ; onglets `Admin` dont 4 gabarits vides `App.tsx:2455-2487` | V34 → V35 → V38 → PDF09 | Absent (maquette) | Conforme dans le code | Réelle | — | — |
| P09 Accueillir une délégation (P1) | flux `visit` (Délégation / Réservation / Message / Écrans / Validation `App.tsx:3028-3031`, `VisitStep` l.3805-3911) | `/accueil-protocole` `routes.tsx:58` → `rooms/Protocol.tsx` (modale « PARCOURS P09 » l.212) → V19 → T05 Bienvenue | Fidèle | Conforme dans le code | Réelle | — | Modale sans focus initial ni retour du focus (D-05) |

**Règles de formulaire (CDC l.892-895)** : symbole « * » + texte « (obligatoire) » pour lecteur d'écran (`ui.tsx:298`) — conforme ; validation immédiate des formats : partielle (validation essentiellement au retour serveur, erreurs 422 affichées par champ via `error.field()`) ; limites de taille affichées avant dépôt (`Media.tsx:16-23`, `Documents.tsx:155`, `DocumentDetail.tsx:253`) — conforme ; fuseau visible sur la programmation (`Program.tsx:100`, `Dashboard.tsx:122`, `CampaignWizard`) — conforme ; boîtes de confirmation contextualisées (`ConfirmDialog` `ui.tsx:249-293`, bouton final explicite, ex. « Révoquer définitivement » `ScreenDetail.tsx:235`) — conforme ; aucun bouton « Oui » générique trouvé (les seules occurrences de « Oui » sont des valeurs affichées, `admin/common.tsx:250`, `admin/Referentials.tsx:184`, l.213).

## 6. Accessibilité (WCAG 2.2 AA) — analyse statique

### 6.1 Contrastes calculés
Formule WCAG 2.x ; couleurs semi-transparentes composées sur le fond indiqué. Seuils : 4,5:1 texte normal ; 3:1 grand texte et composants / indicateurs non textuels (1.4.11).

| Paire (premier plan / fond) | Source | Rapport | Verdict |
| --- | --- | --- | --- |
| Encre #1b2738 / blanc | `maquette.css:14` | 15,07:1 | AA |
| Encre #1b2738 / fond #f4f6f9 | `maquette.css:14`, `:18` | 13,92:1 | AA |
| `--muted` application #637083 / blanc · / #f4f6f9 | `maquette.css:15` | 5,03:1 · 4,64:1 | AA |
| `--muted` maquette #667386 / #f4f6f9 | `index.css:17` | 4,45:1 | Échec texte (corrigé dans l'application) |
| Blanc / bleu #0969a8 (bouton principal) ; bleu / blanc (lien) | `maquette.css:5` | 5,84:1 | AA |
| Blanc / vert application #158461 | `maquette.css:7` | 4,66:1 | AA |
| Blanc / vert maquette #168a65 | `index.css:9` | 4,32:1 | Échec texte (corrigé) |
| Blanc / rouge #c83d48 (bouton destructif) ; rouge / blanc (`.field-error`) | `app.css:53`, `:30` | 4,98:1 | AA |
| Ambre #c67a10 / blanc | `--amber` | 3,39:1 | Grand texte / non-texte seulement |
| Badge `.status.success` #177758 / #e9f7f1 | `maquette.css:134` | 4,99:1 | AA |
| Badge `.status.warning` #9f630d / #fff7e8 (maquette #a4660d : 4,40:1) | `maquette.css:135` | 4,62:1 | AA |
| Badge `.status.danger` #b52d3b / #fff0f1 | `maquette.css:136` | 5,58:1 | AA |
| Badge `.status.blue` #0b659b / #eaf5fb | `maquette.css:137` | 5,66:1 | AA |
| Badge neutre #636e7f / #f1f3f5 | `maquette.css:132` | 4,64:1 | AA |
| Menu #b6c5d5 / marine #0b1f3a ; actif blanc / #174875 | `app.css:7`, `:12` | 9,39:1 ; 9,46:1 | AA |
| Titre de groupe de menu #7689a0 / marine (maquette #6f839b : 4,24:1) | `maquette.css:38` | 4,61:1 | AA |
| En-tête de tableau #677380 / #f7f9fb (maquette #717e8d : 3,92:1) | `maquette.css` `th` | 4,58:1 | AA |
| Étapes `.flow-progress` #6c737a / blanc (maquette #9aa4af : 2,53:1) | `maquette.css` | 4,80:1 | AA |
| Icône / texte du champ de recherche #82909e / blanc | `maquette.css:186` | 3,26:1 | Non-texte OK ; texte d'aide insuffisant |
| **Bordure de champ #d3dbe3 / blanc** | `app.css:33` | **1,40:1** | **Échec 1.4.11** (limite du champ) |
| **Bordure du champ de recherche #d8dfe6 / blanc** | `maquette.css:186` | **1,34:1** | **Échec 1.4.11** |
| **Indicateur de focus des `button`, `input`, `select`, `textarea`** : rgba(9,105,168,.18) → #d3e4ef / blanc | `maquette.css:26` (spécificité 0-1-1, l'emporte sur `app.css:107`) | **1,30:1** | **Échec** (indicateur quasi invisible) |
| **Focus des liens du menu** : rgba(9,105,168,.35) sur marine → #0a3960 / #0b1f3a | `app.css:107` | **1,39:1** | **Échec** |
| Focus générique (liens sur fond blanc) : #a9cae1 / blanc | `app.css:107` | 1,72:1 | Échec 3:1 |
| Palette du cahier : #182230 / #F6F8FA ; blanc / #165A48 ; accent #B18A35 / blanc | CDC l.782 | 15,05 ; 8,11 ; 3,20:1 | Pour mémoire (accent non utilisable en texte) |
| Lecteur : ruban hors ligne blanc / ambre rgba(198,122,16,.92) | `player/src/player.css:51` | ≈3,4:1 | Grand texte seulement (taille en `em`, lisibilité à distance Non vérifiable) |

### 6.2 Clavier et focus
| Point | Preuve | Constat |
| --- | --- | --- |
| Lien d'évitement | `Layout.tsx:110`, cible `main#contenu tabIndex=-1` l.222 ; style `app.css:3-4` | Conforme |
| Focus visible | `maquette.css:26`, `app.css:107` ; **`.search-field input { outline: 0 }`** `maquette.css:187` sans règle `:focus-within` (grep : 0 occurrence dans les CSS) | **Non conforme 2.4.7** pour la recherche globale (`Layout.tsx:178-180`) et toutes les barres de recherche de liste (`Toolbar`, `ui.tsx:116-119`) ; indicateur des autres contrôles à 1,30:1 (D-04) |
| Modale générique `Modal` | `ui.tsx:214-244` : focus initial sur le premier `input, select, textarea, button` l.221 (c'est le bouton « Fermer » de l'en-tête, premier dans l'ordre du document), Échap l.222, retour du focus l.226, `aria-modal` + `aria-labelledby` l.230 | **Pas de piège à focus** : Tab sort de la boîte (grep `'Tab'` / `inert` : 0 occurrence) (D-05) |
| Assistants `FlowModal` (display) | `pages/display/shared.tsx:150-180` : Échap, retour du focus, focus à chaque étape | Pas de piège à focus |
| Modale « Générer un rapport » | `admin/Reports.tsx:~255-275` : Échap seulement | Ni focus initial, ni retour du focus, ni piège (D-05) |
| Modale « Préparer une visite » | `rooms/Protocol.tsx:212` (grep « focus » : 0) | Ni focus initial, ni retour, ni piège (D-05) |
| Pop-over notifications / compte | `Layout.tsx:192-220` : `role="dialog"` / `role="menu"` sans Échap, sans gestion du focus, sans flèches pour `menuitem` ; fermeture seulement au changement de route l.73-76 | Non conforme au motif ARIA menu (D-05) |
| Éléments cliquables non focalisables | `Dashboard.tsx:211` (`<div onClick>` « Mes prochaines réservations ») | Non accessible au clavier (2.1.1) (D-06) |
| Onglets | `ui.tsx:306-317` : `role="tablist"`/`tab`/`aria-selected`, sans `tabpanel`, sans `aria-controls`, sans flèches | Motif ARIA incomplet (mineur, D-12) |
| Éditeur de modèle | zones `tabIndex=0` + `onKeyDown` `TemplateEditor.tsx:235-239` | Alternative clavier présente |
| Maquette | `FlowModal` `App.tsx:3049` : `role="dialog"` sans `aria-labelledby`, sans Échap, sans focus ; `aria-` : 2 occurrences dans tout `App.tsx` (l.202, l.3049) | La maquette n'est pas une référence d'accessibilité ; l'application l'a nettement améliorée |

### 6.3 Formulaires, libellés, erreurs
| Point | Preuve | Constat |
| --- | --- | --- |
| Libellés | `Field` enveloppe le contrôle dans `<label>` `ui.tsx:297-302` ; `Select` libellé masqué + `aria-label` l.130-132 ; `Toolbar` libellé « Recherche » masqué l.118 | Conforme (libellé générique « Recherche » peu informatif quand plusieurs listes : mineur) |
| Champ obligatoire | `*` `aria-hidden` + « (obligatoire) » en `sr-only` `ui.tsx:298` | Conforme |
| Erreur associée au champ | message `role="alert"` **à l'intérieur du `<label>`** `ui.tsx:301` ; `aria-invalid` posé page par page (ex. `Login.tsx:78`), `aria-describedby` non utilisé par `Field` (3 occurrences seulement dans tout `frontend/src`) | Partiel : le message entre dans le nom accessible du champ (concaténé au libellé) ; l'aide `field-help` aussi l.300 ; `aria-invalid` absent sur plusieurs champs (ex. mot de passe `Login.tsx:82`) (D-12) |
| Mot de passe + bouton dans le même `<label>` | `Login.tsx:80-87` | Le nom accessible du champ peut inclure « Afficher le mot de passe » : mineur |
| Messages éphémères | `ToastProvider` `ui.tsx:332-352` : `aria-live="polite"` même pour les erreurs, disparition après 6 s l.337 | Les erreurs d'action ne restent pas lisibles (2.2.1 si l'information n'existe pas ailleurs) : mineur |
| Brouillon non enregistré | `useUnsavedWarning` `ui.tsx:364-374` = `beforeunload` seulement ; aucun `useBlocker` (grep : 0) | La navigation interne (menu, liens) et la redirection sur 401 perdent la saisie sans avertissement (UX-03, CDC l.408 ; CDC l.788 « conserver les données saisies en cas d'erreur ») (D-09) |

### 6.4 Structure, langue, images, couleur
- `lang="fr"` `frontend/index.html:2`, `player/index.html:2` ; un `h1` par page via `PageHeading` (`ui.tsx:96`).
- Fil d'Ariane non balisé en `nav` (`Layout.tsx:150`).
- Tableaux : 34 fichiers avec `<table>`, tous dans `.table-scroll` (défilement horizontal, `maquette.css:189`) ; `scope="col"` dans 25 occurrences seulement ; aucune `<caption>` (grep : 0) — mineur (titres de panneau en `h2` voisins).
- Information non portée par la seule couleur : badges avec texte (`ui.tsx:55-61`), pastilles de langue avec symbole ✓ / ! / … et texte masqué (`publication/shared.tsx:82-95`), calendrier de salles différencié par texte et motif (`RoomCalendar.tsx:16`, `app.css:94`) — conforme UX-02.
- Logo : `alt` présent dans l'application (`Layout.tsx:113`, `Login.tsx:55`) ; dans la maquette, `<img>` sans `alt` (`App.tsx:2659`, `App.tsx:2672`).
- Mouvement : `prefers-reduced-motion` respecté `app.css:108`.
- Tailles de texte : voir §1.3 (D-02) ; WCAG ne fixe pas de taille minimale, mais 7,5 à 9,5 px combinés au zoom restent un risque fort de lisibilité ; agrandissement à 200 % et redistribution à 320 px (1.4.4, 1.4.10) : Non vérifiable (rendu).

### 6.5 Tests automatisés existants
`frontend/e2e/accessibility.spec.ts:13-31` : axe-core (WCAG 2.2 AA) sur **17 des 42 vues**, échec seulement sur « serious »/« critical » (l.52-53) — présent, non exécuté par moi. axe ne détecte ni l'absence de piège à focus, ni le contraste de l'indicateur de focus, ni le contraste des bordures de champ (1.4.11), ni la perte de saisie : les défauts D-04, D-05, D-09 ne peuvent donc pas être couverts par ce test. Le fichier `frontend/e2e/zz-a11y-probe.spec.ts` est une sonde de diagnostic (pas d'assertion).

### 6.6 Responsive
Points de rupture 1100 / 900 / 640 px repris de la maquette (`maquette.css:604-660`) ; tiroir de menu < 900 px avec bouton libellé (`Layout.tsx:149`) et voile de fermeture (l.146) ; recherche globale masquée < 900 px (`maquette.css:620`) **sans autre accès que Ctrl K** (indisponible sur tablette / mobile) — mineur ; à 640 px les actions secondaires d'en-tête de page sont masquées (`.heading-actions .button.secondary { display: none }`, `maquette.css:641`) : **des actions disparaissent sur mobile** (ex. « Exporter l'inventaire » `Screens.tsx:79`) (D-18). Tailles cibles : boutons 30–36 px de haut (`maquette.css` `.button` 36 px, `app.css:55` 30 px) — conforme 2.5.8 (24 px). Rendu réel Non vérifiable.

## 7. Synthèse

| Ensemble | Maquette : présent / partiel / absent | Application : présent / absent |
| --- | --- | --- |
| Vues V01–V42 | 20 écrans (au moins partiels) · 6 réduits à une étape ou un onglet inerte · 4 gabarits vides · 12 absentes | 42 / 0 (routes `frontend/src/routes.tsx:15-69`) |
| Terminaux T01–T05 | T01, T02, T03 représentés (`App.tsx:2493-2696`) · T04 vu seulement côté administration · T05 seulement comme vue d'administration | 5 / 0 (`player/src/views.tsx`) |
| PDF01–PDF10 | 1 (PDF05, `App.tsx:4137-4192`) · 9 absents | 10 / 0 (`backend/app/Services/ExportService.php:37-48`), gabarit unique |
| Parcours P01–P09 | 6 simulés par assistants modaux (P01, P03, P05, P06, P09, P02 partiel) · P04, P07, P08 sans flux | 9 / 0 enchaînements de pages réelles (bout en bout non exécuté ici) |

Fidélité visuelle : l'application reprend la feuille de style de la maquette presque intégralement (`frontend/src/maquette.css` ≈ `index.css`, 25 couleurs assombries pour le contraste), le logo identique, la structure du menu, de l'en-tête et des principaux écrans. Les vues absentes de la maquette ont été construites avec les mêmes composants. La maquette elle-même n'est pas exhaustive au sens du §26 (D-19).

Écarts les plus graves (détail dans `anomalies-D.md`) : D-04 focus invisible ou quasi invisible ; D-05 modales sans piège à focus et deux modales sans gestion du focus ; D-09 perte silencieuse de saisie lors de la navigation interne et de l'expiration de session ; D-13 dépôt de fichiers lourds sans progression ni reprise ; D-02 textes et libellés de 7,5 à 11 px contre 12 px minimum prescrits ; D-19 maquette non exhaustive alors qu'elle annonce une couverture complète.

Limites : analyse statique ; aucun rendu, zoom, lecteur d'écran ni matériel vérifié ; les contrôles côté serveur (masquage confidentiel, droits) relèvent d'autres lots.
