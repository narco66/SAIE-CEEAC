import { Fragment, useMemo, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { fmtDate } from '../../lib/format'
import { Button, ConfirmDialog, ErrorState, Loading, Notice, PageHeading, Status, useToast } from '../../components/ui'
import { POWER_LABEL, useReferentials } from './common'
import { useEffective, useRoles, type RolesData } from './Users'
import './admin.css'

/** V35 Rôles et délégations : matrice permissions × rôles, pouvoirs étendus nominatifs et comparaison des droits effectifs. */
export default function Roles() {
  const { can } = useAuth()
  const client = useQueryClient()
  const toast = useToast()
  const query = useRoles()
  const [draft, setDraft] = useState<Record<string, string[]>>({})
  const [saving, setSaving] = useState<string | null>(null)
  const editable = can('roles.manage')

  const data = query.data
  const groups = useMemo(() => {
    const map = new Map<string, RolesData['permissions']>()
    for (const p of data?.permissions ?? []) map.set(p.group, [...(map.get(p.group) ?? []), p])
    return [...map.entries()]
  }, [data])

  if (query.isLoading) return <Loading />
  if (query.isError || !data) return <ErrorState error={query.error} onRetry={() => query.refetch()} />

  const holders = Array.isArray(data.power_holders) ? {} : data.power_holders
  const current = (roleId: string) => draft[roleId] ?? data.roles.find((r) => r.id === roleId)?.permissions ?? []
  const dirty = (roleId: string) => {
    const original = data.roles.find((r) => r.id === roleId)?.permissions ?? []
    const d = draft[roleId]
    return !!d && (d.length !== original.length || d.some((p) => !original.includes(p)))
  }
  const toggle = (roleId: string, code: string, on: boolean) => {
    const list = current(roleId)
    setDraft({ ...draft, [roleId]: on ? [...list, code] : list.filter((p) => p !== code) })
  }
  const savingRole = data.roles.find((r) => r.id === saving)
  const diff = savingRole ? {
    added: current(savingRole.id).filter((p) => !savingRole.permissions.includes(p)),
    removed: savingRole.permissions.filter((p) => !current(savingRole.id).includes(p)),
  } : { added: [], removed: [] }
  const permName = (code: string) => data.permissions.find((p) => p.code === code)?.name ?? code

  return (
    <>
      <PageHeading title="Rôles & délégations" description="Permissions par rôle, pouvoirs étendus nominatifs et datés, comparaison des droits effectifs." />
      <Notice tone="warning">
        <strong>Prévenir l’escalade hors pouvoir de délégation.</strong> Vous ne pouvez accorder que des droits que vous détenez vous-même (sauf administrateur fonctionnel) ; le serveur refuse toute délégation au-delà et journalise la tentative. Les droits liés à un pouvoir étendu (urgence, modèles, prestations) doivent rester nominatifs et datés.
      </Notice>

      <section className="panel table-panel section-gap">
        <div className="panel-title">
          <div><h2>Matrice des permissions</h2><p>{data.permissions.length} permission(s) · {data.roles.length} rôle(s) · colonnes ambrées : droits ouverts par un pouvoir étendu</p></div>
        </div>
        <div className="matrix-wrap">
          <table className="matrix">
            <thead>
              <tr>
                <th scope="col">Permission</th>
                {data.roles.map((r) => (
                  <th key={r.id} scope="col">
                    <div className="matrix-head">
                      <span>{r.name}</span>
                      <small className="muted" style={{ textTransform: 'none', letterSpacing: 0 }}>{r.users} titulaire(s)</small>
                      {dirty(r.id) && (
                        <span style={{ display: 'flex', gap: 4 }}>
                          <Button size="small" onClick={() => setSaving(r.id)}>Enregistrer</Button>
                          <Button size="small" variant="ghost" onClick={() => { const { [r.id]: _omit, ...rest } = draft; void _omit; setDraft(rest) }}>Annuler</Button>
                        </span>
                      )}
                    </div>
                  </th>
                ))}
                {data.powers.map((p) => <th key={p.code} scope="col" className="power-col">Pouvoir « {p.label} »<br /><small className="muted" style={{ textTransform: 'none' }}>rôle requis : {data.roles.find((r) => r.code === p.role)?.name ?? p.role}</small></th>)}
              </tr>
            </thead>
            <tbody>
              {groups.map(([group, perms]) => (
                <Fragment key={group}>
                  <tr className="group-row"><td colSpan={1 + data.roles.length + data.powers.length}>{group}</td></tr>
                  {perms.map((p) => (
                    <tr key={p.code}>
                      <td><strong>{p.name}</strong><small className="mono">{p.code}</small></td>
                      {data.roles.map((r) => {
                        const checked = current(r.id).includes(p.code)
                        const original = r.permissions.includes(p.code)
                        return (
                          <td key={r.id} className={checked !== original ? 'changed' : ''}>
                            <input type="checkbox" checked={checked} disabled={!editable} onChange={(e) => toggle(r.id, p.code, e.target.checked)} aria-label={`${p.name} pour ${r.name}`} />
                            {checked !== original && <span className="sr-only">(modifié)</span>}
                          </td>
                        )
                      })}
                      {data.powers.map((pw) => (
                        <td key={pw.code} className="power-col">{pw.permissions.includes(p.code) ? <Status tone="warning">Ouvert</Status> : <span className="muted" aria-label="non concerné">—</span>}</td>
                      ))}
                    </tr>
                  ))}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="panel table-panel section-gap">
        <div className="panel-title"><div><h2>Pouvoirs étendus en vigueur</h2><p>Titulaires nominatifs, échéances et périmètres (attribution depuis la fiche utilisateur)</p></div></div>
        <div className="table-scroll">
          <table>
            <thead><tr><th scope="col">Pouvoir</th><th scope="col">Rôle de rattachement</th><th scope="col">Droits ouverts</th><th scope="col">Titulaires</th></tr></thead>
            <tbody>
              {data.powers.map((p) => {
                const list = holders[p.code] ?? []
                return (
                  <tr key={p.code}>
                    <td><strong>{POWER_LABEL[p.code] ?? p.label}</strong></td>
                    <td>{data.roles.find((r) => r.code === p.role)?.name ?? p.role}</td>
                    <td className="mono">{p.permissions.join(', ')}</td>
                    <td>{list.length ? list.map((h, i) => <div key={i}>{h.user}<small>jusqu’au {fmtDate(h.ends_at)}{h.scope ? ' · périmètre restreint' : ''}</small></div>) : <span className="muted">Aucun titulaire</span>}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </section>

      <Compare permissions={data.permissions} />

      {savingRole && (
        <ConfirmDialog
          title={`Modifier les permissions — ${savingRole.name}`}
          object={`${savingRole.users} titulaire(s) concerné(s)`}
          consequence="Les droits de tous les titulaires de ce rôle changent immédiatement ; les sessions ouvertes appliquent la nouvelle matrice à leur prochaine requête."
          confirmLabel="Enregistrer la matrice"
          reasonLabel="Motif"
          reasonRequired
          onClose={() => setSaving(null)}
          onConfirm={async (reason) => {
            const r = await api.put<{ data: RolesData }>(`/roles/${savingRole.id}/permissions`, { permissions: current(savingRole.id), reason })
            client.setQueryData(['roles'], r.data)
            const { [savingRole.id]: _omit, ...rest } = draft
            void _omit
            setDraft(rest)
            toast.success('Permissions enregistrées', savingRole.name)
          }}
        >
          <div style={{ marginTop: 12, display: 'grid', gap: 6 }}>
            {diff.added.length > 0 && <p><strong>Ajoutées :</strong> {diff.added.map(permName).join(', ')}</p>}
            {diff.removed.length > 0 && <p><strong>Retirées :</strong> {diff.removed.map(permName).join(', ')}</p>}
          </div>
        </ConfirmDialog>
      )}
    </>
  )
}

function Compare({ permissions }: { permissions: RolesData['permissions'] }) {
  const refs = useReferentials()
  const [a, setA] = useState('')
  const [b, setB] = useState('')
  const ea = useEffective(a || null)
  const eb = useEffective(b || null)
  const users = refs.data?.users ?? []
  const both = ea.data && eb.data
  const union = both ? [...new Set([...ea.data!.permissions, ...eb.data!.permissions])] : []
  const [onlyDiff, setOnlyDiff] = useState(true)
  const rows = union
    .map((code) => ({ code, name: permissions.find((p) => p.code === code)?.name ?? code, group: permissions.find((p) => p.code === code)?.group ?? 'Autres', a: ea.data!.permissions.includes(code), b: eb.data!.permissions.includes(code) }))
    .filter((r) => !onlyDiff || r.a !== r.b)
    .sort((x, y) => x.group.localeCompare(y.group) || x.name.localeCompare(y.name))
  const nameOf = (id: string) => users.find((u) => u.id === id)?.name ?? ''

  return (
    <section className="panel table-panel section-gap">
      <div className="panel-title"><div><h2>Comparer les droits effectifs</h2><p>Rôles actifs et pouvoirs en cours de validité de deux utilisateurs</p></div></div>
      <div className="filters-grid">
        <label>Utilisateur A<select className="input" value={a} onChange={(e) => setA(e.target.value)}><option value="">— Choisir —</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></label>
        <label>Utilisateur B<select className="input" value={b} onChange={(e) => setB(e.target.value)}><option value="">— Choisir —</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></label>
        <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}><input type="checkbox" checked={onlyDiff} onChange={(e) => setOnlyDiff(e.target.checked)} /> Différences uniquement</label>
      </div>
      {!a || !b ? <p className="muted small" style={{ padding: 19 }}>Choisissez deux utilisateurs actifs.</p> : ea.isLoading || eb.isLoading ? <Loading /> : ea.isError || eb.isError ? <ErrorState error={ea.error ?? eb.error} /> : (
        <div className="table-scroll">
          <table className="matrix">
            <thead><tr><th scope="col">Permission</th><th scope="col">{nameOf(a)}</th><th scope="col">{nameOf(b)}</th></tr></thead>
            <tbody>
              <tr><td><strong>Pouvoirs étendus</strong></td>
                <td>{Object.entries(ea.data!.powers).filter(([, v]) => v).map(([k]) => POWER_LABEL[k] ?? k).join(', ') || '—'}</td>
                <td>{Object.entries(eb.data!.powers).filter(([, v]) => v).map(([k]) => POWER_LABEL[k] ?? k).join(', ') || '—'}</td></tr>
              {rows.map((r) => (
                <tr key={r.code}>
                  <td><strong>{r.name}</strong><small>{r.group} · <span className="mono">{r.code}</span></small></td>
                  <td className="compare-cell">{r.a ? <Status tone="success">Oui</Status> : <Status tone="neutral">Non</Status>}</td>
                  <td className="compare-cell">{r.b ? <Status tone="success">Oui</Status> : <Status tone="neutral">Non</Status>}</td>
                </tr>
              ))}
              {rows.length === 0 && <tr><td colSpan={3} className="muted">{onlyDiff ? 'Aucune différence : droits effectifs identiques.' : 'Aucune permission.'}</td></tr>}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
