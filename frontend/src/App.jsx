import { lazy, Suspense } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import ProtectedRoute from './components/ProtectedRoute.jsx'
import { AuthProvider } from './context/AuthContext.jsx'
import PublicLayout from './layouts/PublicLayout.jsx'
import './styles/design-system.css'
import './styles/staff.css'
import './styles/organizer-access.css'
import './styles/admin-applications.css'
import './styles/admin-events.css'
import './styles/admin-monitoring.css'
import './styles/admin-users.css'
import './styles/admin-activity.css'
import './styles/organizer-application.css'

const HomePage = lazy(() => import('./pages/public/HomePage.jsx'))
const EventsPage = lazy(() => import('./pages/public/EventsPage.jsx'))
const EventDetailPage = lazy(() => import('./pages/public/EventDetailPage.jsx'))
const LoginPage = lazy(() => import('./pages/auth/LoginPage.jsx'))
const RegisterPage = lazy(() => import('./pages/auth/RegisterPage.jsx'))
const RoleDashboardPage = lazy(() => import('./pages/RoleDashboardPage.jsx'))
const MyReservationsPage = lazy(() => import('./pages/client/MyReservationsPage.jsx'))
const ReservationDetailPage = lazy(() => import('./pages/client/ReservationDetailPage.jsx'))
const MyTicketsPage = lazy(() => import('./pages/client/MyTicketsPage.jsx'))
const MyTicketDetailPage = lazy(() => import('./pages/client/MyTicketDetailPage.jsx'))
const OrganizerApplicationPage = lazy(() => import('./pages/client/OrganizerApplicationPage.jsx'))
const CheckoutPage = lazy(() => import('./pages/client/CheckoutPage.jsx'))
const PaymentDetailPage = lazy(() => import('./pages/client/PaymentDetailPage.jsx'))
const PaymentHistoryPage = lazy(() => import('./pages/client/PaymentHistoryPage.jsx'))

const OrganizerLayout = lazy(() => import('./layouts/OrganizerLayout.jsx'))
const OrganizerOverviewPage = lazy(() => import('./pages/organizer/OrganizerOverviewPage.jsx'))
const OrganizerEventsPage = lazy(() => import('./pages/organizer/OrganizerEventsPage.jsx'))
const OrganizerEventFormPage = lazy(() => import('./pages/organizer/OrganizerEventFormPage.jsx'))
const OrganizerEventDetailPage = lazy(() => import('./pages/organizer/OrganizerEventDetailPage.jsx'))
const OrganizerTicketTypesPage = lazy(() => import('./pages/organizer/OrganizerTicketTypesPage.jsx'))
const OrganizerTicketTypeFormPage = lazy(() => import('./pages/organizer/OrganizerTicketTypeFormPage.jsx'))
const OrganizerEventReservationsPage = lazy(() => import('./pages/organizer/OrganizerEventReservationsPage.jsx'))
const OrganizerReservationDetailPage = lazy(() => import('./pages/organizer/OrganizerReservationDetailPage.jsx'))
const OrganizerEventStaffPage = lazy(() => import('./pages/organizer/OrganizerEventStaffPage.jsx'))
const OrganizerEventAccessPage = lazy(() => import('./pages/organizer/OrganizerEventAccessPage.jsx'))

const StaffLayout = lazy(() => import('./layouts/StaffLayout.jsx'))
const StaffDashboardPage = lazy(() => import('./pages/staff/StaffDashboardPage.jsx'))
const StaffEventsPage = lazy(() => import('./pages/staff/StaffEventsPage.jsx'))
const StaffEventDetailPage = lazy(() => import('./pages/staff/StaffEventDetailPage.jsx'))
const StaffScannerPage = lazy(() => import('./pages/staff/StaffScannerPage.jsx'))
const StaffAccessPage = lazy(() => import('./pages/staff/StaffAccessPage.jsx'))

const SuperAdminLayout = lazy(() => import('./layouts/SuperAdminLayout.jsx'))
const AdminTicketTypesPage = lazy(() => import('./pages/admin/AdminTicketTypesPage.jsx'))
const AdminReservationsPage = lazy(() => import('./pages/admin/AdminReservationsPage.jsx'))
const AdminReservationDetailPage = lazy(() => import('./pages/admin/AdminReservationDetailPage.jsx'))
const AdminOverviewPage = lazy(() => import('./pages/admin/AdminOverviewPage.jsx'))
const OrganizerApplicationsPage = lazy(() => import('./pages/admin/OrganizerApplicationsPage.jsx'))
const OrganizerApplicationDetailPage = lazy(() => import('./pages/admin/OrganizerApplicationDetailPage.jsx'))
const AdminUsersPage = lazy(() => import('./pages/admin/AdminUsersPage.jsx'))
const AdminUserDetailPage = lazy(() => import('./pages/admin/AdminUserDetailPage.jsx'))
const AdminStaffProvisioningPage = lazy(() => import('./pages/admin/AdminStaffProvisioningPage.jsx'))
const AdminEventsPage = lazy(() => import('./pages/admin/AdminEventsPage.jsx'))
const AdminEventDetailPage = lazy(() => import('./pages/admin/AdminEventDetailPage.jsx'))
const AdminTicketTypeDetailPage = lazy(() => import('./pages/admin/AdminTicketTypeDetailPage.jsx'))
const AdminAccessPage = lazy(() => import('./pages/admin/AdminAccessPage.jsx'))
const AdminActivityHistoryPage = lazy(() => import('./pages/admin/AdminActivityHistoryPage.jsx'))

