import { useState } from 'react'
import { useNavigate } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { fmtDateTime } from '../../lib/format'
import { Button, ConfirmDialog, EmptyState, ErrorState, Field, Loading, Modal, Notice, PageHeading, Status, Tabs, useToast } from '../../components/ui'
import { ORIENTATION_LABEL, TemplateThumb, ZONE_LABEL, type Template, type TemplateLayout } from './shared'

type Tab = 'landscape' | 'portrait' | 'archived'
const PURPOSE_LABEL: Record<string, string> = { content: 'Contenu', welcome: 'Accueil protocolaire', orientation: 'Orientation', room_panel: 'Panneau de salle' }

/** Disposition de départ d'un nouveau modèle (éditable ensuite dans V16). */
function baseLayout(orientation: 'landscape' | 'portrait'): TemplateLayout {
  const zones = orientation === 'landscape'
    ? [
        { id: 'header', type: 'header', x: 0, y: 0, w: 100, h: 15, style: 'primary' },
        { id: 'text', type: 'text', x: 5, y: 20, w: 52, h: 60, style: null, align: 'left' },
        { id: 'image', type: 'image', x: 61, y: 20, w: 34, h: 60 },
        { id: 'ticker', type: 'ticker', x: 0, y: 88, w: 100, h: 12, style: 'light' },
      ]
    : [
        { id: 'header', type: 'header', x: 0, y: 0, w: 100, h: 10, style: 'primary' },
        { id: 'image', type: 'image', x: 5, y: 13, w: 90, h: 35 },
        { id: 'text', type: 'text', x: 5, y: 51, w: 90, h: 34, align: 'left' },
        { id: 'ticker', type: 'ticker', x: 0, y: 90, w: 100, h: 10, style: 'light' },
      ]
  return { zones, theme: 'institutionnel', transition: 'fade', safe_margin: 5 }
}

/** V15 Modèles d'affichage. Un modèle archivé reste disponible pour les versions déjà approuvées (PUB-03). */
export default function Templates() {
  const { me, can } = useAuth()
  const navigate = useNavigate()
  const client = useQueryClient()
  const toast = useToast()
  const manage = can('templates.manage')
  const [tab, setTab] = useState<Tab>('landscape')
  const [creating, setCreating] = useState(false)
  const [archiving, setArchiving] = useState<Template | null>(null)
  const [duplicating, setDuplicating] = useState<string | null>(null)

  const query = useQuery({
    queryKey: ['templates', tab],
    queryFn: ({ signal }) => api.get<{ data: Template[] }>('/templates', tab === 'archived' ? { status: 'archived' } : { orientation: tab }, signal).then((r) => r.data),
  })

  const duplicate = async (t: Template) => {
    setDuplicating(t.id)
    try {
      const res = await api.post<{ data: Template }>(`/templates/${t.id}/duplicate`)
      client.invalidateQueries({ queryKey: ['templates'] })
      toast.success('Modèle dupliqué', `« ${res.data.name} » a été créé.`)
    } catch (e) {
      toast.error(e, 'Duplication impossible')
    } finally {
      setDuplicating(null)
    }
  }

  return (
    <>
      <PageHeading title="Modèles d’affichage" description="Compositions structurées par orientation. Aucun HTML ni script libre : seules des zones typées sont autorisées." demo={me.demo_mode}
        actions={manage && <Button icon="plus" onClick={() => setCreating(true)}>Nouveau modèle</Button>} />
      {!manage && <div style={{ marginBottom: 12 }}><Notice tone="info">Consultation seule : la création et la modification des modèles exigent le pouvoir « modèles ».</Notice></div>}
      <Tabs<Tab> value={tab} onChange={setTab} items={[{ value: 'landscape', label: 'Paysage 16:9' }, { value: 'portrait', label: 'Portrait 9:16' }, { value: 'archived', label: 'Archivés' }]} />
      <section className="panel">
        {query.isLoading ? <Loading /> : query.isError ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : (query.data ?? []).length === 0 ? (
          <EmptyState title={tab === 'archived' ? 'Aucun modèle archivé' : 'Aucun modèle'} text={tab === 'archived' ? undefined : 'Créez un premier modèle pour cette orientation.'} />
        ) : (
          <div className="tpl-grid">
            {(query.data ?? []).map((t) => (
              <article className="tpl-card" key={t.id}>
                <div className="tpl-visual"><TemplateThumb layout={t.layout} orientation={t.orientation} /></div>
                <div className="tpl-body">
                  <div style={{ display: 'flex', justifyContent: 'space-between', gap: 6, alignItems: 'flex-start' }}>
                    <strong>{t.name}</strong>
                    {t.status === 'archived' ? <Status>Archivé</Status> : t.is_institutional ? <Status tone="blue">Institutionnel</Status> : <Status tone="success">Actif</Status>}
                  </div>
                  <small className="mono">{t.code} · version {t.version}</small>
                  <small>{ORIENTATION_LABEL[t.orientation]} · {PURPOSE_LABEL[t.purpose ?? 'content'] ?? t.purpose} · {t.layout.zones?.length ?? 0} zone(s)</small>
                  <small>Composants : {[...new Set((t.layout.zones ?? []).map((z) => ZONE_LABEL[z.type] ?? z.type))].join(', ') || '—'}</small>
                  <small>Utilisé par {t.used_by} contenu(s) · modifié le {fmtDateTime(t.updated_at)}</small>
                </div>
                {manage && (
                  <div className="tpl-actions">
                    {t.status !== 'archived' && <Button size="small" icon="edit" onClick={() => navigate(`/affichage/modeles/${t.id}/edition`)}>Éditer</Button>}
                    <Button size="small" variant="secondary" icon="plus" onClick={() => duplicate(t)} busy={duplicating === t.id}>Dupliquer</Button>
                    {t.status !== 'archived' && <Button size="small" variant="ghost" icon="folder" onClick={() => setArchiving(t)}>Archiver</Button>}
                  </div>
                )}
              </article>
            ))}
          </div>
        )}
      </section>
      {creating && <NewTemplateModal defaultOrientation={tab === 'portrait' ? 'portrait' : 'landscape'} onClose={() => setCreating(false)} onCreated={(id) => navigate(`/affichage/modeles/${id}/edition`)} />}
      {archiving && (
        <ConfirmDialog title="Archiver le modèle" object={`${archiving.name} · v${archiving.version}`} confirmLabel="Archiver"
          consequence={`Le modèle ne sera plus proposé pour de nouveaux contenus. Les ${archiving.used_by} contenu(s) et versions déjà approuvées qui l’utilisent restent diffusables.`}
          onClose={() => setArchiving(null)}
          onConfirm={async () => {
            await api.post(`/templates/${archiving.id}/archive`)
            client.invalidateQueries({ queryKey: ['templates'] })
            toast.success('Modèle archivé')
          }} />
      )}
    </>
  )
}

