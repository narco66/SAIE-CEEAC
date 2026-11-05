import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../../lib/api'
import { LANG_LABEL } from '../../lib/format'
import { Button, ConfirmDialog, EmptyState, ErrorState, Kv, Loading, Notice, PageHeading, Status, Tabs, useToast } from '../../components/ui'
import { DynamicSources, ServiceAccounts } from './SettingsIntegrations'
import './admin.css'

interface Setting { key: string; tab: string; type: 'string' | 'integer' | 'boolean' | 'array'; value: unknown; editable: boolean; technical: boolean }
interface SettingsData {
  settings: Setting[]
  environment: { antivirus: string; queue: string; mail: string; mfa_enforced: boolean; timezone: string }
  notice: string
}

const SECRET = '••••••'
const TABS = ['Identité', 'Diffusion', 'Fonctionnement et audio', 'Salles', 'Conservation', 'Canaux', 'Sources dynamiques', 'Intégrations'] as const
type TabName = (typeof TABS)[number]

const META: Record<string, { label: string; help?: string; unit?: string; min?: number; max?: number }> = {
  'identity.institution_name': { label: 'Nom de l’institution', help: 'Affiché dans les en-têtes des écrans et des documents.' },
  'display.tagline': { label: 'Mention institutionnelle', help: 'Sous-titre des gabarits.' },
  'display.default_slide_s': { label: 'Durée par défaut d’une diapositive', unit: 's', min: 5, max: 300 },
  'display.language_rotation': { label: 'Rotation des langues', help: 'Ordre d’alternance des langues à l’écran.' },
  'operating.default_window': { label: 'Plage de fonctionnement par défaut', help: 'Jours et heures d’allumage (format JSON).' },
  'operating.audio_max_volume': { label: 'Volume audio maximal', unit: '%', min: 0, max: 100 },
  'rooms.pending_hold_hours': { label: 'Durée de maintien d’une demande en attente', unit: 'h', min: 1, max: 720 },
  'rooms.no_show_auto': { label: 'Libération automatique des réunions non honorées' },
  'retention.audit_months': { label: 'Conservation du journal d’audit', unit: 'mois', min: 1 },
  'retention.trash_days': { label: 'Conservation de la corbeille', unit: 'jours', min: 1 },
  'channels.smtp_enabled': { label: 'Envoi de courriels' },
  'channels.push_enabled': { label: 'Notifications push (P1)', help: 'Exige aussi les clés VAPID du serveur (php artisan saie:vapid). Les utilisateurs s’abonnent depuis « Mon compte ».' },
  'integrations.sso_enabled': { label: 'Authentification unique (SSO, P1)' },
  'integrations.calendar_connector': { label: 'Connecteur d’agenda', help: 'ics par défaut ; connecteurs Exchange/Google en P1 (SAL-11).' },
  'integrations.api_enabled': { label: 'API d’intégration externe (P1, API-07)' },
}

function displayValue(s: Setting): string {
  if (s.value === SECRET) return SECRET
  if (s.type === 'boolean') return s.value ? 'Activé' : 'Désactivé'
  if (s.key === 'display.language_rotation' && Array.isArray(s.value)) return (s.value as string[]).map((l) => LANG_LABEL[l] ?? l).join(' → ')
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
      <PageHeading title="Paramètres" description="Identité, diffusion, fonctionnement, salles, conservation, canaux et intégrations." />
      <Tabs<TabName> value={tab} onChange={setTab} items={TABS.map((t) => ({ value: t, label: t === 'Sources dynamiques' ? 'Sources dynamiques (P1)' : t }))} />
      <div className="two-columns report-grid" style={{ gridTemplateColumns: 'minmax(0, 2fr) minmax(260px, 1fr)' }}>
        <section className="panel table-panel">
          <div className="panel-title"><div><h2>{tab}</h2><p>{tab === 'Sources dynamiques' ? 'Composants dynamiques (PUB-10) : fonction P1 désactivée par défaut' : 'Une modification est appliquée après confirmation et inscrite au journal avec son motif'}</p></div></div>
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
          <Notice tone="info"><strong>Fonctions P1.</strong> {d.notice}</Notice>
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
  const [editing, setEditing] = useState(false)
  const [value, setValue] = useState<unknown>(secret ? '' : s.value)
  const [jsonError, setJsonError] = useState<string | null>(null)
  const [confirm, setConfirm] = useState(false)
  const [text, setText] = useState(s.type === 'array' && s.key !== 'display.language_rotation' ? JSON.stringify(s.value, null, 2) : '')

  const prepare = () => {
    if (s.type === 'array' && s.key !== 'display.language_rotation') {
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
  const langs = Array.isArray(value) ? (value as string[]) : []

  return (
    <div className="setting-row">
      <div>
        <strong>{meta.label}</strong>
        <small className="mono">{s.key}</small>
        {meta.help && <small>{meta.help}</small>}
      </div>
      <div>
        {!editing ? (
          <span className={s.type === 'array' && s.key !== 'display.language_rotation' ? 'mono' : ''}>{displayValue(s)} {secret && <Status tone="neutral">Secret masqué</Status>}</span>
        ) : s.type === 'boolean' ? (
          <select className="input" value={value ? '1' : '0'} onChange={(e) => setValue(e.target.value === '1')} aria-label={meta.label}><option value="1">Activé</option><option value="0">Désactivé</option></select>
        ) : s.type === 'integer' ? (
          <input className="input" type="number" min={meta.min} max={meta.max} value={String(value ?? '')} onChange={(e) => setValue(e.target.value === '' ? '' : Number(e.target.value))} aria-label={meta.label} />
        ) : s.key === 'display.language_rotation' ? (
          <div className="chip-row" role="group" aria-label={meta.label}>
            {['fr', 'en', 'pt', 'es'].map((l) => (
              <label key={l} className="radio-chip">
                <input type="checkbox" checked={langs.includes(l)} onChange={(e) => setValue(e.target.checked ? [...langs, l] : langs.filter((x) => x !== l))} />
                {LANG_LABEL[l]}{langs.includes(l) ? ` (${langs.indexOf(l) + 1})` : ''}
              </label>
            ))}
          </div>
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
