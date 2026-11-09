import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { useQueryClient } from '@tanstack/react-query'
import { api } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useList } from '../../lib/hooks'
import { CLASS_LABEL, CLASS_TONE, CONTENT_STATUS, fmtDate } from '../../lib/format'
import { Button, EmptyState, ErrorState, Icon, Loading, PageHeading, Pagination, Select, Status, StatusOf, Tabs, Toolbar, useDebounced, useToast } from '../../components/ui'
import { LangPills, RowMenu, langLabel, useReferentials, type Content } from './shared'

type Tab = 'all' | 'draft' | 'submitted' | 'approved' | 'scheduled' | 'broadcasting' | 'returned' | 'expired' | 'archived'
const TABS: { value: Tab; label: string }[] = [
  { value: 'all', label: 'Tous' }, { value: 'draft', label: 'Brouillon' }, { value: 'submitted', label: 'En attente' }, { value: 'approved', label: 'Approuvé' },
  { value: 'scheduled', label: 'Programmé' }, { value: 'broadcasting', label: 'En diffusion' }, { value: 'returned', label: 'À corriger' },
  { value: 'expired', label: 'Expiré' }, { value: 'archived', label: 'Archivé' },
]

/** V17 Liste des contenus (PUB-01, PUB-08) : état par langue, action « valider » absente pour l'auteur. */
export default function Contents() {
  const { me, can } = useAuth()
  const navigate = useNavigate()
  const toast = useToast()
  const client = useQueryClient()
  const [params, setParams] = useSearchParams()
  const tab = (params.get('onglet') as Tab) || 'all'
  const [q, setQ] = useState('')
  const [category, setCategory] = useState('')
  const [classification, setClassification] = useState('')
  const [lang, setLang] = useState('')
  const [duplicating, setDuplicating] = useState<string | null>(null)
  const search = useDebounced(q)
  const refs = useReferentials()

  const filters: Record<string, string | undefined> = {
    q: search || undefined, category_id: category || undefined, classification: classification || undefined, lang: lang || undefined,
    status: tab !== 'all' && tab !== 'returned' ? tab : undefined, lang_status: tab === 'returned' ? 'returned' : undefined,
  }
  const list = useList<Content>('contents', '/contents', filters)
  const counts = (list.meta?.counts ?? {}) as Record<string, number>
  const total = Object.values(counts).reduce((a, b) => a + Number(b), 0)
  const tabCount = (t: Tab) => (t === 'all' ? total : t === 'returned' ? Number(list.meta?.returned ?? 0) : Number(counts[t] ?? 0))

  const setTab = (t: Tab) => {
    const next = new URLSearchParams(params)
    if (t === 'all') next.delete('onglet')
    else next.set('onglet', t)
    setParams(next, { replace: true })
    list.setPage(1)
  }

  const duplicate = async (c: Content) => {
    setDuplicating(c.id)
    try {
      const res = await api.post<{ data: Content }>(`/contents/${c.id}/duplicate`)
      client.invalidateQueries({ queryKey: ['contents'] })
      toast.success('Contenu dupliqué', `${res.data.reference} créé en brouillon.`)
      navigate(`/affichage/contenus/${res.data.id}/edition`)
    } catch (e) {
      toast.error(e, 'Duplication impossible')
    } finally {
      setDuplicating(null)
    }
  }

  const validatable = (c: Content) =>
    can('contents.validate') && c.author?.id !== me.id && c.languages.filter((l) => l.status === 'submitted' && me.validation_languages.includes(l.lang))

  const activeLanguages = (refs.data?.languages ?? []).filter((l) => l.is_active)

  return (
    <>
      <PageHeading
        title="Contenus"
        description="Annonces et communiqués multilingues : chaque langue est validée séparément avant toute programmation."
        demo={me.demo_mode}
        actions={can('contents.create') && <Button icon="plus" onClick={() => navigate('/affichage/contenus/nouveau')}>Nouveau contenu</Button>}
      />
      <Tabs value={tab} onChange={setTab} items={TABS.map((t) => ({ ...t, count: list.meta ? tabCount(t.value) : null }))} />
      <section className="panel table-panel">
        <Toolbar value={q} onChange={(v) => { setQ(v); list.setPage(1) }} placeholder="Rechercher par titre, référence ou auteur…">
          <div className="pub-filters">
            <Select label="Catégorie" allLabel="Toutes catégories" value={category} onChange={(v) => { setCategory(v); list.setPage(1) }} options={(refs.data?.categories ?? []).map((c) => ({ value: c.id, label: c.name }))} />
            <Select label="Classification" allLabel="Toutes classifications" value={classification} onChange={(v) => { setClassification(v); list.setPage(1) }} options={Object.entries(CLASS_LABEL).map(([value, label]) => ({ value, label }))} />
            <Select label="Langue" allLabel="Toutes langues" value={lang} onChange={(v) => { setLang(v); list.setPage(1) }} options={(activeLanguages.length ? activeLanguages.map((l) => l.code) : ['fr', 'en', 'pt', 'es']).map((c) => ({ value: c, label: langLabel(c) }))} />
          </div>
        </Toolbar>
        {list.isLoading ? <Loading /> : list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : list.items.length === 0 ? (
          <EmptyState
            title="Aucun contenu trouvé"
            text="Aucun résultat ne correspond à cet onglet et à vos filtres."
            action={can('contents.create') ? <Button icon="plus" onClick={() => navigate('/affichage/contenus/nouveau')}>Créer un contenu</Button> : undefined}
          />
        ) : (
          <div className="table-scroll">
            <table>
              <thead>
                <tr><th>Contenu</th><th>Catégorie</th><th>Langues</th><th>Statut</th><th>Validité</th><th><span className="sr-only">Actions</span></th></tr>
              </thead>
              <tbody>
                {list.items.map((c, i) => {
                  const toValidate = validatable(c) || []
                  return (
                    <tr key={c.id}>
                      <td>
                        <div className="title-cell">
                          <span className={`doc-thumb t${i % 3}`}><Icon name={c.media.length ? 'image' : 'file'} size={17} /></span>
                          <span>
                            <a className="link-text" href={`/affichage/contenus/${c.id}/edition`} onClick={(e) => { e.preventDefault(); navigate(`/affichage/contenus/${c.id}/edition`) }}>{c.title}</a>
                            <small>{c.reference} · v{c.version}{c.author ? ` · ${c.author.name}` : ''}</small>
                          </span>
                        </div>
                      </td>
                      <td>
                        {c.category?.name ?? '—'}
                        <small><Status tone={CLASS_TONE[c.classification]}>{CLASS_LABEL[c.classification] ?? c.classification}</Status></small>
                      </td>
                      <td>
                        <LangPills languages={c.languages} />
                        {c.languages.some((l) => l.status === 'returned') && <small className="red-text">Retour à corriger</small>}
                      </td>
                      <td><StatusOf map={CONTENT_STATUS} value={c.status} /></td>
                      <td>
                        {c.is_permanent ? 'Permanent' : c.expires_at ? `jusqu’au ${fmtDate(c.expires_at)}` : <span className="red-text">Expiration à définir</span>}
                        {c.valid_from && <small>à partir du {fmtDate(c.valid_from)}</small>}
                      </td>
                      <td>
                        <RowMenu label={`Actions pour ${c.title}`}>
                          {(close) => (
                            <>
                              <button role="menuitem" onClick={() => { close(); navigate(`/affichage/contenus/${c.id}/edition`) }}><Icon name="edit" size={15} /> Ouvrir l’édition</button>
                              {toValidate.map((l) => (
                                <button role="menuitem" key={l.lang} onClick={() => { close(); navigate(`/affichage/contenus/${c.id}/validation?lang=${l.lang}`) }}>
                                  <Icon name="check" size={15} /> Valider la version {l.lang.toUpperCase()}
                                </button>
                              ))}
                              {can('contents.create') && (
                                <button role="menuitem" disabled={duplicating === c.id} onClick={() => { close(); duplicate(c) }}><Icon name="plus" size={15} /> Dupliquer</button>
                              )}
                            </>
                          )}
                        </RowMenu>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
        <Pagination meta={list.meta} onPage={list.setPage} />
      </section>
    </>
  )
}
