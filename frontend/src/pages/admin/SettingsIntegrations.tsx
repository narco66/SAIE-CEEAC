import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { fmtDateTime } from '../../lib/format'
import { Button, ConfirmDialog, EmptyState, ErrorState, Field, Loading, Modal, Notice, Status, useToast } from '../../components/ui'

export interface Source {
  id: string; code: string; name: string; type: string; url: string | null; config?: Record<string, string | null> | null
  is_active: boolean; frequency_minutes: number | null; last_fetched_at: string | null; last_status: string | null
}

const SOURCE_TYPES: Record<string, string> = { clock: 'Horloge', countdown: 'Compte à rebours', rss: 'Flux RSS', json: 'Flux JSON', weather: 'Météo' }
const URL_TYPES = ['rss', 'json', 'weather']
/** Champs de configuration lus par le serveur pour chaque type (App\Services\DynamicSources). */
const CONFIG_FIELDS: Record<string, [string, string, string?][]> = {
  countdown: [['label', 'Libellé affiché'], ['target', 'Échéance', 'Date au format AAAA-MM-JJ']],
  json: [['items_path', 'Chemin de la liste', 'Par défaut : items'], ['title_field', 'Champ du titre', 'Par défaut : title'], ['date_field', 'Champ de la date', 'Par défaut : date']],
  weather: [['place', 'Lieu affiché', 'Par défaut : Libreville'], ['temperature_path', 'Chemin de la température', 'Par défaut : current.temperature_2m']],
}

