import { Pipe, PipeTransform } from '@angular/core';

@Pipe({ name: 'filterTenant', standalone: true })
export class FilterTenantPipe implements PipeTransform {
  transform(tenants: any[], searchText: string): any[] {
    if (!tenants) return [];
    if (!searchText) return tenants;
    searchText = searchText.toLowerCase();
    return tenants.filter(t =>
      t.name.toLowerCase().includes(searchText) ||
      t.email.toLowerCase().includes(searchText)
    );
  }
}
