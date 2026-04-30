import { CommonModule } from '@angular/common';
import { Component, OnDestroy, OnInit } from '@angular/core';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import {
  ButtonDirective,
  ButtonCloseDirective,
  ModalBodyComponent,
  ModalComponent,
  ModalHeaderComponent,
  ModalTitleDirective,
  PageItemDirective,
  PageLinkDirective,
  PaginationComponent,
  TableModule
} from '@coreui/angular';
import { IconModule } from '@coreui/icons-angular';
import { debounceTime, distinctUntilChanged, Subject, Subscription } from 'rxjs';
import { LoaderComponent } from '../common/loader/loader.component';
import { PropertyService } from '../../services/property.service';
import { ToasterService } from '../../services/toaster.service';

@Component({
  selector: 'app-commission-settings',
  standalone: true,
  templateUrl: './commission-settings.component.html',
  styleUrls: ['./commission-settings.component.scss'],
  imports: [
    CommonModule,
    FormsModule,
    ReactiveFormsModule,
    TableModule,
    LoaderComponent,
    ModalComponent,
    ModalHeaderComponent,
    ModalTitleDirective,
    ModalBodyComponent,
    ButtonDirective,
    ButtonCloseDirective,
    PaginationComponent,
    PageItemDirective,
    PageLinkDirective,
    IconModule,
  ],
})
export class CommissionSettingsComponent implements OnInit, OnDestroy {
  properties: any[] = [];
  loading = false;
  page = 1;
  perPage = 10;
  totalPages = 1;
  pages: number[] = [];
  searchTerm = '';
  modalVisible = false;
  selectedProperty: any = null;
  form: FormGroup;

  private readonly searchSubject = new Subject<string>();
  private readonly subscription = new Subscription();

  constructor(
    private readonly propertyService: PropertyService,
    private readonly toast: ToasterService,
    fb: FormBuilder
  ) {
    this.form = fb.group({
      owner_commission_percent: [100, [Validators.required, Validators.min(0), Validators.max(100)]],
      manager_commission_percent: [0, [Validators.required, Validators.min(0), Validators.max(100)]],
    });
  }

  ngOnInit(): void {
    this.subscription.add(
      this.searchSubject.pipe(debounceTime(400), distinctUntilChanged()).subscribe(() => {
        this.page = 1;
        this.loadProperties();
      })
    );

    this.loadProperties();
  }

  ngOnDestroy(): void {
    this.subscription.unsubscribe();
  }

  loadProperties(): void {
    this.loading = true;
    this.propertyService.getCommissionSettings({
      page: this.page,
      per_page: this.perPage,
      search: this.searchTerm,
    }).subscribe({
      next: (res) => {
        this.properties = res.data ?? [];
        this.page = res.current_page ?? 1;
        this.totalPages = res.last_page ?? 1;
        this.pages = Array.from({ length: this.totalPages }, (_, index) => index + 1);
        this.loading = false;
      },
      error: (err) => {
        this.loading = false;
        this.toast.showError(this.toast.extractErrorMessage(err, 'Failed to load commission settings'));
      }
    });
  }

  onSearchChange(): void {
    this.searchSubject.next(this.searchTerm);
  }

  openModal(property: any): void {
    this.selectedProperty = property;
    this.form.reset({
      owner_commission_percent: Number(property.owner_commission_percent ?? 100),
      manager_commission_percent: Number(property.manager_commission_percent ?? 0),
    });
    this.form.markAsPristine();
    this.form.markAsUntouched();
    this.modalVisible = true;
  }

  closeModal(): void {
    this.modalVisible = false;
    this.selectedProperty = null;
  }

  save(): void {
    if (!this.selectedProperty) {
      return;
    }

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.toast.showError('Please correct the highlighted fields.');
      return;
    }

    const ownerPercent = Number(this.form.value.owner_commission_percent ?? 0);
    const managerPercent = Number(this.form.value.manager_commission_percent ?? 0);

    if ((ownerPercent + managerPercent) > 100) {
      this.toast.showError('Owner and manager commission together cannot exceed 100%.');
      return;
    }

    this.propertyService.updateCommissionSettings(this.selectedProperty.id, {
      owner_commission_percent: ownerPercent,
      manager_commission_percent: managerPercent,
    }).subscribe({
      next: (res) => {
        const updated = res.property;
        this.properties = this.properties.map((property) => property.id === updated.id ? updated : property);
        this.toast.showSuccess(res.message || 'Commission settings updated successfully.');
        this.closeModal();
      },
      error: (err) => {
        this.toast.showError(this.toast.extractErrorMessage(err, 'Failed to update commission settings'));
      }
    });
  }

  pageChanged(nextPage: number): void {
    if (nextPage < 1 || nextPage > this.totalPages || nextPage === this.page) {
      return;
    }

    this.page = nextPage;
    this.loadProperties();
  }

  connectReady(user: any): boolean {
    return !!user?.stripe_connect_account_id
      && !!user?.stripe_connect_details_submitted
      && !!user?.stripe_connect_charges_enabled
      && !!user?.stripe_connect_payouts_enabled;
  }

  connectStatusLabel(user: any, role: 'owner' | 'manager'): string {
    if (!user) {
      return role === 'owner' ? 'Missing owner' : 'No manager assigned';
    }

    return this.connectReady(user) ? 'Stripe ready' : 'Stripe incomplete';
  }

  isInvalid(name: string): boolean {
    const control = this.form.get(name);
    return !!(control && control.invalid && (control.touched || control.dirty));
  }
}
