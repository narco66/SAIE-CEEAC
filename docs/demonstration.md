# Jeu de démonstration (chapitre 31)

Données **fictives**, marquées `is_demo`, créées par `DemoSeeder` à travers les services métier réels (validation, publication, réservations). Le seeder ne s’exécute qu’en environnement `local` (ou avec `SAIE_DEMO_MODE=true`), refuse la production sans `SAIE_ALLOW_DEMO_SEED`, et ne fait rien si le jeu est déjà présent.

Le mot de passe commun est **généré aléatoirement** au seed et écrit dans `backend/storage/app/private/demo-accounts.txt` (non versionné). Pour imposer une valeur locale : `SAIE_DEMO_PASSWORD=...` avant le seed.

| Compte | Rôle(s) | Pouvoir étendu | Pour tester |
| --- | --- | --- | --- |
| alain.mbeng@demo.saie-ceeac.local | Administrateur technique DSI · Diffuseur | urgence (jusqu’au 31/12) | parc, appairage, alertes, supervision |
| clarisse.ondo@demo.saie-ceeac.local | Administrateur fonctionnel | modèles | référentiels, rôles, modèles, suppléante de Mireille |
| nadia.kombila@demo.saie-ceeac.local | Contributeur (Communication) | — | création de contenus FR/EN, soumission |
| jean.moussavou@demo.saie-ceeac.local | Validateur (FR) | — | suppléant de Grace : tâche escaladée |
| grace.ndong@demo.saie-ceeac.local | Validateur (EN, FR) | — | validations en attente |
| patrick.obiang@demo.saie-ceeac.local | Diffuseur | urgence **expiré la veille** (R19) | campagnes ; alerte refusée |
| mireille.tchibinda@demo.saie-ceeac.local | Gestionnaire de salle | prestations (interprétation, visio, assistance, sono) | validations de salles, non-présentation, prestations |
| estelle.nze@demo.saie-ceeac.local | Contributeur (Protocole) | — | accueil des délégations, messages de bienvenue |
| loic.bouanga@demo.saie-ceeac.local | Agent | — | documents autorisés, réservations |
| sylvain.essono@demo.saie-ceeac.local | Auditeur | — | journal, rapports, exports |

Contenu du scénario : site Libreville, bâtiment Siège, RDC et 6 étages ; 14 écrans (dont une borne d’orientation) et 4 panneaux de salle ; lecteurs connectés, un indisponible (ECR-2E-02, incident ouvert), un suspect (ECR-5E-01), un cache saturé (ECR-4E-02), un écran hors plage (ECR-3E-02, dérogation motivée), un écran non appairé (ECR-6E-02) ; 4 salles (12, 20, 40, 100 places) et 2 ressources mobiles ; 12 documents public/interne/restreint ; 12 contenus à tous les stades (brouillon, soumis, approuvé, programmé, en diffusion, expiré, archivé, retourné), dont un avec version EN non approuvée et un PDF diffusé par pages ; 6 campagnes dont deux concurrentes de même priorité au 2e étage ; une alerte prolongée puis expirée et un exercice levé ; réunions du jour dont une confidentielle ; une série avec occurrence en conflit écartée ; une demande escaladée au suppléant ; une non-présentation déclarée ; une prestation impossible ; un export expiré ; une notification vers un document devenu inaccessible ; un fichier corrompu et un fichier de test antivirus EICAR rejetés.

Les heures des réunions « du jour » sont calées sur l’heure du seed. L’historique de télémétrie de 14 jours est étiqueté « simulé ». Pour garder des lecteurs de démonstration « connectés » pendant une présentation : `php artisan saie:demo-fleet` (interdit en production ; il passe par le vrai compilateur de manifeste). Le fonctionnement réel s’éprouve avec le lecteur `/player` appairé par code.
