import { useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api, type ApiError } from '../../lib/api'
import { fmtDate } from '../../lib/format'
import { Button, ConfirmDialog, EmptyState, ErrorState, Field, Icon, Loading, Modal, Notice, PageHeading, Status, Tabs, useToast } from '../../components/ui'
import { asApiError, useReferentials, type RefItem, type Referentials as RefData } from './common'
import './admin.css'

type RefType = 'sites' | 'buildings' | 'floors' | 'zones' | 'locations' | 'structures' | 'categories' | 'languages' | 'service_types' | 'holidays' | 'equipment'
type FieldKind = 'text' | 'number' | 'bool' | 'select' | 'date' | 'textarea'
interface FieldDef { name: string; label: string; kind: FieldKind; required?: boolean; options?: (d: RefData) => { value: string; label: string }[]; help?: string; createOnly?: boolean }

const opt = (items: RefItem[], label: (i: RefItem) => string) => items.map((i) => ({ value: i.id, label: label(i) + (i.is_active === false ? ' (inactif)' : '') }))
const nameOf = (i: RefItem) => String(i.name ?? i.label ?? i.code ?? '')

const DEFS: Record<RefType, { label: string; singular: string; fields: FieldDef[]; activable: boolean }> = {
  sites: { label: 'Sites', singular: 'site', activable: true, fields: [
    { name: 'code', label: 'Code', kind: 'text', required: true }, { name: 'name', label: 'Nom', kind: 'text', required: true },
    { name: 'timezone', label: 'Fuseau horaire', kind: 'text', help: 'Ex. Africa/Libreville' }, { name: 'address', label: 'Adresse', kind: 'text' }] },
  buildings: { label: 'Bâtiments', singular: 'bâtiment', activable: true, fields: [
    { name: 'code', label: 'Code', kind: 'text', required: true }, { name: 'name', label: 'Nom', kind: 'text', required: true },
    { name: 'site_id', label: 'Site', kind: 'select', required: true, options: (d) => opt(d.sites, nameOf) }] },
  floors: { label: 'Niveaux', singular: 'niveau', activable: true, fields: [
    { name: 'code', label: 'Code', kind: 'text', required: true }, { name: 'label', label: 'Libellé', kind: 'text', required: true },
    { name: 'level', label: 'Niveau (entier, −5 à 60)', kind: 'number', required: true }, { name: 'building_id', label: 'Bâtiment', kind: 'select', required: true, options: (d) => opt(d.buildings, nameOf) }] },
  locations: { label: 'Emplacements', singular: 'emplacement', activable: true, fields: [
    { name: 'code', label: 'Code', kind: 'text', required: true }, { name: 'name', label: 'Nom', kind: 'text', required: true },
    { name: 'floor_id', label: 'Niveau', kind: 'select', required: true, options: (d) => opt(d.floors, nameOf) },
    { name: 'zone_id', label: 'Zone de diffusion', kind: 'select', required: true, options: (d) => opt(d.zones, nameOf) },
    { name: 'description', label: 'Description', kind: 'textarea' }] },
  zones: { label: 'Zones de diffusion', singular: 'zone', activable: true, fields: [
    { name: 'code', label: 'Code', kind: 'text', required: true }, { name: 'name', label: 'Nom', kind: 'text', required: true },
    { name: 'max_classification', label: 'Classification maximale', kind: 'select', required: true, options: () => [{ value: 'public', label: 'Public' }, { value: 'interne', label: 'Interne' }], help: 'Un contenu plus sensible ne peut pas y être diffusé.' },
    { name: 'audio_allowed', label: 'Audio autorisé', kind: 'bool' }, { name: 'audio_max_volume', label: 'Volume maximal (0–100)', kind: 'number' }] },
  structures: { label: 'Structures', singular: 'structure', activable: true, fields: [
    { name: 'code', label: 'Code', kind: 'text', required: true }, { name: 'name', label: 'Nom', kind: 'text', required: true },
    { name: 'type', label: 'Type', kind: 'select', required: true, options: () => ['cabinet', 'departement', 'direction', 'service', 'autre'].map((v) => ({ value: v, label: STRUCT_TYPE[v] })) },
    { name: 'parent_id', label: 'Structure parente', kind: 'select', options: (d) => opt(d.structures, nameOf) },
    { name: 'floor_id', label: 'Niveau', kind: 'select', options: (d) => opt(d.floors, nameOf) },
    { name: 'show_in_orientation', label: 'Afficher dans l’orientation', kind: 'bool' }, { name: 'storage_quota_mb', label: 'Quota de stockage (Mo)', kind: 'number' }] },
  categories: { label: 'Catégories', singular: 'catégorie', activable: true, fields: [
    { name: 'code', label: 'Code', kind: 'text', required: true }, { name: 'name', label: 'Nom', kind: 'text', required: true },
    { name: 'default_duration_s', label: 'Durée d’affichage par défaut (10–120 s)', kind: 'number' }] },
  languages: { label: 'Langues', singular: 'langue', activable: true, fields: [
    { name: 'code', label: 'Code', kind: 'select', required: true, createOnly: true, options: () => ['fr', 'en', 'pt', 'es'].map((v) => ({ value: v, label: v.toUpperCase() })) },
    { name: 'name', label: 'Nom', kind: 'text', required: true }, { name: 'sort', label: 'Ordre', kind: 'number' }] },
  equipment: { label: 'Équipements', singular: 'équipement', activable: true, fields: [{ name: 'code', label: 'Code', kind: 'text', required: true }, { name: 'name', label: 'Nom', kind: 'text', required: true }] },
  service_types: { label: 'Types de prestation', singular: 'type de prestation', activable: true, fields: [
    { name: 'code', label: 'Code', kind: 'text', required: true, createOnly: true, help: 'Non modifiable ensuite : les périmètres du pouvoir « prestations » y font référence.' },
    { name: 'name', label: 'Nom', kind: 'text', required: true },
    { name: 'requires_headcount', label: 'Couverts et heure exigés', kind: 'bool', help: 'La demande de prestation exige le nombre de couverts et l’heure de service (restauration).' }] },
  holidays: { label: 'Jours non ouvrés', singular: 'jour non ouvré', activable: false, fields: [{ name: 'date', label: 'Date', kind: 'date', required: true }, { name: 'label', label: 'Libellé', kind: 'text', required: true }] },
}
const STRUCT_TYPE: Record<string, string> = { cabinet: 'Cabinet', departement: 'Département', direction: 'Direction', service: 'Service', autre: 'Autre' }

