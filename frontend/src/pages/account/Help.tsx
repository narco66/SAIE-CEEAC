import { useMemo, useState } from 'react'
import { useLocation, useNavigate } from 'react-router'
import { useAuth } from '../../lib/auth'
import { Button, Icon, Notice, PageHeading, type IconName } from '../../components/ui'
import './account.css'

interface Guide { title: string; icon: IconName; perms: string[]; steps: string[] }

/** Guides courts par rôle (texte statique de la documentation utilisateur). */
const GUIDES: Guide[] = [
  { title: 'Contributeur : publier un contenu', icon: 'file', perms: ['contents.create'], steps: [
    'Contenus › Créer : choisissez un modèle, saisissez chaque langue requise.',
    'Vérifiez l’aperçu écran (débordements signalés) et la classification.',
    'Soumettez à validation : chaque langue est validée séparément.',
    'Un retour pour correction apparaît dans « Tâches & validations ».',
  ] },
  { title: 'Validateur : examiner une demande', icon: 'check', perms: ['contents.validate'], steps: [
    'Ouvrez la tâche depuis « Tâches & validations » ; l’échéance est indiquée.',
    'Comparez la version soumise et l’aperçu dans votre langue de validation.',
    'Approuvez, ou retournez avec un motif précis (obligatoire).',
    'En votre absence, la tâche est escaladée à votre suppléant.',
  ] },
  { title: 'Diffuseur : programmer une campagne', icon: 'calendar', perms: ['campaigns.manage'], steps: [
    'Campagnes › Nouvelle campagne : contenus approuvés uniquement.',
    'Choisissez période, plages horaires, cibles et priorité.',
    'Contrôlez les conflits et la classification des zones avant publication.',
    'Suivez la réception par les lecteurs dans la fiche de diffusion.',
  ] },
  { title: 'Alertes urgentes', icon: 'alert', perms: ['alerts.manage'], steps: [
    'Réservé aux titulaires du pouvoir « Urgence », nominatif et daté.',
    'Message bref, portée, durée et justification obligatoires.',
    'L’alerte interrompt les programmes ordinaires sur les écrans ciblés.',
    'Révoquez-la dès la fin de l’événement.',
  ] },
  { title: 'Réserver une salle', icon: 'door', perms: ['reservations.create'], steps: [
    'Salles › Calendrier : repérez un créneau libre (préparation et remise en état incluses).',
    'Précisez participants, prestations et confidentialité.',
    'En cas de conflit, des alternatives sont proposées.',
    'La confirmation peut nécessiter l’approbation du gestionnaire.',
  ] },
  { title: 'Gestionnaire de salle', icon: 'building', perms: ['reservations.approve', 'rooms.manage'], steps: [
    'Réservations à valider : approuvez ou refusez avec motif.',
    'Bloquez une salle pour maintenance ; les réunions touchées sont signalées.',
    'Déclarez une réunion non honorée après le délai prévu.',
  ] },
  { title: 'Prestations et accueil', icon: 'users', perms: ['services.process', 'visits.manage'], steps: [
    'Prestations : traitez les demandes avant leur échéance, demandez une précision si besoin.',
    'Accueil & protocole : préparez visites et délégations, messages d’accueil sur écran.',
  ] },
  { title: 'Administration fonctionnelle', icon: 'shield', perms: ['users.manage', 'roles.manage', 'referentials.manage', 'imports.run'], steps: [
    'Utilisateurs : rôles datés avec périmètre et motif ; pouvoirs étendus toujours datés.',
    'Référentiels : désactivez plutôt que supprimer un élément utilisé.',
    'Imports : simulez toujours avant de confirmer ; corrigez les lignes invalides.',
  ] },
  { title: 'Administration technique', icon: 'wifi', perms: ['supervision.view', 'settings.technical', 'devices.pair'], steps: [
    'Supervision : contrôles datés ; une absence de mesure n’est jamais présentée comme un succès.',
    'Appairage : code affiché par le lecteur, affectation à un écran.',
    'Paramètres techniques : chaque changement exige un motif journalisé.',
  ] },
  { title: 'Audit et rapports', icon: 'chart', perms: ['audit.view', 'reports.view'], steps: [
    'Journal : filtres par période, acteur, action ; détail avant/après.',
    'Rapports : chaque indicateur affiche sa période et sa base de calcul.',
    'Une activation déclarée n’est pas une preuve de vue humaine.',
  ] },
]

const ERRORS: { code: string; title: string; text: string }[] = [
  { code: '403', title: 'Accès non autorisé', text: 'Vos habilitations ne couvrent pas cette page ou cette action. Demandez l’ajout du rôle ou du périmètre à l’administrateur fonctionnel.' },
  { code: '404', title: 'Page ou objet introuvable', text: 'L’adresse est erronée, l’objet a été retiré ou il n’entre pas dans votre périmètre.' },
  { code: '409', title: 'Conflit', text: 'L’objet a été modifié entre-temps ou l’action n’est pas possible dans son état actuel : rechargez puis recommencez. Votre saisie est conservée.' },
  { code: '5xx', title: 'Erreur du serveur', text: 'Le service n’a pas pu répondre. Réessayez ; si le problème persiste, transmettez l’identifiant de diagnostic au support.' },
  { code: '401', title: 'Session expirée', text: 'Reconnectez-vous : vous reviendrez automatiquement à la page demandée.' },
]