function LazyPageFallback() {
  return (
    <div className="auth-loading" role="status" aria-live="polite">
      <span className="spinner-border spinner-border-sm" aria-hidden="true" />
      <span>Loading page…</span>
    </div>
  )
}

function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Suspense fallback={<LazyPageFallback />}>
          <Routes>
            <Route element={<PublicLayout />}>
              <Route path="/" element={<HomePage />} />
              <Route path="/events" element={<EventsPage />} />
              <Route path="/events/:slug" element={<EventDetailPage />} />
              <Route path="/login" element={<LoginPage />} />
              <Route path="/register" element={<RegisterPage />} />
              <Route path="/organizer-application" element={<ProtectedRoute roles={['client', 'organizer']}><OrganizerApplicationPage /></ProtectedRoute>} />
              <Route path="/client/dashboard" element={<ProtectedRoute roles={['client']}><RoleDashboardPage /></ProtectedRoute>} />
              <Route path="/client/reservations" element={<ProtectedRoute roles={['client']}><MyReservationsPage /></ProtectedRoute>} />
              <Route path="/client/reservations/:id" element={<ProtectedRoute roles={['client']}><ReservationDetailPage /></ProtectedRoute>} />
              <Route path="/client/checkout/:reservationId" element={<ProtectedRoute roles={['client']}><CheckoutPage /></ProtectedRoute>} />
              <Route path="/client/payments" element={<ProtectedRoute roles={['client']}><PaymentHistoryPage /></ProtectedRoute>} />
              <Route path="/client/payments/:paymentId" element={<ProtectedRoute roles={['client']}><PaymentDetailPage /></ProtectedRoute>} />
              <Route path="/my-tickets" element={<ProtectedRoute roles={['client']}><MyTicketsPage /></ProtectedRoute>} />
              <Route path="/my-tickets/:reservationId/:ticketId" element={<ProtectedRoute roles={['client']}><MyTicketDetailPage /></ProtectedRoute>} />
              <Route path="*" element={<Navigate to="/" replace />} />
            </Route>
            <Route path="/staff" element={<ProtectedRoute roles={['staff']}><StaffLayout /></ProtectedRoute>}>
              <Route index element={<Navigate to="/staff/dashboard" replace />} />
              <Route path="dashboard" element={<StaffDashboardPage />} />
              <Route path="events" element={<StaffEventsPage />} />
              <Route path="events/:eventId" element={<StaffEventDetailPage />} />
              <Route path="events/:eventId/scanner" element={<StaffScannerPage />} />
              <Route path="events/:eventId/access" element={<StaffAccessPage />} />
            </Route>
            <Route path="/organizer" element={<ProtectedRoute roles={['organizer']}><OrganizerLayout /></ProtectedRoute>}>
              <Route index element={<Navigate to="/organizer/dashboard" replace />} />
              <Route path="dashboard" element={<OrganizerOverviewPage />} />
              <Route path="events" element={<OrganizerEventsPage />} />
              <Route path="events/new" element={<OrganizerEventFormPage />} />
              <Route path="events/:id/edit" element={<OrganizerEventFormPage />} />
              <Route path="events/:id" element={<OrganizerEventDetailPage />} />
              <Route path="events/:eventId/ticket-types" element={<OrganizerTicketTypesPage />} />
              <Route path="events/:eventId/ticket-types/new" element={<OrganizerTicketTypeFormPage />} />
              <Route path="events/:eventId/ticket-types/:ticketTypeId/edit" element={<OrganizerTicketTypeFormPage />} />
              <Route path="events/:eventId/reservations" element={<OrganizerEventReservationsPage />} />
              <Route path="events/:eventId/reservations/:reservationId" element={<OrganizerReservationDetailPage />} />
              <Route path="events/:eventId/staff" element={<OrganizerEventStaffPage />} />
              <Route path="events/:eventId/access" element={<OrganizerEventAccessPage />} />
            </Route>
            <Route path="/admin" element={<ProtectedRoute roles={['super_admin']}><SuperAdminLayout /></ProtectedRoute>}>
              <Route index element={<Navigate to="/admin/dashboard" replace />} />
              <Route path="dashboard" element={<AdminOverviewPage />} />
              <Route path="users" element={<AdminUsersPage />} />
              <Route path="users/:id" element={<AdminUserDetailPage />} />
              <Route path="staff-provisioning" element={<AdminStaffProvisioningPage />} />
              <Route path="events" element={<AdminEventsPage />} />
              <Route path="events/:eventId" element={<AdminEventDetailPage />} />
              <Route path="access" element={<AdminAccessPage />} />
              <Route path="activity" element={<AdminActivityHistoryPage />} />
              <Route path="organizer-applications" element={<OrganizerApplicationsPage />} />
              <Route path="organizer-applications/:id" element={<OrganizerApplicationDetailPage />} />
              <Route path="ticket-types" element={<AdminTicketTypesPage />} />
              <Route path="ticket-types/:id" element={<AdminTicketTypeDetailPage />} />
              <Route path="reservations" element={<AdminReservationsPage />} />
              <Route path="reservations/:id" element={<AdminReservationDetailPage />} />
            </Route>
          </Routes>
        </Suspense>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
