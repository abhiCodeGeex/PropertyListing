import { Routes } from '@angular/router';
import { RoleGuard } from '../../guards/role.guard';

export const MAINTENANCE_ROUTES: Routes = [
  {
    path: '',
    redirectTo: 'requests',
    pathMatch: 'full'
  },
  {
    path: 'requests',
    loadComponent: () =>
      import('./request-list/request-list.component').then((m) => m.RequestListComponent),
    canActivate: [RoleGuard],
    data: { roles: ['tenant', 'owner', 'property_manager', 'super-admin'] }
  },
  {
    // Legacy route kept for backwards compatibility with any bookmarked links.
    // The create form is now rendered as a modal on the list page.
    path: 'requests/new',
    redirectTo: 'requests',
    pathMatch: 'full'
  },
  {
    path: 'requests/:id',
    loadComponent: () =>
      import('./request-detail/request-detail.component').then((m) => m.RequestDetailComponent),
    canActivate: [RoleGuard],
    data: { roles: ['tenant', 'owner', 'property_manager', 'super-admin'] }
  },
  {
    path: 'dashboard',
    loadComponent: () =>
      import('./owner-dashboard/owner-dashboard.component').then((m) => m.OwnerDashboardComponent),
    canActivate: [RoleGuard],
    data: { roles: ['owner', 'property_manager', 'super-admin'] }
  }
];
