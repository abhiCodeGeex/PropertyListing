import {
  AfterViewChecked,
  ChangeDetectorRef,
  Component,
  ElementRef,
  EventEmitter,
  Input,
  OnInit,
  Output,
  ViewChild,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { StripeService } from '../../services/stripe.service';
import { RentService } from '../../services/rent.service';
import { formatAppCurrency } from '../../shared/utils/currency.util';

@Component({
  selector: 'app-rent-subscription',
  templateUrl: './rent-subscription.component.html',
  styleUrls: ['./rent-subscription.component.scss'],
  standalone: true,
  imports: [CommonModule, FormsModule]
})
export class RentSubscriptionComponent implements OnInit, AfterViewChecked {
  protected readonly formatCurrency = formatAppCurrency;
  @Input() tenancyId!: number;
  @Input() rent!: number;
  @Input() baseRent!: number;
  @Input() lateFeeAmount = 0;
  @Input() mode: 'pay_and_subscribe' | 'autopay_only' = 'pay_and_subscribe';
  @Input() latePaymentPenaltyPolicy: string | null = null;

  @Output() closed = new EventEmitter<void>();
  @Output() paymentSuccess = new EventEmitter<any>();

  @ViewChild('cardElementRef') cardElementRef!: ElementRef;

  stripe: any;
  elements: any;
  cardElement: any;

  loading = false;
  message: string | null = null;
  processingLabel = 'Preparing secure payment...';

  savedCard: any = null;
  useSavedCard = true;
  saveNewCard = true;

  private pendingStripeMount = false;

  constructor(
    private stripeService: StripeService,
    private rentService: RentService,
    private cdr: ChangeDetectorRef
  ) { }

  ngOnInit() {
    this.loadSavedCard();
  }

  ngAfterViewChecked() {
    // Mount Stripe once the card element div is rendered in the DOM
    if (this.pendingStripeMount && this.cardElementRef?.nativeElement && this.cardElement) {
      this.pendingStripeMount = false;
      this.cardElement.mount(this.cardElementRef.nativeElement);
    }
  }

  async loadSavedCard() {
    this.stripeService.getSavedCard(this.tenancyId).subscribe({
      next: (res) => {
        this.savedCard = res.card;
        this.useSavedCard = !!this.savedCard;

        if (!this.useSavedCard) {
          this.initStripe();
        }
      },
      error: () => {
        this.savedCard = null;
        this.useSavedCard = false;
        this.initStripe();
      },
    });
  }

  async initStripe() {
    if (this.cardElement) return;

    this.stripe = await this.stripeService.getStripe();
    this.elements = this.stripe.elements();

    this.cardElement = this.elements.create('card', { hidePostalCode: true });

    // If the DOM element is already available, mount immediately;
    // otherwise set a flag so ngAfterViewChecked mounts it once rendered.
    if (this.cardElementRef?.nativeElement) {
      this.cardElement.mount(this.cardElementRef.nativeElement);
    } else {
      this.pendingStripeMount = true;
      this.cdr.detectChanges();
    }
  }

  onCardChoiceChange() {
    if (this.useSavedCard && this.cardElement) {
      this.cardElement.destroy();
      this.cardElement = null;
      this.pendingStripeMount = false;
    } else if (!this.useSavedCard) {
      this.initStripe();
    }
  }

  close() {
    if (this.cardElement) {
      this.cardElement.destroy();
      this.cardElement = null;
    }
    this.closed.emit();
  }

  subscribe() {
    if (this.loading) return;
    this.loading = true;
    this.message = null;
    this.processingLabel = this.mode === 'autopay_only'
      ? 'Preparing your automatic payment setup...'
      : this.useSavedCard && this.savedCard
        ? 'Preparing your saved card payment...'
        : 'Securing your card details...';

    if (this.useSavedCard && this.savedCard) {
      this.processSubscriptionAction(this.savedCard.id, true);
    } else {
      this.createPaymentMethod();
    }
  }

  private createPaymentMethod() {
    this.processingLabel = 'Creating payment method...';
    const email = this.rentService.getStoredUserEmail();
    this.stripe.createPaymentMethod({
      type: 'card',
      card: this.cardElement,
      billing_details: { email }
    })
      .then(({ paymentMethod, error }: any) => {
        if (error) throw error;
        return this.processSubscriptionAction(paymentMethod.id, false);
      })
      .catch((err: any) => this.fail(err));
  }

  private processSubscriptionAction(paymentMethodId: string, isSavedCard: boolean) {
    if (this.mode === 'autopay_only') {
      this.activateAutoPay(paymentMethodId, isSavedCard);
      return;
    }

    this.processPayment(paymentMethodId, isSavedCard);
  }

  private activateAutoPay(paymentMethodId: string, isSavedCard: boolean) {
    this.processingLabel = isSavedCard
      ? 'Activating auto-pay with your saved card...'
      : 'Saving your card for future automatic payments...';

    this.rentService.activateAutoPay({
      tenancy_id: this.tenancyId,
      payment_method: paymentMethodId
    }).subscribe({
      next: (response: any) => this.successHandler(
        response,
        'Automatic rent payment activated!',
        'Auto-pay setup completed successfully.'
      ),
      error: err => this.fail(err, 'Automatic rent payment could not be activated.')
    });
  }

  private processPayment(paymentMethodId: string, isSavedCard: boolean) {
    this.processingLabel = isSavedCard
      ? 'Starting payment with your saved card...'
      : 'Starting secure payment...';

    this.rentService.subscribeRent({
      tenancy_id: this.tenancyId,
      payment_method: paymentMethodId,
      rent_amount: this.rent,
      save_card: !isSavedCard && this.saveNewCard
    }).subscribe({
      next: async (res: any) => {
        try {
          let paymentIntentSucceeded = false;
          let paymentIntentId: string | null = null;

          if (res.client_secret) {
            this.processingLabel = 'Confirming card payment...';
            const result = await this.stripe.confirmCardPayment(res.client_secret, {
              payment_method: paymentMethodId
            });

            if (result.error) throw result.error;
            paymentIntentSucceeded = result.paymentIntent?.status === 'succeeded';
            paymentIntentId = result.paymentIntent?.id ?? null;
          } else if (res.status === 'succeeded') {
            paymentIntentSucceeded = true;
            paymentIntentId = res.payment_intent_id ?? null;
          }

          if (!paymentIntentSucceeded) throw new Error('Payment not completed');
          if (!paymentIntentId) throw new Error('Payment reference missing');

          // Call create subscription endpoint
          this.processingLabel = 'Activating your rent subscription...';
          this.rentService.createSubscriptionAfterPayment({
            tenancy_id: this.tenancyId,
            payment_method: paymentMethodId,
            payment_intent_id: paymentIntentId
          }).subscribe({
            next: (subscriptionResponse) => this.successHandler(subscriptionResponse),
            error: err => this.fail(err, 'Rent was paid, but subscription activation failed.')
          });

        } catch (err) {
          this.fail(err, 'Rent payment could not be completed.');
        }
      },
      error: err => this.fail(err, 'Unable to initiate rent payment.')
    });
  }


  private successHandler(
    subscriptionResponse?: any,
    successMessage = 'Rent paid and subscription activated!',
    processingLabel = 'Payment completed successfully.'
  ) {
    this.message = successMessage;
    this.processingLabel = processingLabel;
    this.paymentSuccess.emit(subscriptionResponse ?? { subscription_active: 1 });
    setTimeout(() => this.close(), 1200);
    this.loading = false;
  }

  private fail(err: any, fallback = 'Payment failed') {
    this.loading = false;
    this.message = this.rentService.extractPaymentError(err, fallback);
  }
}
