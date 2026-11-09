import AssignPlaylistDialog from './AssignPlaylistDialog'
import { useMemo, useState } from 'react'
import { useScreenProfiles } from './ScreenProfiles'
import { Link } from 'react-router'
import { useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { fmtRelative, NETWORK_STATUS } from '../../lib/format'
import { Button, ConfirmDialog, EmptyState, ErrorState, Field, Loading, Modal, Notice, PageHeading, Status, StatusOf, Toolbar, useToast } from '../../components/ui'
import { KIND_LABEL, networkOf, useAllScreens, useReferentials, useScreenGroups, type Screen, type ScreenGroup } from './shared'

/** V10 Groupes de diffusion : suppression refusée si une campagne active en dépend. */
export default function Groups() {
  const { me, can } = useAuth()
  const client = useQueryClient()
  const toast = useToast()
  const groups = useScreenGroups()
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<ScreenGroup | 'new' | null>(null)
  const [previewing, setPreviewing] = useState<ScreenGroup | null>(null)
  const [deleting, setDeleting] = useState<ScreenGroup | null>(null)
  const [blocked, setBlocked] = useState<{ group: string; campaigns: string[] } | null>(null)
  const [assigning, setAssigning] = useState<ScreenGroup | null>(null)
  const manage = can('groups.manage')

  const items = (groups.data ?? []).filter((g) => !search || `${g.code} ${g.name} ${g.description ?? ''}`.toLowerCase().includes(search.toLowerCase()))

  return (
    <>
      <PageHeading title="Groupes d’écrans" description="Ensembles nommés d’écrans utilisés comme cibles de campagnes et d’alertes." demo={me.demo_mode}
        actions={manage && <Button icon="plus" onClick={() => setEditing('new')}>Nouveau groupe</Button>} />
      {blocked && (
        <div style={{ marginBottom: 12 }}>
          <Notice tone="warning">
            <strong>Suppression impossible du groupe « {blocked.group} »</strong> : des campagnes programmées, actives ou suspendues le ciblent — {blocked.campaigns.join(', ')}. Retirez ce groupe des campagnes ou attendez leur expiration. <Link className="link-text" to="/affichage/campagnes">Ouvrir les campagnes</Link>
          </Notice>
        </div>
      )}
      <section className="panel table-panel">
        <Toolbar value={search} onChange={setSearch} placeholder="Code, nom ou description…" />
        {groups.isLoading ? <Loading /> : groups.isError ? <ErrorState error={groups.error} onRetry={() => groups.refetch()} /> : items.length === 0 ? (
          <EmptyState title="Aucun groupe" text={search ? 'Aucun groupe ne correspond à la recherche.' : 'Créez un groupe pour cibler plusieurs écrans à la fois.'} />
        ) : (
          <div className="table-scroll">
            <table>
              <thead><tr><th>Groupe</th><th>Description</th><th>Membres</th><th>Supervision</th><th>Campagnes en cours</th><th><span className="sr-only">Actions</span></th></tr></thead>
              <tbody>
                {items.map((g) => (
                  <tr key={g.id}>
                    <td><strong>{g.name}</strong><small className="mono">{g.code}</small>{g.type === 'dynamic' && <Status tone="blue">Dynamique</Status>}</td>
                    <td>{g.description ?? '—'}{g.type === 'dynamic' && g.members_computed_at && <small>Membres recalculés {fmtRelative(g.members_computed_at)}</small>}</td>
                    <td>{g.screens_count} écran(s)</td>
                    <td>{g.stats ? <>{g.stats.online}/{g.screens_count} en ligne{g.stats.anomaly > 0 && <small className="red-text">{g.stats.anomaly} en anomalie</small>}{g.stats.unpaired > 0 && <small>{g.stats.unpaired} sans lecteur</small>}
                      <small>7 j : joignabilité {g.stats.availability_7d === null || g.stats.availability_7d === undefined ? 'non mesurée' : `${String(g.stats.availability_7d).replace('.', ',')} %`} · {g.stats.plays_7d ?? 0} lecture(s) · {g.stats.incidents_7d ?? 0} incident(s)</small></> : '—'}</td>
                    <td>{g.active_campaigns > 0 ? <StatusOf map={{ x: [`${g.active_campaigns} campagne(s)`, 'blue'] }} value="x" /> : 'Aucune'}</td>
                    <td>
                      <div className="row-actions">
                        <Button variant="secondary" size="small" icon="eye" onClick={() => setPreviewing(g)}>Cibles effectives</Button>
                        {can('campaigns.manage') && <Button variant="secondary" size="small" icon="list" onClick={() => setAssigning(g)}>Affecter une liste</Button>}
                        {manage && <Button variant="secondary" size="small" icon="edit" onClick={() => setEditing(g)}>Modifier</Button>}
                        {manage && <Button variant="ghost" size="small" icon="trash" onClick={() => setDeleting(g)} ariaLabel={`Supprimer le groupe ${g.name}`}>Supprimer</Button>}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
      {editing && <GroupModal group={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
      {assigning && <AssignPlaylistDialog target={{ type: 'group', id: assigning.id }} label={assigning.name} onClose={() => setAssigning(null)} />}
      {previewing && <TargetsModal group={previewing} onClose={() => setPreviewing(null)} />}
      {deleting && (
        <ConfirmDialog
          title="Supprimer le groupe" object={`${deleting.name} (${deleting.code}) · ${deleting.screens_count} écran(s)`} danger confirmLabel="Supprimer le groupe"
          consequence="Les écrans ne sont pas supprimés : seul le regroupement disparaît. La suppression est refusée si une campagne en cours cible ce groupe."
          onClose={() => setDeleting(null)}
          onConfirm={async () => {
            try {
              await api.delete(`/screen-groups/${deleting.id}`)
            } catch (e) {
              if (e instanceof ApiError && e.code === 'GROUP_IN_USE') {
                const campaigns = (e.details.campaigns as string[] | undefined) ?? []
                setBlocked({ group: deleting.name, campaigns })
                throw new ApiError(e.status, { code: e.code, message: `${e.message} Campagnes concernées : ${campaigns.join(', ') || 'non précisées'}.` })
              }
              throw e
            }
            setBlocked(null)
            toast.success('Groupe supprimé')
            client.invalidateQueries({ queryKey: ['screen-groups'] })
          }}
        />
      )}
    </>
  )
}

function GroupModal({ group, onClose }: { group: ScreenGroup | null; onClose: () => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const screens = useAllScreens()
  const [code, setCode] = useState(group?.code ?? '')
  const [name, setName] = useState(group?.name ?? '')
  const [description, setDescription] = useState(group?.description ?? '')
  const [selected, setSelected] = useState<string[]>(group?.screens.map((s) => s.id) ?? [])
  // PLY-08 : plage du groupe, appliquée à ses écrans qui n'ont pas de plage propre.
  const [ownWindow, setOwnWindow] = useState(!!group?.operating_window)
  const [days, setDays] = useState<number[]>(group?.operating_window?.days ?? [1, 2, 3, 4, 5])
  const [on, setOn] = useState(group?.operating_window?.on ?? '08:00')
  const [off, setOff] = useState(group?.operating_window?.off ?? '18:00')
  const [profileId, setProfileId] = useState(group?.profile_id ?? '')
  const [type, setType] = useState<'static' | 'dynamic'>(group?.type ?? 'static')
  const [criteria, setCriteria] = useState<Record<string, string[]>>(() => Object.fromEntries(Object.entries(group?.criteria ?? {}).filter(([, v]) => Array.isArray(v))) as Record<string, string[]>)
  const refs = useReferentials()
  const toggleCriterion = (key: string, value: string) => setCriteria((c) => {
    const list = c[key] ?? []
    const next = list.includes(value) ? list.filter((x) => x !== value) : [...list, value]
    const copy = { ...c, [key]: next }
    if (!next.length) delete copy[key]
    return copy
  })
  const profiles = useScreenProfiles()
  const [filter, setFilter] = useState('')
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  const byFloor = useMemo(() => {
    const map = new Map<string, { label: string; level: number; screens: Screen[] }>()
    const t = filter.trim().toLowerCase()
    for (const s of screens.data?.data ?? []) {
      if (t && !`${s.code} ${s.name} ${s.location?.name ?? ''}`.toLowerCase().includes(t)) continue
      const key = s.location?.floor?.id ?? 'none'
      if (!map.has(key)) map.set(key, { label: s.location?.floor?.label ?? 'Sans niveau', level: s.location?.floor?.level ?? -99, screens: [] })
      map.get(key)!.screens.push(s)
    }
    return [...map.entries()].sort((a, b) => b[1].level - a[1].level)
  }, [screens.data, filter])

  const toggle = (id: string) => setSelected((l) => (l.includes(id) ? l.filter((x) => x !== id) : [...l, id]))
  const toggleFloor = (ids: string[]) => {
    const all = ids.every((id) => selected.includes(id))
    setSelected((l) => (all ? l.filter((x) => !ids.includes(x)) : [...new Set([...l, ...ids])]))
  }

  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      // Les options de la plage non éditées ici (jours fériés) sont conservées telles qu'enregistrées.
      const operating_window = ownWindow ? { ...(group?.operating_window ?? {}), days, on, off } : null
      const members = type === 'dynamic' ? { criteria } : { screen_ids: selected }
      if (group) await api.patch(`/screen-groups/${group.id}`, { name, description: description || null, ...members, operating_window, profile_id: profileId || null }, { 'If-Match': String(group.version) })
      else await api.post('/screen-groups', { code, name, description: description || null, type, ...members, operating_window, profile_id: profileId || null })
      client.invalidateQueries({ queryKey: ['screen-groups'] })
      client.invalidateQueries({ queryKey: ['screens'] })
      toast.success(group ? 'Groupe mis à jour' : 'Groupe créé')
      onClose()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal title={group ? `Modifier « ${group.name} »` : 'Nouveau groupe'} kicker="GROUPE DE DIFFUSION" wide onClose={onClose} footer={<>
      <span className="muted small" style={{ marginRight: 'auto' }}>{selected.length} écran(s) sélectionné(s)</span>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button icon="check" onClick={submit} busy={busy} disabled={!name || (!group && !code)}>{group ? 'Enregistrer' : 'Créer le groupe'}</Button>
    </>}>
      <div className="form-grid">
        <Field label="Code" required={!group} error={error?.field('code')} help={group ? 'Le code n’est pas modifiable.' : 'Identifiant court et unique'}>
          <input value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} disabled={!!group} maxLength={40} aria-invalid={!!error?.field('code')} />
        </Field>
        <Field label="Nom" required error={error?.field('name')}>
          <input value={name} onChange={(e) => setName(e.target.value)} maxLength={120} aria-invalid={!!error?.field('name')} />
        </Field>
        <Field label="Description" wide error={error?.field('description')}>
          <textarea value={description} onChange={(e) => setDescription(e.target.value)} maxLength={500} />
        </Field>
        <Field label="Profil de configuration" wide error={error?.field('profile_id')} help="Appliqué aux écrans du groupe qui n’ont pas de profil propre">
          <select value={profileId} onChange={(e) => setProfileId(e.target.value)}>
            <option value="">Aucun</option>
            {(profiles.data?.data ?? []).filter((p) => p.is_active || p.id === profileId).map((p) => <option key={p.id} value={p.id}>{p.name} ({p.code})</option>)}
          </select>
        </Field>
        <fieldset className="wide">
          <legend>Plage de fonctionnement du groupe</legend>
          <label><input type="checkbox" checked={ownWindow} onChange={(e) => setOwnWindow(e.target.checked)} /> Définir une plage pour ce groupe (sinon : plage générale des paramètres). La plage propre d’un écran reste prioritaire.</label>
          {ownWindow && (
            <div className="form-grid" style={{ marginTop: 8 }}>
              <fieldset className="wide">
                <legend>Jours</legend>
                <div className="radio-row">
                  {['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'].map((d, i) => (
                    <label key={d}><input type="checkbox" checked={days.includes(i + 1)} onChange={(e) => setDays(e.target.checked ? [...days, i + 1].sort() : days.filter((x) => x !== i + 1))} /> {d}</label>
                  ))}
                </div>
                {error?.field('operating_window.days') && <span className="field-error">{error.field('operating_window.days')}</span>}
              </fieldset>
              <Field label="Allumage" error={error?.field('operating_window.on')}><input type="time" value={on} onChange={(e) => setOn(e.target.value)} /></Field>
              <Field label="Extinction" error={error?.field('operating_window.off')}><input type="time" value={off} onChange={(e) => setOff(e.target.value)} /></Field>
            </div>
          )}
        </fieldset>
      </div>
      {!group && (
        <Field label="Composition" help="Un groupe dynamique est recalculé à chaque minute : nouveaux écrans, déplacements, état de connexion.">
          <select value={type} onChange={(e) => setType(e.target.value as 'static' | 'dynamic')}>
            <option value="static">Statique : écrans choisis</option><option value="dynamic">Dynamique : écrans répondant à des critères</option>
          </select>
        </Field>
      )}
      {type === 'dynamic' ? (
        <>
          <h3 className="sub-title">Critères (tous doivent être remplis ; aucun critère : tout le parc hors archivés)</h3>
          {[
            ['building_ids', 'Bâtiments', (refs.data?.buildings ?? []).map((b) => [b.id, b.name])],
            ['floor_ids', 'Niveaux', (refs.data?.floors ?? []).map((f) => [f.id, f.label])],
            ['zone_ids', 'Zones d’affichage', (refs.data?.zones ?? []).map((z) => [z.id, z.name])],
            ['kinds', 'Types d’écran', Object.entries(KIND_LABEL)],
            ['statuses', 'Statuts', [['active', 'En service'], ['maintenance', 'En maintenance'], ['inactive', 'Désactivé']]],
            ['network', 'État de connexion', [['online', 'En ligne'], ['suspect', 'Suspect'], ['unavailable', 'Indisponible'], ['unpaired', 'Sans lecteur']]],
          ].map(([key, label, options]) => (
            <fieldset key={key as string} className="criteria-set">
              <legend>{label as string}</legend>
              <div className="chip-row">
                {(options as string[][]).map(([value, text]) => (
                  <label key={value} className="check-label"><input type="checkbox" checked={(criteria[key as string] ?? []).includes(value)} onChange={() => toggleCriterion(key as string, value)} /> {text}</label>
                ))}
              </div>
            </fieldset>
          ))}
          {error?.field('criteria') && <span className="field-error">{error.field('criteria')}</span>}
        </>
      ) : <h3 className="sub-title">Écrans membres</h3>}
      {type === 'dynamic' ? null : screens.isLoading ? <Loading /> : screens.isError ? <ErrorState error={screens.error} onRetry={() => screens.refetch()} /> : (
        <>
          <Field label="Filtrer"><input value={filter} onChange={(e) => setFilter(e.target.value)} placeholder="Code, nom ou emplacement" /></Field>
          <div style={{ marginTop: 10 }}>
            {byFloor.map(([key, f]) => {
              const ids = f.screens.map((s) => s.id)
              const all = ids.every((id) => selected.includes(id))
              return (
                <div className="floor-block" key={key}>
                  <h4>{f.label} <Button variant="ghost" size="small" onClick={() => toggleFloor(ids)}>{all ? 'Tout retirer' : 'Tout ajouter'}</Button></h4>
                  <div className="check-grid">
                    {f.screens.map((s) => (
                      <label key={s.id}><input type="checkbox" checked={selected.includes(s.id)} onChange={() => toggle(s.id)} /><span><strong>{s.code}</strong> · {s.name}</span></label>
                    ))}
                  </div>
                </div>
              )
            })}
            {byFloor.length === 0 && <p className="muted small">Aucun écran.</p>}
          </div>
        </>
      )}
      {error && <div style={{ marginTop: 12 }}><Notice tone="danger">{error.message}{error.field('screen_ids') ? ` ${error.field('screen_ids')}` : ''}</Notice></div>}
    </Modal>
  )
}

function TargetsModal({ group, onClose }: { group: ScreenGroup; onClose: () => void }) {
  const screens = useAllScreens()
  const byId = new Map((screens.data?.data ?? []).map((s) => [s.id, s]))
  const offline = group.screens.filter((s) => {
    const full = byId.get(s.id)
    return full && networkOf(full) !== 'online'
  }).length
  return (
    <Modal title={`Cibles effectives · ${group.name}`} kicker="PRÉVISUALISATION" wide onClose={onClose} footer={<Button variant="secondary" onClick={onClose}>Fermer</Button>}>
      <p className="muted small" style={{ marginBottom: 10 }}>{group.screens.length} écran(s) recevront les campagnes et alertes ciblant ce groupe{screens.data ? ` · ${offline} hors ligne actuellement (réception non garantie)` : ''}.</p>
      {group.screens.length === 0 ? <EmptyState title="Groupe vide" text="Ajoutez des écrans pour qu’il serve de cible." /> : (
        <div className="table-scroll">
          <table>
            <thead><tr><th>Écran</th><th>Emplacement</th><th>Réseau</th></tr></thead>
            <tbody>
              {group.screens.map((s) => {
                const full = byId.get(s.id)
                return (
                  <tr key={s.id}>
                    <td><Link className="cell-link" to={`/affichage/ecrans/${s.id}`}>{s.code}</Link><small>{s.name}</small></td>
                    <td>{full?.location ? `${full.location.floor?.label ?? ''} · ${full.location.name}` : '—'}</td>
                    <td>{full ? <StatusOf map={NETWORK_STATUS} value={networkOf(full)} /> : screens.isError ? 'Non disponible' : '…'}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </Modal>
  )
}
