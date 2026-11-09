import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { Button, EmptyState, ErrorState, Field, Loading, Modal, Notice, PageHeading, Status, useToast } from '../../components/ui'

export interface ScreenProfile {
  id: string; code: string; name: string; description: string | null; settings: Record<string, unknown>; is_active: boolean; version: number
  screens_count?: number; groups_count?: number
}
interface SettingDef { key: string; label: string; capability: string | null }

const LANGS: Record<string, string> = { fr: 'Français', en: 'Anglais', pt: 'Portugais', es: 'Espagnol' }
const BOOLEAN = ['standby_outside_window', 'fullscreen', 'software_rotation']
/** Capacités matérielles requises par certains paramètres : affichées pour ne jamais présenter un réglage comme garanti. */
const CAPABILITY_NOTE: Record<string, string> = {
  panel_power: 'Exige un canal de pilotage de la dalle (agent local) ; sinon signalé « non pris en charge ».',
  panel_picture: 'Exige un pilotage d’image de la dalle, non fourni par le lecteur actuel : toujours signalé « non pris en charge ».',
}

/** Libellés des paramètres, identiques à ceux du serveur (ScreenProfile::SETTINGS). */
export const SETTING_LABEL: Record<string, string> = {
  volume_max: 'Volume maximal (%)', languages: 'Langues des vues non programmées', language_rotation_s: 'Rotation des langues (s)', sync_interval_s: 'Intervalle de synchronisation (s)',
  heartbeat_interval_s: 'Intervalle de remontée d’état (s)', reconnect_max_s: 'Délai maximal entre deux tentatives de reconnexion (s)', standby_outside_window: 'Mise en veille de la dalle hors plage',
  fullscreen: 'Plein écran', brightness: 'Luminosité (%)', contrast: 'Contraste (%)',
  software_rotation: 'Rotation logicielle selon l’orientation', idle_standby_min: 'Veille après inactivité (min)', server_url: 'Serveur de rattachement',
}

export function formatSetting(key: string, value: unknown): string {
  if (key === 'languages' && Array.isArray(value)) return value.map((l) => LANGS[l] ?? l).join(', ')
  if (typeof value === 'boolean') return value ? 'Oui' : 'Non'
  return String(value)
}

export function useScreenProfiles() {
  return useQuery({
    queryKey: ['screen-profiles'],
    queryFn: ({ signal }) => api.get<{ data: ScreenProfile[]; meta: { settings: SettingDef[] } }>('/screen-profiles', undefined, signal),
  })
}

