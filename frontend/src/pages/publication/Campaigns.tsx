import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { useQueryClient } from '@tanstack/react-query'
import { api } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useList } from '../../lib/hooks'
import { addDays, CAMPAIGN_STATUS, DAYS, fmtDateTime, fmtDayShort, localParts, toIsoFromLocal, todayLocal } from '../../lib/format'
import { Button, ConfirmDialog, EmptyState, ErrorState, Icon, Loading, PageHeading, Pagination, StatusOf, Tabs, Toolbar, useDebounced, useToast } from '../../components/ui'
import { RowMenu, useExportRequest, type Campaign } from './shared'

const STATUS_TABS = [
  { value: '', label: 'Toutes' }, { value: 'draft', label: 'Brouillons' }, { value: 'scheduled', label: 'Programmées' }, { value: 'active', label: 'En cours' },
  { value: 'suspended', label: 'Suspendues' }, { value: 'expired', label: 'Expirées' },
]

const dayDiff = (a: string, b: string) => Math.round((new Date(`${b}T12:00:00+01:00`).getTime() - new Date(`${a}T12:00:00+01:00`).getTime()) / 86_400_000)

function rotation(c: Campaign) {
  const langs = [...c.languages].sort((a, b) => a.rank - b.rank).map((l) => l.lang.toUpperCase())
  return langs.length ? langs.join(' → ') : '—'
}

function period(c: Campaign) {
  const days = c.days?.length && c.days.length < 7 ? c.days.map((d) => DAYS[d]).join(', ') : 'tous les jours'
  const slot = c.time_from && c.time_to ? `${c.time_from}–${c.time_to}` : 'toute la journée'
  return { range: `${fmtDateTime(c.starts_at)} → ${fmtDateTime(c.ends_at)}`, detail: `${days} · ${slot} (heure de Libreville)` }
}

