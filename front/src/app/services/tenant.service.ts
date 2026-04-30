// src/app/core/services/tenant.service.ts
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { BaseApiService } from './base-api.service';

@Injectable({ providedIn: 'root' })
export class TenantService extends BaseApiService {

    private readonly tenantsEndpoint = '/tenants';
    private readonly propertiesEndpoint = '/properties';

    /* ---------- TENANTS ---------- */

    searchTenants(query: string): Observable<any> {
        return this.get(
            `${this.tenantsEndpoint}/search`,
            { q: query }
        );
    }

    assignTenants(
        propertyId: number,
        tenantsPayload: any
    ): Observable<any> {
        return this.post(
            `${this.propertiesEndpoint}/${propertyId}/assign-tenants`,
            tenantsPayload
        );
    }
}
