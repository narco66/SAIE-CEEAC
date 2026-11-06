import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, fileUrl } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useData } from '../../lib/hooks'
import { DAYS, fmtBytes, fmtDateTime, fmtRelative, localParts, NETWORK_STATUS, toIsoFromLocal, todayLocal, CLASS_LABEL } from '../../lib/format'
import { Button, ConfirmDialog, EmptyState, ErrorState, Field, Icon, Kv, Loading, Modal, Notice, PageHeading, StatusOf, Tabs, useToast, useUnsavedWarning } from '../../components/ui'
import { CONTROL_LABEL, DEVICE_STATUS, fmtWindow, KIND_LABEL, networkOf, ORIENTATION_LABEL, PANEL_STATE, ProgramResult, type Device, type OperatingWindow, type ProgramPreview, type Screen } from './shared'
import { ScreenFormFields } from './Screens'

interface ScreenDetailData extends Screen {
  devices_history: Device[]
  last_screenshot: { id: string; status: string; requested_at: string; captured_at: string | null; expires_at: string } | null
  manifest: { version: number; etag: string; generated_at: string; valid_until: string | null } | null
  audit: { occurred_at: string; actor_label: string | null; action: string; reason: string | null }[]
  notes?: string | null
}

type Tab = 'identity' | 'device' | 'program' | 'operation' | 'history'

const AUDIT_LABEL: Record<string, string> = {
  'screen.created': 'Création', 'screen.updated': 'Modification', 'screen.window_changed': 'Plage de fonctionnement modifiée', 'device.paired': 'Lecteur appairé',
}

/** V08 Fiche écran. */
export default function ScreenDetail() {
  const { id = '' } = useParams()
  const { me, can } = useAuth()
  const [tab, setTab] = useState<Tab>('identity')
  const query = useData<ScreenDetailData>(['screen', id], `/screens/${id}`)

  if (query.isLoading) return <Loading />
  if (query.isError || !query.data) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  const s = query.data

  return (
    <>
      <PageHeading
        title={`${s.code} · ${s.name}`}
        description={`${KIND_LABEL[s.kind] ?? s.kind} · ${s.location?.floor?.label ?? ''} ${s.location?.name ? `· ${s.location.name}` : ''}`}
        demo={me.demo_mode}
        actions={<Link className="button secondary" to="/affichage/ecrans"><Icon name="list" size={16} /><span>Retour au parc</span></Link>}
      />
      <div className="mini-stats">
        <div><Icon name="wifi" /><span>Réseau du lecteur<strong><StatusOf map={NETWORK_STATUS} value={networkOf(s)} /></strong></span></div>
        <div><Icon name="monitor" /><span>État de la dalle<strong><StatusOf map={PANEL_STATE} value={s.panel_state} /></strong></span></div>
        <div><Icon name="clock" /><span>Dernier contact<strong>{s.device?.last_seen_at ? fmtRelative(s.device.last_seen_at) : '—'}</strong></span></div>
        <div><Icon name="file" /><span>Manifeste actif<strong>{s.device?.active_manifest_version ? `v${s.device.active_manifest_version}` : '—'}</strong></span></div>
      </div>
      <Tabs<Tab> value={tab} onChange={setTab} items={[
        { value: 'identity', label: 'Identité' }, { value: 'device', label: 'Lecteur' }, { value: 'program', label: 'Programme' },
        { value: 'operation', label: 'Fonctionnement' }, { value: 'history', label: 'Historique', count: s.audit.length },
      ]} />
      {tab === 'identity' && <IdentityTab s={s} canManage={can('screens.manage')} />}
      {tab === 'device' && <DeviceTab s={s} />}
      {tab === 'program' && <ProgramTab s={s} />}
      {tab === 'operation' && <OperationTab s={s} canManage={can('screens.manage')} />}
      {tab === 'history' && <HistoryTab s={s} />}
    </>
  )
}

