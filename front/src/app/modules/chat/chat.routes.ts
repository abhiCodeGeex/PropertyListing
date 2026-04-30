import { Routes } from '@angular/router';
import { RoleGuard } from '../../guards/role.guard';

export const CHAT_ROUTES: Routes = [
  {
    path: '',
    loadComponent: () =>
      import('./chat-page.component').then((m) => m.ChatPageComponent),
    canActivate: [RoleGuard],
    data: { roles: ['tenant', 'owner', 'property_manager', 'super-admin'] }
  },
  {
    path: ':id',
    loadComponent: () =>
      import('./chat-page.component').then((m) => m.ChatPageComponent),
    canActivate: [RoleGuard],
    data: { roles: ['tenant', 'owner', 'property_manager', 'super-admin'] }
  }
];
