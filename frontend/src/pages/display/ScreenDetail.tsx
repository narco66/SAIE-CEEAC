import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, fileUrl } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useData } from '../../lib/hooks'
import { DAYS, fmtBytes, fmtDate, fmtDateTime, fmtRelative, localParts, NETWORK_STATUS, toIsoFromLocal, todayLocal, CLASS_LABEL } from '../../lib/format'
import { Button, ConfirmDialog, EmptyState, ErrorState, Field, Icon, Kv, Loading, Modal, Notice, PageHeading, Pagination, Status, StatusOf, Tabs, useToast, useUnsavedWarning } from '../../components/ui'
import { COMMISSIONING_CHECK, CONNECTION_LABEL, CONTROL_LABEL, CRITICALITY_LABEL, DEVICE_STATUS, fmtWindow, INTERFACE_LABEL, LOCATION_TYPE, OPERATING_MODE_LABEL, useReferentials, OPERATIONAL_STATE, KIND_LABEL, networkOf, ORIENTATION_LABEL, PANEL_STATE, ProgramResult, type Device, type OperatingWindow, type ProgramPreview, type Screen } from './shared'
import { ScreenFormFields } from './Screens'
import CommandsPanel from './CommandsPanel'
import { ExportDialog } from '../admin/common'
import AssignPlaylistDialog from './AssignPlaylistDialog'
import { TemplatePreview } from '../publication/shared'
import ReassignDeviceDialog from './ReassignDeviceDialog'
import { QRCodeSVG } from 'qrcode.react'
import { formatSetting, SETTING_LABEL, useScreenProfiles } from './ScreenProfiles'

interface ScreenDetailData extends Screen {
  devices_history: Device[]
  last_screenshot: { id: string; status: string; requested_at: string; captured_at: string | null; expires_at: string } | null
  manifest: { version: number; etag: string; generated_at: string; valid_until: string | null } | null
  audit: { occurred_at: string; actor_label: string | null; action: string; reason: string | null }[]
  notes?: string | null
  archived_at?: string | null
  archive_reason?: string | null
  location_history?: { location: string | null; floor: string | null; room: string | null; detail: string | null; from_at: string; to_at: string | null; moved_by: { id: string; name: string } | null; reason: string | null }[]
  documents?: { id: string; reference: string; title: string; status: string; linked_at: string }[]
  last_played_at?: string | null
  current_playback_resolved?: { title: string | null; campaign_ref: string | null; campaign_id: string | null; lang: string | null; at: string | null; alert: boolean } | null
  location_extra?: { manager: { id: string; name: string } | null; photo_asset_version_id: string | null } | null
  qr?: string | null
  configuration?: {
    profile: { id: string; code: string; name: string; version: number; inherited: boolean } | null; settings: Record<string, unknown>; expected_hash: string
    sync: string; reported_at: string | null; unsupported: string[]; applied: string[]
  }
}

const TECHNOLOGY_LABEL: Record<string, string> = { led: 'LED', lcd: 'LCD', oled: 'OLED', other: 'Autre' }

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
        <div><Icon name="file" /><span>Programme actif<strong>{s.device?.active_manifest_version ? `v${s.device.active_manifest_version}` : '—'}</strong></span></div>
      </div>
      <Tabs<Tab> value={tab} onChange={setTab} items={[
        { value: 'identity', label: 'Identité' }, { value: 'device', label: 'Lecteur' }, { value: 'program', label: 'Programme' },
        { value: 'operation', label: 'Fonctionnement' }, { value: 'history', label: 'Historique', count: s.audit.length },
      ]} />
      {tab === 'identity' && <IdentityTab s={s} canManage={can('screens.manage')} />}
      {tab === 'device' && <DeviceTab s={s} />}
      {tab === 'program' && <ProgramTab s={s} />}
      {tab === 'operation' && <div className="grid-2"><ConfigurationPanel s={s} /><div style={{ gridColumn: '1 / -1' }}><OperationTab s={s} canManage={can('screens.manage')} /></div></div>}
      {tab === 'history' && <HistoryTab s={s} />}
    </>
  )
}

