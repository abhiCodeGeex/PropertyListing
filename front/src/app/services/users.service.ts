// src/app/core/services/users.service.ts
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { BaseApiService } from './base-api.service';

@Injectable({ providedIn: 'root' })
export class UsersService extends BaseApiService {

  private readonly usersEndpoint = '/users';

  /* ---------- COMMON ---------- */

  getRoles(): Observable<any> {
    return this.get('/roles');
  }

  register(userData: any): Observable<any> {
    return this.post('/signup', userData);
  }

  /* ---------- USERS ---------- */

  getUsers(
    page: number = 1,
    perPage: number = 10,
    search: string = '',
    roles: string[] = []
  ): Observable<any> {
    return this.get(this.usersEndpoint, {
      page,
      per_page: perPage,
      search,
      roles
    });
  }

  createUser(userData: any): Observable<any> {
    return this.post(this.usersEndpoint, userData);
  }

  updateUser(id: number, userData: any): Observable<any> {
    return this.put(`${this.usersEndpoint}/${id}`, userData);
  }

  deleteUser(id: number): Observable<void> {
    return this.delete(`${this.usersEndpoint}/${id}`);
  }

  assignRole(userId: string, role: string): Observable<any> {
    return this.post(
      `${this.usersEndpoint}/${userId}/assign-role`,
      { role }
    );
  }

  /* ---------- PROFILE ---------- */

  getProfile(): Observable<any> {
    return this.get('/profile');
  }

  updateProfile(userData: FormData | any): Observable<any> {
    return this.post('/profile', userData);
  }

  createStripeConnectOnboardingLink(): Observable<any> {
    return this.post('/profile/payment-settings/stripe-connect/link');
  }

  refreshStripeConnectStatus(): Observable<any> {
    return this.post('/profile/payment-settings/stripe-connect/refresh');
  }

  /* ---------- NOTIFICATIONS ---------- */

  getNotifications(page: number = 1, perPage: number = 10): Observable<any> {
    return this.get('/notifications', {
      page,
      per_page: perPage
    });
  }

  markNotificationRead(id: number): Observable<any> {
    return this.post(`/notifications/${id}/read`);
  }

  /* ---------- OTP / AADHAAR ---------- */

  generateAadhaarOtp(data: {
    aadhaar_number: string;
  }): Observable<{ message: string; data?: { reference_id?: string } }> {
    return this.post('/aadhaar/generate-otp', data);
  }

  verifyOtp(data: {
    reference_id: string;
    otp: string;
  }): Observable<{ message: string }> {
    return this.post('/aadhaar/verify-otp', data);
  }

  sendEmailOtp(data: {
    email: string;
  }): Observable<{ message: string }> {
    return this.post('/send-email-otp', data);
  }

  verifyEmailOtp(data: {
    email: string;
    otp: string;
  }): Observable<{ message: string }> {
    return this.post('/verify-email-otp', data);
  }

  /* ---------- PROPERTY / SECURITY ---------- */

  assignedProperties(userId: string): Observable<any> {
    return this.post(
      `${this.usersEndpoint}/assigned-properties`,
      { userId }
    );
  }

  dashboardSummary(): Observable<any> {
    return this.get(`${this.usersEndpoint}/owner/dashboard-summary`);
  }

  securityApprovals(userId: string): Observable<any> {
    return this.post(
      `${this.usersEndpoint}/owner/security-deposit-approvals`,
      { userId }
    );
  }

  updateManualSecurityDeposit(data: {
    tenancy_id: number;
    status: string;
  }): Observable<any> {
    return this.post(
      `${this.usersEndpoint}/owner/security-deposit-approve`,
      data
    );
  }

  getPropertyManagers(): Observable<any[]> {
    return this.get(
      `${this.usersEndpoint}/property/managers`,
      { role: 'property_manager' }
    );
  }

  manualRentApprovals(userId: string): Observable<any> {
    return this.post(
      `${this.usersEndpoint}/owner/rent-approvals`,
      { userId }
    );
  }

  updateManualRent(data: {
    tenancy_id: number;
    status: string;
    rent_schedule_id: number;
  }): Observable<any> {
    return this.post(
      `${this.usersEndpoint}/owner/rent-approve`,
      data
    );
  }

}