/** Liste blanche des sources dynamiques (V39, PUB-10) : collecte côté serveur en HTTPS public uniquement (SSRF refusé). */
export function DynamicSources() {
  const { can } = useAuth()
  const client = useQueryClient()
  const toast = useToast()
  const editable = can('settings.technical')
  const query = useQuery({ queryKey: ['dynamic-sources'], queryFn: ({ signal }) => api.get<{ data: Source[] }>('/dynamic-sources', undefined, signal).then((r) => r.data) })
  const [editing, setEditing] = useState<Source | 'new' | null>(null)
  const [testing, setTesting] = useState<string | null>(null)

  const refresh = () => {
    client.invalidateQueries({ queryKey: ['dynamic-sources'] })
    client.invalidateQueries({ queryKey: ['settings'] })
  }
  const runTest = async (s: Source) => {
    setTesting(s.id)
    try {
      const r = await api.post<{ data: { status: string; payload: Record<string, unknown> } }>(`/dynamic-sources/${s.id}/test`)
      if (r.data.status === 'ok') {
        toast.success('Collecte réussie', s.name)
      } else {
        toast.error(String(r.data.payload.error ?? s.name), 'Collecte en échec')
      }
      refresh()
    } catch (e) {
      toast.error(e, 'Collecte impossible')
    } finally {
      setTesting(null)
    }
  }

  return (
    <>
      <div style={{ padding: '0 19px 12px', display: 'grid', gap: 10 }}>
        <Notice tone="info">Les sources sont collectées par le serveur (HTTPS public uniquement, adresses internes refusées) puis mises en cache ; les lecteurs ne contactent jamais ces adresses. Une source inactive n’est plus diffusée.</Notice>
        {editable && <div><Button size="small" icon="plus" onClick={() => setEditing('new')}>Déclarer une source</Button></div>}
      </div>
      {query.isLoading ? <Loading /> : query.isError || !query.data ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : query.data.length === 0 ? <EmptyState title="Aucune source déclarée" /> : (
        <div className="table-scroll">
          <table>
            <thead><tr><th scope="col">Source</th><th scope="col">Type</th><th scope="col">Adresse</th><th scope="col">Fréquence</th><th scope="col">Dernière collecte</th><th scope="col">État</th>{editable && <th scope="col"><span className="sr-only">Actions</span></th>}</tr></thead>
            <tbody>
              {query.data.map((s) => (
                <tr key={s.id}>
                  <td><strong>{s.name}</strong><small className="mono">{s.code}</small></td>
                  <td>{SOURCE_TYPES[s.type] ?? s.type}</td>
                  <td className="mono" style={{ maxWidth: 220, overflowWrap: 'anywhere' }}>{s.url ?? '—'}</td>
                  <td>{s.frequency_minutes ? `${s.frequency_minutes} min` : '—'}</td>
                  <td>{s.last_fetched_at ? fmtDateTime(s.last_fetched_at) : 'Jamais'}{s.last_status && <small>{s.last_status === 'ok' ? 'Réussie' : s.last_status === 'error' ? 'En échec' : s.last_status}</small>}</td>
                  <td>{s.is_active ? <Status tone="success">Active</Status> : <Status tone="neutral">Inactive</Status>}</td>
                  {editable && (
                    <td><div className="row-actions">
                      <Button size="small" variant="secondary" icon="play" busy={testing === s.id} onClick={() => runTest(s)}>Tester</Button>
                      <Button size="small" variant="secondary" icon="edit" onClick={() => setEditing(s)}>Modifier</Button>
                    </div></td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {editing && <SourceForm source={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onDone={() => { refresh(); toast.success(editing === 'new' ? 'Source déclarée' : 'Source modifiée') }} />}
    </>
  )
}

function SourceForm({ source, onClose, onDone }: { source: Source | null; onClose: () => void; onDone: () => void }) {
  const [code, setCode] = useState(source?.code ?? '')
  const [name, setName] = useState(source?.name ?? '')
  const [type, setType] = useState(source?.type ?? 'rss')
  const [url, setUrl] = useState(source?.url ?? '')
  const [frequency, setFrequency] = useState(String(source?.frequency_minutes ?? 30))
  const [active, setActive] = useState(source?.is_active ?? false)
  const [config, setConfig] = useState<Record<string, string>>(Object.fromEntries(Object.entries(source?.config ?? {}).map(([k, v]) => [k, v ?? ''])))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const needsUrl = URL_TYPES.includes(type)

  const save = async () => {
    setBusy(true)
    setError(null)
    const fields = CONFIG_FIELDS[type] ?? []
    const body = {
      ...(source ? {} : { code: code.trim() }), name: name.trim(), type, url: needsUrl ? url.trim() || null : null,
      frequency_minutes: Number(frequency), is_active: active,
      config: Object.fromEntries(fields.map(([key]) => [key, config[key]?.trim() || null]).filter(([, v]) => v !== null)),
    }
    try {
      if (source) {
        await api.patch(`/dynamic-sources/${source.id}`, body)
      } else {
        await api.post('/dynamic-sources', body)
      }
      onDone()
      onClose()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal title={source ? 'Modifier la source' : 'Déclarer une source'} kicker="LISTE BLANCHE" wide onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button busy={busy} disabled={!name.trim() || (!source && !code.trim()) || (needsUrl && !url.trim())} onClick={save}>Enregistrer</Button>
    </>}>
      <div className="form-grid">
        <Field label="Code" required={!source} error={error?.field('code')} help="Minuscules, chiffres, tiret ou souligné ; non modifiable ensuite">
          <input value={code} maxLength={40} disabled={!!source} onChange={(e) => setCode(e.target.value.toLowerCase())} aria-invalid={!!error?.field('code')} />
        </Field>
        <Field label="Nom" required error={error?.field('name')}><input value={name} maxLength={120} onChange={(e) => setName(e.target.value)} aria-invalid={!!error?.field('name')} /></Field>
        <Field label="Type" required error={error?.field('type')}>
          <select value={type} onChange={(e) => setType(e.target.value)}>{Object.entries(SOURCE_TYPES).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
        </Field>
        <Field label="Fréquence de collecte (minutes)" error={error?.field('frequency_minutes')} help="Entre 5 et 1 440">
          <input type="number" min={5} max={1440} value={frequency} onChange={(e) => setFrequency(e.target.value)} />
        </Field>
        {needsUrl && (
          <Field label="Adresse HTTPS" required error={error?.field('url')} help="Adresse publique uniquement ; les réseaux internes sont refusés" wide>
            <input type="url" value={url} maxLength={500} placeholder="https://" onChange={(e) => setUrl(e.target.value)} aria-invalid={!!error?.field('url')} />
          </Field>
        )}
        {(CONFIG_FIELDS[type] ?? []).map(([key, label, help]) => (
          <Field key={key} label={label} help={help} error={error?.field(`config.${key}`)}>
            <input value={config[key] ?? ''} maxLength={200} onChange={(e) => setConfig({ ...config, [key]: e.target.value })} />
          </Field>
        ))}
        <label className="check-label wide"><input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} /> Source active (collectée et diffusée)</label>
      </div>
      {error && <div style={{ marginTop: 12 }}><Notice tone="danger">{error.message}</Notice></div>}
    </Modal>
  )
}

interface ServiceAccount { id: string; name: string; description: string | null; scopes: string[]; status: string; last_used_at: string | null; created_at: string | null }

/** Comptes de service de l'API d'intégration (API-07) : jeton affiché une seule fois, portées limitées, révocation tracée. */
export function ServiceAccounts({ apiEnabled }: { apiEnabled: boolean }) {
  const { can } = useAuth()
  const client = useQueryClient()
  const toast = useToast()
  const query = useQuery({
    queryKey: ['service-accounts'],
    queryFn: ({ signal }) => api.get<{ data: ServiceAccount[]; scopes: Record<string, string> }>('/service-accounts', undefined, signal),
    enabled: can('settings.technical'),
  })
  const [dialog, setDialog] = useState<{ kind: 'create' } | { kind: 'rotate' | 'revoke'; account: ServiceAccount } | null>(null)
  const [token, setToken] = useState<{ name: string; value: string } | null>(null)
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [scopes, setScopes] = useState<string[]>([])

  if (!can('settings.technical')) return null
  const refresh = () => client.invalidateQueries({ queryKey: ['service-accounts'] })
  const scopeLabels = query.data?.scopes ?? {}

  return (
    <div className="panel-body" style={{ borderTop: '1px solid #edf0f3' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10, marginBottom: 10 }}>
        <div><h3 className="small" style={{ fontWeight: 700 }}>Comptes de service</h3><small className="muted">Accès en lecture à /api/v1/integration ; le jeton n’est affiché qu’une fois.</small></div>
        <Button size="small" icon="plus" onClick={() => { setName(''); setDescription(''); setScopes([]); setDialog({ kind: 'create' }) }}>Créer un compte</Button>
      </div>
      {!apiEnabled && <div style={{ marginBottom: 10 }}><Notice tone="warning">Le paramètre « API d’intégration externe » est désactivé : les jetons existants sont refusés tant qu’il n’est pas activé.</Notice></div>}
      {query.isLoading ? <Loading /> : query.isError || !query.data ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : query.data.data.length === 0 ? <EmptyState title="Aucun compte de service" /> : (
        <div className="table-scroll">
          <table>
            <thead><tr><th scope="col">Compte</th><th scope="col">Portées</th><th scope="col">Dernier usage</th><th scope="col">État</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
            <tbody>
              {query.data.data.map((a) => (
                <tr key={a.id}>
                  <td><strong>{a.name}</strong>{a.description && <small>{a.description}</small>}</td>
                  <td>{a.scopes.map((s) => <small key={s}>{scopeLabels[s] ?? s}</small>)}</td>
                  <td>{a.last_used_at ? fmtDateTime(a.last_used_at) : 'Jamais'}</td>
                  <td>{a.status === 'active' ? <Status tone="success">Actif</Status> : <Status tone="neutral">Révoqué</Status>}</td>
                  <td>{a.status === 'active' && (
                    <div className="row-actions">
                      <Button size="small" variant="secondary" icon="refresh" onClick={() => setDialog({ kind: 'rotate', account: a })}>Renouveler</Button>
                      <Button size="small" variant="secondary" icon="x" onClick={() => setDialog({ kind: 'revoke', account: a })}>Révoquer</Button>
                    </div>
                  )}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {dialog?.kind === 'create' && (
        <ConfirmDialog title="Créer un compte de service" consequence="Un jeton d’accès est généré avec les portées choisies. Il ne sera affiché qu’une fois." confirmLabel="Créer le compte"
          reasonLabel="Motif (système demandeur, référence de la demande)" reasonRequired onClose={() => setDialog(null)}
          onConfirm={async (reason) => {
            if (!name.trim() || scopes.length === 0) {
              throw new ApiError(422, { message: 'Le nom et au moins une portée sont obligatoires.' })
            }
            const r = await api.post<{ data: ServiceAccount; token: string }>('/service-accounts', { name: name.trim(), description: description.trim() || null, scopes, reason })
            setToken({ name: r.data.name, value: r.token })
            refresh()
          }}>
          <div className="form-grid" style={{ marginTop: 12 }}>
            <Field label="Nom du compte" required wide><input value={name} maxLength={120} onChange={(e) => setName(e.target.value)} /></Field>
            <Field label="Description" wide><input value={description} maxLength={250} onChange={(e) => setDescription(e.target.value)} /></Field>
            <fieldset className="wide">
              <legend>Portées *</legend>
              {Object.entries(scopeLabels).map(([key, label]) => (
                <label key={key} className="check-label"><input type="checkbox" checked={scopes.includes(key)} onChange={(e) => setScopes(e.target.checked ? [...scopes, key] : scopes.filter((s) => s !== key))} /> {label} <span className="mono muted">{key}</span></label>
              ))}
            </fieldset>
          </div>
        </ConfirmDialog>
      )}
      {dialog?.kind === 'rotate' && (
        <ConfirmDialog title="Renouveler le jeton" object={dialog.account.name} consequence="L’ancien jeton cesse immédiatement de fonctionner ; le nouveau n’est affiché qu’une fois." confirmLabel="Renouveler"
          reasonLabel="Motif" reasonRequired onClose={() => setDialog(null)}
          onConfirm={async (reason) => {
            const r = await api.post<{ data: ServiceAccount; token: string }>(`/service-accounts/${dialog.account.id}/rotate`, { reason })
            setToken({ name: r.data.name, value: r.token })
            refresh()
          }} />
      )}
      {dialog?.kind === 'revoke' && (
        <ConfirmDialog title="Révoquer le compte" object={dialog.account.name} danger consequence="Tous ses jetons sont supprimés ; le système appelant perd l’accès immédiatement. Action définitive." confirmLabel="Révoquer"
          reasonLabel="Motif" reasonRequired onClose={() => setDialog(null)}
          onConfirm={async (reason) => {
            await api.post(`/service-accounts/${dialog.account.id}/revoke`, { reason })
            refresh()
            toast.success('Compte révoqué', dialog.account.name)
          }} />
      )}
      {token && (
        <Modal title="Jeton d’accès" kicker={token.name} onClose={() => setToken(null)} footer={<Button onClick={() => setToken(null)}>J’ai copié le jeton</Button>}>
          <Notice tone="warning">Copiez ce jeton maintenant et transmettez-le par un canal sûr : il ne sera plus jamais affiché.</Notice>
          <div style={{ display: 'flex', gap: 8, marginTop: 12, alignItems: 'center' }}>
            <input className="input mono" readOnly value={token.value} aria-label="Jeton d’accès" onFocus={(e) => e.target.select()} style={{ flex: 1 }} />
            <Button size="small" variant="secondary" onClick={() => { void navigator.clipboard?.writeText(token.value).then(() => toast.success('Jeton copié')) }}>Copier</Button>
          </div>
        </Modal>
      )}
    </div>
  )
}