function IdentityTab({ s, canManage }: { s: ScreenDetailData; canManage: boolean }) {
  const [editing, setEditing] = useState(false)
  return (
    <section className="panel">
      <div className="panel-title">
        <div><h2>Identité et affectation</h2><p>Écran physique · le lecteur est un objet distinct (onglet Lecteur)</p></div>
        {canManage && <Button variant="secondary" icon="edit" onClick={() => setEditing(true)}>Modifier l’affectation</Button>}
      </div>
      <div className="panel-body">
        <Kv items={[
          ['Code', <span className="mono">{s.code}</span>], ['Nom', s.name], ['Type', KIND_LABEL[s.kind] ?? s.kind],
          ['Statut', s.status === 'active' ? 'Actif' : 'Inactif'],
          ['Orientation', ORIENTATION_LABEL[s.orientation] ?? s.orientation], ['Résolution', s.resolution.replace('x', ' × ')],
          ['Emplacement', s.location?.name], ['Niveau', s.location?.floor?.label],
          ['Zone d’affichage', s.location?.zone ? `${s.location.zone.name} (${s.location.zone.code}) · classification maximale ${CLASS_LABEL[s.location.zone.max_classification] ?? s.location.zone.max_classification}` : '—'],
          ['Salle associée', s.room?.name ?? (s.kind === 'room_panel' ? 'Non renseignée' : 'Sans objet')],
          ['Groupes', s.groups.length ? s.groups.map((g) => g.name).join(', ') : 'Aucun'],
          ['Canal de pilotage', CONTROL_LABEL[s.control_channel] ?? s.control_channel],
        ]} />
      </div>
      {editing && <EditAssignmentModal s={s} onClose={() => setEditing(false)} />}
    </section>
  )
}

function EditAssignmentModal({ s, onClose }: { s: ScreenDetailData; onClose: () => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const [form, setForm] = useState({ code: s.code, name: s.name, kind: s.kind, location_id: s.location?.id ?? '', room_id: s.room?.id ?? '', orientation: s.orientation, resolution: s.resolution })
  const [status, setStatus] = useState(s.status)
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      const res = await api.patch<{ data: ScreenDetailData }>(`/screens/${s.id}`, { ...form, room_id: form.kind === 'room_panel' ? form.room_id || null : null, status })
      client.setQueryData(['screen', s.id], res.data)
      client.invalidateQueries({ queryKey: ['screens'] })
      toast.success('Affectation enregistrée')
      onClose()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title="Modifier l’affectation" kicker={s.code} wide onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button icon="check" onClick={submit} busy={busy}>Enregistrer</Button>
    </>}>
      <ScreenFormFields form={form} setForm={setForm} error={error} />
      <div className="form-grid" style={{ marginTop: 14 }}>
        <Field label="Statut de l’écran" error={error?.field('status')}>
          <select value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="active">Actif</option><option value="inactive">Inactif</option>
          </select>
        </Field>
      </div>
      {error && !Object.keys(error.errors).length && <div style={{ marginTop: 12 }}><Notice tone="danger">{error.message}</Notice></div>}
    </Modal>
  )
}

