import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useList } from '../../lib/hooks'
import { fmtDate, fmtDateTime, fmtRelative, LANG_LABEL } from '../../lib/format'
import {
  Button, ConfirmDialog, EmptyState, ErrorState, Field, Icon, Loading, Modal, Notice, PageHeading, Pagination, Select, Status, Tabs, Toolbar, useDebounced, useToast,
} from '../../components/ui'
import { asApiError, localInputToIso, POWER_LABEL, useReferentials } from './common'
import './admin.css'

interface Assignment {
  id: string; code: string; name: string; structure: string | null; structure_id: string | null; zone_id: string | null; languages: string[] | null
  starts_at: string | null; ends_at: string | null; revoked_at: string | null; active: boolean; reason: string | null
}
interface PowerGrant { id: string; power: string; scope: { zone_ids?: string[]; service_types?: string[] } | null; starts_at: string | null; ends_at: string | null; revoked_at: string | null; active: boolean }
export interface UserRow {
  id: string; name: string; email: string; initials: string; title: string | null; status: string; source: string
  structure: { id: string; name: string } | null; backup: { id: string; name: string } | null
  roles: Assignment[]; powers: PowerGrant[]; mfa_enabled: boolean; locked?: boolean; locked_until?: string | null; last_login_at: string | null; last_activity_at: string | null; is_demo: boolean
}
export interface RolesData {
  roles: { id: string; code: string; name: string; description: string | null; permissions: string[]; users: number }[]
  permissions: { id: string; code: string; name: string; group: string }[]
  powers: { code: string; label: string; role: string; permissions: string[] }[]
  power_holders: Record<string, { user: string; ends_at: string; scope: unknown }[]> | []
}
export interface Effective { user: { id: string; name: string }; roles: string[]; permissions: string[]; powers: Record<string, boolean>; validation_languages: string[] }

const USER_STATUS: Record<string, [string, string]> = { active: ['Actif', 'success'], disabled: ['Désactivé', 'neutral'], locked: ['Verrouillé', 'warning'] }

export function useRoles() {
  return useQuery({ queryKey: ['roles'], queryFn: ({ signal }) => api.get<{ data: RolesData }>('/roles', undefined, signal).then((r) => r.data) })
}

function grantState(g: { active: boolean; revoked_at: string | null; ends_at: string | null; starts_at: string | null }): [string, string] {
  if (g.revoked_at) return ['Révoqué', 'neutral']
  if (g.active) return ['Actif', 'success']
  if (g.ends_at && new Date(g.ends_at) < new Date()) return ['Échu', 'neutral']
  return ['À venir', 'blue']
}

