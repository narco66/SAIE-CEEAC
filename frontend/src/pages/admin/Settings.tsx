import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../../lib/api'
import { Button, ConfirmDialog, EmptyState, ErrorState, Kv, Loading, Notice, PageHeading, Status, Tabs, useToast } from '../../components/ui'
import { DynamicSources, ServiceAccounts } from './SettingsIntegrations'
import { useReferentials } from './common'
import './admin.css'

interface Setting { key: string; tab: string; type: 'string' | 'integer' | 'boolean' | 'array'; value: unknown; editable: boolean; technical: boolean }
interface SettingsData {
  settings: Setting[]
  environment: { antivirus: string; queue: string; mail: string; mfa_enforced: boolean; timezone: string }
  notice: string
}

const SECRET = '••••••'
const TABS = ['Identité', 'Diffusion', 'Fonctionnement et audio', 'Alertes et supervision', 'Salles', 'Canaux', 'Conservation', 'Sources dynamiques', 'Intégrations', 'Sécurité des lecteurs'] as const
type TabName = (typeof TABS)[number]

const META: Record<string, { label: string; help?: string; unit?: string; min?: number; max?: number }> = {
  'identity.institution_name': { label: 'Nom de l’institution', help: 'Affiché en tête des écrans.' },
  'display.tagline': { label: 'Mention institutionnelle', help: 'Sous-titre affiché sous le nom de l’institution sur les écrans.' },
  'display.default_slide_s': { label: 'Durée par défaut d’une diapositive', help: 'Valeur proposée par l’assistant de campagne ; chaque campagne peut la modifier.', unit: 's', min: 10, max: 300 },
  'operating.default_window': { label: 'Plage de fonctionnement par défaut', help: 'S’applique aux écrans sans plage propre. Format : {"days": [1,…,7], "on": "HH:MM", "off": "HH:MM"} (1 = lundi).' },
  'operating.audio_max_volume': { label: 'Volume audio maximal', help: 'Plafond général ; le plafond de chaque zone s’applique s’il est plus bas.', unit: '%', min: 0, max: 100 },
  'rooms.pending_hold_hours': { label: 'Durée de maintien d’une demande en attente', help: 'Valeur donnée aux nouvelles salles ; réglable ensuite salle par salle.', unit: 'h', min: 1, max: 168 },
  'rooms.no_show_auto': { label: 'Libération automatique des salles sans confirmation de présence', help: 'Valeur donnée aux nouvelles salles ; réglable ensuite salle par salle.' },
  'channels.smtp_enabled': { label: 'Envoi de courriels', help: 'Désactivé : aucun courriel n’est envoyé ; les notifications restent visibles dans l’application.' },
  'channels.push_enabled': { label: 'Notifications push', help: 'Exige aussi les clés VAPID du serveur. Les utilisateurs s’abonnent depuis « Mon compte ».' },
  'integrations.api_enabled': { label: 'API d’intégration externe', help: 'Désactivée : les comptes de service sont refusés.' },
  'alerts.critical_after_hours': { label: 'Alerte critique affichée hors plage de fonctionnement', help: 'Les alertes critiques s’affichent aussi sur les écrans en dehors de leurs heures ; les autres niveaux attendent l’ouverture. Une dalle éteinte ne s’allume que si elle est pilotable.' },
  'anomalies.storage_ratio_pct': { label: 'Seuil d’anomalie : stockage du lecteur', help: 'Au-delà, un incident automatique est ouvert.', unit: '%', min: 50, max: 99 },
  'anomalies.clock_skew_s': { label: 'Seuil d’anomalie : écart d’horloge', help: 'Écart toléré entre l’horloge du lecteur et celle du serveur.', unit: 's', min: 5, max: 600 },
  'supervision.suspect_after_s': { label: 'Délai avant « suspect »', help: 'Sans battement du lecteur pendant ce délai, il est signalé comme suspect.', unit: 's', min: 45, max: 1800 },
  'supervision.offline_after_s': { label: 'Délai avant « hors ligne »', help: 'Sans battement pendant ce délai, le lecteur est hors ligne et un incident est ouvert. Doit dépasser le délai « suspect ».', unit: 's', min: 90, max: 3600 },
  'anomalies.stalled_minutes': { label: 'Seuil d’anomalie : lecture arrêtée', help: 'Un lecteur joignable qui ne déclare plus de lecture pendant ce délai (hors arrêt commandé et hors plage) ouvre un incident.', unit: 'min', min: 5, max: 240 },
  'anomalies.errors_per_hour': { label: 'Seuil d’anomalie : échecs de lecture', help: 'Nombre d’erreurs de lecture déclarées en une heure qui ouvre un incident.', unit: '/ h', min: 1, max: 500 },
  'anomalies.temperature_c': { label: 'Seuil d’anomalie : température', help: 'Température du lecteur (mesurée par l’agent local, capteur Linux) à partir de laquelle un incident est ouvert.', unit: '°C', min: 40, max: 110 },
  'anomalies.unstable_losses': { label: 'Seuil d’anomalie : connexion instable', help: 'Nombre de pertes de connexion d’un lecteur en 24 h à partir duquel un incident « connexion instable » est ouvert.', min: 2, max: 50 },
  'anomalies.outdated_hours': { label: 'Seuil d’anomalie : version obsolète', help: 'Délai après la publication d’une version sur le canal du lecteur au-delà duquel une version non appliquée ouvre un incident.', unit: 'h', min: 1, max: 720 },
  'anomalies.sync_stalled_minutes': { label: 'Seuil d’anomalie : programme non appliqué', help: 'Délai après lequel un lecteur en ligne qui n’applique pas le programme le plus récent ouvre un incident.', unit: 'min', min: 10, max: 1440 },
  'retention.telemetry_days': { label: 'Conservation de la télémétrie des lecteurs', unit: 'jours', min: 30, max: 730 },
  'retention.notifications_days': { label: 'Conservation des notifications lues', unit: 'jours', min: 30, max: 730 },
  'retention.receipts_days': { label: 'Conservation des accusés de diffusion', help: 'Preuves de réception, d’activation et de lecture remontées par les lecteurs.', unit: 'jours', min: 90, max: 1095 },
  'retention.commands_days': { label: 'Conservation des commandes à distance terminées', help: 'Les commandes en cours ne sont jamais purgées ; le journal d’audit garde leur trace.', unit: 'jours', min: 30, max: 1095 },
  'retention.states_days': { label: 'Conservation de l’historique des états des écrans', unit: 'jours', min: 90, max: 1825 },
  'incidents.delay_critical_min': { label: 'Prise en charge d’un incident critique', help: 'Au-delà, l’incident non acquitté est escaladé aux gestionnaires des incidents et à son responsable.', unit: 'min', min: 5, max: 1440 },
  'incidents.delay_major_min': { label: 'Prise en charge d’un incident majeur', help: 'Délai avant escalade d’un incident majeur non acquitté.', unit: 'min', min: 5, max: 2880 },
  'incidents.delay_medium_min': { label: 'Prise en charge d’un incident moyen', help: 'Délai avant escalade d’un incident moyen non acquitté.', unit: 'min', min: 5, max: 10080 },
  'incidents.delay_minor_min': { label: 'Prise en charge d’un incident mineur', help: 'Délai avant escalade d’un incident mineur non acquitté.', unit: 'min', min: 5, max: 20160 },
  'incidents.default_assignee_id': { label: 'Responsable des incidents détectés automatiquement', help: 'Agent (technicien d’astreinte) à qui chaque incident automatique est confié ; vide : aucun responsable désigné.' },
  'campaigns.urgent_from': { label: 'Seuil du niveau « messages urgents »', help: 'Priorité de campagne à partir de laquelle le pouvoir « urgence » est exigé (niveaux : 1–19 permanents, 20–39 informations, 40–59 institutionnelles, 60–79 officielles prioritaires, 80–99 urgentes).', min: 60, max: 99 },
  'documents.four_eyes': { label: 'Double validation des documents', help: 'Activée : celui qui a déposé un document ne peut pas le publier lui-même ; il demande la publication à un autre gestionnaire.' },
  'devices.enroll_cidrs': { label: 'Liste blanche d’enrôlement des lecteurs', help: 'Réseaux autorisés à demander un code d’appairage, séparés par des virgules (ex. 10.20.0.0/16, 192.168.1.10). Vide : enrôlement ouvert (comportement d’origine). Décision DSI.' },
  'devices.signed_requests': { label: 'Requêtes des lecteurs signées (anti-rejeu)', help: 'Activé : toute requête d’un lecteur doit porter une signature HMAC horodatée à usage unique. Les lecteurs à jour (programme, battement, accusés et médias) signent déjà avec l’horloge corrigée par le serveur ; un lecteur d’ancienne version sera refusé. Décision DSI.' },
  'devices.secure_storage': { label: 'Jeton des lecteurs chiffré au repos', help: 'Activé : chaque lecteur chiffre son jeton dans le navigateur par une clé non exportable, à son prochain battement. Décision DSI.' },
  'referentials.restricted': { label: 'Annuaire des comptes réservé aux gestionnaires', help: 'Activé : la liste des comptes n’est plus transmise aux agents sans droit de gestion dans les référentiels. Décision DSI.' },
  'retention.audit_months': { label: 'Archivage du journal d’audit', help: 'Au-delà, les entrées du journal sont archivées dans le stockage privé (jamais supprimées sans archive).', unit: 'mois', min: 12, max: 120 },
  'retention.export_hours': { label: 'Disponibilité des exports générés', help: 'Durée pendant laquelle un export reste téléchargeable.', unit: 'h', min: 1, max: 720 },
  'retention.exchanges_days': { label: 'Conservation du journal des échanges des lecteurs', help: 'Route, statut, durée et volume de chaque échange d’un lecteur avec le serveur ; purgé par la purge nocturne.', unit: 'jours', min: 7, max: 365 },
  'anomalies.fallback_minutes': { label: 'Seuil d’anomalie : contenu de repli prolongé', help: 'Durée d’affichage du seul contenu de repli, alors qu’un programme existe, au-delà de laquelle un incident est ouvert.', unit: 'min', min: 5, max: 720 },
  'releases.auto_promote': { label: 'Promotion automatique des versions du lecteur', help: 'Activée : une version pilote saine (déployée à 100 %, sans incident, depuis le délai réglé) est publiée sur le canal stable avec une première vague de 25 %.' },
  'releases.promote_after_h': { label: 'Délai avant promotion d’une version pilote', unit: 'h', min: 24, max: 720 },
  'retention.auto_purge': { label: 'Purge nocturne automatique', help: 'Supprime la télémétrie et les notifications échues ; le journal d’audit échu est archivé, jamais supprimé par l’application.' },
}

