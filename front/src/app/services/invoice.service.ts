import { HttpClient } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import { BaseApiService } from './base-api.service';

@Injectable({ providedIn: 'root' })
export class InvoiceService extends BaseApiService {
  private readonly endpoint = '/invoices';

  constructor(http: HttpClient) {
    super(http);
  }

  getInvoices(params?: {
    page?: number;
    per_page?: number;
    type?: string;
    property_id?: number;
  }): Observable<any> {
    return this.get(this.endpoint, params);
  }

  resendInvoice(invoiceId: number): Observable<{ message: string }> {
    return this.post(`${this.endpoint}/${invoiceId}/resend`, {});
  }

  downloadInvoice(invoiceId: number): Observable<Blob> {
    return this.http.get(`${environment.apiUrl}${this.endpoint}/${invoiceId}/download`, {
      responseType: 'blob',
    });
  }
}
