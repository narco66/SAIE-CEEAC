import { createContext, useContext, useEffect, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Navigate, useLocation } from 'react-router'
import { api, ApiError, setUnauthenticatedHandler } from './api'
import { ErrorState, Loading } from '../components/ui'

export interface Me {
  id: string
  name: string
  email: string
  initials: string
  title: string | null
  structure: { id: string; name: string } | null
  role_label: string
  roles: { id: string; code: string; name: string; languages: string[] | null; ends_at: string | null }[]
  permissions: string[]
  powers: { power: string; ends_at: string; active: boolean; scope: unknown }[]
  validation_languages: string[]
  mfa_enabled: boolean
  mfa_enrollment_required: boolean
  must_change_password: boolean
  notification_preferences: { email?: boolean; push?: boolean; muted_events?: string[] }
  locale: string
  source: string
  demo_mode: boolean
  server_time: string
}

interface AuthValue {
  me: Me
  can: (...permissions: string[]) => boolean
  hasPower: (power: string) => boolean
  hasRole: (role: string) => boolean
}

const AuthContext = createContext<AuthValue | null>(null)

export function useMeQuery() {
  return useQuery({
    queryKey: ['me'],
    queryFn: async () => {
      try {
        return (await api.get<{ data: Me }>('/me')).data
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) return null
        throw e
      }
    },
    staleTime: 60_000,
    retry: false,
  })
}

export function useAuth(): AuthValue {
  const value = useContext(AuthContext)
  if (!value) throw new Error('useAuth hors du contexte authentifié')
  return value
}

/** Garde globale : redirige vers /connexion en conservant le lien direct demandé (chapitre 26.2). */
export function RequireAuth({ children }: { children: ReactNode }) {
  const query = useMeQuery()
  const location = useLocation()
  const client = useQueryClient()

  useEffect(() => {
    setUnauthenticatedHandler(() => client.setQueryData(['me'], null))
  }, [client])

  if (query.isLoading) return <Loading label="Vérification de la session…" />
  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  if (!query.data) {
    return <Navigate to={`/connexion?retour=${encodeURIComponent(location.pathname + location.search)}`} replace state={{ expired: true }} />
  }
  const me = query.data
  if ((me.mfa_enrollment_required || me.must_change_password) && !location.pathname.startsWith('/mon-compte')) {
    return <Navigate to="/mon-compte?securite=1" replace />
  }
  const value: AuthValue = {
    me,
    can: (...permissions) => permissions.some((p) => me.permissions.includes(p)),
    hasPower: (power) => me.powers.some((p) => p.power === power && p.active),
    hasRole: (role) => me.roles.some((r) => r.code === role),
  }
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

/** Une page non habilitée n'est ni listée dans le menu ni affichée vide : elle présente l'état « accès interdit ». */
export function Guard({ perm, children }: { perm: string[]; children: ReactNode }) {
  const { can } = useAuth()
  if (perm.length && !can(...perm)) return <ErrorState error={new ApiError(403, { message: 'Accès interdit' })} />
  return <>{children}</>
}