function displayValue(s: Setting): string {
  if (s.value === SECRET) return SECRET
  if (s.type === 'boolean') return s.value ? 'Activé' : 'Désactivé'
  if (s.type === 'array') return JSON.stringify(s.value)
  if (s.value === null || s.value === undefined || s.value === '') return '—'
  const unit = META[s.key]?.unit
  return `${String(s.value)}${unit ? ` ${unit}` : ''}`
}

/** V39 Paramètres : chaque modification est confirmée avec motif ; les valeurs secrètes ne sont jamais relues. */
export default function Settings() {
  const query = useQuery({ queryKey: ['settings'], queryFn: ({ signal }) => api.get<{ data: SettingsData }>('/settings', undefined, signal).then((r) => r.data) })
  const [tab, setTab] = useState<TabName>('Identité')
  if (query.isLoading) return <Loading />
  if (query.isError || !query.data) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  const d = query.data
  const items = d.settings.filter((s) => s.tab === tab)

  return (
    <>
      <PageHeading title="Paramètres" description="Identité, diffusion, fonctionnement, salles, canaux, sources dynamiques et intégrations." />
      <Tabs<TabName> value={tab} onChange={setTab} items={TABS.map((t) => ({ value: t, label: t }))} />
      <div className="two-columns report-grid" style={{ gridTemplateColumns: 'minmax(0, 2fr) minmax(260px, 1fr)' }}>
        <section className="panel table-panel">
          <div className="panel-title"><div><h2>{tab}</h2><p>{tab === 'Sources dynamiques' ? 'Météo, flux et comptes à rebours collectés par le serveur ; seules les sources actives sont diffusées' : 'Une modification est appliquée après confirmation et inscrite au journal avec son motif'}</p></div></div>
          {tab === 'Sources dynamiques' ? <DynamicSources /> : items.length === 0 ? <EmptyState title="Aucun paramètre dans cet onglet" /> : items.map((s) => <SettingRow key={s.key} s={s} />)}
          {tab === 'Intégrations' && <ServiceAccounts apiEnabled={d.settings.some((s) => s.key === 'integrations.api_enabled' && s.value === true)} />}
        </section>
        <aside style={{ display: 'grid', gap: 15, alignContent: 'start' }}>
          <section className="panel">
            <div className="panel-title"><div><h2>Environnement</h2><p>Lecture seule · configuration serveur</p></div></div>
            <div className="panel-body">
              <Kv items={[
                ['Antivirus', d.environment.antivirus], ['Files de traitement', d.environment.queue], ['Courriel', d.environment.mail === 'log' ? 'log (aucun envoi réel)' : d.environment.mail],
                ['Second facteur imposé', d.environment.mfa_enforced ? 'Oui' : 'Non'], ['Fuseau institutionnel', d.environment.timezone],
              ]} />
            </div>
          </section>
          <Notice tone="info">{d.notice}</Notice>
        </aside>
      </div>
    </>
  )
}

