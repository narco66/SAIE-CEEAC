# 11 — Rapport des tests de bout en bout

Exécution complète du 8 novembre 2026 (dimanche) : **53 parcours — 52 réussis, 1 ignoré, 0 échec** (17,3 min). L'ignoré est le parcours historique du lecteur kiosque (`player.spec.ts`), qui ne s'exécute qu'en jours ouvrés de 08:05 à 17:30 à Libreville (écran de démonstration hors plage).

Environnement : API Laravel (avec `SAIE_MFA_ENFORCED=false` pour ce seul processus pendant les tests, `.env` inchangé), worker de file, planificateur, frontend Vite, **lecteur réel** (application `/player` dans Chromium) et agent local de la dalle **en mode simulation**.

## Scénarios de la mission

| Scénario | Parcours | Ce qui est démontré | Résultat |
|---|---|---|---|
| A — Mise en service | `screens-scenarios.spec.ts` | Écran créé et localisé, lecteur enrôlé par son code, campagne publiée, programme téléchargé et vérifié, **lecture déclarée remontée au suivi de diffusion** | Réussi |
| B — Perte de connexion | idem | Coupure du lecteur, état « hors ligne » au délai réglé, incident automatique, reconnexion, incident résolu avec son résultat, période hors ligne dans l'historique | Réussi |
| C — Commande distante | `screens-commands.spec.ts` | Capacités déclarées ; journaux, arrêt/reprise (écran noir constaté), veille/réveil, relance de l'application et redémarrage de l'appareil, chacun **confirmé par le lecteur** ; journal lu par l'auditeur | Réussi (veille et redémarrage : agent **simulé**) |
| D — Diffusion collective | `screens-scenarios.spec.ts` | Groupe de deux écrans, campagne ciblant le groupe, lecture déclarée sur **les deux** lecteurs | Réussi |
| E — Maintenance | `screens-incidents.spec.ts` | Qualification, affectation, intervention, résolution, clôture refusée sans vérification, vérification par un second technicien, clôture | Réussi |
| F — Sécurité | `screens-scenarios.spec.ts` | Refus serveur (403) pour un agent et un auditeur ; jeton de lecteur forgé refusé (401) | Réussi |
| G — Fonctionnement hors ligne | `screens-scenarios.spec.ts` | Pendant la coupure, le lecteur continue d'afficher le programme en cache et signale la perte de contact ; resynchronisation à la reprise | Réussi |

## Autres parcours du module

| Parcours | Objet |
|---|---|
| `screens-inventory.spec.ts` | Import CSV, fiche, déplacement historisé, QR, archivage |
| `screens-profiles.spec.ts` | Profil transmis au lecteur réel, empreinte confirmée, luminosité « non prise en charge » |
| `screens-groups.spec.ts` | Groupe dynamique créé dans l'interface |
| `screens-overview.spec.ts` | Vue d'ensemble, carte vers la liste filtrée, vue par niveau |
| `screens-reports.spec.ts` | Cinq rapports générés par le worker (PDF et XLSX vérifiés), téléchargés |
| `screens-reassign.spec.ts` | Lecteur réel réaffecté depuis la fiche, nouveau manifeste reçu, ancien écran sans lecteur (ajouté après la recette complète ; réussi avec 16 parcours de non-régression) |
| `screens-suspension.spec.ts` | Lecteur réel : télémétrie de l'agent local affichée, suspension puis rétablissement depuis la fiche (nouveau manifeste à chaque fois), suivi de déploiement d'une version ouvert en lecture (aucune version publiée ni modifiée). Réussi le 8 novembre 2026 avec `screens-scenarios.spec.ts` et `views.spec.ts` (7 parcours) ; deux essais précédents ont échoué faute de nouveau manifeste sur un écran sans programme, corrigé par l'indicateur `suspended` du manifeste ; leurs écrans ont été archivés |
| `screens-inventory-logs.spec.ts` | Lecteur réel : code d'inventaire saisi puis retrouvé par la recherche, désactivation et réactivation motivées (historique), journaux techniques et adresse observée dans l'onglet Lecteur |

## Distinction simulé / réel

| Élément | Nature |
|---|---|
| Lecteur (SAIE Player) | **Réel** : l'application livrée, exécutée dans Chromium |
| Serveur, base, file, planificateur | **Réels** |
| Agent de la dalle (veille, réveil, redémarrage de l'appareil) | **Simulé** (`SAIE_PANEL_DRIVER=simulate`) ; le compte rendu du redémarrage l'indique explicitement |
| Dalle physique, HDMI-CEC, RS-232 | **Non testés** (aucun matériel disponible) |

## Passage du 8 novembre 2026 (lots 17 à 19)

Parcours `screens-*.spec.ts`, `views.spec.ts` et `player.spec.ts` : **13 réussis, 3 en échec, 1 ignoré** au premier passage. Les trois échecs avaient une seule cause, un défaut réel : la date de dernière coupure n'était pas convertie en date par le modèle, si bien que la liste des écrans renvoyait une erreur 500 dès qu'un lecteur réel l'avait déclarée. Après correction (et un test PHPUnit qui affiche la liste après un battement), `screens-scenarios.spec.ts` et `views.spec.ts` ont été relancés : **6 réussis**.

Le retrait des écrans de test passe désormais par une fonction commune (`retireScreen`, `e2e/helpers.ts`) : lecteur révoqué, incidents ouverts de l'écran clos avec motif (la nouvelle règle « aucun programme » en ouvre sur un écran de test sans campagne), puis archivage. Cinq écrans de test restés actifs après des essais interrompus (ECR-E2E-CMD ×2, ECR-E2E-INV ×2, ECR-RCT-A ×1) ont été retirés de la même manière ; rien n'a été supprimé.

## Passages du 8 novembre 2026 (lots 20 à 26)

- Après les lots 20 à 25, l'ensemble `screens-*.spec.ts`, `views.spec.ts` et `player.spec.ts` (dont le nouveau `screens-lots20-25.spec.ts` : mise en service, battement réel de l'agent local, journal des échanges, fiche PDF, indicateur « Temps réel ») : **17 réussis, 1 ignoré (kiosque, dimanche), 0 échec**.
- Deux passages précédents avaient échoué en bloc pour une cause d'environnement, non du code : un ancien serveur de développement (second facteur actif) resté à l'écoute du port 8000 captait les connexions, puis un routeur PHP lancé depuis le mauvais dossier. Corrigé, consigné dans les notes du poste.
- Après le lot 26 (corrections du second contrôle, dont le lecteur), le passage complet a été **interrompu** : un serveur `php artisan serve` démarré depuis le terminal Laragon à 20 h 13 (hors de cette intervention, laissé en place) écoutait lui aussi le port 8000 avec le second facteur, rendant les résultats non déterministes. Le parcours `screens-scenarios.spec.ts`, renforcé pour exiger l'état « En diffusion » avec un lecteur réel, **reste donc à rejouer**. L'écran créé par le passage interrompu a été retiré (lecteur révoqué, incident clos, archivage).

