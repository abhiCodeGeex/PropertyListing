import { Injectable } from '@angular/core';
import { loadStripe, Stripe } from '@stripe/stripe-js';
import { environment } from '../../environments/environment';
import { Observable } from 'rxjs';
import { BaseApiService } from './base-api.service';

@Injectable({ providedIn: 'root' })
export class StripeService extends BaseApiService {
  private stripePromise = loadStripe(environment.stripeKey);

  async getStripe(): Promise<Stripe> {
    const stripe = await this.stripePromise;
    if (!stripe) {
      throw new Error('Stripe failed to initialize.');
    }
    return stripe;
  }

  getSavedCard(tenancyId: number): Observable<any> {
    return this.get(`/rent/saved-card/${tenancyId}`, undefined, { skipLoader: true });
  }
}
