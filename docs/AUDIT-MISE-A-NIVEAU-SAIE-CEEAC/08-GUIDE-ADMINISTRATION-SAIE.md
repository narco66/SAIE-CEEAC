# 08 — Guide d'administration du SAIE-CEEAC

Ce guide s'adresse aux administrateurs fonctionnels et techniques, aux diffuseurs et aux gestionnaires du parc. Il rassemble les opérations d'administration et renvoie aux manuels détaillés par rôle (`docs/manuels/`). Les nouveautés de la mise à niveau du 6 novembre 2026 sont signalées par **[nouveau]**.

## 1. Comptes, rôles et habilitations

Détail : `docs/manuels/administrateur.md`.

| Opération | Où | Règles |
|---|---|---|
| Créer un compte | Administration › Utilisateurs | Mot de passe provisoire à changer à la première connexion ; second facteur obligatoire pour les rôles sensibles |
| Attribuer un rôle | Administration › Rôles | Motif obligatoire ; dates de début et de fin ; périmètre (structure, site, zone) ; jamais pour soi-même |
| Attribuer un pouvoir étendu (urgence, modèles, prestations) | Administration › Rôles | Nominatif et daté ; périmètre explicite obligatoire (« Tout le parc » est un choix tracé, jamais un défaut) |
| Retirer un rôle **[nouveau]** | Administration › Rôles | Refusé sur son propre compte et sur un compte qui détient des droits que l'on n'a pas |
| Désactiver, réactiver, fermer les sessions **[nouveau]** | Fiche de l'utilisateur | Refusé sur un compte qui détient des droits d'administration que l'on n'a pas : un administrateur technique ne peut pas neutraliser un administrateur fonctionnel ni l'auditeur, et inversement |
| Débloquer, réinitialiser le second facteur | Fiche de l'utilisateur | Motif obligatoire ; le titulaire est prévenu par courriel ; jamais sur son propre compte |
| Modifier les permissions d'un rôle **[nouveau]** | Administration › Rôles | Le rôle « Agent », attribué à tous, ne peut recevoir aucun droit d'administration : créez une affectation nominative |

**Connexion [nouveau].** Après 5 échecs, un compte est bloqué 15 minutes. Le message est le même pour un compte inconnu, un mot de passe faux et un compte bloqué, pour ne pas révéler l'existence d'un compte. Un administrateur peut débloquer le compte depuis sa fiche. Au-delà de 30 tentatives par minute depuis une même adresse, toutes les connexions de cette adresse sont refusées un moment.

## 2. Lieux et écrans

Détail : `docs/manuels/administrateur.md`, `docs/lecteur.md`.

1. **Référentiels** (Administration › Référentiels, ou import CSV) : sites, bâtiments, niveaux, emplacements, zones de diffusion. La zone fixe la classification maximale affichable et la politique audio.
2. **Écran** (Affichage › Écrans et lecteurs) : code, emplacement, type (écran, panneau de salle, orientation), orientation, résolution, plage de fonctionnement (écran, sinon groupe, sinon défaut), canal de pilotage de la dalle.
3. **Fiche matérielle [nouveau]** (fiche de l'écran › Modifier l'écran) : marque, modèle, numéro de série, diagonale, adresse IP, type de connexion, date d'installation, fin de garantie. La garantie expirée est signalée.
4. **Statut administratif [nouveau]** :
   - **Actif** ;
   - **En maintenance** : motif obligatoire ; l'écran reste ciblé et reprend seul à la fin de l'intervention ;
   - **Désactivé** : l'écran n'est plus ciblé par aucune campagne.
5. **État opérationnel [nouveau]**, calculé à chaque consultation :

| État | Signification |
|---|---|
| Désactivé | Statut administratif |
| En maintenance | Statut administratif |
| En attente de configuration | Aucun lecteur appairé |
| Hors ligne | Lecteur sans contact depuis plus de 90 s (suspect) ou 5 min (indisponible) |
| En anomalie | Erreurs déclarées par le lecteur ou incident ouvert |
| Lecteur joignable | Le lecteur communique. **Cela ne prouve ni que la dalle est allumée ni que le contenu est vu.** |

