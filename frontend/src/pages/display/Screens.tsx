import { useEffect, useMemo, useState } from 'react'
import ImportScreensDialog from './ImportScreensDialog'
import { ExportDialog } from '../admin/common'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useList, useData } from '../../lib/hooks'
import { fmtDateTime, fmtRelative, fmtTime, NETWORK_STATUS } from '../../lib/format'
import { Button, EmptyState, ErrorState, Field, Icon, Loading, Modal, Notice, PageHeading, Pagination, Select, Status, StatusOf, Toolbar, useDebounced, useToast } from '../../components/ui'
import { CRITICALITY_LABEL, fmtWindow, KIND_LABEL, networkOf, OPERATIONAL_STATE, ORIENTATION_LABEL, PANEL_STATE, RESOLUTIONS, useReferentials, useScreenGroups, type Screen } from './shared'

interface ParkSummary {
  last_contact: string | null
  cards: { screens: { online: number; total: number; suspect: number; unavailable: number; unpaired: number } }
  floors: { id: string; label: string; total: number; online: number; state: string }[]
}

const FILTER_KEYS = ['q', 'building_id', 'floor_id', 'room_id', 'zone_id', 'group_id', 'network', 'kind', 'status', 'state', 'category_id', 'criticality', 'commissioned'] as const
const SCREEN_STATUS: Record<string, string> = { active: 'En service', maintenance: 'En maintenance', inactive: 'Désactivés', archived: 'Archivés' }