function IdentityTab({ s, canManage }: { s: ScreenDetailData; canManage: boolean }) {
  const [editing, setEditing] = useState(false)
  const [archiving, setArchiving] = useState(false)
  const [commissioning, setCommissioning] = useState(false)
  const [printing, setPrinting] = useState(false)
  const client = useQueryClient()
  const toast = useToast()
  const archived = s.status === 'archived'
  return (
    <section className="panel">
      <div className="panel-title">
        <div><h2>Identité et affectation</h2><p>Écran physique · le lecteur est un objet distinct (onglet Lecteur)</p></div>
        {canManage && !archived && (
          <div className="row-actions">
            {!s.commissioning && s.status !== 'inactive' && <Button variant="secondary" icon="check" onClick={() => setCommissioning(true)}>Mettre en service</Button>}
            <Button variant="secondary" icon="edit" onClick={() => setEditing(true)}>Modifier l’écran</Button>
            <Button variant="danger" icon="x" onClick={() => setArchiving(true)}>Archiver</Button>
          </div>
        )}
      </div>
      {archived && <div style={{ padding: '0 20px' }}><Notice tone="info">Écran archivé le {fmtDateTime(s.archived_at ?? null)} : {s.archive_reason}. La fiche, l’historique et les incidents sont conservés en lecture seule.</Notice></div>}
      <div className="panel-body">
        <Kv items={[
          ['Code', <span className="mono">{s.code}</span>], ['Code d’inventaire', s.inventory_code ? <span className="mono">{s.inventory_code}</span> : 'Non renseigné'],
          ['Nom', s.name], ['Type', KIND_LABEL[s.kind] ?? s.kind], ['Catégorie', s.category?.name ?? 'Non classé'],
          ['Criticité', CRITICALITY_LABEL[s.criticality ?? 'normal']], ['Mode de fonctionnement', s.operating_mode ? OPERATING_MODE_LABEL[s.operating_mode] : 'Non renseigné'],
          ['Mise en service', s.commissioning ? `${fmtDateTime(s.commissioning.at)}${s.commissioning.by ? ` par ${s.commissioning.by.name}` : ''}` : <Status tone="warning">Non mis en service</Status>],
          ['Statut', s.status === 'active' ? 'Actif' : s.status === 'maintenance' ? `En maintenance${s.maintenance_reason ? ` — ${s.maintenance_reason}` : ''}` : archived ? 'Archivé' : 'Désactivé'],
          ['Description', s.notes || 'Non renseignée'],
          ['État opérationnel', s.operational_state ? <><StatusOf map={OPERATIONAL_STATE} value={s.operational_state.code} /> <small className="muted">« Lecteur joignable » ne prouve ni l’allumage de la dalle ni la lecture : voir le suivi de diffusion.</small></> : '—'],
          ['Dernière diffusion confirmée', s.last_played_at ? `${fmtDateTime(s.last_played_at)} (${fmtRelative(s.last_played_at)})` : 'Aucune lecture confirmée'],
          ['Orientation', ORIENTATION_LABEL[s.orientation] ?? s.orientation], ['Résolution', s.resolution.replace('x', ' × ')],
          ['Emplacement', s.location?.name], ['Type d’espace', s.location?.type ? LOCATION_TYPE[s.location.type] ?? s.location.type : '—'],
          ['Responsable de l’espace', s.location_extra?.manager?.name ?? 'Non désigné'], ['Niveau', s.location?.floor?.label],
          ['Zone d’affichage', s.location?.zone ? `${s.location.zone.name} (${s.location.zone.code}) · classification maximale ${CLASS_LABEL[s.location.zone.max_classification] ?? s.location.zone.max_classification}` : '—'],
          ['Salle associée', s.room?.name ?? (s.kind === 'room_panel' ? 'Non renseignée' : 'Sans objet')],
          ['Groupes', s.groups.length ? s.groups.map((g) => g.name).join(', ') : 'Aucun'],
          ['Canal de pilotage', CONTROL_LABEL[s.control_channel] ?? s.control_channel],
        ]} />
        <h3 className="sub-title">Fiche matérielle</h3>
        <Kv items={[
          ['Marque et modèle', [s.hardware.manufacturer, s.hardware.model].filter(Boolean).join(' ') || 'Non renseigné'],
          ['Numéro de série', s.hardware.serial_number ? <span className="mono">{s.hardware.serial_number}</span> : 'Non renseigné'],
          ['Diagonale', s.hardware.diagonal_inches ? `${s.hardware.diagonal_inches} pouces` : 'Non renseignée'],
          ['Connexion', s.hardware.connection_type ? CONNECTION_LABEL[s.hardware.connection_type] ?? s.hardware.connection_type : 'Non renseignée'],
          ['Adresse IP', s.hardware.ip_address ? <span className="mono">{s.hardware.ip_address}</span> : 'Non renseignée'],
          ['Installation', s.hardware.installed_on ? fmtDate(s.hardware.installed_on) : 'Non renseignée'],
          ['Garantie', s.hardware.warranty_until ? `${s.hardware.under_warranty ? 'Jusqu’au' : 'Expirée le'} ${fmtDate(s.hardware.warranty_until)}` : 'Non renseignée'],
          ['Technologie', s.hardware.technology ? TECHNOLOGY_LABEL[s.hardware.technology] ?? s.hardware.technology : 'Non renseignée'],
          ['Consommation nominale', s.hardware.power_watts ? `${s.hardware.power_watts} W` : 'Non renseignée'],
          ['Adresse MAC', s.hardware.mac_address ? <span className="mono">{s.hardware.mac_address}</span> : 'Non renseignée'],
          ['Interfaces physiques', s.interfaces?.length ? s.interfaces.map((i) => INTERFACE_LABEL[i] ?? i).join(', ') : 'Non décrites'],
          ['Système · firmware', [s.hardware.os_name, s.hardware.firmware_version].filter(Boolean).join(' · ') || 'Non renseignés'],
        ]} />
        <h3 className="sub-title">Fiche administrative</h3>
        <Kv items={[
          ['Acquisition', s.hardware.acquired_on ? fmtDate(s.hardware.acquired_on) : 'Non renseignée'],
          ['Fournisseur', s.hardware.supplier || 'Non renseigné'], ['Référence de commande', s.hardware.order_reference || 'Non renseignée'],
          ['Bâtiment', s.location?.building?.name ?? '—'], ['Emplacement précis', s.location_detail || 'Non renseigné'],
        ]} />
        {s.location_extra?.photo_asset_version_id && (
          <figure className="location-photo">
            <img src={fileUrl(`/asset-versions/${s.location_extra.photo_asset_version_id}/file`)} alt={`Photographie de l’emplacement ${s.location?.name ?? ''}`} />
            <figcaption className="muted small">Photographie de l’emplacement (référentiel)</figcaption>
          </figure>
        )}
        <div className="row-actions" style={{ justifyContent: 'flex-start', marginTop: 12 }}>
          <Button variant="ghost" size="small" icon="download" onClick={() => setPrinting(true)}>Fiche écran (PDF)</Button>
        </div>
        <LocationHistory s={s} />
        <LinkedDocuments s={s} canManage={canManage && !archived} />
        <ScreenQr s={s} canManage={canManage && !archived} />
      </div>
      {editing && <EditAssignmentModal s={s} onClose={() => setEditing(false)} />}
      {commissioning && <CommissionDialog s={s} onClose={() => setCommissioning(false)} />}
      {printing && (
        <ExportDialog type="screen_sheet" title="Fiche écran" withPeriod={false} params={{ screen_id: s.id }} onClose={() => setPrinting(false)}>
          <p className="small muted">Identité, localisation, matériel, lecteur, mise en service et derniers incidents de {s.code}.</p>
        </ExportDialog>
      )}
      {archiving && (
        <ConfirmDialog title="Archiver l’écran" object={`${s.code} · ${s.name}`} danger confirmLabel="Archiver définitivement" reasonLabel="Motif du retrait" reasonRequired
          consequence="L’écran sort de toute programmation et de ses groupes ; son lecteur est révoqué. La fiche, l’historique et les incidents sont conservés. Impossible si un incident est encore ouvert."
          onConfirm={async (reason) => {
            await api.post(`/screens/${s.id}/archive`, { reason, expected_version: s.version })
            toast.success('Écran archivé', s.code)
            await client.invalidateQueries({ queryKey: ['screen', s.id] })
          }}
          onClose={() => setArchiving(false)} />
      )}
    </section>
  )
}