function DeviceTab({ s }: { s: ScreenDetailData }) {
  const { can } = useAuth()
  const client = useQueryClient()
  const toast = useToast()
  const [revoking, setRevoking] = useState(false)
  const [requesting, setRequesting] = useState(false)
  const [changingChannel, setChangingChannel] = useState(false)
  const d = s.device
  if (!d) {
    return (
      <section className="panel">
        <EmptyState title="Aucun lecteur actif" text="Cet écran n’a pas de lecteur associé. Saisissez le code temporaire affiché par le lecteur pour l’appairer."
          action={can('devices.pair') ? <Link className="button" to="/affichage/appairage"><Icon name="wifi" size={16} /><span>Appairer un lecteur</span></Link> : undefined} />
      </section>
    )
  }
  const shot = s.last_screenshot
  const shotVisible = shot?.status === 'captured' && new Date(shot.expires_at).getTime() > Date.now()
  const usage = d.storage?.usage ?? null
  const quota = d.storage?.quota ?? null

  const changeChannel = async (channel: string) => {
    setChangingChannel(true)
    try {
      await api.patch(`/devices/${d.id}/channel`, { channel })
      await client.invalidateQueries({ queryKey: ['screen', s.id] })
      toast.success('Canal de version modifié', channel === 'pilot' ? 'Pilote' : 'Stable')
    } catch (e) {
      toast.error(e)
    } finally {
      setChangingChannel(false)
    }
  }
  const requestShot = async () => {
    setRequesting(true)
    try {
      const res = await api.post<{ message?: string }>(`/devices/${d.id}/screenshot`)
      toast.success('Capture demandée', res.message ?? 'Transmise au prochain heartbeat du lecteur.')
      client.invalidateQueries({ queryKey: ['screen', s.id] })
    } catch (e) {
      toast.error(e)
    } finally {
      setRequesting(false)
    }
  }

  return (
    <div className="grid-2">
      <section className="panel">
        <div className="panel-title">
          <div><h2>Lecteur {d.code}</h2><p>Jamais de jeton affiché : l’identité est conservée par le lecteur</p></div>
          <StatusOf map={DEVICE_STATUS} value={d.status} />
        </div>
        <div className="panel-body">
          <Kv items={[
            ['État réseau', <StatusOf map={NETWORK_STATUS} value={d.network_status} />],
            ['Dernier contact', d.last_seen_at ? `${fmtDateTime(d.last_seen_at)} (${fmtRelative(d.last_seen_at)})` : 'Jamais'],
            ['Dernière synchronisation', fmtDateTime(d.last_sync_at)],
            ['Identifiant matériel', <span className="mono">{d.hardware_id ?? '—'}</span>],
            ['Version applicative', d.app_version ?? '—'], ['Plateforme', d.platform ?? '—'],
            ['Canal de version (PLY-12)', can('screens.manage') && d.status === 'active'
              ? <select className="input" style={{ maxWidth: 220 }} aria-label="Canal de version du lecteur" value={d.release_channel ?? 'stable'} disabled={changingChannel} onChange={(e) => changeChannel(e.target.value)}>
                  <option value="stable">Stable</option><option value="pilot">Pilote (nouvelles versions en premier)</option>
                </select>
              : d.release_channel === 'pilot' ? 'Pilote' : 'Stable'],
            ['Stockage', usage !== null && quota ? `${fmtBytes(usage)} utilisés sur ${fmtBytes(quota)} (${Math.round((usage / quota) * 100)} %)${d.storage?.persisted ? ' · persistant' : ''}` : 'Non déclaré'],
            ['Écart d’horloge', d.clock_skew_ms === null ? 'Non mesuré' : `${(d.clock_skew_ms / 1000).toFixed(1).replace('.', ',')} s${Math.abs(d.clock_skew_ms) > 30000 ? ' — écart élevé' : ''}`],
            ['Manifeste actif', d.active_manifest_version ? `v${d.active_manifest_version}` : '—'],
            ['Dernier manifeste généré', s.manifest ? `v${s.manifest.version} · ${fmtDateTime(s.manifest.generated_at)}${s.manifest.valid_until ? ` · autorisation jusqu’au ${fmtDateTime(s.manifest.valid_until)}` : ''}` : '—'],
            ['Lecture en cours (déclarée)', d.current_playback ? <span className="mono">{String(d.current_playback.title ?? d.current_playback.entry_id ?? d.current_playback.mode ?? JSON.stringify(d.current_playback)).slice(0, 160)}</span> : '—'],
            ['Appairé le', fmtDateTime(d.paired_at)],
          ]} />
          <h3 className="sub-title">Erreurs déclarées</h3>
          {d.last_errors?.length ? <ul className="small" style={{ paddingLeft: 18 }}>{d.last_errors.map((e, i) => <li key={i}>{e}</li>)}</ul> : <p className="muted small">Aucune erreur au dernier heartbeat.</p>}
          <h3 className="sub-title">Inventaire du cache ({d.cache_inventory?.length ?? 0} ressource(s))</h3>
          {d.cache_inventory?.length ? (
            <div className="chip-row">{d.cache_inventory.slice(0, 60).map((c) => <span key={c} className="chip mono">{c.slice(0, 12)}</span>)}{d.cache_inventory.length > 60 && <span className="chip">+{d.cache_inventory.length - 60}</span>}</div>
          ) : <p className="muted small">Inventaire non transmis.</p>}
          <div className="row-actions" style={{ justifyContent: 'flex-start', marginTop: 16 }}>
            {can('devices.revoke') && d.status !== 'revoked' && <Button variant="danger" icon="shield" onClick={() => setRevoking(true)}>Révoquer le lecteur</Button>}
          </div>
        </div>
      </section>
      <section className="panel">
        <div className="panel-title">
          <div><h2>Capture de rendu</h2><p>Image transmise par le lecteur, conservée 7 jours</p></div>
          {can('screens.manage') && <Button variant="secondary" icon="image" onClick={requestShot} busy={requesting} disabled={d.status === 'revoked'}>Demander une capture</Button>}
        </div>
        <div className="panel-body">
          {!shot ? <p className="muted small">Aucune capture demandée.</p> : (
            <div className="screenshot-box">
              <Kv items={[
                ['Demandée le', fmtDateTime(shot.requested_at)],
                ['État', shot.status === 'captured' ? 'Capturée' : shot.status === 'requested' ? 'En attente du prochain heartbeat' : shot.status],
                ['Capturée le', fmtDateTime(shot.captured_at)],
                ['Disponible jusqu’au', fmtDateTime(shot.expires_at)],
              ]} />
              {shotVisible ? <img src={fileUrl(`/device-screenshots/${shot.id}`)} alt={`Dernière capture de rendu de l’écran ${s.code} le ${fmtDateTime(shot.captured_at)}`} /> : shot.status === 'captured' && <p className="muted small">La capture a expiré : demandez-en une nouvelle.</p>}
              {shot.status === 'requested' && <Button variant="ghost" size="small" icon="refresh" onClick={() => client.invalidateQueries({ queryKey: ['screen', s.id] })}>Actualiser</Button>}
            </div>
          )}
          <div style={{ marginTop: 12 }}><Notice tone="info">Une capture montre ce que le lecteur rend ; elle ne prouve pas l’allumage de la dalle ni qu’une personne a vu le contenu.</Notice></div>
        </div>
      </section>
      {revoking && (
        <ConfirmDialog
          title="Révoquer le lecteur" object={`${d.code} · écran ${s.code}`} danger confirmLabel="Révoquer définitivement"
          consequence="Le jeton du lecteur est invalidé immédiatement et il ne recevra plus de manifeste. S’il est hors ligne, il continue à lire son cache jusqu’à l’expiration de l’autorisation en cours : le retrait effectif attend cette expiration."
          reasonLabel="Motif de la révocation" reasonRequired
          onClose={() => setRevoking(false)}
          onConfirm={async (reason) => {
            const res = await api.post<{ message?: string }>(`/devices/${d.id}/revoke`, { reason })
            toast.success('Lecteur révoqué', res.message)
            await client.invalidateQueries({ queryKey: ['screen', s.id] })
            client.invalidateQueries({ queryKey: ['screens'] })
          }}
        />
      )}
    </div>
  )
}

