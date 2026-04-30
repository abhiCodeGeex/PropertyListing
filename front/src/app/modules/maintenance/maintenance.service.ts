import { HttpClient } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { BaseApiService } from '../../services/base-api.service';
import { MaintenanceFilters, MaintenanceWorkspaceSummary } from './maintenance.models';

@Injectable({ providedIn: 'root' })
export class MaintenanceService extends BaseApiService {
  private readonly endpoint = '/v1/maintenance';

  constructor(http: HttpClient) {
    super(http);
  }

  getMyRequests(filters: MaintenanceFilters = {}): Observable<any> {
    return this.get(`${this.endpoint}/requests/my`, filters);
  }

  getRequests(filters: MaintenanceFilters = {}): Observable<any> {
    return this.get(`${this.endpoint}/requests`, filters);
  }

  getWorkspaceSummary(): Observable<{ summary: MaintenanceWorkspaceSummary }> {
    return this.get(`${this.endpoint}/summary`);
  }

  getRequest(requestId: number): Observable<{ request: any }> {
    return this.get(`${this.endpoint}/requests/${requestId}`);
  }

  createRequest(payload: {
    property_id: number;
    tenancy_id: number;
    title: string;
    description: string;
    category?: string | null;
    priority: string;
    attachments?: File[];
  }): Observable<any> {
    return this.post(`${this.endpoint}/requests`, this.requestFormData(payload));
  }

  addComment(
    requestId: number,
    payload: {
      body: string;
      attachments?: File[];
    }
  ): Observable<any> {
    return this.post(
      `${this.endpoint}/requests/${requestId}/comment`,
      this.commentFormData(payload)
    );
  }

  approveRequest(requestId: number, payload: { reason: string; message?: string | null }): Observable<any> {
    return this.post(`${this.endpoint}/requests/${requestId}/approve`, payload);
  }

  rejectRequest(requestId: number, payload: { reason: string; message?: string | null }): Observable<any> {
    return this.post(`${this.endpoint}/requests/${requestId}/reject`, payload);
  }

  updateStatus(
    requestId: number,
    payload: { status: string; reason?: string | null; message?: string | null }
  ): Observable<any> {
    return this.post(`${this.endpoint}/requests/${requestId}/status`, payload);
  }

  assignRequest(
    requestId: number,
    payload: { assigned_to: number; message?: string | null }
  ): Observable<any> {
    return this.post(`${this.endpoint}/requests/${requestId}/assign`, payload);
  }

  downloadAttachment(requestId: number, attachmentId: number): Observable<Blob> {
    return this.http.get(
      `${environment.apiUrl}${this.endpoint}/requests/${requestId}/attachments/${attachmentId}`,
      { responseType: 'blob' }
    );
  }

  private requestFormData(payload: {
    property_id: number;
    tenancy_id: number;
    title: string;
    description: string;
    category?: string | null;
    priority: string;
    attachments?: File[];
  }): FormData {
    const formData = new FormData();
    formData.append('property_id', String(payload.property_id));
    formData.append('tenancy_id', String(payload.tenancy_id));
    formData.append('title', payload.title.trim());
    formData.append('description', payload.description.trim());
    formData.append('priority', payload.priority);

    if (payload.category?.trim()) {
      formData.append('category', payload.category.trim());
    }

    for (const file of payload.attachments ?? []) {
      formData.append('attachments[]', file);
    }

    return formData;
  }

  private commentFormData(payload: {
    body: string;
    attachments?: File[];
  }): FormData {
    const formData = new FormData();
    formData.append('body', payload.body.trim());

    for (const file of payload.attachments ?? []) {
      formData.append('attachments[]', file);
    }

    return formData;
  }
}