6. **Relancer un lecteur [nouveau]** (fiche de l'écran › Lecteur › Relancer le lecteur) : motif obligatoire. L'application du lecteur se recharge à sa prochaine liaison, en 30 secondes environ, et le cache vérifié est conservé. Ce n'est pas un redémarrage du système du terminal.
7. **Enrôler un lecteur** : le lecteur affiche un code à 6 caractères, valable 10 minutes. Le saisir dans Écrans et lecteurs › Enrôler un lecteur. **[nouveau]** Appairage, révocation et captures sont limités aux écrans du site et de la zone de votre habilitation.

## 3. Contenus, modèles et documents

Détail : `docs/manuels/contributeur.md`, `validateur.md`.

- **Médias** : déposés en quarantaine, puis analysés (signature réelle du fichier, antivirus, empreinte) avant d'être utilisables. **[nouveau]** Un fichier bureautique (DOCX, PPTX, XLSX) reste téléchargeable, mais ne peut pas illustrer un contenu diffusé : déposez-en une version PDF.
- **Modèles** (pouvoir « modèles ») : zones positionnées en pourcentage du cadre. **[nouveau]** Une zone qui sortirait du cadre est refusée.
- **Circuit** : rédaction, soumission, validation par un validateur habilité pour la langue et la structure, distinct de tout rédacteur, puis version immuable. Échéance de 24 h, relance, escalade au suppléant.
- **Doublons [nouveau]** : la fiche d'un média liste les autres médias au fichier identique (même empreinte).
- **Corbeille d'un document [nouveau]** : motif obligatoire ; le document doit d'abord être dépublié ; elle est refusée tant qu'un contenu y renvoie par son QR.
- **Documents** : audience, versions (la précédente reste servie jusqu'à ce que la nouvelle soit saine), accusés de lecture, QR, diffusion par courriel.

## 4. Campagnes et alertes

Détail : `docs/manuels/diffuseur.md`.

- **Campagne** : contenus approuvés ordonnés (la liste de lecture), langues, cibles (écran, groupe, zone, niveau), période, jours, créneau, priorité. La publication fige les versions. Une campagne peut être suspendue, puis reprise.
- **Mode événement** : remplace le programme ordinaire pendant sa période (31 jours au plus).
- **Alerte** (pouvoir « urgence ») : message court, niveau (critique, avertissement, exercice, information), cibles, durée de 30 minutes par défaut et de 240 minutes au plus, prolongations et révocation tracées. **[nouveau]** Une alerte **critique** s'affiche aussi sur les écrans hors de leur plage de fonctionnement. Le délai peut atteindre 5 minutes, car un lecteur hors plage ne se synchronise que toutes les 5 minutes. Une dalle éteinte matériellement ne s'allume que si elle est pilotable. Réglage : `SAIE_CRITICAL_ALERT_OVERRIDES_WINDOW` (décision D1 à confirmer).

## 5. Supervision, preuves et incidents

Détail : `docs/manuels/administrateur.md`.

- **Suivi de diffusion** (fiche d'une campagne › Diffusion), par écran :

| Niveau | Signification |
|---|---|
| Transmis **[nouveau]** | Première version du programme servie au lecteur. **Conservé durablement.** |
| Reçu | Programme vérifié par le lecteur |
| Chargé, activé | Médias téléchargés et vérifiés, programme basculé |
| Lecture déclarée **[nouveau]** | Diapositive affichée jusqu'au bout de sa durée sans erreur de rendu, passages agrégés par tranche de 10 minutes |
| Dalle | Seulement si un canal de pilotage remonte l'état ; sinon « Non mesuré » |

- **Dernière synchronisation [nouveau]** : n'avance que lorsqu'un programme vérifié a été reçu, plus jamais au simple contact du lecteur.
- **Supervision** : un contrôle n'apparaît en vert que s'il est mesuré. **[nouveau]** « Dalles » est un avertissement « non mesuré » tant qu'aucun écran ne déclare de canal de pilotage ; « Sources dynamiques » vérifie la fraîcheur du dernier relevé réussi.
- **Terminaux non autorisés [nouveau]** : la supervision signale les appels refusés sur l'API des lecteurs depuis 24 h (jeton absent, révoqué ou forgé) ; le détail se trouve au journal, action `device.unauthorized`.
- **Anomalies [nouveau]** : stockage du lecteur au-delà de 90 %, horloge déréglée de plus de 30 s, ou programme récent non appliqué depuis une heure. Chacune ouvre un incident automatique, notifié, résolu de lui-même quand la condition disparaît.
- **Incidents [nouveau]** :
  - déclarer avec une catégorie (lecteur, dalle, réseau, alimentation, contenu, logiciel, autre) et une nature (maintenance corrective ou préventive) ;
  - affecter à un agent actif qui a accès à la supervision ; il est prévenu par notification ;
  - consigner le diagnostic, puis acquitter, résoudre et clore.

## 6. Tableau de bord

Les chiffres viennent de la base :

- écrans par état opérationnel (parc complet, désactivés et en maintenance compris) ;
- répartition par bâtiment quand il y en a plusieurs ;
- salles équipées ;
- contenus en attente de validation ;
- campagnes du jour sur la plage par défaut réellement paramétrée **[nouveau]** ;
- tâches, réunions, incidents, activité.

## 7. Paramètres et rapports

- **Paramètres** (Administration › Paramètres) : identité, bandeau, durée de diapositive, plage et volume par défaut, valeurs initiales des salles, canaux courriel, push et API. Les seuils, formats, durées de conservation et paramètres du lecteur se règlent dans la configuration du serveur (livrable 09).
- **Rapports** : PDF01 à PDF10 et extraits CSV. Le PDF05 (diffusion) s'appuie désormais sur la transmission durable.

## 8. Agenda personnel [nouveau]

Depuis la fiche d'une salle, « S'abonner » crée une adresse iCalendar personnelle, à ajouter dans Outlook, Thunderbird ou l'agenda du téléphone (« S'abonner à un calendrier »). L'adresse n'est affichée qu'une fois. Elle applique vos droits courants : une réunion confidentielle apparaît « Réservée » si vous n'en êtes ni l'organisateur ni le gestionnaire, et l'abonnement cesse de fonctionner si votre compte est désactivé. Pour révoquer une adresse : Mon compte › Abonnements d'agenda.

## 9. Fonctions ajoutées au troisième passage [nouveau]

- **Rôles** : « Technicien de maintenance » (supervision, incidents, appairage ; second facteur obligatoire), « Gestionnaire des contenus », « Consultation (lecture seule) ».
- **Circuit à plusieurs niveaux** : Administration › Référentiels › Catégories › « Niveaux de validation ». Chaque niveau est validé par une personne différente ; la version n'est diffusable qu'après le dernier niveau.
- **Alertes à confirmer** : une information adressée à tout le parc apparaît dans « Alertes à confirmer » du centre des alertes ; un autre titulaire du pouvoir « urgence » la confirme ou la refuse.
- **Documents** : avec la double validation activée (Paramètres › Diffusion), l'auteur utilise « Demander la publication » et un autre gestionnaire publie.
- **Listes de lecture** : dans l'assistant de campagne, « Charger la liste » ou « Enregistrer comme liste ». Les éléments se réordonnent par glisser-déposer. Une campagne terminée, suspendue ou en brouillon s'archive depuis son menu.
- **Médiathèque** : étiquettes (recherche `#étiquette`), mention de droits et fin des droits d'usage. Un média aux droits échus n'est plus diffusé.
- **Présentations** : un fichier PowerPoint converti par le serveur se choisit comme un PDF et se diffuse page par page.
- **Rapports** : format Excel ; filtre par bâtiment ; chiffres limités à votre périmètre.
- **Maintenance préventive** (Supervision) : créez un plan (écran ou parc, fréquence). À l'échéance, une intervention est ouverte ; sa résolution fixe l'échéance suivante.
- **Supervision › Erreurs et API** (administrateurs techniques) : requêtes, erreurs serveur et temps de réponse de la dernière heure, dernières erreurs sans données personnelles.
- **Orientation** : type d'espace des emplacements ; plan de chaque niveau (image publique) ; bureau et téléphone d'accueil des structures ; option « Borne tactile » sur un écran d'orientation, qui affiche un annuaire filtrable par niveau et par initiale.
- **Paramètres** : onglets « Alertes et supervision » et « Conservation ».