/** V20 Campagnes : liste, calendrier hebdomadaire et actions de programmation (PUB-02, PUB-07). */
export default function Campaigns() {
  const { me, can } = useAuth()
  const navigate = useNavigate()
  const toast = useToast()
  const client = useQueryClient()
  const [params, setParams] = useSearchParams()
  const status = params.get('status') ?? ''
  const view = params.get('vue') === 'calendrier' ? 'calendar' : 'list'
  const [q, setQ] = useState('')
  const search = useDebounced(q)
  const [suspending, setSuspending] = useState<Campaign | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const exporter = useExportRequest()

  const list = useList<Campaign>('campaigns', '/campaigns', { status: status || undefined, q: search || undefined })

  const setParam = (key: string, value: string) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    setParams(next, { replace: true })
    list.setPage(1)
  }

  const act = async (c: Campaign, action: 'publish' | 'resume', label: string) => {
    setBusy(c.id + action)
    try {
      await api.post(`/campaigns/${c.id}/${action}`)
      client.invalidateQueries({ queryKey: ['campaigns'] })
      toast.success(label, `${c.reference} · ${c.name}`)
    } catch (e) {
      toast.error(e, action === 'publish' ? 'Programmation impossible' : 'Reprise impossible')
    } finally {
      setBusy(null)
    }
  }

  const manage = can('campaigns.manage')
  const menu = (c: Campaign) => (
    <RowMenu label={`Actions pour ${c.name}`}>
      {(close) => (
        <>
          <button role="menuitem" onClick={() => { close(); navigate(`/affichage/diffusions/${c.id}`) }}><Icon name="chart" size={15} /> Ouvrir le suivi de diffusion</button>
          {manage && ['draft', 'suspended'].includes(c.status) && <button role="menuitem" onClick={() => { close(); navigate(`/affichage/campagnes/${c.id}/modifier`) }}><Icon name="edit" size={15} /> Modifier</button>}
          {manage && c.status === 'draft' && <button role="menuitem" disabled={busy !== null} onClick={() => { close(); act(c, 'publish', 'Campagne programmée') }}><Icon name="play" size={15} /> Publier / programmer</button>}
          {manage && ['scheduled', 'active'].includes(c.status) && <button role="menuitem" className="danger" onClick={() => { close(); setSuspending(c) }}><Icon name="x" size={15} /> Suspendre…</button>}
          {manage && c.status === 'suspended' && <button role="menuitem" disabled={busy !== null} onClick={() => { close(); act(c, 'resume', 'Campagne reprise') }}><Icon name="refresh" size={15} /> Reprendre</button>}
          <button role="menuitem" disabled={exporter.busy !== null} onClick={() => { close(); exporter.run('campaign_order', 'pdf', { campaign_id: c.id }) }}><Icon name="download" size={15} /> Bon de programmation (PDF04)</button>
        </>
      )}
    </RowMenu>
  )

  return (
    <>
      <PageHeading
        title="Campagnes"
        description="Programmation des versions approuvées sur les écrans · horaires exprimés en heure de Libreville (UTC+1)."
        demo={me.demo_mode}
        actions={manage && <Button icon="plus" onClick={() => navigate('/affichage/campagnes/nouvelle')}>Programmer une campagne</Button>}
      />
      <Tabs value={status} onChange={(v) => setParam('status', v)} items={STATUS_TABS} />
      <section className="panel table-panel">
        <Toolbar value={q} onChange={(v) => { setQ(v); list.setPage(1) }} placeholder="Rechercher par nom ou référence…" right={
          <div className="view-switch" role="group" aria-label="Affichage">
            <button className={view === 'list' ? 'active' : ''} aria-pressed={view === 'list'} onClick={() => setParam('vue', '')} aria-label="Vue liste" title="Vue liste"><Icon name="list" /></button>
            <button className={view === 'calendar' ? 'active' : ''} aria-pressed={view === 'calendar'} onClick={() => setParam('vue', 'calendrier')} aria-label="Vue calendrier" title="Vue calendrier"><Icon name="calendar" /></button>
          </div>
        } />
        {view === 'calendar' ? <WeekView status={status} search={search} onOpen={(id) => navigate(`/affichage/diffusions/${id}`)} /> : list.isLoading ? <Loading /> : list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : list.items.length === 0 ? (
          <EmptyState title="Aucune campagne" text="Aucune campagne ne correspond à ce filtre." action={manage ? <Button icon="plus" onClick={() => navigate('/affichage/campagnes/nouvelle')}>Programmer une campagne</Button> : undefined} />
        ) : (
          <div className="table-scroll">
            <table>
              <thead>
                <tr><th>Campagne</th><th>Priorité</th><th>Langues · rotation</th><th>Période</th><th>Statut</th><th>Écrans</th><th>Manifestes reçus</th><th><span className="sr-only">Actions</span></th></tr>
              </thead>
              <tbody>
                {list.items.map((c) => {
                  const p = period(c)
                  const r = c.receipts ?? { received: 0, targeted: 0 }
                  const pct = r.targeted ? Math.round((r.received / r.targeted) * 100) : null
                  return (
                    <tr key={c.id}>
                      <td>
                        <div className="title-cell">
                          <span className="doc-thumb"><Icon name="play" size={16} /></span>
                          <span>
                            <a className="link-text" href={`/affichage/diffusions/${c.id}`} onClick={(e) => { e.preventDefault(); navigate(`/affichage/diffusions/${c.id}`) }}>{c.name}</a>
                            <small>{c.reference} · {c.items.length} contenu(s){c.creator ? ` · ${c.creator.name}` : ''}</small>
                          </span>
                        </div>
                      </td>
                      <td>{c.priority}</td>
                      <td><span className="lang">{rotation(c)}</span></td>
                      <td>{p.range}<small>{p.detail}</small></td>
                      <td><StatusOf map={CAMPAIGN_STATUS} value={c.status} />{c.suspend_reason && <small>Motif : {c.suspend_reason}</small>}</td>
                      <td>{c.screens ?? '—'}</td>
                      <td>
                        {c.published_at ? (
                          <div className="rate">
                            <span>{r.received} / {r.targeted} terminal(aux){pct !== null ? ` · ${pct} %` : ''}</span>
                            <div className="progress" aria-hidden="true"><i style={{ width: `${pct ?? 0}%` }} /></div>
                            <small>Ne représente pas une audience humaine.</small>
                          </div>
                        ) : <span className="muted small">Non publiée</span>}
                      </td>
                      <td>{menu(c)}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
        {view === 'list' && <Pagination meta={list.meta} onPage={list.setPage} />}
      </section>
      <p className="muted small" style={{ marginTop: 10 }}><Icon name="info" size={13} /> Le taux de reçus mesure la réception du manifeste par les terminaux ; il ne prouve ni l’allumage de l’écran ni qu’une personne a vu le message.</p>
      {suspending && (
        <ConfirmDialog
          title="Suspendre la campagne"
          object={`${suspending.reference} · ${suspending.name}`}
          consequence="La campagne est retirée des manifestes : les écrans reprennent les autres programmations ou le contenu de repli. Elle pourra être reprise ; la reprise réévalue les versions approuvées et les dates."
          confirmLabel="Suspendre la diffusion"
          reasonLabel="Motif de la suspension"
          reasonRequired
          danger
          onClose={() => setSuspending(null)}
          onConfirm={async (reason) => {
            await api.post(`/campaigns/${suspending.id}/suspend`, { reason })
            client.invalidateQueries({ queryKey: ['campaigns'] })
            toast.success('Campagne suspendue', suspending.name)
          }}
        />
      )}
    </>
  )
}

function WeekView({ status, search, onOpen }: { status: string; search: string; onOpen: (id: string) => void }) {
  const today = todayLocal()
  const monday = (d: string) => addDays(d, -(localParts(new Date(`${d}T12:00:00+01:00`)).weekday - 1))
  const [weekStart, setWeekStart] = useState(() => monday(today))
  const week = useList<Campaign>('campaigns', '/campaigns', {
    status: status || undefined, q: search || undefined, from: toIsoFromLocal(weekStart, '00:00'), to: toIsoFromLocal(addDays(weekStart, 7), '00:00'), sort: 'starts_at',
  }, 100)
  const weekDays = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i))
  return (
    <>
            <div className="calendar-top">
              <div className="date-nav">
                <button onClick={() => setWeekStart(addDays(weekStart, -7))} aria-label="Semaine précédente"><Icon name="chevron" size={16} /></button>
                <h2>Semaine du {fmtDayShort(new Date(`${weekStart}T12:00:00+01:00`))} au {fmtDayShort(new Date(`${addDays(weekStart, 6)}T12:00:00+01:00`))}</h2>
                <button onClick={() => setWeekStart(addDays(weekStart, 7))} aria-label="Semaine suivante"><Icon name="chevron" size={16} /></button>
              </div>
              <div><Button variant="secondary" onClick={() => setWeekStart(monday(today))}>Cette semaine</Button></div>
            </div>
            {week.isLoading ? <Loading /> : week.isError ? <ErrorState error={week.error} onRetry={() => week.refetch()} /> : week.items.length === 0 ? <EmptyState title="Aucune campagne cette semaine" text="Les écrans affichent leur contenu de repli en l’absence de campagne." /> : (
              <div className="table-scroll">
                <div className="calendar-grid week-grid">
                  <div className="cal-head"><span>Campagne</span>{weekDays.map((d) => <span key={d} style={d === today ? { color: 'var(--blue)', fontWeight: 700 } : undefined}>{fmtDayShort(new Date(`${d}T12:00:00+01:00`))}</span>)}</div>
                  {week.items.map((c) => {
                    const s = Math.max(0, dayDiff(weekStart, localParts(c.starts_at).date))
                    const e = Math.min(6, dayDiff(weekStart, localParts(c.ends_at).date))
                    const p = period(c)
                    return (
                      <div className="cal-row" key={c.id}>
                        <strong>{c.name}<small>Priorité {c.priority} · {rotation(c)}</small></strong>
                        <button className={`cal-event st-${c.status}`} style={{ left: `${(s / 7) * 100 + 0.5}%`, width: `${((e - s + 1) / 7) * 100 - 1}%` }} onClick={() => onOpen(c.id)} title={`${p.range} · ${p.detail}`}>
                          <b>{c.name} · {CAMPAIGN_STATUS[c.status]?.[0] ?? c.status}</b>
                          <small>{c.time_from && c.time_to ? `${c.time_from}–${c.time_to}` : 'Toute la journée'} · {rotation(c)}</small>
                        </button>
                      </div>
                    )
                  })}
                </div>
              </div>
            )}
    </>
  )
}
