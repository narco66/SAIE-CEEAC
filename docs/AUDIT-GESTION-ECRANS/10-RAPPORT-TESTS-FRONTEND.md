# 10 — Rapport des tests frontend

## Contrôles statiques

- Typage TypeScript (`npx tsc -b --noEmit`) : **aucune erreur** après chaque lot.
- ESLint : non exécutable sur ce poste (dépendances de développement non installées) — à lancer en intégration continue.

## Tests dans un navigateur réel (Playwright, Chrome)

| Vérification | Parcours | Résultat |
|---|---|---|
| Chargement de chaque vue sans erreur JavaScript ni appel en échec (vues du module comprises : vue d'ensemble, écrans, groupes, profils) | `views.spec.ts` | Réussi |
| Accessibilité WCAG 2.2 AA (axe) sur les vues principales | `accessibility.spec.ts` | Réussi |
| Menus selon les permissions | `views.spec.ts` | Réussi ; menu réorganisé vérifié pour un administrateur et un agent (`captures/menu-*.png`) |
| Formulaires, validation et messages : import CSV (simulation, bilan, confirmation), modification avec motif de déplacement, archivage, profils, groupes dynamiques, circuit d'incident | `screens-inventory`, `screens-profiles`, `screens-groups`, `screens-incidents` | Réussis |
| Cartes interactives vers les listes filtrées | `screens-overview.spec.ts` | Réussi |
| Commandes : boutons désactivés avec leur motif, historique et comptes rendus | `screens-commands.spec.ts` | Réussi |
| Rapports proposés dans le parcours « Générer un rapport » | `screens-reports.spec.ts` | Réussi |

## Vérification visuelle

Captures relues après chaque lot : `docs/AUDIT-GESTION-ECRANS/captures/`. Défauts trouvés et corrigés par cette relecture : barres de répartition non stylées (feuille de style non chargée), codes d'écran comprimés dans la vue par niveau, clés techniques affichées au lieu des libellés de configuration, identifiant de transaction peu distinctif, icônes du menu en double.

## Non couvert

- Rendu mobile du module (les mises en page utilisent des grilles adaptatives, non vérifiées en largeur de téléphone par un test).
- Impression de la fiche (feuille de style d'impression non testée automatiquement).
