import {
  Component,
  EventEmitter,
  Input,
  OnDestroy,
  OnInit,
  AfterViewInit,
  Output,
  ElementRef,
  ViewChild,
  ChangeDetectorRef,
} from '@angular/core';
import {
  ButtonDirective,
  ModalBodyComponent,
  ModalComponent,
  ModalFooterComponent,
  ModalHeaderComponent,
} from '@coreui/angular';
import { CommonModule } from '@angular/common';
import { StripeService } from '../../services/stripe.service';
import { RentService } from '../../services/rent.service';
import { catchError, finalize, from, switchMap, throwError } from 'rxjs';
import { formatAppCurrency } from '../../shared/utils/currency.util';

@Component({
  selector: 'app-security-deposite',
  standalone: true,
  templateUrl: './security-deposite.component.html',
  styleUrls: ['./security-deposite.component.scss'],
  imports: [
    CommonModule,
    ModalComponent,
    ModalHeaderComponent,
    ModalBodyComponent,
    ModalFooterComponent,
    ButtonDirective,
  ],
})
export class SecurityDepositeComponent implements OnInit, AfterViewInit, OnDestroy {
  protected readonly formatCurrency = formatAppCurrency;
  @Input() tenancyId!: number;
  @Input() security!: number;
  @Output() closed = new EventEmitter<void>();
  @Output() paymentSuccess = new EventEmitter<void>();
  @ViewChild('cardElementRef') cardElementRef!: ElementRef;

  isModalVisible = true;
  stripe: any;
  cardElement: any;
  stripeReady = false;
  cardComplete = false;
  loading = false;
  message: string | null = null;
  processingLabel = 'Preparing secure payment...';

  constructor(
    private stripeService: StripeService,
    private rentService: RentService,
    private cdr: ChangeDetectorRef
  ) { }

  async ngOnInit() {
    this.stripe = await this.stripeService.getStripe();
  }

  async ngAfterViewInit() {
    await this.initStripe();
  }

  private async initStripe() {
    if (this.stripeReady || !this.cardElementRef?.nativeElement) {
      return;
    }

    if (!this.stripe) {
      this.stripe = await this.stripeService.getStripe();
    }

    const elements = this.stripe.elements();
    this.cardElement = elements.create('card', { hidePostalCode: true });
    this.cardElement.mount(this.cardElementRef.nativeElement);
    this.cardElement.on('change', (event: any) => {
      this.cardComplete = event.complete;

      if (event.error) {
        this.message = event.error.message;
      } else {
        this.message = null;
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
    this.cardComplete = false;
  }

  ngOnDestroy() {
    this.destroyStripe();
  }

  onVisibleChange(visible: boolean) {
    this.isModalVisible = visible;
    if (!visible) {
      this.close();
    }
  }

  close() {
    if (this.isModalVisible) {
      this.isModalVisible = false;
      this.cdr.detectChanges();
    }
    this.destroyStripe();
    this.closed.emit();
  }

  payByStripe() {
    if (this.loading || !this.stripeReady || !this.cardElement) {
      return;
    }

    this.loading = true;
    this.message = null;
    this.processingLabel = 'Starting security deposit payment...';

    const email = this.rentService.getStoredUserEmail();

    this.rentService
      .createSecurityDepositPaymentIntent({
        tenancy_id: this.tenancyId
      })
      .pipe(
        switchMap((res: any) => {
          if (!res.client_secret) {
            throw new Error('Unable to initiate payment');
          }

          this.processingLabel = 'Confirming card payment...';
          return from(
            this.stripe.confirmCardPayment(res.client_secret, {
              payment_method: {
                card: this.cardElement,
                billing_details: { email },
              },
            })
          );
        }),
        catchError((err) => {
          this.message = this.rentService.extractPaymentError(
            err,
            'Security deposit payment failed.'
          );
          return throwError(() => err);
        }),
        finalize(() => {
          this.loading = false;
        })
      )
      .subscribe({
        next: (result: any) => {
          if (result.error) {
            this.message = this.rentService.extractPaymentError(
              result.error,
              'Security deposit payment failed.'
            );
            return;
          }

            if (result.paymentIntent?.status === 'succeeded') {
              this.processingLabel = 'Payment completed successfully.';
              this.message = 'Security deposit paid successfully!';
              this.paymentSuccess.emit();

            setTimeout(() => this.close(), 1200);
          }
        },
      });
  }
}
