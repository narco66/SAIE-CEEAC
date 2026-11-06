# Consignes communes aux auditeurs — audit SAIE-CEEAC du 2026-11-06

## Base auditée
- Dépôt : `C:\laragon\www\SAIE-CEEAC`, branche `mise-en-conformite`, commit `98041685dd2505f2a9a55b7a28b7217838066893`, plus 13 modifications non commitées (lot 3 en cours, liste dans `travail/etat-depot.txt`). Ne les modifie pas, ne les annule pas.
- Applications : `backend/` (API Laravel 13, PHP 8.4, PostgreSQL 18), `frontend/` (React + TypeScript + Vite), `player/` (lecteur kiosque et panneau de salle), `infra/` (Docker, Nginx), `.github/workflows/ci.yml`, `docs/` (documentation, manuels, tests).
- Référence fonctionnelle : `docs/SAIE-CEEAC_Cahier_des_charges_v3.0.md` (conversion du .docx, original absent du poste). Décisions Q1–Q5 et harmonisations H1–H14 : annexe D (lignes ~984-1027). Niveaux de preuve : annexe C (~972-983). Manifeste : annexe A (~933-967). Recette R01–R27 : §21 (~606-643).
- Référence visuelle : `docs/Maquette FIGMA SAIE-CEEAC/src/App.tsx` et `src/index.css` (extraction de l'archive « valid », archive elle-même absente). Ce code n'est PAS l'application.
- Liste des 106 exigences avec priorité et ligne : `docs/audit/2026-11-06/travail/exigences.tsv`.

## Règles absolues
1. LECTURE SEULE du code : ne modifie aucun fichier hors de `docs/audit/2026-11-06/travail/`. Aucun commit, aucun `migrate`, aucun `migrate:fresh`, aucun envoi réel, aucun déploiement.
2. Ne te fie pas aux affirmations de `docs/conformite.md`, `docs/limites.md`, `docs/tests/*` ni aux commentaires du code : ce sont des « annoncé ». Vérifie dans le code.
3. Chaque conclusion cite une preuve : `chemin:ligne` (relatif à la racine du dépôt), migration, route, test. Pas de preuve → « Non vérifiable » avec la cause.
4. Distingue : annoncé / présent dans le code / testé (un test existant qui couvre le comportement : cite `tests/...::nom_du_test`) / vérifié sur matériel (toujours « non » sauf preuve).
5. Statuts autorisés : Conforme ; Partiellement conforme ; Non conforme ; Non implémenté ; Non vérifiable ; Non applicable (avec justification).
6. Priorité contractuelle (P0/P1/P2) ≠ gravité du défaut (Bloquant / Majeur / Mineur). Une P1 non réalisée = reste à réaliser, pas un défaut bloquant. Exception DATA-03 : les entités prescrites doivent exister dès le Socle.
7. Ne fabrique rien. Ne recopie pas les conclusions d'un rapport antérieur sans les revérifier.

## Méthode pour éviter les blocages
- Lis les fichiers par extraits (300 lignes maximum par lecture), utilise grep pour localiser.
- N'exécute aucune commande longue (pas de build, pas de suite de tests complète, pas de docker) : l'exécution des tests est faite par le coordinateur.
- ÉCRIS TES RÉSULTATS AU FIL DE L'EAU dans ton fichier de sortie (ajoute les lignes au fur et à mesure, toutes les 5 exigences environ), afin que rien ne soit perdu si ta session s'interrompt.

## Format de la matrice (CSV, séparateur `;`, guillemets doubles si le champ contient `;`)
`ID;Priorité;Exigence et sous-critère;Référence source;Implémentation;Preuve;Statut;Écart;Correction;Test de recette`
- Une ligne par sous-critère vérifiable si l'exigence en comporte plusieurs (ID suffixé : `SAL-05.a`, `SAL-05.b`…), sinon une ligne.
- « Référence source » : `CDC l.NNN` (+ annexe ou vue si pertinent).
- « Implémentation » : fichiers principaux.
- « Preuve » : `chemin:ligne` et/ou `tests/...::test_xxx (présent, non exécuté par toi)`.
- « Test de recette » : identifiant R01–R27 s'il existe, sinon le test à écrire en une phrase.

## Format des anomalies (Markdown)
`| ID | Gravité | Exigence | Localisation | Preuve | Impact | Reproduction | Correction | Critère de clôture |`
Préfixe d'ID imposé par ton lot (voir ta mission). Gravité : Bloquant / Majeur / Mineur.