/** V07 Parc des écrans : état réseau du lecteur distinct de l'état physique de la dalle (PLY-06, PLY-08). */
export default function Screens() {
  const { me, can } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('q') ?? '')
  const debounced = useDebounced(search)
  const [creating, setCreating] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [exportDone, setExportDone] = useState(false)

  const filters = useMemo(() => Object.fromEntries(FILTER_KEYS.map((k) => [k, params.get(k) ?? undefined])) as Record<(typeof FILTER_KEYS)[number], string | undefined>, [params])
  const setFilter = (key: (typeof FILTER_KEYS)[number], value: string) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    setParams(next, { replace: true })
  }

  useEffect(() => {
    if ((params.get('q') ?? '') !== debounced) setFilter('q', debounced)
  }, [debounced])

  const list = useList<Screen>('screens', '/screens', filters, 20)
  const { setPage } = list
  useEffect(() => setPage(1), [params, setPage])
  const summary = useData<ParkSummary>(['dashboard'], '/dashboard')
  const refs = useReferentials()
  const groups = useScreenGroups()

  const [importing, setImporting] = useState(false)

  const s = summary.data?.cards.screens
  const summaryItems: { key: string; value: number | undefined; label: string; cls?: string }[] = [
    { key: '', value: s?.total, label: 'écrans en service' },
    { key: 'online', value: s?.online, label: 'connectés', cls: 'green-text' },
    { key: 'suspect', value: s?.suspect, label: 'suspects', cls: 'amber-text' },
    { key: 'unavailable', value: s?.unavailable, label: 'indisponibles', cls: 'red-text' },
    { key: 'unpaired', value: s?.unpaired, label: 'non appairés' },
  ]

  return (
    <>
      <PageHeading
        title="Tous les écrans"
        description="Écrans physiques, lecteurs associés et état de contact. L’état réseau du lecteur ne renseigne pas l’allumage de la dalle."
        demo={me.demo_mode}
        actions={<>
          {can('exports.create') && <Button variant="secondary" icon="download" onClick={() => setExporting(true)}>Exporter l’inventaire</Button>}
          {can('screens.manage') && <Button variant="secondary" icon="upload" onClick={() => setImporting(true)}>Importer</Button>}
          {can('devices.pair') && <Button variant="secondary" icon="wifi" onClick={() => navigate('/affichage/appairage')}>Enrôler un lecteur</Button>}
          {can('screens.manage') && <Button icon="plus" onClick={() => setCreating(true)}>Nouvel écran</Button>}
        </>}
      />
      {exportDone && (
        <div style={{ marginBottom: 12 }}>
          <Notice tone="success">Export demandé. Suivez sa génération et téléchargez-le depuis <Link className="link-text" to="/traitements">Exports & traitements</Link>.</Notice>
        </div>
      )}

      {summary.isError ? null : (
        <div className="screen-summary" role="group" aria-label="Synthèse du parc">
          {summaryItems.map((it) => (
            <button key={it.label} aria-pressed={(filters.network ?? '') === it.key} onClick={() => setFilter('network', it.key)} title={it.key ? `Filtrer : ${it.label}` : 'Retirer le filtre réseau'}>
              <strong className={it.cls}>{it.value ?? '…'}</strong>
              <span>{it.label}</span>
            </button>
          ))}
        </div>
      )}

      <div className="split-view">
        <section className="panel building-panel">
          <div className="panel-title">
            <div>
              <h2>Synoptique du siège</h2>
              <p>Représentation schématique · cliquer un niveau pour filtrer{summary.data?.last_contact ? ` · dernier contact ${fmtTime(summary.data.last_contact)} (${fmtRelative(summary.data.last_contact)})` : ''}</p>
            </div>
            {s && <Status tone={s.online === s.total ? 'success' : 'warning'}>{s.online} / {s.total} en ligne</Status>}
          </div>
          {summary.isLoading ? <Loading /> : summary.isError ? <ErrorState error={summary.error} onRetry={() => summary.refetch()} /> : (
            <div className="tower">
              {(summary.data?.floors ?? []).map((f) => {
                const offline = f.total - f.online
                const pressed = filters.floor_id === f.id
                return (
                  <button key={f.id} className={offline > 0 ? 'issue' : ''} aria-pressed={pressed} onClick={() => setFilter('floor_id', pressed ? '' : f.id)} aria-label={`${f.label} : ${f.online} sur ${f.total} écrans en ligne${pressed ? ' (filtre actif)' : ''}`}>
                    <span>{f.label}</span>
                    <div aria-hidden="true">
                      {Array.from({ length: Math.min(f.total, 8) }, (_, i) => <i key={i} className={i >= f.online ? 'offline' : ''} />)}
                      {f.total > 8 && <span className="tower-more">+{f.total - 8}</span>}
                    </div>
                    <strong>{f.online} / {f.total}</strong>
                    <Icon name="chevron" size={15} />
                  </button>
                )
              })}
              {(summary.data?.floors ?? []).length === 0 && <p className="muted small">Aucun niveau avec écran inventorié.</p>}
            </div>
          )}
        </section>

        <section className="panel table-panel">
          <Toolbar value={search} onChange={setSearch} placeholder="Code, inventaire, série, marque, emplacement, salle…" right={<Button variant="ghost" size="small" icon="x" onClick={() => { setSearch(''); setParams(new URLSearchParams(), { replace: true }) }} disabled={!FILTER_KEYS.some((k) => params.get(k))}>Réinitialiser</Button>} />
          <div className="toolbar filters-row">
            <Select label="Niveau" allLabel="Tous les niveaux" value={filters.floor_id ?? ''} onChange={(v) => setFilter('floor_id', v)} options={(refs.data?.floors ?? []).map((f) => ({ value: f.id, label: f.label }))} />
            <Select label="Salle" allLabel="Toutes les salles" value={filters.room_id ?? ''} onChange={(v) => setFilter('room_id', v)} options={(refs.data?.rooms ?? []).filter((r) => !filters.floor_id || r.floor_id === filters.floor_id).map((r) => ({ value: r.id, label: r.name }))} />
            <Select label="Bâtiment" allLabel="Tous les bâtiments" value={filters.building_id ?? ''} onChange={(v) => setFilter('building_id', v)} options={(refs.data?.buildings ?? []).map((b) => ({ value: b.id, label: b.name }))} />
            <Select label="État opérationnel" allLabel="Tous les états" value={filters.state ?? ''} onChange={(v) => setFilter('state', v)} options={Object.entries(OPERATIONAL_STATE).map(([value, [label]]) => ({ value, label }))} />
            <Select label="Catégorie" allLabel="Toutes les catégories" value={filters.category_id ?? ''} onChange={(v) => setFilter('category_id', v)} options={(refs.data?.screen_categories ?? []).map((c) => ({ value: c.id, label: c.name }))} />
            <Select label="Criticité" allLabel="Toutes" value={filters.criticality ?? ''} onChange={(v) => setFilter('criticality', v)} options={Object.entries(CRITICALITY_LABEL).map(([value, label]) => ({ value, label }))} />
            <Select label="Mise en service" allLabel="Tous" value={filters.commissioned ?? ''} onChange={(v) => setFilter('commissioned', v)} options={[{ value: '1', label: 'Mis en service' }, { value: '0', label: 'Non mis en service' }]} />
            <Select label="Zone" allLabel="Toutes les zones" value={filters.zone_id ?? ''} onChange={(v) => setFilter('zone_id', v)} options={(refs.data?.zones ?? []).map((z) => ({ value: z.id, label: `${z.name} (${z.code})` }))} />
            <Select label="Groupe" allLabel="Tous les groupes" value={filters.group_id ?? ''} onChange={(v) => setFilter('group_id', v)} options={(groups.data ?? []).map((g) => ({ value: g.id, label: g.name }))} />
            <Select label="État réseau" allLabel="Tous les états" value={filters.network ?? ''} onChange={(v) => setFilter('network', v)} options={['online', 'suspect', 'unavailable', 'unpaired'].map((k) => ({ value: k, label: NETWORK_STATUS[k][0] }))} />
            <Select label="Type d’écran" allLabel="Tous les types" value={filters.kind ?? ''} onChange={(v) => setFilter('kind', v)} options={Object.entries(KIND_LABEL).map(([value, label]) => ({ value, label }))} />
            <Select label="Statut" allLabel="Hors archivés" value={filters.status ?? ''} onChange={(v) => setFilter('status', v)} options={Object.entries(SCREEN_STATUS).map(([value, label]) => ({ value, label }))} />
          </div>
          {list.isLoading ? <Loading /> : list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : list.items.length === 0 ? (
            <EmptyState title="Aucun écran" text={FILTER_KEYS.some((k) => params.get(k)) ? 'Aucun écran ne correspond aux filtres.' : 'Aucun écran n’est encore inventorié.'} />
          ) : (
            <>
              <div className="table-scroll">
                <table>
                  <thead>
                    <tr><th>Code</th><th>État</th><th>Emplacement</th><th>Zone</th><th>Orientation</th><th>Plage</th><th>Réseau</th><th>Dalle</th><th>Dernier contact</th><th>Programme</th></tr>
                  </thead>
                  <tbody>
                    {list.items.map((sc) => (
                      <tr key={sc.id}>
                        <td><Link className="cell-link" to={`/affichage/ecrans/${sc.id}`}>{sc.code}</Link><small>{sc.name} · {KIND_LABEL[sc.kind] ?? sc.kind}</small></td>
                        <td>{sc.operational_state ? <StatusOf map={OPERATIONAL_STATE} value={sc.operational_state.code} /> : '—'}</td>
                        <td>{sc.location?.name ?? '—'}<small>{sc.location?.floor?.label ?? ''}</small></td>
                        <td>{sc.location?.zone?.name ?? '—'}</td>
                        <td>{ORIENTATION_LABEL[sc.orientation] ?? sc.orientation}<small>{sc.resolution}</small></td>
                        <td>{fmtWindow(sc.operating_window)}{sc.window_override_reason && <small>Dérogation</small>}</td>
                        <td><StatusOf map={NETWORK_STATUS} value={networkOf(sc)} />{sc.device && <small>{sc.device.code}</small>}</td>
                        <td><StatusOf map={PANEL_STATE} value={sc.panel_state} /></td>
                        <td>{sc.device?.last_seen_at ? <>{fmtRelative(sc.device.last_seen_at)}<small>{fmtDateTime(sc.device.last_seen_at)}</small></> : '—'}</td>
                        <td>{sc.device?.active_manifest_version ? `v${sc.device.active_manifest_version}` : '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <Pagination meta={list.meta} onPage={list.setPage} />
            </>
          )}
        </section>
      </div>
      {importing && <ImportScreensDialog onClose={() => setImporting(false)} />}
      {exporting && (
        <ExportDialog type="equipment_inventory" title="Inventaire des équipements" withPeriod={false} params={{ building_id: filters.building_id, floor_id: filters.floor_id, include_archived: filters.status === 'archived' }} onClose={() => { setExporting(false); setExportDone(true) }}>
          <p className="small muted">Inventaire général, ou du niveau sélectionné dans les filtres ; borné au périmètre de votre habilitation.</p>
        </ExportDialog>
      )}
      {creating && <NewScreenModal onClose={() => setCreating(false)} onCreated={(id) => navigate(`/affichage/ecrans/${id}`)} />}
    </>
  )
}

interface ScreenForm { code: string; name: string; kind: string; location_id: string; room_id: string; orientation: string; resolution: string }

export function ScreenFormFields({ form, setForm, error, lockCode }: { form: ScreenForm; setForm: (f: ScreenForm) => void; error: ApiError | null; lockCode?: boolean }) {
  const refs = useReferentials()
  const rooms = useQuery({
    queryKey: ['rooms', 'select'],
    queryFn: ({ signal }) => api.get<{ data: { id: string; code: string; name: string }[] }>('/rooms', { per_page: 500 }, signal).then((r) => r.data),
    // Tout écran peut être rattaché à une salle (§5.3) ; elle est obligatoire pour un panneau de salle.
  })
  const set = (k: keyof ScreenForm, v: string) => setForm({ ...form, [k]: v })
  return (
    <div className="form-grid">
      <Field label="Code" required error={error?.field('code')} help="Majuscules, chiffres et tirets (ex. ECR-2E-02)">
        <input value={form.code} onChange={(e) => set('code', e.target.value.toUpperCase())} disabled={lockCode} aria-invalid={!!error?.field('code')} maxLength={40} />
      </Field>
      <Field label="Nom" required error={error?.field('name')}>
        <input value={form.name} onChange={(e) => set('name', e.target.value)} aria-invalid={!!error?.field('name')} maxLength={120} />
      </Field>
      <Field label="Type d’écran" required error={error?.field('kind')}>
        <select value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
          {Object.entries(KIND_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
      </Field>
      <Field label="Emplacement" required error={error?.field('location_id')} help={refs.isError ? 'Référentiel des emplacements indisponible' : undefined}>
        <select value={form.location_id} onChange={(e) => set('location_id', e.target.value)} aria-invalid={!!error?.field('location_id')}>
          <option value="">— Choisir —</option>
          {(refs.data?.locations ?? []).filter((l) => l.is_active !== false || l.id === form.location_id).map((l) => (
            <option key={l.id} value={l.id}>{l.floor?.label ? `${l.floor.label} · ` : ''}{l.name}{l.zone ? ` (${l.zone.name})` : ''}</option>
          ))}
        </select>
      </Field>
      {(
        <Field label="Salle associée" required={form.kind === 'room_panel'} wide error={error?.field('room_id')} help={rooms.isError ? 'Liste des salles indisponible' : form.kind === 'room_panel' ? undefined : 'Facultatif : salle où l’écran est installé (même niveau que l’emplacement)'}>
          <select value={form.room_id} onChange={(e) => set('room_id', e.target.value)} aria-invalid={!!error?.field('room_id')}>
            <option value="">{form.kind === 'room_panel' ? '— Choisir —' : 'Aucune salle'}</option>
            {(rooms.data ?? []).map((r) => <option key={r.id} value={r.id}>{r.code} · {r.name}</option>)}
          </select>
        </Field>
      )}
      <Field label="Orientation" required error={error?.field('orientation')}>
        <select value={form.orientation} onChange={(e) => set('orientation', e.target.value)}>
          {Object.entries(ORIENTATION_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
      </Field>
      <Field label="Résolution" required error={error?.field('resolution')}>
        <select value={form.resolution} onChange={(e) => set('resolution', e.target.value)}>
          {RESOLUTIONS.map((r) => <option key={r} value={r}>{r.replace('x', ' × ')}</option>)}
        </select>
      </Field>
    </div>
  )
}

function NewScreenModal({ onClose, onCreated }: { onClose: () => void; onCreated: (id: string) => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const [form, setForm] = useState<ScreenForm>({ code: '', name: '', kind: 'display', location_id: '', room_id: '', orientation: 'landscape', resolution: '1920x1080' })
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: Screen }>('/screens', { ...form, room_id: form.room_id || null })
      client.invalidateQueries({ queryKey: ['screens'] })
      client.invalidateQueries({ queryKey: ['dashboard'] })
      toast.success('Écran créé', `${res.data.code} est inventorié. Appairez un lecteur pour le mettre en service.`)
      onCreated(res.data.id)
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title="Nouvel écran" kicker="INVENTAIRE" onClose={onClose} wide footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button icon="check" onClick={submit} busy={busy} disabled={!form.code || !form.name || !form.location_id}>Créer l’écran</Button>
    </>}>
      <p className="muted small" style={{ marginBottom: 12 }}>L’écran physique et le lecteur sont deux objets distincts : le lecteur sera associé ensuite par code d’appairage.</p>
      <ScreenFormFields form={form} setForm={setForm} error={error} />
      {error && !Object.keys(error.errors).length && <div style={{ marginTop: 12 }}><Notice tone="danger">{error.message}</Notice></div>}
    </Modal>
  )
}
