# Inventaire des sources consultées

| Fichier | Nature | Statut de consultation | Usage |
| --- | --- | --- | --- |
| `docs/SAIE-CEEAC_Cahier_des_charges_v3.0.md` | Cahier des charges consolidé v3.0 (3 nov. 2026) | Lu intégralement | Référence fonctionnelle et technique (fait autorité) |
| `docs/Maquette FIGMA SAIE-CEEAC/src/App.tsx` | Export Figma Make (React) de la maquette, 4 386 lignes | Lu intégralement | Référence visuelle : 15 pages, 8 parcours modaux, vues lecteur et panneau |
| `docs/Maquette FIGMA SAIE-CEEAC/src/index.css` | Feuille de style de la maquette (701 lignes) | Lue (jetons, composants, règles responsives) | Reprise telle quelle dans `frontend/src/maquette.css` |
| `docs/Maquette FIGMA SAIE-CEEAC/AGENTS.md`, `package.json`, `vite.config.ts`, `.figma/*` | Métadonnées du projet Figma Make | Lus (AGENTS, package) / survolés | Versions React 19, Vite 8, Tailwind 4 de la maquette |
| `docs/Maquette FIGMA SAIE-CEEAC/public/ceeac-logo.jpg`, `favicon.svg` | Logo et favicon | Utilisés | Logo copié dans `frontend/public`, `player/public`, `resources/images` |
| `docs/CEEAC-logo.jpg` | Logo officiel de la CEEAC | Utilisé | En-têtes de l'interface, du lecteur et des PDF |

## Ressources inaccessibles ou absentes

- **Fichier Figma natif** : aucun connecteur Figma n'est disponible dans cet environnement. Seul l'export Figma Make (code React) a été exploité. Les variantes de composants qui n'existent que dans le fichier Figma (états survol/focus détaillés, pages « Fondations » et « Composants ») n'ont pas pu être vérifiées ; la conformité visuelle est établie par rapport à l'export.
- **Modèles documentaires PDF** : aucun modèle PDF n'est fourni dans `/docs`. Les éditions PDF01–PDF10 suivent les règles du chapitre 29 et l'aperçu `PdfPreview` de la maquette (en-tête logo, classification, tableau, pied « Généré le … · Africa/Libreville · Page n sur N »).
- **Document DOCX mentionné par la maquette** (« note de cadrage antérieure ») : non fourni ; la maquette indique elle-même que le cahier v3.0 le remplace.