function SettingRow({ s }: { s: Setting }) {
  const client = useQueryClient()
  const toast = useToast()
  const meta = META[s.key] ?? { label: s.key }
  const secret = s.value === SECRET
  const refs = useReferentials()
  const [editing, setEditing] = useState(false)
  const [value, setValue] = useState<unknown>(secret ? '' : s.value)
  const [jsonError, setJsonError] = useState<string | null>(null)
  const [confirm, setConfirm] = useState(false)
  const [text, setText] = useState(s.type === 'array' ? JSON.stringify(s.value, null, 2) : '')

  const prepare = () => {
    if (s.type === 'array') {
      try {
        setValue(JSON.parse(text))
        setJsonError(null)
      } catch {
        setJsonError('JSON invalide : vérifiez la syntaxe.')
        return
      }
    }
    setConfirm(true)
  }

  return (
    <div className="setting-row">
      <div>
        <strong>{meta.label}</strong>
        <small className="mono">{s.key}</small>
        {meta.help && <small>{meta.help}</small>}
      </div>
      <div>
        {!editing ? (
          <span className={s.type === 'array' ? 'mono' : ''}>{s.key === 'incidents.default_assignee_id' && s.value ? (refs.data?.users ?? []).find((u) => u.id === s.value)?.name ?? '—' : displayValue(s)} {secret && <Status tone="neutral">Secret masqué</Status>}</span>
        ) : s.type === 'boolean' ? (
          <select className="input" value={value ? '1' : '0'} onChange={(e) => setValue(e.target.value === '1')} aria-label={meta.label}><option value="1">Activé</option><option value="0">Désactivé</option></select>
        ) : s.type === 'integer' ? (
          <input className="input" type="number" min={meta.min} max={meta.max} value={String(value ?? '')} onChange={(e) => setValue(e.target.value === '' ? '' : Number(e.target.value))} aria-label={meta.label} />
        ) : s.key === 'incidents.default_assignee_id' ? (
          <select className="input" value={String(value ?? '')} onChange={(e) => setValue(e.target.value)} aria-label={meta.label}>
            <option value="">Aucun</option>
            {(refs.data?.users ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </select>
        ) : s.type === 'array' ? (
          <>
            <textarea className="input mono" style={{ minHeight: 120 }} value={text} onChange={(e) => setText(e.target.value)} aria-label={meta.label} aria-invalid={!!jsonError} />
            {jsonError && <span className="field-error" role="alert">{jsonError}</span>}
          </>
        ) : (
          <input className="input" type={secret ? 'password' : 'text'} value={String(value ?? '')} placeholder={secret ? 'Nouvelle valeur (jamais relue)' : undefined} maxLength={300} onChange={(e) => setValue(e.target.value)} aria-label={meta.label} autoComplete="off" />
        )}
      </div>
      <div className="row-actions">
        {!s.editable ? <Status tone="neutral" title={s.technical ? 'Droit settings.technical requis' : 'Droit settings.manage requis'}>Lecture seule</Status> : editing ? (
          <>
            <Button size="small" variant="secondary" onClick={() => { setEditing(false); setValue(secret ? '' : s.value); setJsonError(null) }}>Annuler</Button>
            <Button size="small" onClick={prepare} disabled={value === '' && s.type !== 'array'}>Enregistrer</Button>
          </>
        ) : <Button size="small" variant="secondary" icon="edit" onClick={() => setEditing(true)}>Modifier</Button>}
        {s.technical && <Status tone="blue">Technique</Status>}
      </div>
      {confirm && (
        <ConfirmDialog
          title="Modifier le paramètre"
          object={meta.label}
          consequence={`Nouvelle valeur : ${secret ? SECRET : s.type === 'boolean' ? (value ? 'Activé' : 'Désactivé') : Array.isArray(value) ? JSON.stringify(value) : String(value)}. L’ancienne et la nouvelle valeur sont inscrites au journal.`}
          confirmLabel="Appliquer le changement"
          reasonLabel="Motif"
          reasonRequired
          onClose={() => setConfirm(false)}
          onConfirm={async (reason) => {
            const r = await api.patch<{ data: SettingsData }>('/settings', { key: s.key, value, reason })
            client.setQueryData(['settings'], r.data)
            setEditing(false)
            toast.success('Paramètre modifié', meta.label)
          }}
        />
      )}
    </div>
  )
}
