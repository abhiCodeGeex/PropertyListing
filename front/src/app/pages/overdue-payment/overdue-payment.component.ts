import {
  Component,
  EventEmitter,
  Input,
  Output,
  OnChanges,
  SimpleChanges,
  OnDestroy,
  ViewChild,
  ElementRef,
  ChangeDetectorRef,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { RentService } from '../../services/rent.service';
import { StripeService } from '../../services/stripe.service';
import { firstValueFrom } from 'rxjs';
import { formatAppCurrency } from '../../shared/utils/currency.util';

@Component({
  selector: 'app-overdue-payment',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './overdue-payment.component.html',
  styleUrls: ['./overdue-payment.component.scss'],
})
export class OverduePaymentComponent implements OnChanges, OnDestroy {
  protected readonly formatCurrency = formatAppCurrency;

  @Input() tenancyId!: number;
  @Input() visible = false;
  @Input() hasSubscription = 0;
  @Input() latePaymentPenaltyPolicy: string | null = null;

  @Output() closed = new EventEmitter<void>();
  @Output() paid = new EventEmitter<any>();

  @ViewChild('cardElementRef') cardElementRef!: ElementRef;

  overdues: any[] = [];
  totalOverdue = 0;
  baseTotal = 0;
  lateFeeTotal = 0;
  loading = false;
  paying = false;
  message: string | null = null;
  messageType: 'success' | 'danger' | null = null;
  cardComplete = false;

  private stripe: any;
  private elements: any;
  private cardElement: any;
  private stripeReady = false;

  constructor(
    private rentService: RentService,
    private stripeService: StripeService,
    private cdr: ChangeDetectorRef
  ) { }

  /* ---------------- MODAL VISIBILITY ---------------- */

  async ngOnChanges(changes: SimpleChanges) {
    if (changes['visible']?.currentValue === true) {
      this.loadOverdues();

      // Force change detection so @if(visible) renders the card element in DOM,
      // then wait for the next animation frame before mounting Stripe.
      this.cdr.detectChanges();
      setTimeout(() => this.initStripe(), 50);
    }

    if (changes['visible']?.currentValue === false) {
      this.destroyStripe();
    }
  }

  /* ---------------- STRIPE INIT ---------------- */

  private async initStripe() {
    if (this.stripeReady || !this.cardElementRef?.nativeElement) return;

    this.stripe = await this.stripeService.getStripe();
    this.elements = this.stripe.elements();

    this.cardElement = this.elements.create('card', {
      hidePostalCode: true,
    });

    this.cardElement.mount(this.cardElementRef.nativeElement);

    this.cardElement.on('change', (event: any) => {
      this.cardComplete = event.complete;

      if (event.error) {
        this.message = event.error.message;
        this.messageType = 'danger';
      } else {
        this.message = null;
        this.messageType = null;
      }
    });

    this.stripeReady = true;
  }

  private destroyStripe() {
    if (this.cardElement) {
      this.cardElement.unmount();
      this.cardElement.destroy();
      this.cardElement = null;
    }
    this.stripeReady = false;
  }

  /* ---------------- LOAD OVERDUES ---------------- */

  loadOverdues() {
    this.loading = true;

    this.rentService.getOverdueRents(this.tenancyId).subscribe({
      next: (res) => {
        this.overdues = res.items ?? [];
        this.baseTotal = res.base_total ?? 0;
        this.lateFeeTotal = res.late_fee_total ?? 0;
        this.totalOverdue = res.total ?? 0;
        this.latePaymentPenaltyPolicy = res.late_payment_penalty_policy ?? this.latePaymentPenaltyPolicy ?? null;
        this.loading = false;
      },
      error: (err) => {
        this.loading = false;
        this.message = this.rentService.extractPaymentError(
          err,
          'Unable to load overdue rent details.'
        );
        this.messageType = 'danger';
      },
    });
  }

  /* ---------------- PAYMENT ---------------- */

  async payAll() {
    if (!this.stripeReady || !this.cardElement || this.totalOverdue <= 0) {
      this.message = 'Payment form not ready';
      this.messageType = 'danger';
      return;
    }

    this.paying = true;
    this.message = null;
    this.messageType = null;

    try {
      const paymentMethodId = await this.createPaymentMethod();

      const response: any = await firstValueFrom(
        this.rentService.payOverdues({ tenancy_id: this.tenancyId })
      );

      const result = await this.stripe.confirmCardPayment(
        response.client_secret,
        {
          payment_method: paymentMethodId,
        }
      );

      if (result.error) {
        throw result.error;
      }

      if (result.paymentIntent?.status === 'succeeded') {
        let payload: any = null;

        if ((this.hasSubscription ?? 0) < 1) {
          try {
            payload = await firstValueFrom(this.rentService.activateAutoPay({
              tenancy_id: this.tenancyId,
              payment_method: paymentMethodId,
            }));
            this.message = 'Missed rent paid and auto-pay activated successfully';
          } catch (activationError: any) {
            payload = { flow: 'overdue_only', activation_failed: true };
            this.message = this.rentService.extractPaymentError(
              activationError,
              'Missed rent was paid, but auto-pay activation failed.'
            );
            this.messageType = 'danger';
            this.paid.emit(payload);
            return;
          }
        } else {
          this.message = 'Missed rent paid successfully';
        }

        this.messageType = 'success';
        this.paid.emit(payload);
        setTimeout(() => this.close(), 1500);
      }
    } catch (err: any) {
      this.message = this.rentService.extractPaymentError(
        err,
        'Overdue rent payment failed.'
      );
      this.messageType = 'danger';
    } finally {
      this.paying = false;
    }
  }

  private async createPaymentMethod(): Promise<string> {
    const result = await this.stripe.createPaymentMethod({
      type: 'card',
      card: this.cardElement,
      billing_details: {
        email: this.rentService.getStoredUserEmail(),
      },
    });

    if (result.error) {
      throw result.error;
    }

    if (!result.paymentMethod?.id) {
      throw new Error('Payment method could not be created.');
    }

    return result.paymentMethod.id;
  }

  /* ---------------- CLOSE ---------------- */

  close() {
    this.visible = false;
    this.message = null;
    this.messageType = null;
    this.baseTotal = 0;
    this.lateFeeTotal = 0;
    this.destroyStripe();
    this.closed.emit();
  }

  /* ---------------- CLEANUP ---------------- */

  ngOnDestroy() {
    this.destroyStripe();
  }
}
