// src/app/core/services/property.service.ts
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { Property } from '../models/property.model';
import { BaseApiService } from './base-api.service';

@Injectable({ providedIn: 'root' })
export class PropertyService extends BaseApiService {

  private readonly endpoint = '/properties';

  /* ---------- PROPERTIES ---------- */

  getProperties(params?: {
    page?: number;
    per_page?: number;
    search?: string;
    filter_role?: string;
    filter_user_id?: string;
  }): Observable<any> {
    // Only include params that are actually defined
    const query: any = {
      ...(params?.page !== undefined && { page: params.page }),
      ...(params?.per_page !== undefined && { per_page: params.per_page }),
      ...(params?.search && { search: params.search }),
      ...(params?.filter_role != null && { filter_role: params.filter_role }),
      ...(params?.filter_user_id != null && { filter_user_id: params.filter_user_id }),
    };

    return this.get(this.endpoint, query);
  }

  getCommissionSettings(params?: {
    page?: number;
    per_page?: number;
    search?: string;
  }): Observable<any> {
    const query: any = {
      ...(params?.page !== undefined && { page: params.page }),
      ...(params?.per_page !== undefined && { per_page: params.per_page }),
      ...(params?.search && { search: params.search }),
    };

    return this.get(`${this.endpoint}/commission-settings`, query);
  }

  updateCommissionSettings(propertyId: number, payload: {
    owner_commission_percent: number;
    manager_commission_percent: number;
  }): Observable<any> {
    return this.put(`${this.endpoint}/${propertyId}/commission-settings`, payload);
  }


  getPropertyTenants(propertyId: number): Observable<any[]> {
    return this.get(`${this.endpoint}/${propertyId}/tenants`);
  }

  createProperty(property: Property): Observable<Property> {
    return this.post(this.endpoint, property);
  }

  updateProperty(id: number, property: Property): Observable<Property> {
    return this.put(`${this.endpoint}/${id}`, property);
  }

  deleteProperty(id: number): Observable<void> {
    return this.delete(`${this.endpoint}/${id}`);
  }

  /* ---------- RENT DEEDS ---------- */

  getRentDeeds(page: number, search: string = '', propertyId?: number): Observable<any> {
    const params: any = { page, search };
    if (propertyId) params.propertyId = propertyId;
    return this.get(`${this.endpoint}/rent-deeds`, params);
  }

  createRentDeed(payload: any): Observable<any> {
    return this.post(`${this.endpoint}/rent-deeds`, payload);
  }

  updateRentDeed(id: number, payload: any): Observable<any> {
    return this.put(`${this.endpoint}/rent-deeds/${id}`, payload);
  }

  deleteRentDeed(id: number): Observable<void> {
    return this.delete(`${this.endpoint}/rent-deeds/${id}`);
  }

  /* ---------- OWNERS / TENANTS ---------- */

  getOwners(): Observable<any[]> {
    return this.get(`${this.endpoint}/owners`);
  }

  getTenants(): Observable<any[]> {
    return this.get(`${this.endpoint}/tenants`);
  }

  /* ---------- MANAGER ---------- */

  assignManager(
    propertyId: number,
    managerId: number
  ): Observable<any> {
    return this.post(
      `${this.endpoint}/${propertyId}/assign-manager`,
      { manager_id: managerId }
    );
  }
}
