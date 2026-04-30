import { Routes } from '@angular/router';
import { LoginComponent } from './pages/login/login.component';
import { AuthGuard } from './guards/auth.guard';
import { SignupComponent } from './pages/signup/signup.component';
import { ProfileCompletionGuard } from './guards/profile-completion.guard';
import { RoleGuard } from './guards/role.guard';
import { LoggedInGuard } from './guards/loggedIn.guard';
import { ForgotPasswordComponent } from './pages/forgot-password/forgot-password.component';
import { ResetPasswordComponent } from './pages/reset-password/reset-password.component';
import { VerifyEmailComponent } from './pages/verify-email/verify-email.component';
import { AuthComponent } from './pages/auth/auth.component';

export const routes: Routes = [
  // Public route
  // { path: 'login', component: LoginComponent, canActivate: [LoggedInGuard]},
  // { path: 'register', component: SignupComponent, canActivate: [LoggedInGuard] },
  { path: 'login', component: AuthComponent, canActivate: [LoggedInGuard], data: { form: 'login' } },
  { path: 'register', component: AuthComponent, canActivate: [LoggedInGuard], data: { form: 'signup' } },
  { path: 'forget-password', component: ForgotPasswordComponent, canActivate: [LoggedInGuard] },
  { path: 'verify-email', component: VerifyEmailComponent, canActivate: [LoggedInGuard] },
  { path: 'reset-password/:token', component: ResetPasswordComponent },
  // Protected layout wrapper
  {
    path: '',
    loadComponent: () =>
      import('./layout').then((m) => m.DefaultLayoutComponent),
    canActivate: [AuthGuard, ProfileCompletionGuard],
    runGuardsAndResolvers: 'always',
    children: [
      { path: '', redirectTo: 'dashboard', pathMatch: 'full' },
      {
        path: 'dashboard',
        loadComponent: () =>
          import('./pages/dashboard/dashboard.component').then(
            (m) => m.DashboardComponent
          ),
      },
      {
        path: 'profile',
        loadComponent: () =>
          import('./pages/update-profile/update-profile.component').then(
            (m) => m.UpdateProfileComponent
          ),
      },
      {
        path: 'users',
        loadComponent: () =>
          import('./pages/users/users.component').then(
            (m) => m.UsersComponent
          ),
        canActivate: [RoleGuard],
        data: { roles: ['super-admin'] }
      },
      {
        path: 'commission-settings',
        loadComponent: () =>
          import('./pages/commission-settings/commission-settings.component').then(
            (m) => m.CommissionSettingsComponent
          ),
        canActivate: [RoleGuard],
        data: { roles: ['super-admin'] }
      },
      {
        path: 'properties',
        loadComponent: () =>
          import('./pages/owners/property/property.component').then(
            (m) => m.PropertyComponent
          ),
        canActivate: [RoleGuard],
        data: { roles: ['owner', 'super-admin', 'property_manager'] }
      },
      {
        path: 'rent-deeds',
        loadComponent: () =>
          import('./pages/owners/rent-deed/rent-deed.component').then(
            (m) => m.RentDeedComponent
          ),
        canActivate: [RoleGuard],
        data: { roles: ['owner', 'super-admin', 'property_manager', 'tenant'] }
      },
      {
        path: 'assign-property',
        loadComponent: () =>
          import('./pages/property-tenant-assign/property-tenant-assign.component').then(
            (m) => m.PropertyTenantAssignComponent
          ),
        canActivate: [RoleGuard],
        data: { roles: ['owner', 'super-admin', 'property_manager'] }
      },
      {
        path: 'rent/subscribe/:tenancyId',
        loadComponent: () =>
          import('./pages/rent-subscription/rent-subscription.component').then((m) => m.RentSubscriptionComponent),
      },
      {
        path: 'rent-history',
        loadComponent: () =>
          import('./pages/rent-history/rent-history.component').then((m) => m.RentHistoryComponent),
        canActivate: [RoleGuard],
        data: { roles: ['owner', 'super-admin', 'tenant', 'property_manager'] }
      },
      {
        path: 'invoices',
        loadComponent: () =>
          import('./pages/invoices/invoices.component').then((m) => m.InvoicesComponent),
        canActivate: [RoleGuard],
        data: { roles: ['owner', 'super-admin', 'tenant', 'property_manager'] }
      },
      {
        path: 'payment-report',
        loadComponent: () =>
          import('./pages/report/report.component').then((m) => m.ReportComponent),
        canActivate: [RoleGuard],
        data: { roles: ['owner', 'super-admin', 'property_manager'] }
      },
      {
        path: 'maintenance',
        loadChildren: () =>
          import('./modules/maintenance/maintenance.routes').then((m) => m.MAINTENANCE_ROUTES),
      },
      {
        path: 'chat',
        loadChildren: () =>
          import('./modules/chat/chat.routes').then((m) => m.CHAT_ROUTES),
      },
    ],
  },

  // Fallback
  { path: '**', redirectTo: 'dashboard' },
];