function ProgramTab({ s }: { s: ScreenDetailData }) {
  const now = localParts(new Date())
  const [date, setDate] = useState(todayLocal())
  const [time, setTime] = useState(now.time)
  const at = date && time ? toIsoFromLocal(date, time) : undefined
  const q = useQuery({
    queryKey: ['screen-preview', s.id, at],
    queryFn: ({ signal }) => api.get<{ data: ProgramPreview }>(`/screens/${s.id}/preview`, { at }, signal).then((r) => r.data),
    enabled: !!at,
  })
  return (
    <section className="panel">
      <div className="panel-title">
        <div><h2>Programme résolu</h2><p>Même algorithme que le lecteur : alerte, puis priorité, date de début, identifiant</p></div>
        <Link className="link" to={`/affichage/programme?screen_id=${s.id}`}>Piste horaire complète <Icon name="arrow" size={15} /></Link>
      </div>
      <div className="sim-controls">
        <Field label="Date (Africa/Libreville)"><input type="date" value={date} onChange={(e) => setDate(e.target.value)} /></Field>
        <Field label="Heure"><input type="time" value={time} onChange={(e) => setTime(e.target.value)} /></Field>
        <Button variant="secondary" icon="refresh" onClick={() => q.refetch()}>Recalculer</Button>
      </div>
      <div className="panel-body" style={{ paddingTop: 14 }}>
        {q.isLoading ? <Loading label="Résolution du programme…" /> : q.isError ? <ErrorState error={q.error} onRetry={() => q.refetch()} /> : q.data ? <ProgramResult data={q.data} /> : null}
      </div>
    </section>
  )
}