function NewTemplateModal({ defaultOrientation, onClose, onCreated }: { defaultOrientation: 'landscape' | 'portrait'; onClose: () => void; onCreated: (id: string) => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const [name, setName] = useState('')
  const [orientation, setOrientation] = useState<'landscape' | 'portrait'>(defaultOrientation)
  const [purpose, setPurpose] = useState('content')
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: Template }>('/templates', { name, orientation, purpose, layout: baseLayout(orientation) })
      client.invalidateQueries({ queryKey: ['templates'] })
      toast.success('Modèle créé', 'Composez maintenant ses zones dans l’éditeur.')
      onCreated(res.data.id)
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title="Nouveau modèle" kicker="MODÈLES" onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button icon="check" onClick={submit} busy={busy} disabled={!name.trim()}>Créer et ouvrir l’éditeur</Button>
    </>}>
      <div className="form-grid">
        <Field label="Nom" required wide error={error?.field('name')}><input value={name} onChange={(e) => setName(e.target.value)} maxLength={120} aria-invalid={!!error?.field('name')} /></Field>
        <Field label="Orientation" required error={error?.field('orientation')}>
          <select value={orientation} onChange={(e) => setOrientation(e.target.value as 'landscape' | 'portrait')}>
            <option value="landscape">Paysage 16:9</option><option value="portrait">Portrait 9:16</option>
          </select>
        </Field>
        <Field label="Usage" error={error?.field('purpose')}>
          <select value={purpose} onChange={(e) => setPurpose(e.target.value)}>
            {Object.entries(PURPOSE_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </Field>
      </div>
      <div style={{ marginTop: 14, display: 'grid', placeItems: 'center' }}><TemplateThumb layout={baseLayout(orientation)} orientation={orientation} /></div>
      <p className="muted small" style={{ marginTop: 8, textAlign: 'center' }}>Disposition de départ : en-tête, texte, image et bandeau. Modifiable dans l’éditeur.</p>
      {error && !Object.keys(error.errors).length && <div style={{ marginTop: 12 }}><Notice tone="danger">{error.message}</Notice></div>}
    </Modal>
  )
}