/** V34 Utilisateurs : comptes, affectations datées et pouvoirs étendus nominatifs (HAB-01 à HAB-04). */
export default function Users() {
  const { can } = useAuth()
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [role, setRole] = useState('')
  const [structure, setStructure] = useState('')
  const [creating, setCreating] = useState(false)
  const [detail, setDetail] = useState<string | null>(null)
  const debounced = useDebounced(q)
  const refs = useReferentials()
  const roles = useRoles()
  const list = useList<UserRow>('users', '/users', { q: debounced || undefined, status: status || undefined, role: role || undefined, structure_id: structure || undefined })

  return (
    <>
      <PageHeading
        title="Utilisateurs"
        description="Comptes, structures de rattachement, rôles datés et pouvoirs étendus."
        actions={can('users.manage') && <Button icon="plus" onClick={() => setCreating(true)}>Nouvel utilisateur</Button>}
      />
      <section className="panel table-panel">
        <Toolbar value={q} onChange={(v) => { setQ(v); list.setPage(1) }} placeholder="Rechercher un nom, une adresse ou une structure…">
          <Select label="Statut" value={status} onChange={(v) => { setStatus(v); list.setPage(1) }} allLabel="Tous statuts" options={[{ value: 'active', label: 'Actifs' }, { value: 'disabled', label: 'Désactivés' }]} />
          <Select label="Rôle" value={role} onChange={(v) => { setRole(v); list.setPage(1) }} allLabel="Tous rôles" options={(roles.data?.roles ?? []).map((r) => ({ value: r.code, label: r.name }))} />
          <Select label="Structure" value={structure} onChange={(v) => { setStructure(v); list.setPage(1) }} allLabel="Toutes structures" options={(refs.data?.structures ?? []).map((s) => ({ value: s.id, label: String(s.name) }))} />
        </Toolbar>
        {list.isLoading ? <Loading /> : list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : list.items.length === 0 ? <EmptyState title="Aucun utilisateur" text="Aucun compte ne correspond à ces critères." /> : (
          <>
            <div className="table-scroll">
              <table>
                <thead><tr><th scope="col">Utilisateur</th><th scope="col">Structure</th><th scope="col">Rôles</th><th scope="col">Pouvoirs étendus</th><th scope="col">Statut</th><th scope="col">Dernière activité</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
                <tbody>
                  {list.items.map((u) => {
                    const activeRoles = u.roles.filter((r) => r.active)
                    const activePowers = u.powers.filter((p) => p.active)
                    return (
                      <tr key={u.id}>
                        <td>
                          <div className="user-cell">
                            <span aria-hidden="true">{u.initials}</span>
                            <strong>{u.name}<small>{u.email}{u.title ? ` · ${u.title}` : ''}</small></strong>
                          </div>
                        </td>
                        <td>{u.structure?.name ?? '—'}{u.backup && <small>Suppléant : {u.backup.name}</small>}</td>
                        <td>{activeRoles.length ? activeRoles.map((r) => <div key={r.id}>{r.name}{r.ends_at && <small>jusqu’au {fmtDate(r.ends_at)}</small>}</div>) : <span className="muted">Aucun</span>}</td>
                        <td>{activePowers.length ? activePowers.map((p) => <div key={p.id}>{POWER_LABEL[p.power] ?? p.power}<small>expire le {fmtDate(p.ends_at)}</small></div>) : '—'}</td>
                        <td><Status tone={(USER_STATUS[u.status] ?? [u.status, 'neutral'])[1]}>{(USER_STATUS[u.status] ?? [u.status])[0]}</Status>{!u.mfa_enabled && <small>Second facteur non activé</small>}</td>
                        <td>{u.last_activity_at ? <>{fmtRelative(u.last_activity_at)}<small>{fmtDateTime(u.last_activity_at)}</small></> : 'Jamais'}</td>
                        <td><Button size="small" variant="secondary" onClick={() => setDetail(u.id)}>Détail</Button></td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
            <Pagination meta={list.meta} onPage={list.setPage} />
          </>
        )}
      </section>
      {creating && <UserForm onClose={() => setCreating(false)} />}
      {detail && <UserDetail id={detail} onClose={() => setDetail(null)} />}
    </>
  )
}

/** Création ou modification d'un compte. Le mot de passe initial n'est jamais affiché ni saisi ici. */
function UserForm({ user, onClose }: { user?: UserRow; onClose: () => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const refs = useReferentials()
  const editable = !user || user.source === 'local'
  const [form, setForm] = useState({ name: user?.name ?? '', email: user?.email ?? '', structure_id: user?.structure?.id ?? '', title: user?.title ?? '', backup_user_id: user?.backup?.id ?? '', locale: 'fr' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [done, setDone] = useState<string | null>(null)

  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      const base = { structure_id: form.structure_id || null, title: form.title || null, backup_user_id: form.backup_user_id || null }
      if (user) {
        await api.patch(`/users/${user.id}`, editable ? { ...base, name: form.name, email: form.email } : base)
        toast.success('Compte mis à jour')
        await client.invalidateQueries({ queryKey: ['users'] })
        await client.invalidateQueries({ queryKey: ['user', user.id] })
        onClose()
      } else {
        const r = await api.post<{ message?: string }>('/users', { ...base, name: form.name, email: form.email, locale: form.locale })
        await client.invalidateQueries({ queryKey: ['users'] })
        setDone(r.message ?? 'Compte créé : un lien de définition du mot de passe a été envoyé.')
      }
    } catch (e) {
      setError(asApiError(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal title={user ? `Modifier ${user.name}` : 'Nouvel utilisateur'} kicker="COMPTE" onClose={onClose} footer={done ? <Button onClick={onClose}>Fermer</Button> : <>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button onClick={submit} busy={busy} disabled={!form.name.trim() || !form.email.trim()}>{user ? 'Enregistrer' : 'Créer le compte'}</Button>
    </>}>
      {done ? <Notice tone="success"><strong>Lien de définition envoyé.</strong> {done} Le mot de passe initial n’est jamais affiché.</Notice> : (
        <div className="form-grid">
          {!editable && <div className="wide"><Notice tone="info">Identité gérée par l’annuaire ({user?.source}) : nom et adresse ne sont pas modifiables ici.</Notice></div>}
          <Field label="Nom complet" required error={error?.field('name')}><input className="input" value={form.name} disabled={!editable} onChange={(e) => setForm({ ...form, name: e.target.value })} aria-invalid={!!error?.field('name')} /></Field>
          <Field label="Adresse professionnelle" required error={error?.field('email')}><input className="input" type="email" value={form.email} disabled={!editable} onChange={(e) => setForm({ ...form, email: e.target.value })} aria-invalid={!!error?.field('email')} /></Field>
          <Field label="Structure" error={error?.field('structure_id')}>
            <select className="input" value={form.structure_id} onChange={(e) => setForm({ ...form, structure_id: e.target.value })}>
              <option value="">— Aucune —</option>
              {(refs.data?.structures ?? []).filter((s) => s.is_active !== false || s.id === form.structure_id).map((s) => <option key={s.id} value={s.id}>{String(s.name)}</option>)}
            </select>
          </Field>
          <Field label="Fonction / titre" error={error?.field('title')}><input className="input" value={form.title} maxLength={120} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
          <Field label="Suppléant (HAB-04)" error={error?.field('backup_user_id')} help="Reçoit les tâches escaladées en cas d’absence.">
            <select className="input" value={form.backup_user_id} onChange={(e) => setForm({ ...form, backup_user_id: e.target.value })}>
              <option value="">— Aucun —</option>
              {(refs.data?.users ?? []).filter((u) => u.id !== user?.id).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </select>
          </Field>
          {!user && (
            <Field label="Langue d’interface" error={error?.field('locale')}>
              <select className="input" value={form.locale} onChange={(e) => setForm({ ...form, locale: e.target.value })}>
                {Object.entries(LANG_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
            </Field>
          )}
          {!user && <div className="wide"><Notice tone="info">Aucun mot de passe n’est défini ici : un lien de définition personnel est envoyé à l’adresse professionnelle.</Notice></div>}
          {error && !Object.keys(error.errors).length && <div className="wide"><Notice tone="danger">{error.message}</Notice></div>}
        </div>
      )}
    </Modal>
  )
}

type DetailTab = 'roles' | 'powers' | 'effective'

function UserDetail({ id, onClose }: { id: string; onClose: () => void }) {
  const { can, me } = useAuth()
  const client = useQueryClient()
  const toast = useToast()
  const [tab, setTab] = useState<DetailTab>('roles')
  const [editing, setEditing] = useState(false)
  const [confirm, setConfirm] = useState<null | 'deactivate' | 'reactivate' | 'sessions' | 'unlock' | 'reset-mfa'>(null)
  // Fiche toujours relue à l'ouverture : un autre administrateur peut avoir modifié rôles ou statut entre-temps.
  const query = useQuery({ queryKey: ['user', id], queryFn: ({ signal }) => api.get<{ data: UserRow }>(`/users/${id}`, undefined, signal).then((r) => r.data), staleTime: 0 })
  const refresh = async () => {
    await client.invalidateQueries({ queryKey: ['user', id] })
    await client.invalidateQueries({ queryKey: ['users'] })
    await client.invalidateQueries({ queryKey: ['effective', id] })
    await client.invalidateQueries({ queryKey: ['roles'] })
  }
  const u = query.data

  return (
    <Modal title={u?.name ?? 'Utilisateur'} kicker="DÉTAIL DU COMPTE" onClose={onClose} wide>
      {query.isLoading ? <Loading /> : query.isError || !u ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : (
        <>
          <div className="grid-2">
            <dl className="kv">
              <dt>Adresse</dt><dd>{u.email}</dd>
              <dt>Structure</dt><dd>{u.structure?.name ?? '—'}</dd>
              <dt>Fonction</dt><dd>{u.title ?? '—'}</dd>
              <dt>Suppléant</dt><dd>{u.backup?.name ?? 'Aucun'}</dd>
            </dl>
            <dl className="kv">
              <dt>Statut</dt><dd><Status tone={(USER_STATUS[u.status] ?? [u.status, 'neutral'])[1]}>{(USER_STATUS[u.status] ?? [u.status])[0]}</Status></dd>
              <dt>Source d’identité</dt><dd>{u.source === 'local' ? 'Locale' : u.source}</dd>
              <dt>Second facteur</dt><dd>{u.mfa_enabled ? 'Activé' : 'Non activé'}</dd>
              {u.locked && <><dt>Verrouillage</dt><dd>Verrouillé jusqu’au {fmtDateTime(u.locked_until)} (échecs de connexion)</dd></>}
              <dt>Dernière connexion</dt><dd>{fmtDateTime(u.last_login_at)}</dd>
            </dl>
          </div>
          {can('users.manage') && (
            <div className="row-actions" style={{ justifyContent: 'flex-start', marginTop: 12 }}>
              <Button size="small" variant="secondary" icon="edit" onClick={() => setEditing(true)}>Modifier</Button>
              <Button size="small" variant="secondary" icon="logout" onClick={() => setConfirm('sessions')}>Révoquer les sessions</Button>
              {u.locked && u.id !== me.id && <Button size="small" variant="secondary" icon="check" onClick={() => setConfirm('unlock')}>Débloquer</Button>}
              {u.mfa_enabled && u.id !== me.id && <Button size="small" variant="secondary" icon="shield" onClick={() => setConfirm('reset-mfa')}>Réinitialiser le second facteur</Button>}
              {u.status === 'active'
                ? <Button size="small" variant="danger" icon="x" disabled={u.id === me.id} title={u.id === me.id ? 'Vous ne pouvez pas désactiver votre propre compte' : undefined} onClick={() => setConfirm('deactivate')}>Désactiver</Button>
                : <Button size="small" icon="check" onClick={() => setConfirm('reactivate')}>Réactiver</Button>}
            </div>
          )}
          <div className="drawer-tabs section-gap">
            <Tabs<DetailTab> value={tab} onChange={setTab} items={[
              { value: 'roles', label: 'Affectations de rôles', count: u.roles.filter((r) => r.active).length },
              { value: 'powers', label: 'Pouvoirs étendus', count: u.powers.filter((p) => p.active).length },
              { value: 'effective', label: 'Droits effectifs' },
            ]} />
          </div>
          {tab === 'roles' && <AssignmentsTab user={u} onChanged={refresh} />}
          {tab === 'powers' && <PowersTab user={u} onChanged={refresh} />}
          {tab === 'effective' && <EffectiveRights userId={u.id} />}
        </>
      )}
      {editing && u && <UserForm user={u} onClose={() => setEditing(false)} />}
      {confirm === 'deactivate' && u && (
        <ConfirmDialog title="Désactiver le compte" object={u.name} danger reasonLabel="Motif" reasonRequired confirmLabel="Désactiver et couper les sessions"
          consequence="Les sessions ouvertes sont fermées immédiatement ; l’utilisateur ne peut plus se connecter ni recevoir de nouvelles tâches. Ses actions passées restent au journal."
          onClose={() => setConfirm(null)} onConfirm={async (reason) => { await api.post(`/users/${u.id}/deactivate`, { reason }); toast.success('Compte désactivé'); await refresh() }} />
      )}
      {confirm === 'reactivate' && u && (
        <ConfirmDialog title="Réactiver le compte" object={u.name} confirmLabel="Réactiver" reasonLabel="Motif" reasonRequired
          consequence="L’utilisateur pourra de nouveau se connecter ; ses rôles encore valides s’appliquent aussitôt. Le verrouillage éventuel est levé."
          onClose={() => setConfirm(null)} onConfirm={async (reason) => { await api.post(`/users/${u.id}/reactivate`, { reason }, false); toast.success('Compte réactivé'); await refresh() }} />
      )}
      {confirm === 'unlock' && u && (
        <ConfirmDialog title="Débloquer le compte" object={u.name} confirmLabel="Débloquer" reasonLabel="Motif (vérification d’identité effectuée)" reasonRequired
          consequence="Le compteur d’échecs est remis à zéro et l’utilisateur peut se reconnecter immédiatement. Vérifiez d’abord son identité."
          onClose={() => setConfirm(null)} onConfirm={async (reason) => { await api.post(`/users/${u.id}/unlock`, { reason }, false); toast.success('Compte débloqué'); await refresh() }} />
      )}
      {confirm === 'reset-mfa' && u && (
        <ConfirmDialog title="Réinitialiser le second facteur" object={u.name} danger confirmLabel="Réinitialiser" reasonLabel="Motif (vérification d’identité effectuée)" reasonRequired
          consequence="L’application d’authentification et les codes de secours actuels cessent de fonctionner, et toutes les sessions sont fermées. L’utilisateur devra configurer à nouveau son second facteur à sa prochaine connexion. Vérifiez d’abord son identité."
          onClose={() => setConfirm(null)} onConfirm={async (reason) => { await api.post(`/users/${u.id}/reset-mfa`, { reason }, false); toast.success('Second facteur réinitialisé'); await refresh() }} />
      )}
      {confirm === 'sessions' && u && (
        <ConfirmDialog title="Révoquer les sessions" object={u.name} confirmLabel="Révoquer toutes les sessions"
          consequence="Toutes les sessions ouvertes de cet utilisateur sont fermées ; il devra se reconnecter."
          onClose={() => setConfirm(null)} onConfirm={async () => { const r = await api.post<{ revoked: number }>(`/users/${u.id}/revoke-sessions`, undefined, false); toast.success(`${r.revoked} session(s) fermée(s)`) }} />
      )}
    </Modal>
  )
}

function AssignmentsTab({ user, onChanged }: { user: UserRow; onChanged: () => Promise<void> }) {
  const { can } = useAuth()
  const toast = useToast()
  const refs = useReferentials()
  const roles = useRoles()
  const [revoking, setRevoking] = useState<Assignment | null>(null)
  const [form, setForm] = useState({ role_code: '', structure_id: '', zone_id: '', languages: [] as string[], starts_at: '', ends_at: '', reason: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const zoneName = (zid: string | null) => (zid ? String(refs.data?.zones.find((z) => z.id === zid)?.name ?? zid) : null)
  const languages = (refs.data?.languages ?? []).filter((l) => l.is_active !== false).map((l) => String(l.code))

  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      await api.post('/role-assignments', {
        user_id: user.id, role_code: form.role_code, structure_id: form.structure_id || null, zone_id: form.zone_id || null,
        languages: form.role_code === 'validateur' && form.languages.length ? form.languages : null,
        starts_at: localInputToIso(form.starts_at), ends_at: localInputToIso(form.ends_at), reason: form.reason,
      })
      toast.success('Rôle attribué')
      setForm({ role_code: '', structure_id: '', zone_id: '', languages: [], starts_at: '', ends_at: '', reason: '' })
      await onChanged()
    } catch (e) {
      setError(asApiError(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <div className="table-scroll">
        <table>
          <thead><tr><th scope="col">Rôle</th><th scope="col">Périmètre</th><th scope="col">Langues</th><th scope="col">Début</th><th scope="col">Fin</th><th scope="col">État</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
          <tbody>
            {user.roles.map((r) => {
              const [label, tone] = grantState(r)
              return (
                <tr key={r.id} className={r.active ? '' : 'inactive-row'}>
                  <td><strong>{r.name}</strong>{r.reason && <small>Motif : {r.reason}</small>}</td>
                  <td>{r.structure ?? 'Toutes structures'}{r.zone_id && <small>Zone : {zoneName(r.zone_id)}</small>}</td>
                  <td>{r.languages?.length ? r.languages.map((l) => l.toUpperCase()).join(', ') : '—'}</td>
                  <td>{fmtDate(r.starts_at)}</td>
                  <td>{r.ends_at ? fmtDate(r.ends_at) : 'Sans échéance'}</td>
                  <td><Status tone={tone}>{label}</Status></td>
                  <td>{can('roles.manage') && !r.revoked_at && <Button size="small" variant="secondary" onClick={() => setRevoking(r)}>Révoquer</Button>}</td>
                </tr>
              )
            })}
            {user.roles.length === 0 && <tr><td colSpan={7} className="muted">Aucune affectation.</td></tr>}
          </tbody>
        </table>
      </div>
      {can('roles.manage') && (
        <div className="mini-form">
          <h3>Attribuer un rôle</h3>
          <div className="form-grid">
            <Field label="Rôle" required error={error?.field('role_code')}>
              <select className="input" value={form.role_code} onChange={(e) => setForm({ ...form, role_code: e.target.value })} aria-invalid={!!error?.field('role_code')}>
                <option value="">— Choisir —</option>
                {(roles.data?.roles ?? []).map((r) => <option key={r.code} value={r.code}>{r.name}</option>)}
              </select>
            </Field>
            <Field label="Structure (périmètre)" error={error?.field('structure_id')} help="Vide : toutes les structures.">
              <select className="input" value={form.structure_id} onChange={(e) => setForm({ ...form, structure_id: e.target.value })}>
                <option value="">Toutes structures</option>
                {(refs.data?.structures ?? []).filter((s) => s.is_active !== false).map((s) => <option key={s.id} value={s.id}>{String(s.name)}</option>)}
              </select>
            </Field>
            <Field label="Zone de diffusion" error={error?.field('zone_id')}>
              <select className="input" value={form.zone_id} onChange={(e) => setForm({ ...form, zone_id: e.target.value })}>
                <option value="">Toutes zones</option>
                {(refs.data?.zones ?? []).filter((z) => z.is_active !== false).map((z) => <option key={z.id} value={z.id}>{String(z.name)}</option>)}
              </select>
            </Field>
            {form.role_code === 'validateur' && (
              <fieldset className="admin-fieldset">
                <legend>Langues de validation</legend>
                <div className="chip-row">
                  {languages.map((l) => (
                    <label key={l} className="radio-chip">
                      <input type="checkbox" checked={form.languages.includes(l)} onChange={(e) => setForm({ ...form, languages: e.target.checked ? [...form.languages, l] : form.languages.filter((x) => x !== l) })} />
                      {LANG_LABEL[l] ?? l}
                    </label>
                  ))}
                </div>
                {error?.field('languages') && <span className="field-error">{error.field('languages')}</span>}
              </fieldset>
            )}
            <Field label="Début" error={error?.field('starts_at')} help="Vide : immédiat (heure de Libreville)."><input className="input" type="datetime-local" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} /></Field>
            <Field label="Fin" error={error?.field('ends_at')} help="Vide : sans échéance."><input className="input" type="datetime-local" value={form.ends_at} min={form.starts_at || undefined} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} /></Field>
            <Field label="Motif" required wide error={error?.field('reason')} help="5 caractères minimum, inscrit au journal."><textarea value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} /></Field>
          </div>
          <Notice tone="warning">Le serveur refuse l’attribution d’un rôle dont les droits excèdent votre propre pouvoir de délégation.</Notice>
          {error && !Object.keys(error.errors).length && <Notice tone="danger">{error.message}</Notice>}
          <div className="form-actions"><Button icon="plus" onClick={submit} busy={busy} disabled={!form.role_code || form.reason.trim().length < 5}>Attribuer</Button></div>
        </div>
      )}
      {revoking && (
        <ConfirmDialog title="Révoquer l’affectation" object={`${revoking.name} — ${user.name}`} danger reasonLabel="Motif" reasonRequired confirmLabel="Révoquer le rôle"
          consequence="Les droits issus de ce rôle cessent immédiatement ; les tâches en cours passent au suppléant ou restent à réattribuer."
          onClose={() => setRevoking(null)} onConfirm={async (reason) => { await api.post(`/role-assignments/${revoking.id}/revoke`, { reason }); toast.success('Affectation révoquée'); await onChanged() }} />
      )}
    </>
  )
}

function PowersTab({ user, onChanged }: { user: UserRow; onChanged: () => Promise<void> }) {
  const { can } = useAuth()
  const toast = useToast()
  const refs = useReferentials()
  const roles = useRoles()
  const [revoking, setRevoking] = useState<PowerGrant | null>(null)
  const [form, setForm] = useState({ power: 'urgence', starts_at: '', ends_at: '', zone_ids: [] as string[], service_types: [] as string[], reason: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const powerDef = roles.data?.powers.find((p) => p.code === form.power)
  const requiredRole = roles.data?.roles.find((r) => r.code === powerDef?.role)
  const hasRequiredRole = user.roles.some((r) => r.active && r.code === powerDef?.role)
  const toggle = (list: string[], v: string, on: boolean) => (on ? [...list, v] : list.filter((x) => x !== v))
  const zoneName = (zid: string) => String(refs.data?.zones.find((z) => z.id === zid)?.name ?? zid)

  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      const scope = form.power === 'urgence' && form.zone_ids.length ? { zone_ids: form.zone_ids } : form.power === 'prestations' && form.service_types.length ? { service_types: form.service_types } : null
      await api.post('/role-extensions', { user_id: user.id, power: form.power, starts_at: localInputToIso(form.starts_at), ends_at: localInputToIso(form.ends_at), scope, reason: form.reason })
      toast.success('Pouvoir étendu attribué')
      setForm({ ...form, starts_at: '', ends_at: '', zone_ids: [], service_types: [], reason: '' })
      await onChanged()
    } catch (e) {
      setError(asApiError(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <div className="table-scroll">
        <table>
          <thead><tr><th scope="col">Pouvoir</th><th scope="col">Périmètre</th><th scope="col">Début</th><th scope="col">Fin</th><th scope="col">État</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
          <tbody>
            {user.powers.map((p) => {
              const [label, tone] = grantState(p)
              return (
                <tr key={p.id} className={p.active ? '' : 'inactive-row'}>
                  <td><strong>{POWER_LABEL[p.power] ?? p.power}</strong></td>
                  <td>{p.scope?.zone_ids?.length ? `Zones : ${p.scope.zone_ids.map(zoneName).join(', ')}` : p.scope?.service_types?.length ? `Prestations : ${p.scope.service_types.join(', ')}` : 'Périmètre du rôle'}</td>
                  <td>{fmtDateTime(p.starts_at)}</td>
                  <td>{fmtDateTime(p.ends_at)}</td>
                  <td><Status tone={tone}>{label}</Status></td>
                  <td>{can('roles.manage') && !p.revoked_at && <Button size="small" variant="secondary" onClick={() => setRevoking(p)}>Révoquer</Button>}</td>
                </tr>
              )
            })}
            {user.powers.length === 0 && <tr><td colSpan={6} className="muted">Aucun pouvoir étendu.</td></tr>}
          </tbody>
        </table>
      </div>
      {can('roles.manage') && (
        <div className="mini-form">
          <h3>Attribuer un pouvoir étendu (HAB-03)</h3>
          <div className="form-grid">
            <Field label="Pouvoir" required error={error?.field('power')}>
              <select className="input" value={form.power} onChange={(e) => setForm({ ...form, power: e.target.value })}>
                {(roles.data?.powers ?? []).map((p) => <option key={p.code} value={p.code}>{p.label}</option>)}
              </select>
            </Field>
            <div>
              {powerDef && (
                <Notice tone={hasRequiredRole ? 'info' : 'warning'}>
                  Extension du rôle « {requiredRole?.name ?? powerDef.role} » {hasRequiredRole ? '(détenu)' : '— non détenu : le serveur refusera l’attribution'}. Droits ouverts : {powerDef.permissions.join(', ')}.
                </Notice>
              )}
            </div>
            <Field label="Début" error={error?.field('starts_at')} help="Vide : immédiat."><input className="input" type="datetime-local" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} /></Field>
            <Field label="Fin" required error={error?.field('ends_at')} help="Obligatoire : un pouvoir étendu est toujours daté."><input className="input" type="datetime-local" value={form.ends_at} min={form.starts_at || undefined} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} aria-invalid={!!error?.field('ends_at')} /></Field>
            {form.power === 'urgence' && (
              <fieldset className="admin-fieldset wide">
                <legend>Zones autorisées (vide : toutes les zones du rôle)</legend>
                <div className="check-grid">
                  {(refs.data?.zones ?? []).filter((z) => z.is_active !== false).map((z) => (
                    <label key={z.id}><input type="checkbox" checked={form.zone_ids.includes(z.id)} onChange={(e) => setForm({ ...form, zone_ids: toggle(form.zone_ids, z.id, e.target.checked) })} /> {String(z.name)}</label>
                  ))}
                </div>
              </fieldset>
            )}
            {form.power === 'prestations' && (
              <fieldset className="admin-fieldset wide">
                <legend>Types de prestation (vide : tous)</legend>
                <div className="check-grid">
                  {(refs.data?.service_types ?? []).filter((s) => s.is_active !== false).map((s) => (
                    <label key={s.id}><input type="checkbox" checked={form.service_types.includes(String(s.code))} onChange={(e) => setForm({ ...form, service_types: toggle(form.service_types, String(s.code), e.target.checked) })} /> {String(s.name)}</label>
                  ))}
                </div>
              </fieldset>
            )}
            <Field label="Motif" required wide error={error?.field('reason')}><textarea value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} /></Field>
          </div>
          {error && !Object.keys(error.errors).length && <Notice tone="danger">{error.message}</Notice>}
          <div className="form-actions"><Button icon="shield" onClick={submit} busy={busy} disabled={!form.ends_at || form.reason.trim().length < 5}>Attribuer le pouvoir</Button></div>
        </div>
      )}
      {revoking && (
        <ConfirmDialog title="Révoquer le pouvoir étendu" object={`${POWER_LABEL[revoking.power] ?? revoking.power} — ${user.name}`} danger reasonLabel="Motif" reasonRequired confirmLabel="Révoquer le pouvoir"
          consequence="Le pouvoir cesse immédiatement, avant son échéance."
          onClose={() => setRevoking(null)} onConfirm={async (reason) => { await api.post(`/role-extensions/${revoking.id}/revoke`, { reason }); toast.success('Pouvoir révoqué'); await onChanged() }} />
      )}
    </>
  )
}

export function useEffective(userId: string | null) {
  return useQuery({
    queryKey: ['effective', userId],
    queryFn: ({ signal }) => api.get<{ data: Effective }>(`/users/${userId}/effective`, undefined, signal).then((r) => r.data),
    enabled: !!userId,
  })
}

function EffectiveRights({ userId }: { userId: string }) {
  const eff = useEffective(userId)
  const roles = useRoles()
  if (eff.isLoading) return <Loading />
  if (eff.isError || !eff.data) return <ErrorState error={eff.error} onRetry={() => eff.refetch()} />
  const perms = roles.data?.permissions ?? []
  const groups = new Map<string, { code: string; name: string }[]>()
  for (const code of eff.data.permissions) {
    const p = perms.find((x) => x.code === code)
    const g = p?.group ?? 'Autres'
    groups.set(g, [...(groups.get(g) ?? []), { code, name: p?.name ?? code }])
  }
  return (
    <div style={{ display: 'grid', gap: 12 }}>
      <dl className="kv">
        <dt>Rôles actifs</dt><dd>{eff.data.roles.length ? eff.data.roles.map((c) => roles.data?.roles.find((r) => r.code === c)?.name ?? c).join(', ') : 'Aucun'}</dd>
        <dt>Pouvoirs en vigueur</dt><dd>{Object.entries(eff.data.powers).map(([k, v]) => `${POWER_LABEL[k] ?? k} : ${v ? 'oui' : 'non'}`).join(' · ')}</dd>
        <dt>Langues de validation</dt><dd>{eff.data.validation_languages.length ? eff.data.validation_languages.map((l) => LANG_LABEL[l] ?? l).join(', ') : '—'}</dd>
      </dl>
      {[...groups.entries()].map(([g, items]) => (
        <div key={g}>
          <strong className="small">{g}</strong>
          <div className="chip-row" style={{ marginTop: 6 }}>{items.map((p) => <span className="chip" key={p.code} title={p.code}><Icon name="check" size={12} />{p.name}</span>)}</div>
        </div>
      ))}
      {eff.data.permissions.length === 0 && <EmptyState title="Aucun droit effectif" />}
    </div>
  )
}
