import { lazy, Suspense, type ComponentType, type ReactNode } from 'react'
import { createBrowserRouter, Navigate } from 'react-router'
import Layout from './components/Layout'
import { Guard, RequireAuth } from './lib/auth'
import { Loading } from './components/ui'

const page = (loader: () => Promise<{ default: ComponentType }>) => {
  const Component = lazy(loader)
  return <Suspense fallback={<Loading />}><Component /></Suspense>
}
const guarded = (perm: string[], node: ReactNode) => <Guard perm={perm}>{node}</Guard>

/** Catalogue des vues web (chapitre 27). Les droits sont toujours revérifiés par l'API. */
export const router = createBrowserRouter([
  { path: '/connexion', element: page(() => import('./pages/account/Login')) },
  { path: '/compte/verification', element: page(() => import('./pages/account/Verification')) },
  { path: '/q/:code', element: page(() => import('./pages/account/QrResolve')) },
  {
    element: <RequireAuth><Layout /></RequireAuth>,
    children: [
      { index: true, element: <Navigate to="/accueil" replace /> },
      { path: '/accueil', element: page(() => import('./pages/account/Dashboard')) },
      { path: '/mon-compte', element: page(() => import('./pages/account/MyAccount')) },
      { path: '/mes-taches', element: page(() => import('./pages/account/Tasks')) },
      { path: '/notifications', element: page(() => import('./pages/account/Notifications')) },
      { path: '/aide', element: page(() => import('./pages/account/Help')) },

      { path: '/affichage/ecrans', element: guarded(['screens.view'], page(() => import('./pages/display/Screens'))) },
      { path: '/affichage/ecrans/:id', element: guarded(['screens.view', 'campaigns.view'], page(() => import('./pages/display/ScreenDetail'))) },
      { path: '/affichage/appairage', element: guarded(['devices.pair'], page(() => import('./pages/display/Pairing'))) },
      { path: '/affichage/parc', element: guarded(['screens.view', 'supervision.view'], page(() => import('./pages/display/FleetOverview'))) },
      { path: '/affichage/groupes', element: guarded(['screens.view', 'groups.manage'], page(() => import('./pages/display/Groups'))) },
      { path: '/affichage/profils', element: guarded(['screens.view', 'screens.manage'], page(() => import('./pages/display/ScreenProfiles'))) },
      { path: '/affichage/modeles', element: guarded(['templates.view', 'templates.manage'], page(() => import('./pages/display/Templates'))) },
      { path: '/affichage/modeles/:id/edition', element: guarded(['templates.manage'], page(() => import('./pages/display/TemplateEditor'))) },
      { path: '/affichage/programme', element: guarded(['campaigns.view'], page(() => import('./pages/display/Program'))) },
      { path: '/affichage/alertes', element: guarded(['alerts.view', 'alerts.manage'], page(() => import('./pages/display/Alerts'))) },

      { path: '/affichage/contenus', element: guarded(['contents.view'], page(() => import('./pages/publication/Contents'))) },
      { path: '/affichage/contenus/nouveau', element: guarded(['contents.create'], page(() => import('./pages/publication/ContentEditor'))) },
      { path: '/affichage/contenus/:id/edition', element: guarded(['contents.view'], page(() => import('./pages/publication/ContentEditor'))) },
      { path: '/affichage/contenus/:id/validation', element: guarded(['contents.validate'], page(() => import('./pages/publication/ContentValidation'))) },
      { path: '/affichage/campagnes', element: guarded(['campaigns.view'], page(() => import('./pages/publication/Campaigns'))) },
      { path: '/affichage/campagnes/nouvelle', element: guarded(['campaigns.manage'], page(() => import('./pages/publication/CampaignWizard'))) },
      { path: '/affichage/campagnes/:id/modifier', element: guarded(['campaigns.manage'], page(() => import('./pages/publication/CampaignWizard'))) },
      { path: '/affichage/diffusions/:id', element: guarded(['campaigns.view'], page(() => import('./pages/publication/Delivery'))) },
      { path: '/mediatheque', element: guarded(['media.view'], page(() => import('./pages/publication/Media'))) },
      { path: '/mediatheque/:id', element: guarded(['media.view'], page(() => import('./pages/publication/MediaDetail'))) },
      { path: '/documents', element: guarded(['documents.view'], page(() => import('./pages/publication/Documents'))) },
      { path: '/documents/:id', element: guarded(['documents.view'], page(() => import('./pages/publication/DocumentDetail'))) },

      { path: '/salles', element: guarded(['rooms.view'], page(() => import('./pages/rooms/Rooms'))) },
      { path: '/salles/calendrier', element: guarded(['rooms.view'], page(() => import('./pages/rooms/RoomCalendar'))) },
      { path: '/salles/:id', element: guarded(['rooms.view'], page(() => import('./pages/rooms/RoomDetail'))) },
      { path: '/mes-reservations', element: guarded(['reservations.create'], page(() => import('./pages/rooms/MyReservations'))) },
      { path: '/reservations/nouvelle', element: guarded(['reservations.create'], page(() => import('./pages/rooms/NewReservation'))) },
      { path: '/reservations/a-valider', element: guarded(['reservations.approve'], page(() => import('./pages/rooms/ReservationApprovals'))) },
      { path: '/reservations/:id', element: page(() => import('./pages/rooms/ReservationDetail')) },
      { path: '/prestations', element: guarded(['services.process'], page(() => import('./pages/rooms/Services'))) },
      { path: '/accueil-protocole', element: guarded(['visits.manage'], page(() => import('./pages/rooms/Protocol'))) },

      { path: '/rapports', element: guarded(['reports.view'], page(() => import('./pages/admin/Reports'))) },
      { path: '/traitements', element: guarded(['dashboard.view'], page(() => import('./pages/admin/Exports'))) },
      { path: '/supervision', element: guarded(['supervision.view'], page(() => import('./pages/admin/Supervision'))) },
      { path: '/administration/utilisateurs', element: guarded(['users.manage', 'roles.manage'], page(() => import('./pages/admin/Users'))) },
      { path: '/administration/roles', element: guarded(['roles.manage', 'users.manage'], page(() => import('./pages/admin/Roles'))) },
      { path: '/administration/referentiels', element: guarded(['referentials.manage'], page(() => import('./pages/admin/Referentials'))) },
      { path: '/administration/imports', element: guarded(['imports.run'], page(() => import('./pages/admin/Imports'))) },
      { path: '/administration/journaux', element: guarded(['audit.view'], page(() => import('./pages/admin/Audit'))) },
      { path: '/administration/parametres', element: guarded(['settings.manage', 'settings.technical'], page(() => import('./pages/admin/Settings'))) },
      { path: '*', element: page(() => import('./pages/account/Help')) },
    ],
  },
])
