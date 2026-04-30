// src/app/core/services/rent.service.ts
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { extractApiErrorMessage } from '../shared/utils/api-error.util';
import { BaseApiService } from './base-api.service';

@Injectable({ providedIn: 'root' })
export class RentService extends BaseApiService {

    private readonly securityDepositEndpoint = '/security-deposit';
    private readonly rentEndpoint = '/rent';
    private readonly tenancyEndpoint = '/tenancy';

    /* ---------- SECURITY DEPOSIT ---------- */

    createSecurityDepositPaymentIntent(
        payload: { tenancy_id: number }
    ): Observable<any> {
        return this.post(
            `${this.securityDepositEndpoint}/stripe`,
            payload,
            { skipLoader: true }
        );
    }

    requestManualSecurityDeposit(
        payload: { tenancy_id: number }
    ): Observable<any> {
        return this.post(
            `${this.securityDepositEndpoint}/manual`,
            payload
        );
    }

    /* ---------- RENT SUBSCRIPTION ---------- */

    subscribeRent(payload: {
        tenancy_id: number;
        payment_method: string;
        rent_amount: number;
        save_card?: boolean; // added optional save_card
    }): Observable<{
        client_secret: string;
        success: boolean;
        message?: string;
    }> {
        return this.post(`${this.rentEndpoint}/subscribe`, payload, { skipLoader: true });
    }

    createSubscriptionAfterPayment(payload: {
        tenancy_id: number;
        payment_method: string;
        payment_intent_id: string;
    }): Observable<any> {
        return this.post(
            `${this.rentEndpoint}/create-subscription-after-payment`,
            payload,
            { skipLoader: true }
        );
    }

    activateAutoPay(payload: {
        tenancy_id: number;
        payment_method: string;
    }): Observable<any> {
        return this.post(
            `${this.rentEndpoint}/activate-autopay`,
            payload,
            { skipLoader: true }
        );
    }

    /* ---------- RENT HISTORY ---------- */

    getHistory(params: {
        tenancy_id?: number;
        page?: number;
        from_date?: string;
        to_date?: string;
        status?: string;
    }): Observable<any> {
        return this.get(
            `${this.rentEndpoint}-history`,
            params
        );
    }

    /* ---------- OVERDUE RENTS ---------- */

    getOverdueRents(
        tenancyId: number
    ): Observable<any> {
        return this.get(
            `${this.rentEndpoint}s/overdue/${tenancyId}`
        );
    }

    payOverdues(payload: {
        tenancy_id: number;
    }): Observable<any> {
        return this.post(
            `${this.rentEndpoint}s/pay-overdue`,
            payload
        );
    }

    /* ---------- CANCEL SUBSCRIPTION ---------- */

    cancelSubscription(
        tenancyId: number
    ): Observable<any> {
        return this.post(
            `${this.tenancyEndpoint}/${tenancyId}/cancel-subscription`,
            {}
        );
    }

    requestManualRent(payload: {
        tenancy_id: number;
    }): Observable<any> {
        return this.post(
            `${this.rentEndpoint}/rent-approval-request`,
            payload
        );
    }

    getRevenue(payload: {
        from_date?: string;
        to_date?: string;
        page?: number;
        per_page?: number;
        search?: string;
    }) {
        return this.get(`${this.rentEndpoint}/reports/revenue`, payload);
    }

    extractPaymentError(error: unknown, fallback = 'Payment failed'): string {
        return extractApiErrorMessage(error, fallback);
    }

    getStoredUserEmail(): string | undefined {
        try {
            const stored = localStorage.getItem('user');
            const parsed = stored ? JSON.parse(stored) : null;

            return parsed?.email || parsed?.user?.email;
        } catch {
            return undefined;
        }
    }

}