/** §7.3 Profils de configuration réutilisables, affectés aux écrans ou aux groupes. */
export default function ScreenProfiles() {
  const { can } = useAuth()
  const query = useScreenProfiles()
  const [editing, setEditing] = useState<ScreenProfile | 'new' | null>(null)
  const defs = query.data?.meta.settings ?? []
  return (
    <>
      <PageHeading title="Profils de configuration" description="Paramètres d’affichage et de communication réutilisables, affectés à un écran ou à un groupe. Le lecteur confirme la configuration appliquée."
        actions={can('screens.manage') ? <Button icon="plus" onClick={() => setEditing('new')}>Nouveau profil</Button> : undefined} />
      {query.isLoading ? <Loading /> : query.isError ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : !query.data?.data.length ? (
        <section className="panel"><EmptyState title="Aucun profil" text="Créez un profil (hall d’accueil, couloir, salle de conférence…) puis affectez-le à des écrans ou à des groupes." /></section>
      ) : (
        <section className="panel table-panel">
          <div className="table-scroll">
            <table>
              <thead><tr><th>Profil</th><th>Paramètres</th><th>Utilisation</th><th>Statut</th><th><span className="sr-only">Actions</span></th></tr></thead>
              <tbody>
                {query.data.data.map((p) => (
                  <tr key={p.id}>
                    <td><strong>{p.name}</strong><small className="mono">{p.code} · v{p.version}</small>{p.description && <small>{p.description}</small>}</td>
                    <td className="small">{Object.entries(p.settings).map(([k, v]) => `${defs.find((d) => d.key === k)?.label ?? k} : ${formatSetting(k, v)}`).join(' · ') || 'Aucun (comportement par défaut)'}</td>
                    <td>{p.screens_count ?? 0} écran(s) · {p.groups_count ?? 0} groupe(s)</td>
                    <td>{p.is_active ? <Status tone="success">Actif</Status> : <Status tone="neutral">Désactivé</Status>}</td>
                    <td>{can('screens.manage') && <Button size="small" variant="secondary" icon="edit" onClick={() => setEditing(p)}>Modifier</Button>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
      {editing && <ProfileModal profile={editing === 'new' ? null : editing} defs={defs} onClose={() => setEditing(null)} />}
    </>
  )
}

function ProfileModal({ profile, defs, onClose }: { profile: ScreenProfile | null; defs: SettingDef[]; onClose: () => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const [code, setCode] = useState(profile?.code ?? '')
  const [name, setName] = useState(profile?.name ?? '')
  const [description, setDescription] = useState(profile?.description ?? '')
  const [active, setActive] = useState(profile?.is_active ?? true)
  const [settings, setSettings] = useState<Record<string, unknown>>({ ...(profile?.settings ?? {}) })
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const set = (key: string, value: unknown) => setSettings((s) => {
    const next = { ...s }
    if (value === '' || value === null || (Array.isArray(value) && value.length === 0)) delete next[key]
    else next[key] = value
    return next
  })
  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      const body = { code, name, description: description || null, is_active: active, settings }
      if (profile) await api.patch(`/screen-profiles/${profile.id}`, body)
      else await api.post('/screen-profiles', body)
      await client.invalidateQueries({ queryKey: ['screen-profiles'] })
      toast.success(profile ? 'Profil modifié' : 'Profil créé', name)
      onClose()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title={profile ? 'Modifier le profil' : 'Nouveau profil'} kicker="CONFIGURATION" wide onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button><Button icon="check" busy={busy} onClick={submit}>Enregistrer</Button>
    </>}>
      <div className="form-grid">
        <Field label="Code" required error={error?.field('code')} help="Majuscules, chiffres et tirets"><input value={code} disabled={!!profile} maxLength={40} onChange={(e) => setCode(e.target.value.toUpperCase())} /></Field>
        <Field label="Nom" required error={error?.field('name')}><input value={name} maxLength={120} onChange={(e) => setName(e.target.value)} /></Field>
        <Field label="Description" wide error={error?.field('description')}><textarea value={description} maxLength={500} onChange={(e) => setDescription(e.target.value)} /></Field>
        {profile && <Field label="Statut"><select value={active ? '1' : '0'} onChange={(e) => setActive(e.target.value === '1')}><option value="1">Actif</option><option value="0">Désactivé</option></select></Field>}
      </div>
      <h3 className="sub-title">Paramètres (laisser vide : comportement par défaut)</h3>
      <div className="form-grid">
        {defs.map((d) => (
          <Field key={d.key} label={d.label} error={error?.field(`settings.${d.key}`) ?? error?.field(`settings.${d.key}.0`)} help={d.capability ? CAPABILITY_NOTE[d.capability] : undefined}>
            {d.key === 'languages' ? (
              <div className="chip-row" role="group" aria-label={d.label}>
                {Object.entries(LANGS).map(([c, l]) => {
                  const list = (settings.languages as string[] | undefined) ?? []
                  return <label key={c} className="check-label"><input type="checkbox" checked={list.includes(c)} onChange={(e) => set('languages', e.target.checked ? [...list, c] : list.filter((x) => x !== c))} /> {l}</label>
                })}
              </div>
            ) : BOOLEAN.includes(d.key) ? (
              <select value={settings[d.key] === undefined ? '' : settings[d.key] ? '1' : '0'} onChange={(e) => set(d.key, e.target.value === '' ? '' : e.target.value === '1')}>
                <option value="">Par défaut</option><option value="1">Oui</option><option value="0">Non</option>
              </select>
            ) : d.key === 'server_url' ? (
              <input type="url" placeholder="https://saie.exemple.org" value={settings[d.key] === undefined ? '' : String(settings[d.key])} onChange={(e) => set(d.key, e.target.value)} />
            ) : (
              <input type="number" value={settings[d.key] === undefined ? '' : String(settings[d.key])} onChange={(e) => set(d.key, e.target.value === '' ? '' : Number(e.target.value))} />
            )}
          </Field>
        ))}
      </div>
      {error && !Object.keys(error.errors).length && <Notice tone="danger">{error.message}</Notice>}
    </Modal>
  )
}