function OperationTab({ s, canManage }: { s: ScreenDetailData; canManage: boolean }) {
  const client = useQueryClient()
  const toast = useToast()
  const initial: OperatingWindow = s.operating_window ?? { days: [1, 2, 3, 4, 5], on: '08:00', off: '18:00' }
  const [days, setDays] = useState<number[]>(initial.days ?? [])
  const [on, setOn] = useState(initial.on)
  const [off, setOff] = useState(initial.off)
  const [wake, setWake] = useState<number>(initial.wake_before_min ?? 5)
  const [ignoreHolidays, setIgnoreHolidays] = useState(!!initial.ignore_holidays)
  const [audio, setAudio] = useState(s.audio_enabled)
  const [channel, setChannel] = useState(s.control_channel)
  const [confirming, setConfirming] = useState(false)
  const windowChanged = JSON.stringify([...days].sort()) !== JSON.stringify([...(initial.days ?? [])].sort()) || on !== initial.on || off !== initial.off || wake !== (initial.wake_before_min ?? 5) || ignoreHolidays !== !!initial.ignore_holidays
  const dirty = windowChanged || audio !== s.audio_enabled || channel !== s.control_channel
  useUnsavedWarning(dirty)
  const invalid = days.length === 0 || !on || !off || off <= on
  const toggleDay = (d: number) => setDays((list) => (list.includes(d) ? list.filter((x) => x !== d) : [...list, d].sort()))

  return (
    <section className="panel">
      <div className="panel-title">
        <div><h2>Fonctionnement</h2><p>Plage actuelle : {fmtWindow(s.operating_window)}{s.window_override_reason ? ` · dérogation : ${s.window_override_reason}` : ''}</p></div>
      </div>
      <div className="panel-body">
        <fieldset disabled={!canManage} style={{ border: 0, padding: 0, margin: 0 }}>
          <div className="form-grid">
            <div className="wide">
              <span className="field" style={{ marginBottom: 6 }}>Jours de fonctionnement <em aria-hidden="true">*</em></span>
              <div className="days-picker" role="group" aria-label="Jours de fonctionnement">
                {[1, 2, 3, 4, 5, 6, 7].map((d) => (
                  <label key={d}><input type="checkbox" checked={days.includes(d)} onChange={() => toggleDay(d)} /> {DAYS[d]}</label>
                ))}
              </div>
            </div>
            <Field label="Allumage" required><input type="time" value={on} onChange={(e) => setOn(e.target.value)} /></Field>
            <Field label="Extinction" required error={off && on && off <= on ? 'L’extinction doit suivre l’allumage.' : undefined}><input type="time" value={off} onChange={(e) => setOff(e.target.value)} /></Field>
            <Field label="Réveil anticipé (minutes)" help="0 à 30 min avant l’allumage">
              <input type="number" min={0} max={30} value={wake} onChange={(e) => setWake(Number(e.target.value))} />
            </Field>
            <label className="check-label" style={{ alignSelf: 'end' }}><input type="checkbox" checked={ignoreHolidays} onChange={(e) => setIgnoreHolidays(e.target.checked)} /> Fonctionner aussi les jours fériés</label>
            <label className="check-label"><input type="checkbox" checked={audio} onChange={(e) => setAudio(e.target.checked)} /> Audio autorisé sur cet écran (sous réserve de la zone)</label>
            <Field label="Canal de pilotage de la dalle" help="Sans canal, l’état de la dalle reste « Non mesuré ».">
              <select value={channel} onChange={(e) => setChannel(e.target.value)}>
                {Object.entries(CONTROL_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </Field>
          </div>
        </fieldset>
        {!canManage && <div style={{ marginTop: 12 }}><Notice tone="info">Lecture seule : la modification exige la permission de gestion des écrans.</Notice></div>}
        {canManage && (
          <div className="form-actions">
            <Button icon="check" onClick={() => setConfirming(true)} disabled={!dirty || invalid}>Enregistrer le fonctionnement</Button>
          </div>
        )}
      </div>
      {confirming && (
        <ConfirmDialog
          title="Modifier le fonctionnement" object={`${s.code} · ${s.name}`} confirmLabel="Appliquer"
          consequence={windowChanged
            ? `Nouvelle plage : ${fmtWindow({ days, on, off })}. Le lecteur l’appliquera à son prochain manifeste ; hors plage, l’écran passe en veille.`
            : 'Les réglages audio et de pilotage seront transmis au lecteur avec son prochain manifeste.'}
          reasonLabel={windowChanged ? 'Motif de la dérogation à la plage de fonctionnement' : undefined}
          reasonRequired={windowChanged}
          onClose={() => setConfirming(false)}
          onConfirm={async (reason) => {
            const body: Record<string, unknown> = { audio_enabled: audio, control_channel: channel }
            if (windowChanged) {
              body.operating_window = { days, on, off, wake_before_min: wake, ignore_holidays: ignoreHolidays }
              body.window_override_reason = reason
            }
            const res = await api.patch<{ data: ScreenDetailData }>(`/screens/${s.id}`, body)
            client.setQueryData(['screen', s.id], res.data)
            client.invalidateQueries({ queryKey: ['screens'] })
            toast.success('Fonctionnement enregistré')
          }}
        />
      )}
    </section>
  )
}

function HistoryTab({ s }: { s: ScreenDetailData }) {
  return (
    <div className="grid-2">
      <section className="panel">
        <div className="panel-title"><div><h2>Lecteurs successifs</h2><p>Du plus récent au plus ancien</p></div></div>
        {s.devices_history.length === 0 ? <EmptyState title="Aucun lecteur" text="Aucun lecteur n’a encore été associé à cet écran." /> : (
          <div className="table-scroll">
            <table>
              <thead><tr><th>Lecteur</th><th>Statut</th><th>Appairé</th><th>Révoqué</th></tr></thead>
              <tbody>
                {s.devices_history.map((d) => (
                  <tr key={d.id}>
                    <td><strong>{d.code}</strong><small>{d.platform ?? ''} {d.app_version ? `· v${d.app_version}` : ''}</small></td>
                    <td><StatusOf map={DEVICE_STATUS} value={d.status} /></td>
                    <td>{fmtDateTime(d.paired_at)}</td>
                    <td>{d.revoked_at ? <>{fmtDateTime(d.revoked_at)}<small>{d.revoke_reason}</small></> : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
      <section className="panel">
        <div className="panel-title"><div><h2>Journal de l’écran</h2><p>20 derniers événements</p></div></div>
        <div className="panel-body">
          {s.audit.length === 0 ? <p className="muted small">Aucun événement journalisé.</p> : (
            <div className="timeline-list">
              {s.audit.map((a, i) => (
                <div key={i}>
                  <time>{fmtDateTime(a.occurred_at)}</time>
                  <span><strong>{AUDIT_LABEL[a.action] ?? a.action}</strong> · {a.actor_label ?? 'Système'}{a.reason && <small className="muted" style={{ display: 'block' }}>Motif : {a.reason}</small>}</span>
                </div>
              ))}
            </div>
          )}
        </div>
      </section>
    </div>
  )
}