type Tab = 'tree' | 'zones' | 'structures' | 'categories' | 'languages' | 'equipment' | 'service_types' | 'holidays'
interface Editing { type: RefType; item?: RefItem; preset?: Record<string, unknown> }

/** V36 Référentiels : ajout, modification, désactivation (jamais de suppression d'un objet référencé, REF-03). */
export default function Referentials() {
  const query = useReferentials()
  const [tab, setTab] = useState<Tab>('tree')
  const [editing, setEditing] = useState<Editing | null>(null)
  const [toggling, setToggling] = useState<{ type: RefType; item: RefItem } | null>(null)
  const client = useQueryClient()
  const toast = useToast()

  return (
    <>
      <PageHeading title="Référentiels" description="Lieux, zones de diffusion, structures et listes de valeurs. Les éléments utilisés sont désactivés, jamais supprimés." />
      <Tabs<Tab> value={tab} onChange={setTab} items={[
        { value: 'tree', label: 'Sites & emplacements' }, { value: 'zones', label: 'Zones de diffusion' }, { value: 'structures', label: 'Structures' },
        { value: 'categories', label: 'Catégories' }, { value: 'languages', label: 'Langues' }, { value: 'equipment', label: 'Équipements' },
        { value: 'service_types', label: 'Types de prestation' }, { value: 'holidays', label: 'Jours non ouvrés' },
      ]} />
      {query.isLoading ? <Loading /> : query.isError || !query.data ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : (
        tab === 'tree' ? <Tree d={query.data} onEdit={setEditing} onToggle={(type, item) => setToggling({ type, item })} />
          : tab === 'structures' ? <StructureTree d={query.data} onEdit={setEditing} onToggle={(type, item) => setToggling({ type, item })} />
            : <FlatList type={tab} d={query.data} onEdit={setEditing} onToggle={(type, item) => setToggling({ type, item })} />
      )}
      {editing && query.data && <RefForm {...editing} d={query.data} onClose={() => setEditing(null)} />}
      {toggling && (
        <ConfirmDialog
          title={toggling.item.is_active === false ? `Réactiver ce ${DEFS[toggling.type].singular}` : `Désactiver ce ${DEFS[toggling.type].singular}`}
          object={nameOf(toggling.item)}
          danger={toggling.item.is_active !== false}
          consequence={toggling.item.is_active === false ? 'L’élément redevient proposé dans les formulaires.' : 'L’élément n’est plus proposé pour de nouvelles saisies ; les objets qui l’utilisent déjà le conservent et l’historique reste intact.'}
          confirmLabel={toggling.item.is_active === false ? 'Réactiver' : 'Désactiver'}
          onClose={() => setToggling(null)}
          onConfirm={async () => {
            await api.patch(`/referentials/${toggling.type}/${toggling.item.id}`, { is_active: toggling.item.is_active === false })
            await client.invalidateQueries({ queryKey: ['referentials'] })
            toast.success(toggling.item.is_active === false ? 'Élément réactivé' : 'Élément désactivé')
          }}
        />
      )}
    </>
  )
}