/** RG-011 : emplacements successifs de l'écran, avec auteur et motif de chaque déplacement. */
function LocationHistory({ s }: { s: ScreenDetailData }) {
  return (
    <>
      <h3 className="sub-title">Historique des emplacements</h3>
      {!s.location_history?.length ? <p className="muted small">Aucun déplacement enregistré.</p> : (
        <div className="table-scroll">
          <table>
            <thead><tr><th>Emplacement</th><th>Du</th><th>Au</th><th>Par · motif</th></tr></thead>
            <tbody>
              {s.location_history.map((h, i) => (
                <tr key={i}>
                  <td>{h.floor ? `${h.floor} · ` : ''}{h.location}{h.room ? <small>Salle {h.room}</small> : null}{h.detail ? <small>{h.detail}</small> : null}</td>
                  <td>{fmtDateTime(h.from_at)}</td>
                  <td>{h.to_at ? fmtDateTime(h.to_at) : <strong>actuel</strong>}</td>
                  <td>{h.moved_by?.name ?? 'Système'}{h.reason ? <small>{h.reason}</small> : null}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  )
}

/** §5.1 : documents rattachés (facture, notice, procès-verbal), choisis parmi les documents consultables par l'utilisateur. */
function LinkedDocuments({ s, canManage }: { s: ScreenDetailData; canManage: boolean }) {
  const client = useQueryClient()
  const toast = useToast()
  const [search, setSearch] = useState('')
  const found = useQuery({
    queryKey: ['documents', 'link', search],
    queryFn: ({ signal }) => api.get<{ data: { id: string; reference: string; title: string }[] }>('/documents', { q: search, per_page: 8 }, signal).then((r) => r.data),
    enabled: canManage && search.trim().length >= 2,
  })
  const call = async (method: 'post' | 'delete', documentId: string) => {
    try {
      const res = method === 'post'
        ? await api.post<{ data: ScreenDetailData }>(`/screens/${s.id}/documents`, { document_id: documentId })
        : await api.delete<{ data: ScreenDetailData }>(`/screens/${s.id}/documents/${documentId}`)
      client.setQueryData(['screen', s.id], res.data)
      setSearch('')
    } catch (e) {
      toast.error(e)
    }
  }
  return (
    <>
      <h3 className="sub-title">Documents associés</h3>
      {s.documents?.length ? (
        <ul className="link-list">
          {s.documents.map((d) => (
            <li key={d.id}><Link className="link-text" to={`/documents/${d.id}`}>{d.title}</Link> <small className="muted">{d.reference}</small>
              {canManage && <Button size="small" variant="ghost" onClick={() => call('delete', d.id)}>Retirer</Button>}</li>
          ))}
        </ul>
      ) : <p className="muted small">Aucun document associé.</p>}
      {canManage && (
        <Field label="Associer un document" help="Saisissez au moins deux caractères du titre ou de la référence">
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Facture, notice, procès-verbal…" />
        </Field>
      )}
      {canManage && (found.data ?? []).filter((d) => !s.documents?.some((x) => x.id === d.id)).map((d) => (
        <div key={d.id} className="row-actions" style={{ justifyContent: 'flex-start' }}>
          <Button size="small" variant="secondary" icon="plus" onClick={() => call('post', d.id)}>{d.reference} · {d.title}</Button>
        </div>
      ))}
    </>
  )
}

/** §5.5 : code QR de la fiche, imprimable et collé sur l'écran ; il n'ouvre la fiche qu'à un compte habilité. */
function ScreenQr({ s, canManage }: { s: ScreenDetailData; canManage: boolean }) {
  const client = useQueryClient()
  const toast = useToast()
  const [busy, setBusy] = useState(false)
  const url = s.qr ? `${window.location.origin}/q/${s.qr}` : null
  const generate = async () => {
    setBusy(true)
    try {
      await api.post(`/screens/${s.id}/qr`)
      await client.invalidateQueries({ queryKey: ['screen', s.id] })
    } catch (e) {
      toast.error(e)
    } finally {
      setBusy(false)
    }
  }
  return (
    <>
      <h3 className="sub-title">Code QR de la fiche</h3>
      {url ? (
        <div className="screen-qr print-sheet">
          <QRCodeSVG value={url} size={132} level="M" role="img" aria-label={`Code QR de la fiche de l’écran ${s.code}`} />
          <div>
            <strong>{s.code}</strong><span>{s.name}</span><small>{s.location?.floor?.label} · {s.location?.name}</small><small className="mono">{url}</small>
            <small>Accès réservé aux comptes habilités sur cet écran.</small>
            <div className="row-actions no-print" style={{ justifyContent: 'flex-start' }}><Button size="small" variant="secondary" icon="file" onClick={() => window.print()}>Imprimer la fiche</Button></div>
          </div>
        </div>
      ) : canManage ? <Button variant="secondary" icon="plus" busy={busy} onClick={generate}>Générer le code QR</Button> : <p className="muted small">Aucun code QR généré.</p>}
    </>
  )
}

/** §15.1 : mise en service — contrôles calculés par le serveur, contrôle visuel attesté par l'agent. */
function CommissionDialog({ s, onClose }: { s: ScreenDetailData; onClose: () => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const [visual, setVisual] = useState(false)
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [checks, setChecks] = useState<Record<string, boolean> | null>(null)
  const [error, setError] = useState<ApiError | null>(null)
  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: ScreenDetailData }>(`/screens/${s.id}/commission`, { visual_check: visual, note: note.trim() || null })
      client.setQueryData(['screen', s.id], res.data)
      client.invalidateQueries({ queryKey: ['screens'] })
      toast.success('Écran mis en service', s.code)
      onClose()
    } catch (e) {
      if (e instanceof ApiError) {
        setError(e)
        setChecks((e.details as { checks?: Record<string, boolean> } | undefined)?.checks ?? null)
      } else {
        toast.error(e)
      }
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title="Mettre en service" kicker={s.code} onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button icon="check" busy={busy} disabled={!visual} onClick={submit}>Mettre en service</Button>
    </>}>
      <p>Le serveur vérifie la communication et le rendu : lecteur appairé et joignable, programme et configuration appliqués, rendu confirmé par un accusé de moins de 24 h.</p>
      {checks && (
        <ul className="check-list">
          {Object.entries(COMMISSIONING_CHECK).map(([k, l]) => <li key={k}><Status tone={checks[k] ? 'success' : 'danger'}>{checks[k] ? 'Satisfait' : 'Non satisfait'}</Status> {l}</li>)}
        </ul>
      )}
      {error && !checks && <Notice tone="danger">{error.message}</Notice>}
      <label className="check-label" style={{ marginTop: 12 }}><input type="checkbox" checked={visual} onChange={(e) => setVisual(e.target.checked)} /> J’atteste avoir vérifié visuellement l’affichage sur la dalle</label>
      <Field label="Observation" error={error?.field('note')}><textarea maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} /></Field>
    </Modal>
  )
}

function EditAssignmentModal({ s, onClose }: { s: ScreenDetailData; onClose: () => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const [form, setForm] = useState({ code: s.code, name: s.name, kind: s.kind, location_id: s.location?.id ?? '', room_id: s.room?.id ?? '', orientation: s.orientation, resolution: s.resolution })
  const [status, setStatus] = useState(s.status)
  const [maintenanceReason, setMaintenanceReason] = useState(s.maintenance_reason ?? '')
  const [statusReason, setStatusReason] = useState('')
  const [classification, setClassification] = useState({ category_id: s.category?.id ?? '', criticality: s.criticality ?? 'normal', operating_mode: s.operating_mode ?? '' })
  const [interfaces, setInterfaces] = useState<string[]>(s.interfaces ?? [])
  const refs = useReferentials()
  const toggling = status !== s.status && (status === 'inactive' || s.status === 'inactive')
  const [hardware, setHardware] = useState({
    inventory_code: s.inventory_code ?? '', manufacturer: s.hardware.manufacturer ?? '', model: s.hardware.model ?? '', serial_number: s.hardware.serial_number ?? '', diagonal_inches: s.hardware.diagonal_inches ? String(s.hardware.diagonal_inches) : '',
    ip_address: s.hardware.ip_address ?? '', connection_type: s.hardware.connection_type ?? '', installed_on: s.hardware.installed_on ?? '', warranty_until: s.hardware.warranty_until ?? '',
    acquired_on: s.hardware.acquired_on ?? '', supplier: s.hardware.supplier ?? '', order_reference: s.hardware.order_reference ?? '', technology: s.hardware.technology ?? '',
    power_watts: s.hardware.power_watts ? String(s.hardware.power_watts) : '', mac_address: s.hardware.mac_address ?? '', os_name: s.hardware.os_name ?? '', firmware_version: s.hardware.firmware_version ?? '',
    notes: s.notes ?? '', location_detail: s.location_detail ?? '',
    plan_x: s.plan ? String(s.plan.x) : '', plan_y: s.plan ? String(s.plan.y) : '',
  })
  const [moveReason, setMoveReason] = useState('')
  const [profileId, setProfileId] = useState(s.configuration?.profile && !s.configuration.profile.inherited ? s.configuration.profile.id : '')
  const profiles = useScreenProfiles()
  const moving = form.location_id !== (s.location?.id ?? '') || (form.room_id || '') !== (s.room?.id ?? '') || hardware.location_detail !== (s.location_detail ?? '')
  const hw = (key: keyof typeof hardware) => ({ value: hardware[key], onChange: (e: { target: { value: string } }) => setHardware({ ...hardware, [key]: e.target.value }) })
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      const res = await api.patch<{ data: ScreenDetailData }>(`/screens/${s.id}`, {
        ...form, room_id: form.room_id || null, status, maintenance_reason: status === 'maintenance' ? maintenanceReason || null : null, status_reason: toggling ? statusReason || null : undefined,
        category_id: classification.category_id || null, criticality: classification.criticality, operating_mode: classification.operating_mode || null, interfaces,
        ...Object.fromEntries(Object.entries(hardware).map(([k, v]) => [k, v === '' ? null : ['diagonal_inches', 'power_watts', 'plan_x', 'plan_y'].includes(k) ? Number(v) : v])),
        move_reason: moving ? moveReason || null : undefined, profile_id: profileId || null,
      }, { 'If-Match': String(s.version) })
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
    <Modal title="Modifier l’écran" kicker={s.code} wide onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button icon="check" onClick={submit} busy={busy}>Enregistrer</Button>
    </>}>
      <ScreenFormFields form={form} setForm={setForm} error={error} />
      <div className="form-grid" style={{ marginTop: 14 }}>
        <Field label="Statut de l’écran" error={error?.field('status')}>
          <select value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="active">Actif</option><option value="maintenance">En maintenance (reste ciblé)</option><option value="inactive">Désactivé (plus ciblé)</option>
          </select>
        </Field>
        {toggling && (
          <Field label={status === 'inactive' ? 'Motif de la désactivation' : 'Motif de la réactivation'} required error={error?.field('status_reason')} help="Journalisé dans l’historique de l’écran">
            <input value={statusReason} maxLength={300} onChange={(e) => setStatusReason(e.target.value)} />
          </Field>
        )}
        {status === 'maintenance' && (
          <Field label="Motif de la maintenance" required error={error?.field('maintenance_reason')}>
            <input value={maintenanceReason} maxLength={300} onChange={(e) => setMaintenanceReason(e.target.value)} />
          </Field>
        )}
      </div>
      <h3 className="sub-title">Classement</h3>
      <div className="form-grid">
        <Field label="Catégorie" error={error?.field('category_id')}>
          <select value={classification.category_id} onChange={(e) => setClassification({ ...classification, category_id: e.target.value })}>
            <option value="">Non classé</option>{(refs.data?.screen_categories ?? []).filter((c) => c.is_active !== false || c.id === classification.category_id).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </Field>
        <Field label="Criticité" error={error?.field('criticality')}>
          <select value={classification.criticality} onChange={(e) => setClassification({ ...classification, criticality: e.target.value })}>{Object.entries(CRITICALITY_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        </Field>
        <Field label="Mode de fonctionnement" error={error?.field('operating_mode')}>
          <select value={classification.operating_mode} onChange={(e) => setClassification({ ...classification, operating_mode: e.target.value })}>
            <option value="">Non renseigné</option>{Object.entries(OPERATING_MODE_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </Field>
        <fieldset className="field wide" style={{ border: 0, padding: 0, margin: 0, gridColumn: 'span 2' }}>
          <legend style={{ padding: 0 }}>Interfaces physiques</legend>
          <div className="chip-row" style={{ marginTop: 6 }}>
            {Object.entries(INTERFACE_LABEL).map(([v, l]) => (
              <label key={v} className="check-label" style={{ padding: '6px 10px' }}>
                <input type="checkbox" checked={interfaces.includes(v)} onChange={(e) => setInterfaces(e.target.checked ? [...interfaces, v] : interfaces.filter((x) => x !== v))} /> {l}
              </label>
            ))}
          </div>
          {error?.field('interfaces') && <span className="field-error">{error.field('interfaces')}</span>}
        </fieldset>
      </div>
      <h3 className="sub-title">Fiche matérielle</h3>
      <div className="form-grid">
        <Field label="Marque" error={error?.field('manufacturer')}><input maxLength={80} {...hw('manufacturer')} /></Field>
        <Field label="Modèle" error={error?.field('model')}><input maxLength={80} {...hw('model')} /></Field>
        <Field label="Numéro de série" error={error?.field('serial_number')}><input maxLength={80} {...hw('serial_number')} /></Field>
        <Field label="Diagonale (pouces)" error={error?.field('diagonal_inches')}><input type="number" min={7} max={120} {...hw('diagonal_inches')} /></Field>
        <Field label="Adresse IP" error={error?.field('ip_address')}><input inputMode="decimal" maxLength={45} {...hw('ip_address')} /></Field>
        <Field label="Connexion" error={error?.field('connection_type')}>
          <select {...hw('connection_type')}>
            <option value="">Non renseignée</option>
            {Object.entries(CONNECTION_LABEL).map(([code, label]) => <option key={code} value={code}>{label}</option>)}
          </select>
        </Field>
        <Field label="Technologie" error={error?.field('technology')}>
          <select {...hw('technology')}><option value="">Non renseignée</option>{Object.entries(TECHNOLOGY_LABEL).map(([c, l]) => <option key={c} value={c}>{l}</option>)}</select>
        </Field>
        <Field label="Consommation nominale (W)" error={error?.field('power_watts')}><input type="number" min={1} max={5000} {...hw('power_watts')} /></Field>
        <Field label="Adresse MAC" error={error?.field('mac_address')} help="Format 00:1A:2B:3C:4D:5E"><input maxLength={17} className="mono" {...hw('mac_address')} /></Field>
        <Field label="Système d’exploitation" error={error?.field('os_name')}><input maxLength={60} {...hw('os_name')} /></Field>
        <Field label="Version du firmware" error={error?.field('firmware_version')}><input maxLength={60} {...hw('firmware_version')} /></Field>
        <Field label="Date d’installation" error={error?.field('installed_on')}><input type="date" {...hw('installed_on')} /></Field>
        <Field label="Fin de garantie" error={error?.field('warranty_until')}><input type="date" {...hw('warranty_until')} /></Field>
      </div>
      <h3 className="sub-title">Fiche administrative</h3>
      <div className="form-grid">
        <Field label="Code d’inventaire" error={error?.field('inventory_code')} help="Numéro patrimonial, distinct du code technique"><input maxLength={40} className="mono" {...hw('inventory_code')} /></Field>
        <Field label="Date d’acquisition" error={error?.field('acquired_on')}><input type="date" {...hw('acquired_on')} /></Field>
        <Field label="Fournisseur" error={error?.field('supplier')}><input maxLength={120} {...hw('supplier')} /></Field>
        <Field label="Référence de commande" error={error?.field('order_reference')}><input maxLength={80} {...hw('order_reference')} /></Field>
        <Field label="Emplacement précis" error={error?.field('location_detail')} help="Ex. « face aux ascenseurs, à 2 m du sol »"><input maxLength={200} {...hw('location_detail')} /></Field>
        <Field label="Position sur le plan : horizontale (%)" error={error?.field('plan_x')} help="0 = bord gauche, 100 = bord droit du plan du niveau"><input type="number" min={0} max={100} step={0.5} {...hw('plan_x')} /></Field>
        <Field label="Position sur le plan : verticale (%)" error={error?.field('plan_y')} help="0 = haut, 100 = bas du plan du niveau"><input type="number" min={0} max={100} step={0.5} {...hw('plan_y')} /></Field>
        <Field label="Description" wide error={error?.field('notes')}><textarea maxLength={1000} {...hw('notes')} /></Field>
        <Field label="Profil de configuration" error={error?.field('profile_id')} help="Vide : profil du groupe de l’écran, s’il en a un">
          <select value={profileId} onChange={(e) => setProfileId(e.target.value)}>
            <option value="">Profil du groupe (ou aucun)</option>
            {(profiles.data?.data ?? []).filter((p) => p.is_active || p.id === profileId).map((p) => <option key={p.id} value={p.id}>{p.name} ({p.code})</option>)}
          </select>
        </Field>
        {moving && <Field label="Motif du déplacement" wide error={error?.field('move_reason')} help="Conservé dans l’historique des emplacements"><input maxLength={300} value={moveReason} onChange={(e) => setMoveReason(e.target.value)} /></Field>}
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
  const [reassigning, setReassigning] = useState(false)
  const [requesting, setRequesting] = useState(false)
  const [changingChannel, setChangingChannel] = useState(false)
  const [suspending, setSuspending] = useState<'suspend' | 'resume' | null>(null)
  const [identityOpen, setIdentityOpen] = useState(false)
  const [rotating, setRotating] = useState(false)
  const [pinning, setPinning] = useState(false)
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
      toast.success('Capture demandée', res.message ?? 'Transmise à la prochaine liaison du lecteur (environ 30 secondes).')
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
          {d.suspended_at && <div style={{ marginBottom: 12 }}><Notice tone="danger">Lecteur suspendu depuis le {fmtDateTime(d.suspended_at)} : il ne reçoit plus que le contenu de repli. Motif : {d.suspend_reason}</Notice></div>}
          <Kv items={[
            ['Nom · type · n° de série', [d.name, d.hardware_type, d.serial_number].filter(Boolean).join(' · ') || 'Non renseignés'],
            ['État réseau', <StatusOf map={NETWORK_STATUS} value={d.network_status} />],
            ['Dernier contact', d.last_seen_at ? `${fmtDateTime(d.last_seen_at)} (${fmtRelative(d.last_seen_at)})` : 'Jamais'],
            ['Dernière synchronisation', fmtDateTime(d.last_sync_at)],
            ['Identifiant matériel', <span className="mono">{d.hardware_id ?? '—'}</span>],
            ['Version applicative', d.app_version ?? '—'], ['Plateforme', d.platform ?? '—'],
            ['Canal de version', can('screens.manage') && d.status === 'active'
              ? <select className="input" style={{ maxWidth: 220 }} aria-label="Canal de version du lecteur" value={d.release_channel ?? 'stable'} disabled={changingChannel} onChange={(e) => changeChannel(e.target.value)}>
                  <option value="stable">Stable</option><option value="pilot">Pilote (nouvelles versions en premier)</option>
                </select>
              : d.release_channel === 'pilot' ? 'Pilote' : 'Stable'],
            ['Stockage', usage !== null && quota ? `${fmtBytes(usage)} utilisés sur ${fmtBytes(quota)} (${Math.round((usage / quota) * 100)} %)${d.storage?.persisted ? ' · persistant' : ''}` : 'Non déclaré'],
            ['Écart d’horloge', d.clock_skew_ms === null ? 'Non mesuré' : `${(d.clock_skew_ms / 1000).toFixed(1).replace('.', ',')} s${Math.abs(d.clock_skew_ms) > 30000 ? ' — écart élevé' : ''}`],
            ['Version du programme actif', d.active_manifest_version ? `v${d.active_manifest_version}` : '—'],
            ['Dernier programme émis', s.manifest ? `v${s.manifest.version} · ${fmtDateTime(s.manifest.generated_at)}${s.manifest.valid_until ? ` · autorisation jusqu’au ${fmtDateTime(s.manifest.valid_until)}` : ''}` : '—'],
            ['Lecture en cours (déclarée)', s.current_playback_resolved
              ? <>{s.current_playback_resolved.alert ? 'Alerte en cours' : s.current_playback_resolved.title ?? 'Entrée hors du programme connu'}{s.current_playback_resolved.campaign_ref && <small> · campagne <span className="mono">{s.current_playback_resolved.campaign_ref}</span></small>}{s.current_playback_resolved.at && <small> · depuis {fmtDateTime(s.current_playback_resolved.at)}</small>}</>
              : d.current_playback ? <span className="mono">{String(d.current_playback.mode ?? JSON.stringify(d.current_playback)).slice(0, 160)}</span> : '—'],
            ['Appairé le', fmtDateTime(d.paired_at)],
            ['Adresse vue par le serveur', d.observed_ip ? <span className="mono">{d.observed_ip}</span> : 'Non observée'],
            ['Dernier battement de l’agent local', d.agent_seen_at ? `${fmtDateTime(d.agent_seen_at)} (${fmtRelative(d.agent_seen_at)})` : 'Aucun (agent absent ou non rattaché)'],
            ['Dernière coupure déclarée', d.offline_since ? fmtDateTime(d.offline_since) : 'Aucune'],
            ['Événements perdus (file pleine hors ligne)', String(d.events_dropped ?? 0)],
          ]} />
          <h3 className="sub-title">Caractéristiques déclarées par le lecteur</h3>
          {d.hardware_info ? <Kv items={[
            ['Définition de l’écran', d.hardware_info.screen ? String(d.hardware_info.screen).replace('x', ' × ') : '—'],
            ['Processeur', d.hardware_info.cores ? `${d.hardware_info.cores} cœur(s) logique(s)` : 'Non communiqué'],
            ['Mémoire', d.hardware_info.memory_gb ? `${d.hardware_info.memory_gb} Go (valeur arrondie par le navigateur)` : 'Non communiquée'],
            ['Langue et fuseau', [d.hardware_info.language, d.hardware_info.timezone].filter(Boolean).join(' · ') || '—'],
            ['Adresse MAC, système, firmware', [d.hardware_info.mac, d.hardware_info.os, d.hardware_info.firmware].filter(Boolean).join(' · ') || 'Non accessibles à l’application web : fournis seulement par l’agent local'],
          ]} /> : <p className="muted small">Aucune caractéristique transmise (version antérieure du lecteur).</p>}
          <h3 className="sub-title">Télémétrie de l’agent local</h3>
          {d.hardware_info?.agent_version ? <AgentTelemetry hw={d.hardware_info} /> : <p className="muted small">Aucun agent local n’a répondu : disque, température et adresse réseau ne sont pas mesurés sur ce poste.</p>}
          <h3 className="sub-title">Erreurs déclarées</h3>
          {d.last_errors?.length ? <ul className="small" style={{ paddingLeft: 18 }}>{d.last_errors.map((e, i) => <li key={i}>{e}</li>)}</ul> : <p className="muted small">Aucune erreur au dernier contact.</p>}
          <h3 className="sub-title">Inventaire du cache ({d.cache_inventory?.length ?? 0} ressource(s))</h3>
          {d.cache_inventory?.length ? (
            <div className="chip-row">{d.cache_inventory.slice(0, 60).map((c) => <span key={c} className="chip mono">{c.slice(0, 12)}</span>)}{d.cache_inventory.length > 60 && <span className="chip">+{d.cache_inventory.length - 60}</span>}</div>
          ) : <p className="muted small">Inventaire non transmis.</p>}
          <div className="row-actions" style={{ justifyContent: 'flex-start', marginTop: 16 }}>
            {can('screens.manage') && d.status === 'active' && <Button variant="secondary" icon="edit" onClick={() => setIdentityOpen(true)}>Identité du lecteur</Button>}
            {can('devices.revoke') && d.status === 'active' && <Button variant="secondary" icon="shield" disabled={!!d.rotate_requested_at} title={d.rotate_requested_at ? `Demandé le ${fmtDateTime(d.rotate_requested_at)}` : undefined} onClick={() => setRotating(true)}>{d.rotate_requested_at ? 'Renouvellement en attente' : 'Renouveler les identifiants'}</Button>}
            {can('player_releases.manage') && d.status === 'active' && <Button variant="secondary" icon="download" onClick={() => setPinning(true)}>{d.pinned_release_id ? 'Version épinglée…' : 'Mise à jour ciblée'}</Button>}
            {can('devices.pair') && d.status === 'active' && <Button variant="secondary" icon="refresh" onClick={() => setReassigning(true)}>Réaffecter à un autre écran</Button>}
            {can('devices.revoke') && d.status === 'active' && (d.suspended_at
              ? <Button variant="secondary" icon="refresh" onClick={() => setSuspending('resume')}>Rétablir le lecteur</Button>
              : <Button variant="secondary" icon="clock" onClick={() => setSuspending('suspend')}>Suspendre le lecteur</Button>)}
            {can('devices.revoke') && d.status !== 'revoked' && <Button variant="danger" icon="shield" onClick={() => setRevoking(true)}>Révoquer le lecteur</Button>}
          </div>
        </div>
      </section>
      <section className="panel">
        <div className="panel-title">
          <div><h2>Capture de rendu</h2><p>Image transmise par le lecteur, conservée 7 jours</p></div>
          {(can('screens.manage') || can('devices.command')) && <Button variant="secondary" icon="image" onClick={requestShot} busy={requesting} disabled={d.status === 'revoked' || !d.capabilities?.includes('screenshot')} title={d.capabilities?.includes('screenshot') ? undefined : 'Le lecteur n’a pas déclaré la capture (version antérieure)'}>Demander une capture</Button>}
        </div>
        <div className="panel-body">
          {!shot ? <p className="muted small">Aucune capture demandée.</p> : (
            <div className="screenshot-box">
              <Kv items={[
                ['Demandée le', fmtDateTime(shot.requested_at)],
                ['État', shot.status === 'captured' ? 'Capturée' : shot.status === 'requested' ? 'En attente de la prochaine liaison du lecteur' : shot.status],
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
      {d.status === 'active' && <div style={{ gridColumn: '1 / -1' }}><CommandsPanel deviceId={d.id} deviceCode={d.code} /></div>}
      <div style={{ gridColumn: '1 / -1' }}><DeviceLogs deviceId={d.id} /></div>
      {identityOpen && <DeviceIdentityDialog device={d} screenId={s.id} onClose={() => setIdentityOpen(false)} />}
      {pinning && <PinReleaseDialog device={d} screenId={s.id} onClose={() => setPinning(false)} />}
      {rotating && (
        <ConfirmDialog title="Renouveler les identifiants" object={`${d.code} · écran ${s.code}`} confirmLabel="Demander le renouvellement" reasonLabel="Motif" reasonRequired
          consequence="Le lecteur obtient un nouveau jeton à son prochain battement ; l’ancien cesse de fonctionner dix minutes après. Un lecteur hors ligne l’effectuera à son retour."
          onClose={() => setRotating(false)}
          onConfirm={async (reason) => {
            const res = await api.post<{ message?: string }>(`/devices/${d.id}/rotate-credentials`, { reason })
            toast.success('Renouvellement demandé', res.message)
            await client.invalidateQueries({ queryKey: ['screen', s.id] })
          }} />
      )}
      {suspending && (
        <ConfirmDialog
          title={suspending === 'suspend' ? 'Suspendre le lecteur' : 'Rétablir le lecteur'} object={`${d.code} · écran ${s.code}`} danger={suspending === 'suspend'}
          confirmLabel={suspending === 'suspend' ? 'Suspendre' : 'Rétablir'}
          consequence={suspending === 'suspend'
            ? 'Le lecteur conserve son identité et reste supervisé, mais le serveur ne lui sert plus ni programme ni alerte : il n’affiche que le contenu de repli dès sa prochaine synchronisation. Réversible.'
            : 'Le lecteur reprendra le programme de son écran à sa prochaine synchronisation.'}
          reasonLabel="Motif" reasonRequired
          onClose={() => setSuspending(null)}
          onConfirm={async (reason) => {
            const res = await api.post<{ message?: string }>(`/devices/${d.id}/${suspending}`, { reason })
            toast.success(suspending === 'suspend' ? 'Lecteur suspendu' : 'Lecteur rétabli', res.message)
            await client.invalidateQueries({ queryKey: ['screen', s.id] })
            client.invalidateQueries({ queryKey: ['screens'] })
          }}
        />
      )}
      {reassigning && <ReassignDeviceDialog deviceId={d.id} deviceCode={d.code} screenId={s.id} onClose={() => setReassigning(false)} />}
      {revoking && (
        <ConfirmDialog
          title="Révoquer le lecteur" object={`${d.code} · écran ${s.code}`} danger confirmLabel="Révoquer définitivement"
          consequence="Le jeton du lecteur est invalidé immédiatement et il ne recevra plus de programme. S’il est hors ligne, il continue à lire son cache jusqu’à l’expiration de l’autorisation en cours : le retrait effectif attend cette expiration."
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

/** §9.2 : identité propre du lecteur. */
function DeviceIdentityDialog({ device, screenId, onClose }: { device: Device; screenId: string; onClose: () => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const [form, setForm] = useState({ name: device.name ?? '', hardware_type: device.hardware_type ?? '', serial_number: device.serial_number ?? '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const save = async () => {
    setBusy(true)
    setError(null)
    try {
      await api.patch(`/devices/${device.id}`, { name: form.name || null, hardware_type: form.hardware_type || null, serial_number: form.serial_number || null })
      await client.invalidateQueries({ queryKey: ['screen', screenId] })
      toast.success('Identité du lecteur enregistrée', device.code)
      onClose()
    } catch (e) {
      setError(e instanceof ApiError ? e : null)
      toast.error(e)
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title="Identité du lecteur" kicker={device.code} onClose={onClose} footer={<><Button variant="secondary" onClick={onClose}>Annuler</Button><Button icon="check" busy={busy} onClick={save}>Enregistrer</Button></>}>
      <div className="form-grid">
        <Field label="Nom" error={error?.field('name')}><input maxLength={120} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
        <Field label="Type de matériel" error={error?.field('hardware_type')} help="Ex. mini-PC, boîtier Android, carte Raspberry Pi"><input maxLength={60} value={form.hardware_type} onChange={(e) => setForm({ ...form, hardware_type: e.target.value })} /></Field>
        <Field label="Numéro de série" error={error?.field('serial_number')}><input maxLength={80} className="mono" value={form.serial_number} onChange={(e) => setForm({ ...form, serial_number: e.target.value })} /></Field>
      </div>
    </Modal>
  )
}

/** §9.8 : mise à jour ciblée — épinglage d'une version active sur ce lecteur. */
function PinReleaseDialog({ device, screenId, onClose }: { device: Device; screenId: string; onClose: () => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const releases = useQuery({ queryKey: ['player-releases'], queryFn: ({ signal }) => api.get<{ data: { id: string; version: string; channel: string; status: string }[] }>('/player-releases', undefined, signal).then((r) => r.data) })
  const [release, setRelease] = useState(device.pinned_release_id ?? '')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const save = async () => {
    setBusy(true)
    try {
      const res = await api.patch<{ target: string | null }>(`/devices/${device.id}/release`, { release_id: release || null, reason: reason.trim() })
      await client.invalidateQueries({ queryKey: ['screen', screenId] })
      toast.success(release ? 'Version épinglée' : 'Épinglage retiré', res.target ? `Version cible : ${res.target}` : 'Version embarquée')
      onClose()
    } catch (e) {
      toast.error(e)
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title="Mise à jour ciblée" kicker={device.code} onClose={onClose} footer={<><Button variant="secondary" onClick={onClose}>Annuler</Button><Button icon="check" busy={busy} disabled={reason.trim().length < 5} onClick={save}>Appliquer</Button></>}>
      <p>La version choisie s’applique à ce seul lecteur, quel que soit son canal, à sa prochaine synchronisation (empreinte vérifiée avant la bascule).</p>
      <div className="form-grid">
        <Field label="Version">
          <select value={release} onChange={(e) => setRelease(e.target.value)}>
            <option value="">Aucune (version de son canal)</option>
            {(releases.data ?? []).filter((r) => r.status === 'active').map((r) => <option key={r.id} value={r.id}>{r.version} · {r.channel === 'pilot' ? 'pilote' : 'stable'}</option>)}
          </select>
        </Field>
        <Field label="Motif" required><input maxLength={300} value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
      </div>
    </Modal>
  )
}

/** §10.2 : mesures de l'agent local ; une mesure absente reste « non mesurée », jamais estimée. */
function AgentTelemetry({ hw }: { hw: NonNullable<Device['hardware_info']> }) {
  const nm = 'Non mesuré'
  const num = (n: number) => n.toLocaleString('fr-FR', { maximumFractionDigits: 1 })
  const diskUsed = hw.disk_total_gb && hw.disk_free_gb != null ? Math.round(100 * (1 - hw.disk_free_gb / hw.disk_total_gb)) : null
  return (
    <>
      {hw.simulated && <p className="muted small">Agent en mode simulation (pilotage de la dalle simulé) ; les mesures système ci-dessous sont réelles.</p>}
      <Kv items={[
        ['Agent', `v${hw.agent_version}${hw.hostname ? ` · ${hw.hostname}` : ''}`],
        ['Système', hw.os ?? nm], ['Adresse IP · MAC', [hw.ip, hw.mac].filter(Boolean).join(' · ') || nm],
        ['Disque', hw.disk_total_gb != null && hw.disk_free_gb != null ? `${num(hw.disk_free_gb)} Go libres sur ${num(hw.disk_total_gb)} Go (${diskUsed} % utilisés)` : nm],
        ['Mémoire', hw.mem_total_gb != null ? `${num(hw.mem_free_gb ?? 0)} Go libres sur ${num(hw.mem_total_gb)} Go` : nm],
        ['Processeur', `${hw.cpu_count ?? '?'} cœur(s)${hw.cpu_load != null ? ` · charge ${hw.cpu_load} %` : ' · charge non mesurée sur ce système'}`],
        ['Température', hw.temperature_c != null ? `${num(hw.temperature_c)} °C` : 'Non mesurée (capteur indisponible)'],
        ['Allumé depuis', hw.uptime_s != null ? `${Math.floor(hw.uptime_s / 86400)} j ${Math.floor((hw.uptime_s % 86400) / 3600)} h` : nm],
      ]} />
    </>
  )
}

function ProgramTab({ s }: { s: ScreenDetailData }) {
  const { can } = useAuth()
  const [assigning, setAssigning] = useState(false)
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
        {q.data && <RenderPreview data={q.data} />}
        {can('campaigns.manage') && s.status !== 'archived' && (
          <div className="row-actions" style={{ justifyContent: 'flex-start', marginTop: 12 }}>
            <Button variant="secondary" icon="list" onClick={() => setAssigning(true)}>Affecter une liste de lecture</Button>
          </div>
        )}
      </div>
      {assigning && <AssignPlaylistDialog target={{ type: 'screen', id: s.id }} label={s.code} onClose={() => setAssigning(false)} />}
    </section>
  )
}

/**
 * §12.2 : aperçu visuel du rendu, à l'échelle et dans l'orientation de l'écran, pour les diapositives programmées à
 * l'instant choisi (gabarit par défaut ; les médias sont représentés par leur type).
 */
function RenderPreview({ data }: { data: ProgramPreview }) {
  const slides = data.result.mode === 'program' ? data.result.playlist.filter((e) => e.slide).slice(0, 4) : []
  const orientation = data.screen.orientation === 'portrait' ? 'portrait' : 'landscape'
  if (data.result.mode === 'alert' || slides.length === 0) return null
  return (
    <>
      <h3 className="sub-title">Aperçu du rendu ({data.screen.resolution.replace('x', ' × ')}, {orientation === 'portrait' ? 'portrait' : 'paysage'})</h3>
      <div className="render-preview-grid">
        {slides.map((e) => (
          <figure key={e.entry_id}>
            <TemplatePreview orientation={orientation} title={e.slide!.title} body={e.slide!.body} lang={e.slide!.lang} mediaLabel={e.slide!.kind !== 'text' ? e.slide!.kind : null} label={`Aperçu ${e.campaign_ref}`} />
            <figcaption className="small muted">{e.campaign_ref} · {e.duration_s} s · {e.lang.toUpperCase()}</figcaption>
          </figure>
        ))}
      </div>
    </>
  )
}

/** §7 : configuration en vigueur et confirmation d'application par le lecteur (empreinte identique). */
function ConfigurationPanel({ s }: { s: ScreenDetailData }) {
  const c = s.configuration
  if (!c) return null
  const SYNC: Record<string, [string, string]> = { applied: ['Appliquée et confirmée par le lecteur', 'success'], pending: ['En attente d’application', 'warning'], unknown: ['Non confirmée (lecteur sans compte rendu)', 'neutral'], no_device: ['Aucun lecteur', 'neutral'] }
  return (
    <section className="panel" style={{ gridColumn: '1 / -1' }}>
      <div className="panel-title">
        <div><h2>Configuration</h2><p>{c.profile ? `${c.profile.name} (${c.profile.code}) v${c.profile.version}${c.profile.inherited ? ' · hérité du groupe' : ''}` : 'Aucun profil : comportement par défaut du lecteur'}</p></div>
        <StatusOf map={SYNC} value={c.sync} />
      </div>
      <div className="panel-body">
        {Object.keys(c.settings ?? {}).length ? <Kv items={Object.entries(c.settings).map(([k, v]) => [SETTING_LABEL[k] ?? k, <>{formatSetting(k, v)}{c.unsupported.includes(k) ? <Status tone="warning">non pris en charge par ce lecteur</Status> : c.applied.includes(k) ? <Status tone="success">appliqué</Status> : null}</>])} />
          : <p className="muted small">Aucun paramètre défini.</p>}
        {c.reported_at && <p className="muted small">Dernier compte rendu du lecteur : {fmtDateTime(c.reported_at)}</p>}
        <h3 className="sub-title">Résolution et orientation</h3>
        <Kv items={[
          ['Résolution', <>{s.resolution.replace('x', ' × ')} <Status tone="neutral">non pilotée : la scène est mise à l’échelle de la sortie vidéo</Status>{s.device?.reported_resolution && s.device.reported_resolution !== s.resolution && <Status tone="warning">{`sortie déclarée ${s.device.reported_resolution.replace('x', ' × ')}`}</Status>}</>],
          ['Orientation', <>{ORIENTATION_LABEL[s.orientation] ?? s.orientation} {c.settings && (c.settings as Record<string, unknown>).software_rotation ? <Status tone="success">rotation logicielle activée par le profil</Status> : <Status tone="neutral">non pilotée : portée par la dalle ou le système</Status>}</>],
        ]} />
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
  const [touch, setTouch] = useState(!!s.touch_enabled)
  const [channel, setChannel] = useState(s.control_channel)
  const [confirming, setConfirming] = useState(false)
  const windowChanged = JSON.stringify([...days].sort()) !== JSON.stringify([...(initial.days ?? [])].sort()) || on !== initial.on || off !== initial.off || wake !== (initial.wake_before_min ?? 5) || ignoreHolidays !== !!initial.ignore_holidays
  const dirty = windowChanged || audio !== s.audio_enabled || touch !== !!s.touch_enabled || channel !== s.control_channel
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
            {s.kind === 'orientation' && <label className="check-label"><input type="checkbox" checked={touch} onChange={(e) => setTouch(e.target.checked)} /> Borne tactile : annuaire interactif des services et plans des niveaux</label>}
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
            ? `Nouvelle plage : ${fmtWindow({ days, on, off })}. Le lecteur l’appliquera à la réception de son prochain programme ; hors plage, l’écran passe en veille.`
            : 'Les réglages audio et de pilotage seront transmis au lecteur avec son prochain programme.'}
          reasonLabel={windowChanged ? 'Motif de la dérogation à la plage de fonctionnement' : undefined}
          reasonRequired={windowChanged}
          onClose={() => setConfirming(false)}
          onConfirm={async (reason) => {
            const body: Record<string, unknown> = { audio_enabled: audio, touch_enabled: touch, control_channel: channel }
            if (windowChanged) {
              body.operating_window = { days, on, off, wake_before_min: wake, ignore_holidays: ignoreHolidays }
              body.window_override_reason = reason
            }
            const res = await api.patch<{ data: ScreenDetailData }>(`/screens/${s.id}`, body, { 'If-Match': String(s.version) })
            client.setQueryData(['screen', s.id], res.data)
            client.invalidateQueries({ queryKey: ['screens'] })
            toast.success('Fonctionnement enregistré')
          }}
        />
      )}
    </section>
  )
}

/** §10.1 : périodes successives d'états opérationnels, enregistrées par la supervision (une période par changement d'état). */
function StatusHistory({ screenId }: { screenId: string }) {
  const q = useQuery({
    queryKey: ['screen-status-history', screenId],
    queryFn: ({ signal }) => api.get<{ data: { id: number; state: string; network_status: string | null; started_at: string; ended_at: string | null; duration_s: number }[] }>(`/screens/${screenId}/status-history`, { per_page: 30 }, signal).then((r) => r.data),
  })
  const duration = (seconds: number) => (seconds < 3600 ? `${Math.max(1, Math.round(seconds / 60))} min` : seconds < 86400 ? `${(seconds / 3600).toFixed(1).replace('.', ',')} h` : `${(seconds / 86400).toFixed(1).replace('.', ',')} j`)
  return (
    <section className="panel" style={{ gridColumn: '1 / -1' }}>
      <div className="panel-title"><div><h2>Historique des états</h2><p>Relevé chaque minute par la supervision · 30 dernières périodes</p></div></div>
      {q.isLoading ? <Loading /> : q.isError ? <ErrorState error={q.error} onRetry={() => q.refetch()} /> : !q.data?.length ? <EmptyState title="Aucun état relevé" text="La supervision relève l’état de l’écran à chaque passage du planificateur." /> : (
        <div className="table-scroll">
          <table>
            <thead><tr><th>État</th><th>Réseau</th><th>Début</th><th>Fin</th><th>Durée</th></tr></thead>
            <tbody>
              {q.data.map((h) => (
                <tr key={h.id}>
                  <td><StatusOf map={OPERATIONAL_STATE} value={h.state} /></td>
                  <td>{h.network_status ? <StatusOf map={NETWORK_STATUS} value={h.network_status} /> : '—'}</td>
                  <td>{fmtDateTime(h.started_at)}</td>
                  <td>{h.ended_at ? fmtDateTime(h.ended_at) : <strong>en cours</strong>}</td>
                  <td>{duration(h.duration_s)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

const HISTORY_CATEGORY: Record<string, string> = {
  record: 'Fiche', location: 'Emplacement', device: 'Lecteur', group: 'Groupe', configuration: 'Configuration', assignment: 'Diffusion', command: 'Commande', incident: 'Incident', state: 'État', error: 'Erreurs du lecteur',
}

/** §13.1, §13.2 : historique unifié de l'écran, filtrable par catégorie. */
function UnifiedHistory({ screenId }: { screenId: string }) {
  const [category, setCategory] = useState('')
  const q = useQuery({
    queryKey: ['screen-history', screenId, category],
    queryFn: ({ signal }) => api.get<{ data: { at: string; category: string; label: string; detail: string | null; actor: string | null; outcome: string | null }[] }>(`/screens/${screenId}/history`, category ? { category } : undefined, signal).then((r) => r.data),
  })
  return (
    <section className="panel" style={{ gridColumn: '1 / -1' }}>
      <div className="panel-title">
        <div><h2>Historique de l’écran</h2><p>Fiche, emplacements, lecteurs, groupes, configuration, diffusions, commandes, incidents et états</p></div>
        <select className="input" aria-label="Catégorie d’événements" value={category} onChange={(e) => setCategory(e.target.value)} style={{ maxWidth: 220 }}>
          <option value="">Toutes les catégories</option>
          {Object.entries(HISTORY_CATEGORY).map(([c, l]) => <option key={c} value={c}>{l}</option>)}
        </select>
      </div>
      {q.isLoading ? <Loading /> : q.isError ? <ErrorState error={q.error} onRetry={() => q.refetch()} /> : !q.data?.length ? <EmptyState title="Aucun événement" text="Aucun événement dans cette catégorie." /> : (
        <div className="table-scroll" style={{ maxHeight: 480 }}>
          <table>
            <thead><tr><th>Date</th><th>Catégorie</th><th>Événement</th><th>Par</th></tr></thead>
            <tbody>
              {q.data.map((h, i) => (
                <tr key={i}>
                  <td>{fmtDateTime(h.at)}</td>
                  <td><span className="chip">{HISTORY_CATEGORY[h.category] ?? h.category}</span></td>
                  <td>{h.label}{h.detail && <small>{h.detail}</small>}</td>
                  <td>{h.actor ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

const LOG_KIND: Record<string, [string, 'danger' | 'warning' | 'success' | 'neutral']> = {
  error: ['Erreur', 'danger'], fallback: ['Contenu de repli', 'warning'], offline: ['Coupure', 'danger'], online: ['Reprise', 'success'], off_window: ['Hors plage', 'neutral'],
}

/** §9.12 : journaux techniques du lecteur (événements déclarés ou relevés de télémétrie), paginés. */
function DeviceLogs({ deviceId }: { deviceId: string }) {
  const [source, setSource] = useState<'events' | 'telemetry' | 'exchanges'>('events')
  const [kind, setKind] = useState('')
  const [page, setPage] = useState(1)
  type Row = { id: string; kind?: string; occurred_at?: string; recorded_at?: string; manifest_version?: number | null; ref_id?: string | null; payload: Record<string, unknown> | null }
  const q = useQuery({
    queryKey: ['device-logs', deviceId, source, kind, page],
    queryFn: ({ signal }) => api.get<{ data: Row[]; meta: { current_page: number; last_page: number; total: number; per_page: number } }>(`/devices/${deviceId}/logs`, { source, kind: source === 'events' && kind ? kind : undefined, page }, signal),
  })
  const summary = (p: Record<string, unknown> | null) => {
    if (!p) return '—'
    const text = typeof p.message === 'string' ? p.message : JSON.stringify(p)
    return text.length > 220 ? `${text.slice(0, 220)}…` : text
  }
  return (
    <section className="panel table-panel">
      <div className="panel-title">
        <div><h2>Journaux techniques du lecteur</h2><p>Événements déclarés par le lecteur (erreurs, repli, coupures, reprises) et relevés de télémétrie conservés</p></div>
        <div className="row-actions">
          <select className="input" aria-label="Source des journaux" value={source} onChange={(e) => { setSource(e.target.value as 'events' | 'telemetry' | 'exchanges'); setPage(1) }}>
            <option value="events">Événements</option><option value="telemetry">Télémétrie</option><option value="exchanges">Échanges avec le serveur</option>
          </select>
          {source === 'events' && (
            <select className="input" aria-label="Type d’événement" value={kind} onChange={(e) => { setKind(e.target.value); setPage(1) }}>
              <option value="">Tous les types</option>{Object.entries(LOG_KIND).map(([k, [l]]) => <option key={k} value={k}>{l}</option>)}
            </select>
          )}
        </div>
      </div>
      {q.isLoading ? <Loading /> : q.isError || !q.data ? <ErrorState error={q.error} onRetry={() => q.refetch()} /> : q.data.data.length === 0 ? <EmptyState title="Aucune entrée" text="Le lecteur n’a rien déclaré pour ce filtre." /> : (
        <>
          <div className="table-scroll" style={{ maxHeight: 420 }}>
            <table>
              <thead><tr><th scope="col">Date</th>{source === 'events' && <th scope="col">Type</th>}<th scope="col">Détail</th></tr></thead>
              <tbody>
                {q.data.data.map((r) => (
                  <tr key={r.id}>
                    <td>{fmtDateTime(r.occurred_at ?? r.recorded_at ?? null)}</td>
                    {source === 'events' && <td>{r.kind && LOG_KIND[r.kind] ? <Status tone={LOG_KIND[r.kind][1]}>{LOG_KIND[r.kind][0]}</Status> : r.kind}</td>}
                    <td className="mono small">{summary(r.payload)}{r.manifest_version ? ` · programme v${r.manifest_version}` : ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination meta={q.data.meta} onPage={setPage} />
        </>
      )}
    </section>
  )
}

function HistoryTab({ s }: { s: ScreenDetailData }) {
  return (
    <div className="grid-2">
      <UnifiedHistory screenId={s.id} />
      <StatusHistory screenId={s.id} />
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