/** V42 Aide et page introuvable : guides par rôle, raccourcis, explication des erreurs, identifiant de diagnostic. */
export default function Help() {
  const { me, can } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()
  const [copied, setCopied] = useState<'ok' | 'ko' | null>(null)
  const notFound = location.pathname !== '/aide'
  const guides = GUIDES.filter((g) => g.perms.some((p) => can(p)))

  const diagnostic = useMemo(() => {
    const now = new Date()
    const skew = me.server_time ? Math.round((now.getTime() - new Date(me.server_time).getTime()) / 1000) : null
    return [
      `SAIE-${now.getTime().toString(36).toUpperCase()}`,
      `page=${location.pathname}`,
      `heure=${now.toISOString()}`,
      `ecart_horloge_s=${skew ?? 'n.d.'}`,
      `langue=${navigator.language}`,
      `ecran=${window.innerWidth}x${window.innerHeight}`,
    ].join(' · ')
  }, [location.pathname, me.server_time])

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(diagnostic)
      setCopied('ok')
    } catch {
      setCopied('ko')
    }
  }

  return (
    <>
      {notFound ? (
        <section className="panel" style={{ marginBottom: 15 }}>
          <div className="state-box" role="alert">
            <div className="empty-icon"><Icon name="search" size={23} /></div>
            <h1 style={{ font: '700 18px Manrope, sans-serif' }}>Page introuvable</h1>
            <p>L’adresse <span className="mono">{location.pathname}</span> ne correspond à aucune page de SAIE-CEEAC, ou cette page n’est plus disponible.</p>
            <div className="row-actions" style={{ justifyContent: 'center' }}>
              <Button icon="home" onClick={() => navigate('/accueil')}>Retour à l’accueil</Button>
              <Button variant="secondary" onClick={() => navigate(-1)}>Page précédente</Button>
            </div>
          </div>
        </section>
      ) : (
        <PageHeading title="Aide" description="Guides adaptés à vos rôles, raccourcis clavier et signification des messages d’erreur." actions={<Button variant="secondary" icon="home" onClick={() => navigate('/accueil')}>Retour à l’accueil</Button>} />
      )}

      <section className="panel">
        <div className="panel-title"><div><h2>Guides pour vos rôles</h2><p>{me.role_label}</p></div></div>
        {guides.length === 0 ? <p className="muted small" style={{ padding: '0 19px 18px' }}>Aucun guide spécifique : consultez vos tâches et notifications depuis le menu.</p> : (
          <div className="help-grid">
            {guides.map((g) => (
              <article key={g.title}>
                <h3><Icon name={g.icon} size={16} />{g.title}</h3>
                <ol>{g.steps.map((s) => <li key={s}>{s}</li>)}</ol>
              </article>
            ))}
          </div>
        )}
      </section>

      <div className="grid-2 section-gap">
        <section className="panel">
          <div className="panel-title"><div><h2>Raccourcis clavier</h2><p>Toutes les fonctions restent accessibles au clavier</p></div></div>
          <div className="panel-body">
            <dl className="kv">
              <dt><span className="kbd">Ctrl</span> + <span className="kbd">K</span></dt><dd>Recherche globale</dd>
              <dt><span className="kbd">Échap</span></dt><dd>Fermer une fenêtre ou un menu</dd>
              <dt><span className="kbd">Tab</span> / <span className="kbd">Maj</span> + <span className="kbd">Tab</span></dt><dd>Passer d’un élément à l’autre</dd>
              <dt><span className="kbd">Entrée</span></dt><dd>Activer le bouton ou le lien sélectionné</dd>
              <dt>Lien d’évitement</dt><dd>Premier élément de chaque page : « Aller au contenu »</dd>
            </dl>
          </div>
        </section>
        <section className="panel">
          <div className="panel-title"><div><h2>Identifiant de diagnostic</h2><p>Non sensible : aucune donnée personnelle ni jeton de session</p></div></div>
          <div className="panel-body" style={{ display: 'grid', gap: 10 }}>
            <pre className="code-block" style={{ maxHeight: 120 }}>{diagnostic}</pre>
            <div><Button variant="secondary" icon="file" onClick={copy}>Copier l’identifiant</Button></div>
            {copied === 'ok' && <Notice tone="success">Identifiant copié : collez-le dans votre message au support.</Notice>}
            {copied === 'ko' && <Notice tone="warning">Copie automatique impossible : sélectionnez le texte ci-dessus et copiez-le manuellement.</Notice>}
          </div>
        </section>
      </div>

      <section className="panel table-panel section-gap">
        <div className="panel-title"><div><h2>Comprendre les messages d’erreur</h2><p>Chaque erreur affiche un identifiant de diagnostic à communiquer au support</p></div></div>
        <div className="table-scroll">
          <table>
            <thead><tr><th scope="col">Code</th><th scope="col">Situation</th><th scope="col">Que faire</th></tr></thead>
            <tbody>{ERRORS.map((e) => <tr key={e.code}><td className="mono">{e.code}</td><td><strong>{e.title}</strong></td><td>{e.text}</td></tr>)}</tbody>
          </table>
        </div>
      </section>
    </>
  )
}
