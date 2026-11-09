# 03 — Matrice de conformité aux standards

**Avertissement.** Cette matrice évalue l'alignement du logiciel sur des exigences tirées de référentiels reconnus. Elle **ne vaut ni certification ni déclaration de conformité**. Les normes de management (ISO/IEC 27001, ISO 22301, ISO/IEC 20000-1) portent sur l'organisation qui exploite le système, pas sur le logiciel seul : seul un organisme accrédité peut les certifier, après audit de l'organisation.

Le tableau ci-dessous distingue la nature de chaque référentiel.

| Référentiel | Nature | Usage dans cet audit |
|---|---|---|
| ITU-T H.785.1 et série H.780–H.789 | Recommandations techniques (UIT-T) | Modèle de référence d'un service d'affichage dynamique : séparation des fonctions, distribution, journaux, gestion des terminaux |
| ANSI/AVIXA D401.01:2023 | Norme de documentation des systèmes audiovisuels | Exigences documentaires du dossier technique |
| ISO/IEC 27001:2022 (annexe A) | Norme de management certifiable | Mesures de sécurité portées par le logiciel |
| OWASP ASVS 5.0 | Référentiel de vérification | Niveau 1 évalué par revue et tests ; **niveau 2 non vérifié** (test d'intrusion requis) |
| OWASP Top 10 (2021) | Liste de sensibilisation | Contrôle des risques majeurs |
| ISO/IEC 25010:2023 | Modèle de qualité | Caractéristiques de qualité du produit |
| ISO 22301:2019 | Norme de management certifiable | Dispositions logicielles de continuité |
| ISO/IEC 20000-1:2018 | Norme de management certifiable | Pertinente pour l'exploitation par la DSI ; hors portée du logiciel |
| WCAG 2.2 | Recommandation du W3C | Accessibilité de l'administration (niveau AA visé) |

Niveaux : **Aligné** (preuve exécutée) · **Partiel** · **Non aligné** · **Non vérifié** (preuve impossible sur ce poste).

## 1. Affichage dynamique : ITU-T H.785.1 et bonnes pratiques

| Exigence | Preuve constatée | Écart | Niveau |
|---|---|---|---|
| Séparation entre gestion des contenus, programmation, distribution et terminal | `ContentWorkflow`, `CampaignService`, `ManifestBuilder`/`ManifestSigner`, distribution des médias par Nginx, lecteur `player/` | — | Aligné |
| Identification et authentification individuelles des terminaux | Code d’appairage de 10 min, jeton Sanctum `device` de 90 jours avec rotation, jeton chiffré jusqu’à son retrait, révocation | — | Aligné |
| Intégrité des contenus distribués | Signature Ed25519 du manifeste, SHA-256 par média, SRI de la version du lecteur | — | Aligné |
| Programmation : dates, créneaux, jours, priorités | `ScheduleResolver` et `schedule.ts` identiques, testés | Priorité stricte non paramétrable | Aligné |
| Fonctionnement hors ligne | Cache vérifié, autorisation hors ligne, service worker, refus des manifestes expirés ou antérieurs, téléchargements reprenables par blocs | Non éprouvé sur terminal | Aligné (logiciel) |
| Gestion et supervision des terminaux | Heartbeat, télémétrie, versions, capture de rendu, incidents automatiques (indisponibilité, stockage, horloge, synchronisation), relance à distance, détection des terminaux non autorisés | Redémarrage du système du terminal : agent local et matériel | Aligné (logiciel) |
| Journaux de diffusion (preuve de lecture) | `device_receipts` distinguant reçu, chargé, activé et lecture déclarée ; `campaign_transmissions` durable ; PDF05 | État physique de la dalle non mesuré sans matériel pilotable | Partiel → Aligné pour la partie logicielle (DIF-01 à DIF-05) |
| Diffusion prioritaire d'urgence | Alertes plein écran, accusés, expiration, retour au programme, alerte critique hors plage | Validation selon la criticité (PUB-05) | Aligné |
| Synchronisation horaire | Écart d'horloge mesuré à chaque heartbeat | Compensation du temps d'aller-retour absente | Partiel |

## 2. Documentation audiovisuelle : ANSI/AVIXA D401.01:2023

| Exigence | Preuve | Écart | Niveau |
|---|---|---|---|
| Description du système et de ses composants | `docs/architecture.md`, livrables 05 et 09 | — | Aligné |
| Inventaire des équipements et de leur emplacement | Référentiels, fiche matérielle des écrans (PAR-01), PDF06 | Inventaire du parc physique réel à saisir | Partiel |
| Schémas de câblage et de signal, plans d'implantation | Absents | Dépendent de l'installation réelle | Non aligné (externe) |
| Procédures d'exploitation et de maintenance | `docs/exploitation/`, livrables 08 et 09 | — | Aligné |

## 3. Sécurité : ISO/IEC 27001:2022, mesures de l'annexe A portées par le logiciel

| Mesure | Preuve | Écart | Niveau |
|---|---|---|---|
| A.5.15, A.5.18, A.8.2 : contrôle d'accès, droits d'accès, droits privilégiés | RBAC daté et à périmètre, pouvoirs étendus nominatifs, refus par défaut, contrôle hiérarchique (SEC-A1 à SEC-A3), politiques Laravel | Rôles du prompt incomplets (ROL-01) | Aligné |
| A.5.3 : séparation des tâches | Auteur et validateur distincts, auto-attribution refusée, administrateurs mutuellement protégés | — | Aligné |
| A.8.5 : authentification sécurisée | MFA TOTP imposée, anti-rejeu, verrouillage, réponses uniformes (SEC-A4), plafond par IP (SEC-A5) | Déni de service par verrouillage (SEC-A6) | Aligné |
| A.8.7 : protection contre les logiciels malveillants | Quarantaine, signature MIME, antivirus en échec sûr (SEC-AV1) | ClamAV réel non éprouvé (SEC-AV2) | Partiel |
| A.8.15 : journalisation | Journal en ajout seul (déclencheur), refus journalisés, secrets filtrés | Rétention non appliquée (ADM-01) | Partiel |
| A.8.13 : sauvegarde | `infra/backup` (base, WAL, stockage, empreintes), état affiché dans la supervision | Exercice de restauration sur la pile Docker à faire | Partiel |
| A.8.24 : cryptographie | TLS (Nginx), Ed25519, hachage bcrypt | — | Aligné |
| A.8.9 : gestion de la configuration | `saie:preflight` bloque les réglages de démonstration en production | Images Docker à figer par empreinte | Partiel |

## 4. Applications web : OWASP ASVS 5.0 (niveau 1 évalué) et Top 10

| Domaine | Preuve | Écart | Niveau |
|---|---|---|---|
| Authentification (V6) | Tests `AuthHardeningTest`, `SecurityAndAccessTest`, `AdministrativeHierarchyTest` | — | Aligné (N1) |
| Sessions (V7) | Cookies HttpOnly, régénération, fermeture au changement de mot de passe | — | Aligné (N1) |
| Contrôle d'accès (V8), A01 | `PolicyCoverageTest`, `ObjectAccessTest`, `ZoneScopeTest`, SEC-A7 | — | Aligné (N1) |
| Validation et injections (V1, V2), A03 | Validation par requête, SQL paramétré, CSV neutralisé ; dates invalides → 422 (API-08) | — | Aligné (N1) |
| Fichiers (V5) | Liste blanche, signature MIME, archives contrôlées, quarantaine | — | Aligné (N1) |
| Configuration (V13), A05 | En-têtes, CORS en liste blanche, préflight | — | Aligné (N1) |
| Journalisation (V16), A09 | Journal d'audit et refus | — | Aligné (N1) |
| Niveau 2 complet et test d'intrusion | Non réalisés | SEC-07 | **Non vérifié** |

## 5. Qualité logicielle : ISO/IEC 25010:2023

| Caractéristique | Preuve | Niveau |
|---|---|---|
| Adéquation fonctionnelle | 22 modules : 14 opérationnels, 8 partiels (livrable 01 § 4) | Partiel |
| Fiabilité et tolérance aux pannes | Hors ligne, bascule atomique, délais réseau, chien de garde, file sérialisée (DIF-06) | Aligné (non éprouvé sur matériel) |
| Sécurité | § 3 et § 4 | Partiel (SEC-07) |
| Maintenabilité | Couches séparées, Pint, typage strict, 205 tests | Aligné |
| Testabilité | Tests de contrat OpenAPI, base réelle, résolveurs identiques testés des deux côtés | Aligné |
| Interopérabilité | API REST versionnée, OpenAPI 3.1, iCalendar, API d'intégration | Partiel (pas de synchronisation entrante) |
| Performance | Pagination, Nginx pour les médias, mesures sur le poste | Non vérifié sur la cible |

## 6. Continuité : ISO 22301:2019, dispositions logicielles

| Exigence | Preuve | Écart | Niveau |
|---|---|---|---|
| Diffusion pendant une coupure Internet ou du serveur central | Cache local, autorisation hors ligne de 24 h (public) et 1 h (interne), service worker, tests du lecteur | Non éprouvé sur terminal | Partiel |
| Resynchronisation au rétablissement | File d'événements différés, ETag, reconnexion | — | Aligné |
| Sauvegarde et restauration | Scripts, WAL (RPO ≤ 1 h visé), exercice sur le poste (`docs/exploitation/sauvegarde-restauration.md`) | Exercice sur la pile Docker cible | Partiel |
| Plan de continuité de l'organisation | Hors logiciel | À établir par la Commission | Non vérifié |

## 7. Accessibilité : WCAG 2.2 AA (interface d'administration)

| Critère | Preuve | Écart | Niveau |
|---|---|---|---|
| 1.4.3 et 1.4.11 : contrastes | Contrôle axe sur 17 pages (lot R6) | — | Aligné (17 pages) |
| 2.1.1 et 2.4.7 : clavier, focus visible | Focus contrasté, modales piégées | — | Aligné |
| 2.4.2 : titre de page | Titre propre à chaque page (`PageHeading`) | — | Aligné (non vérifié par outil) |
| 2.4.3 : ordre du focus au changement de page | Focus déplacé sur le contenu principal après navigation | — | Aligné (non vérifié par outil) |
| 3.3.1 : identification des erreurs | Messages `role="alert"`, `aria-invalid` et `aria-describedby` posés par `Field` | — | Aligné (non vérifié par outil) |
| Lecteur d'écran | Non essayé | — | Non vérifié |
| Écrans publics : lisibilité à distance | Taille de base min(l, h)/45 (24 px en 1080p) ; plus petits textes vers 20 px (QR, agenda) | Petits textes limites | Partiel |

## 8. Synthèse

| Référentiel | Lecture |
|---|---|
| ITU-T H.785.1 | Architecture alignée ; preuve de lecture désormais fiable côté logiciel ; état physique des dalles à mesurer au pilote |
| AVIXA D401.01 | Documentation logicielle alignée ; documentation d'installation à produire avec l'intégrateur |
| ISO/IEC 27001 (mesures) | Mesures logicielles en place ; rétention, ClamAV et sauvegarde à éprouver |
| OWASP ASVS 5.0 | Niveau 1 aligné par revue et tests ; **niveau 2 non vérifié** |
| ISO/IEC 25010 | Bon niveau de maintenabilité et de testabilité ; adéquation fonctionnelle partielle |
| ISO 22301 | Dispositions logicielles présentes ; éprouvées sur poste, pas sur matériel |
| WCAG 2.2 | Largement aligné ; titres, focus et erreurs corrigés au second passage, à confirmer par un essai au lecteur d’écran |
