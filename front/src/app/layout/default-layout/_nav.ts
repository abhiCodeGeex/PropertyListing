import { INavData } from '@coreui/angular';

/**
 * Role checker utility
 */
function hasAnyRole(userRoles: string[], allowedRoles: string[]): boolean {
  return allowedRoles.some(role => userRoles.includes(role));
}

/**
 * Centralized navigation configuration
 */
const NAV_CONFIG: Array<INavData & { roles?: string[] }> = [
  {
    name: 'Dashboard',
    url: '/dashboard',
    iconComponent: { name: 'cil-speedometer' },
  },
  {
    name: 'Users',
    url: '/users',
    iconComponent: { name: 'cil-people' },
    roles: ['super-admin'],
  },
  {
    name: 'Commission Settings',
    url: '/commission-settings',
    iconComponent: { name: 'cil-dollar' },
    roles: ['super-admin'],
  },
  {
    name: 'Properties',
    url: '/properties',
    iconComponent: { name: 'cil-layers' },
    roles: ['owner', 'super-admin', 'property_manager'],
  },
  {
    name: 'Rent Deeds',
    url: '/rent-deeds',
    iconComponent: { name: 'cil-notes' },
    roles: ['owner', 'super-admin', 'tenant', 'property_manager'],
  },
  {
    name: 'Rent History',
    url: '/rent-history',
    iconComponent: { name: 'cil-history' },
    roles: ['owner', 'super-admin', 'tenant', 'property_manager'],
  },
  {
    name: 'Invoices',
    url: '/invoices',
    iconComponent: { name: 'cil-description' },
    roles: ['owner', 'super-admin', 'tenant', 'property_manager'],
  },
  {
    name: 'Payment Report',
    url: '/payment-report',
    iconComponent: { name: 'cil-spreadsheet' },
    roles: ['owner', 'super-admin', 'property_manager'],
  },
  {
    name: 'Maintenance',
    url: '/maintenance/requests',
    iconComponent: { name: 'cil-task' },
    roles: ['tenant', 'owner', 'super-admin', 'property_manager'],
  },
  {
    name: 'Chat',
    url: '/chat',
    iconComponent: { name: 'cil-speech' },
    roles: ['tenant', 'owner', 'super-admin', 'property_manager'],
  },
];

/**
 * Filter navigation based on roles
 */
export function buildNavItems(userRoles: string[]): INavData[] {
  return NAV_CONFIG
    .filter(item => !item.roles || hasAnyRole(userRoles, item.roles))
    .map(item => {
      const nextUrl = item.name === 'Maintenance'
        ? hasAnyRole(userRoles, ['owner', 'property_manager', 'super-admin'])
          ? '/maintenance/dashboard'
          : '/maintenance/requests'
        : item.url;

      return {
        ...item,
        url: nextUrl,
        iconComponent: item.iconComponent ? { ...item.iconComponent } : undefined,
      };
    });
}