type Handlers = { onEdit: (e: Editing) => void; onToggle: (type: RefType, item: RefItem) => void }

function RowActions({ type, item, onEdit, onToggle, addChild }: Handlers & { type: RefType; item: RefItem; addChild?: { type: RefType; label: string; preset: Record<string, unknown> } }) {
  return (
    <span className="row-actions">
      {addChild && <Button size="small" variant="ghost" icon="plus" onClick={() => onEdit({ type: addChild.type, preset: addChild.preset })} ariaLabel={`Ajouter ${addChild.label} à ${nameOf(item)}`}>{addChild.label}</Button>}
      <Button size="small" variant="secondary" icon="edit" onClick={() => onEdit({ type, item })} ariaLabel={`Modifier ${nameOf(item)}`} />
      {DEFS[type].activable && <Button size="small" variant="secondary" onClick={() => onToggle(type, item)}>{item.is_active === false ? 'Réactiver' : 'Désactiver'}</Button>}
    </span>
  )
}

const ActiveTag = ({ item }: { item: RefItem }) => (item.is_active === false ? <Status tone="neutral">Inactif</Status> : null)

function Tree({ d, onEdit, onToggle }: { d: RefData } & Handlers) {
  const h = { onEdit, onToggle }
  return (
    <section className="panel">
      <div className="panel-title">
        <div><h2>Site › bâtiment › niveau › emplacement</h2><p>Chaque emplacement est rattaché à une zone de diffusion qui borne la classification affichable.</p></div>
        <Button size="small" icon="plus" onClick={() => onEdit({ type: 'sites' })}>Ajouter un site</Button>
      </div>
      <div className="panel-body">
        {d.sites.length === 0 ? <EmptyState title="Aucun site" /> : (
          <ul className="admin-tree">
            {d.sites.map((s) => (
              <li key={s.id}>
                <div className={`tree-row ${s.is_active === false ? 'inactive-row' : ''}`}>
                  <span className="tree-kind">Site</span><span><strong>{nameOf(s)}</strong><small className="mono">{s.code}</small> <ActiveTag item={s} /></span>
                  <RowActions type="sites" item={s} {...h} addChild={{ type: 'buildings', label: 'Bâtiment', preset: { site_id: s.id } }} />
                </div>
                <ul>
                  {d.buildings.filter((b) => b.site_id === s.id).map((b) => (
                    <li key={b.id}>
                      <div className={`tree-row ${b.is_active === false ? 'inactive-row' : ''}`}>
                        <span className="tree-kind">Bâtiment</span><span><strong>{nameOf(b)}</strong><small className="mono">{b.code}</small> <ActiveTag item={b} /></span>
                        <RowActions type="buildings" item={b} {...h} addChild={{ type: 'floors', label: 'Niveau', preset: { building_id: b.id } }} />
                      </div>
                      <ul>
                        {d.floors.filter((f) => f.building_id === b.id).map((f) => (
                          <li key={f.id}>
                            <div className={`tree-row ${f.is_active === false ? 'inactive-row' : ''}`}>
                              <span className="tree-kind">Niveau {String(f.level)}</span><span><strong>{nameOf(f)}</strong><small className="mono">{f.code}</small> <ActiveTag item={f} /></span>
                              <RowActions type="floors" item={f} {...h} addChild={{ type: 'locations', label: 'Emplacement', preset: { floor_id: f.id } }} />
                            </div>
                            <ul>
                              {d.locations.filter((l) => l.floor_id === f.id).map((l) => {
                                const zone = (l.zone as { name?: string; code?: string } | null) ?? null
                                return (
                                  <li key={l.id}>
                                    <div className={`tree-row ${l.is_active === false ? 'inactive-row' : ''}`}>
                                      <span className="tree-kind">Emplacement</span>
                                      <span><strong>{nameOf(l)}</strong><small className="mono">{l.code}</small><small>Zone : {zone?.name ?? '—'}</small> <ActiveTag item={l} /></span>
                                      <RowActions type="locations" item={l} {...h} />
                                    </div>
                                  </li>
                                )
                              })}
                            </ul>
                          </li>
                        ))}
                      </ul>
                    </li>
                  ))}
                </ul>
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  )
}

function StructureTree({ d, onEdit, onToggle }: { d: RefData } & Handlers) {
  const children = (parent: string | null): RefItem[] => d.structures.filter((s) => (s.parent_id ?? null) === parent)
  const known = new Set(d.structures.map((s) => s.id))
  const roots = d.structures.filter((s) => !s.parent_id || !known.has(String(s.parent_id)))
  const render = (s: RefItem, depth: number): ReactNode[] => [
    <tr key={s.id} className={s.is_active === false ? 'inactive-row' : ''}>
      <td style={{ paddingLeft: 12 + depth * 22 }}>{depth > 0 && <span aria-hidden="true" className="muted">└ </span>}<strong>{nameOf(s)}</strong><small className="mono">{s.code}</small></td>
      <td>{STRUCT_TYPE[String(s.type)] ?? String(s.type ?? '—')}</td>
      <td>{s.floor_id ? nameOf(d.floors.find((f) => f.id === s.floor_id) ?? { id: '' }) : '—'}</td>
      <td>{s.show_in_orientation ? 'Oui' : 'Non'}</td>
      <td>{s.storage_quota_mb ? `${s.storage_quota_mb} Mo` : '—'}</td>
      <td>{s.is_active === false ? <Status tone="neutral">Inactive</Status> : <Status tone="success">Active</Status>}</td>
      <td><RowActions type="structures" item={s} onEdit={onEdit} onToggle={onToggle} addChild={{ type: 'structures', label: 'Sous-structure', preset: { parent_id: s.id } }} /></td>
    </tr>,
    ...children(s.id).flatMap((c) => render(c, depth + 1)),
  ]
  return (
    <section className="panel table-panel">
      <div className="panel-title">
        <div><h2>Structures</h2><p>Hiérarchie cabinet › département › direction › service</p></div>
        <Button size="small" icon="plus" onClick={() => onEdit({ type: 'structures' })}>Ajouter une structure</Button>
      </div>
      <div className="table-scroll">
        <table>
          <thead><tr><th scope="col">Structure</th><th scope="col">Type</th><th scope="col">Niveau</th><th scope="col">Orientation</th><th scope="col">Quota</th><th scope="col">État</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
          <tbody>{roots.flatMap((s) => render(s, 0))}{roots.length === 0 && <tr><td colSpan={7} className="muted">Aucune structure.</td></tr>}</tbody>
        </table>
      </div>
    </section>
  )
}

function FlatList({ type, d, onEdit, onToggle }: { type: Exclude<Tab, 'tree' | 'structures'>; d: RefData } & Handlers) {
  const def = DEFS[type]
  const items = d[type]
  const cols = def.fields.filter((f) => f.kind !== 'textarea')
  const display = (f: FieldDef, item: RefItem) => {
    const v = item[f.name]
    if (f.kind === 'bool') return v ? 'Oui' : 'Non'
    if (f.kind === 'date') return fmtDate(String(v))
    if (f.kind === 'select' && f.options) return f.options(d).find((o) => o.value === v)?.label ?? String(v ?? '—')
    return v === null || v === undefined || v === '' ? '—' : String(v)
  }
  return (
    <section className="panel table-panel">
      <div className="panel-title">
        <div>
          <h2>{def.label}</h2>
          <p>{type === 'languages' ? 'Activer le portugais ou l’espagnol suffit à les proposer dans la saisie et la diffusion, sans développement.' : type === 'holidays' ? 'Exclus des calculs de disponibilité des salles et des délais.' : `${items.length} élément(s)`}</p>
        </div>
        <Button size="small" icon="plus" onClick={() => onEdit({ type })}>Ajouter</Button>
      </div>
      {items.length === 0 ? <EmptyState title="Aucun élément" /> : (
        <div className="table-scroll">
          <table>
            <thead><tr>{cols.map((c) => <th key={c.name} scope="col">{c.label}</th>)}{def.activable && <th scope="col">État</th>}<th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id} className={item.is_active === false ? 'inactive-row' : ''}>
                  {cols.map((c, i) => <td key={c.name} className={c.name === 'code' ? 'mono' : ''}>{i === 1 ? <strong>{display(c, item)}</strong> : display(c, item)}</td>)}
                  {def.activable && <td>{item.is_active === false ? <Status tone="neutral">Inactif</Status> : <Status tone="success">Actif</Status>}</td>}
                  <td><RowActions type={type} item={item} onEdit={onEdit} onToggle={onToggle} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

function RefForm({ type, item, preset, d, onClose }: Editing & { d: RefData; onClose: () => void }) {
  const def = DEFS[type]
  const client = useQueryClient()
  const toast = useToast()
  const initial: Record<string, unknown> = {}
  for (const f of def.fields) {
    const v = item?.[f.name] ?? preset?.[f.name]
    initial[f.name] = f.kind === 'bool' ? !!v : f.kind === 'date' && v ? String(v).slice(0, 10) : v ?? ''
  }
  const [values, setValues] = useState<Record<string, unknown>>(initial)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const fields = def.fields.filter((f) => !(item && f.createOnly))

  const submit = async () => {
    setBusy(true)
    setError(null)
    const normalize = (f: FieldDef, v: unknown) => (f.kind === 'bool' ? !!v : v === '' || v === null || v === undefined ? null : f.kind === 'number' ? Number(v) : v)
    const payload: Record<string, unknown> = {}
    for (const f of fields) {
      const v = normalize(f, values[f.name])
      if (item && v === normalize(f, initial[f.name])) continue
      if (!item && v === null && !f.required) continue
      payload[f.name] = v
    }
    try {
      if (item) {
        if (Object.keys(payload).length) await api.patch(`/referentials/${type}/${item.id}`, payload)
      } else {
        await api.post(`/referentials/${type}`, payload)
      }
      await client.invalidateQueries({ queryKey: ['referentials'] })
      toast.success(item ? 'Modification enregistrée' : 'Élément ajouté')
      onClose()
    } catch (e) {
      setError(asApiError(e))
    } finally {
      setBusy(false)
    }
  }
  const missing = fields.some((f) => f.required && (values[f.name] === '' || values[f.name] === null || values[f.name] === undefined))

  return (
    <Modal title={item ? `Modifier ${nameOf(item)}` : `Ajouter un ${def.singular}`} kicker={def.label.toUpperCase()} onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button onClick={submit} busy={busy} disabled={missing}>{item ? 'Enregistrer' : 'Ajouter'}</Button>
    </>}>
      <div className="form-grid">
        {fields.map((f) => {
          const err = error?.field(f.name)
          const set = (v: unknown) => setValues({ ...values, [f.name]: v })
          const value = values[f.name]
          return (
            <Field key={f.name} label={f.label} required={f.required} error={err} help={f.help} wide={f.kind === 'textarea'}>
              {f.kind === 'bool' ? (
                <select className="input" value={value ? '1' : '0'} onChange={(e) => set(e.target.value === '1')}><option value="1">Oui</option><option value="0">Non</option></select>
              ) : f.kind === 'select' ? (
                <select className="input" value={String(value ?? '')} onChange={(e) => set(e.target.value)} aria-invalid={!!err}>
                  <option value="">{f.required ? '— Choisir —' : '— Aucun —'}</option>
                  {(f.options?.(d) ?? []).filter((o) => o.value !== item?.id).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                </select>
              ) : f.kind === 'textarea' ? (
                <textarea value={String(value ?? '')} onChange={(e) => set(e.target.value)} aria-invalid={!!err} />
              ) : (
                <input className="input" type={f.kind === 'number' ? 'number' : f.kind === 'date' ? 'date' : 'text'} value={String(value ?? '')} onChange={(e) => set(e.target.value)} aria-invalid={!!err} />
              )}
            </Field>
          )
        })}
      </div>
      {error && (error.status === 409 || error.code === 'DUPLICATE' || !Object.keys(error.errors).length) && (
        <div style={{ marginTop: 12 }}><Notice tone="danger">{error.status === 409 || error.code === 'DUPLICATE' ? `Doublon : ${error.message}` : error.message}</Notice></div>
      )}
      {error && Object.keys(error.errors).length > 0 && <div style={{ marginTop: 12 }}><Notice tone="warning">Corrigez les champs signalés (un code déjà utilisé est refusé).</Notice></div>}
      <div className="footer-note" style={{ marginTop: 12 }}><Icon name="shield" size={14} /> Modification tracée dans le journal (avant / après)</div>
    </Modal>
  )
}
