import { Component, OnInit, OnDestroy } from '@angular/core';
import { AbstractControl, FormBuilder, FormGroup, FormsModule, ReactiveFormsModule, ValidationErrors, ValidatorFn, Validators } from '@angular/forms';
import { debounceTime, distinctUntilChanged, Subject, Subscription } from 'rxjs';
import { ToasterService } from '../../../services/toaster.service';
import { PropertyService } from '../../../services/property.service';
import { CommonModule } from '@angular/common';
import { ButtonCloseDirective, ButtonDirective, ModalBodyComponent, ModalComponent, ModalHeaderComponent, ModalTitleDirective, PageItemDirective, PageLinkDirective, PaginationComponent, TableModule } from '@coreui/angular';
import { NgSelectModule } from '@ng-select/ng-select';
import { AuthService } from '../../../services/auth.service';
import { ActivatedRoute } from '@angular/router';
import { IconModule } from '@coreui/icons-angular';
import { FormErrorService } from '../../../services/form-error.service';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatNativeDateModule } from '@angular/material/core';
import { ConfirmModalComponent } from '../../common/confirm-modal/confirm-modal.component';
import { RentService } from '../../../services/rent.service';
import { formatAppCurrency } from '../../../shared/utils/currency.util';

@Component({
  selector: 'app-rent-deed',
  templateUrl: './rent-deed.component.html',
  styleUrls: ['./rent-deed.component.scss'],
  standalone: true,
  imports: [
    CommonModule, FormsModule, ReactiveFormsModule, TableModule,
    ModalComponent, ModalHeaderComponent, ModalTitleDirective, ModalBodyComponent,
    ButtonDirective, ButtonCloseDirective,
    PaginationComponent, PageItemDirective, PageLinkDirective,
    NgSelectModule, IconModule,
    MatDatepickerModule, MatFormFieldModule, MatInputModule, MatNativeDateModule,
    ConfirmModalComponent
  ]
})
export class RentDeedComponent implements OnInit, OnDestroy {
  protected readonly formatCurrency = formatAppCurrency;
  private readonly maxRentDeedFileSizeBytes = 5 * 1024 * 1024;
  rentDeeds: any[] = [];
  owners: any[] = [];
  tenants: any[] = [];
  properties: any[] = [];
  selectedProperty: any = null;
  filteredProperty: any = null;
  selectedDeed: any = null;
  highlightedDeed: any = null;
  currentUser: any = null;
  roles: string[] = [];
  userId: number | null = null;

  page = 1;
  perPage = 10;
  totalPages = 1;
  pages: number[] = [];
  searchTerm = '';

  form!: FormGroup;
  isEditMode = false;
  deedModalVisible = false;
  confirmModalVisible = false;
  deedToDelete: any = null;
  subscriptionConfirmVisible = false;
  deedForSubscriptionCancel: any = null;
  propertyId: number | null = null;

  maintenanceOptions = ['Owner', 'Tenant', 'Shared'];
  private readonly backendToFormFieldMap: Record<string, string> = {
    agreement_number: 'estamp',
    agreement_date: 'agreementDate',
    property_id: 'propertyId',
    'property_id.id': 'propertyId',
    propertyId: 'propertyId',
    'propertyId.id': 'propertyId',
    rent_due_date: 'rentDueDate',
    maintenance_charges: 'maintenanceCharges',
    other_details: 'otherDetails',
  };

  private searchSubject = new Subject<string>();
  private subscription = new Subscription();

  constructor(private fb: FormBuilder, private deedService: PropertyService, private toast: ToasterService, private authService: AuthService, private route: ActivatedRoute, private formErrorService: FormErrorService, private rentService: RentService) { }

  ngOnInit(): void {
    this.initForm();

    // Read query param
    this.route.queryParams.subscribe(params => {
      this.propertyId = params['propertyId'] ? +params['propertyId'] : null;
      this.filteredProperty = null;
      this.highlightedDeed = null;
      this.page = 1;
      this.syncFilteredPropertyContext();
      this.loadRentDeeds();
    });

    const sub = this.searchSubject.pipe(debounceTime(400), distinctUntilChanged())
      .subscribe(() => this.loadRentDeeds());
    this.subscription.add(sub);

    this.roles = JSON.parse(localStorage.getItem('roles') || '[]');
    this.currentUser = JSON.parse(localStorage.getItem('user') || '');
    this.userId = this.currentUser?.user?.id || null;

    this.loadOwners();
    this.loadTenants();
    this.loadProperties();
  }

  ngOnDestroy(): void { this.subscription.unsubscribe(); }

  initForm() {
    this.form = this.fb.group({
      estamp: ['', [this.requiredTrimmed(), Validators.maxLength(255)]],
      agreementDate: [null, Validators.required],
      propertyId: ['', Validators.required],
      rentDueDate: [
        null,
        [Validators.required, Validators.min(1), Validators.max(20)]
      ],
      maintenanceCharges: ['', Validators.required],
      otherDetails: [''],
      file: [null]
    });
  }

  private requiredTrimmed(): ValidatorFn {
    return (control: AbstractControl): ValidationErrors | null => {
      const value = control.value;
      if (value === null || value === undefined) {
        return { required: true };
      }

      return String(value).trim().length > 0 ? null : { required: true };
    };
  }

  hasAtionRole(type: any) {
    switch (type) {
      case 'create_rent_deed':
        return this.authService.hasAnyRole('owner', 'property_manager');
      case 'manage_rent_deed':
        return this.authService.hasAnyRole('owner', 'super-admin', 'property_manager');
      default:
        return false;
    }
  }

  loadRentDeeds() {
    const payload: any = {
      page: this.page,
      search: this.searchTerm
    };

    // Include property filter if query param exists
    if (this.propertyId) {
      payload.propertyId = this.propertyId;
    }

    this.deedService.getRentDeeds(payload.page, payload.search, payload.propertyId).subscribe({
      next: res => {
        this.rentDeeds = res.data.map((d: any) => ({
          ...d,
          agreement_date: d.agreement_date ? new Date(d.agreement_date) : null,
          due_date: d.due_date ? new Date(d.due_date).toISOString().split('T')[0] : null,
        }));
        this.highlightedDeed = this.propertyId
          ? this.rentDeeds.find((deed: any) => Number(deed.property_id) === this.propertyId) ?? null
          : null;
        this.syncFilteredPropertyContext();
        this.totalPages = res.last_page;
        this.pages = Array.from({ length: this.totalPages }, (_, i) => i + 1);
      },
      error: (err) => this.toast.showError(this.toast.extractErrorMessage(err, 'Failed to load rent deeds'))
    });
  }


  loadOwners() { this.deedService.getOwners().subscribe(res => this.owners = res); }
  loadTenants() { this.deedService.getTenants().subscribe(res => this.tenants = res); }
  loadProperties() {
    this.deedService.getProperties({}).subscribe((res: any) => {
      this.properties = res.data.map((item: any) => ({ id: item.id, propertyName: item.property_name, ...item }));
      this.syncFilteredPropertyContext();
    });
  }

  loadPropertyDetails(property: any) {
    this.selectedProperty = property;
    this.form.patchValue({
      rentDueDate: property.rent_due_date,
      maintenanceCharges: property.maintenance_charges ?? ''
    });
  }

  openCreateModal() {
    this.isEditMode = false;
    this.selectedDeed = null;
    this.selectedProperty = null;
    this.formErrorService.clearServerErrors(this.form);
    this.form.reset({
      estamp: '',
      agreementDate: null,
      propertyId: '',
      rentDueDate: null,
      maintenanceCharges: '',
      otherDetails: '',
      file: null
    });
    this.selectedProperty = null;
    this.form.markAsPristine();
    this.form.markAsUntouched();
    this.deedModalVisible = true;
  }

  openEditModal(deed: any) {
    this.isEditMode = true;
    this.selectedDeed = deed;
    this.formErrorService.clearServerErrors(this.form);
    const selectedProperty = this.properties.find(p => p.id === deed.property_id) || null;


    this.selectedProperty = selectedProperty;
    this.form.patchValue({
      estamp: deed.agreement_number,
      agreementDate: deed.agreement_date ? new Date(deed.agreement_date) : '',
      propertyId: selectedProperty,
      rentDueDate: deed.rent_due_date || selectedProperty?.rent_due_date || '',
      maintenanceCharges: deed.maintenance_charges || selectedProperty?.maintenance_charges || '',
      otherDetails: deed.other_details || '',
      file: null
    });

    this.form.markAsPristine();
    this.form.markAsUntouched();
    this.deedModalVisible = true;
  }

  handleModalChange(v: boolean) { this.deedModalVisible = v; }
  toggleModal() { this.deedModalVisible = !this.deedModalVisible; }

  markAllTouched() {
    Object.values(this.form.controls).forEach(control => {
      control.markAsTouched({ onlySelf: true });
      control.markAsDirty({ onlySelf: true });
      control.updateValueAndValidity({ onlySelf: true });
    });
  }

  isInvalid(name: string): boolean {
    const c = this.form.get(name);
    return !!(c && c.invalid && (c.touched || c.dirty));
  }

  submit() {
    this.formErrorService.clearServerErrors(this.form);

    if (this.form.invalid) {
      this.markAllTouched();
      this.scrollToFirstError();
      this.toast.showError('Please correct the highlighted fields.');
      return;
    }
    const v = this.normalizePayload(this.form.getRawValue());
    
    const formData = new FormData();
    formData.append('agreementNumber', v.estamp);
    formData.append('agreementDate', this.formatDate(v.agreementDate) || '');
    formData.append('property_id', v.propertyId?.id || v.propertyId);
    formData.append('rentDueDate', String(v.rentDueDate));
    formData.append('maintenanceCharges', v.maintenanceCharges);
    if (v.otherDetails) formData.append('otherDetails', v.otherDetails);
    if (v.file) formData.append('rent_deed_file', v.file);

    const req = this.isEditMode
      ? this.deedService.updateRentDeed(this.selectedDeed.id, formData)
      : this.deedService.createRentDeed(formData);


    req.subscribe({
      next: () => {
        this.deedModalVisible = false;
        this.loadRentDeeds();
      },
      error: (err) => {
        if (!this.formErrorService.applyServerErrors(this.form, err?.error?.errors, this.backendToFormFieldMap)) {
          this.toast.showError(this.toast.extractErrorMessage(err, 'Operation failed'));
          return;
        }

        this.scrollToFirstError();
        this.toast.showError('Please correct the highlighted fields.');
      }
    });

  }

  prettyFieldName(field: string): string {
    const map: any = {
      estamp: 'E-Stamp Number',
      agreementDate: 'Agreement Date',
      propertyId: 'Property',
      size: 'Size',
      usage: 'Usage',
      maintenanceCharges: 'Maintenance',
      rentDueDate: 'Rent Due Date',
      otherDetails: 'Other Details',
      file: 'Rent Deed File'
    };

    return map[field] || field;
  }

  hasError(name: string): boolean {
    const c = this.form.get(name);
    return !!(c && c.invalid && (c.touched || c.dirty));
  }

  propertyFields = [
    { name: 'rentDueDate', label: 'Rent Due Date', type: 'number' },
    { name: 'maintenanceCharges', label: 'Maintenance', type: 'select', options: this.maintenanceOptions },
  ];

  getError(name: string): string | null {
    const c = this.form.get(name);
    if (!c || !c.errors || !(c.touched || c.dirty)) return null;

    if (c.errors['server']) return c.errors['server'];
    if (c.errors['required']) return `${this.prettyFieldName(name)} is required.`;
    if (c.errors['minlength']) return `${this.prettyFieldName(name)} must be at least ${c.errors['minlength'].requiredLength} characters.`;
    if (c.errors['maxlength']) return `${this.prettyFieldName(name)} must be at most ${c.errors['maxlength'].requiredLength} characters.`;
    if (c.errors['min']) return `${this.prettyFieldName(name)} must be ${c.errors['min'].min} or more.`;
    if (c.errors['max']) return `${this.prettyFieldName(name)} must be ${c.errors['max'].max} or less.`;

    return null;
  }

  confirmDelete(deed: any) { this.deedToDelete = deed; this.confirmModalVisible = true; }
  deleteDeed() {
    if (!this.deedToDelete?.id) return;
    this.deedService.deleteRentDeed(this.deedToDelete.id).subscribe({
      next: () => { this.toast.showSuccess('Deleted successfully'); this.confirmModalVisible = false; this.loadRentDeeds(); },
      error: (err) => this.toast.showError(this.toast.extractErrorMessage(err, 'Failed to delete'))
    });
  }

  scrollToFirstError() {
    setTimeout(() => {
      const el = document.querySelector('.is-invalid');
      el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  }

  pageChanged(p: number) { this.page = p; this.loadRentDeeds(); }
  onSearchChange(q: string) { this.searchSubject.next(q); }

  canManageSubscription(): boolean {
    return this.authService.hasAnyRole('owner', 'super-admin', 'property_manager', 'tenant');
  }

  openSubscriptionCancelModal(deed: any): void {
    this.deedForSubscriptionCancel = deed;
    this.subscriptionConfirmVisible = true;
  }

  confirmSubscriptionCancel(): void {
    if (!this.deedForSubscriptionCancel?.tenancy_id) return;

    this.rentService.cancelSubscription(this.deedForSubscriptionCancel.tenancy_id).subscribe({
      next: (response: any) => {
        this.toast.showSuccess(response?.message || 'Subscription cancellation scheduled');
        this.subscriptionConfirmVisible = false;
        this.deedForSubscriptionCancel = null;
        this.loadRentDeeds();
      },
      error: (err) => {
        this.toast.showError(this.toast.extractErrorMessage(err, 'Failed to schedule subscription cancellation'));
        this.subscriptionConfirmVisible = false;
        this.deedForSubscriptionCancel = null;
      }
    });
  }

  subscriptionStatusLabel(deed: any): string {
    if (deed?.subscription_active === 3) {
      return deed?.subscription_cancel_at
        ? `Ends ${new Date(deed.subscription_cancel_at).toLocaleDateString()}`
        : 'Scheduled for month end';
    }

    if (deed?.subscription_active === 2) return 'Cancelled';
    if (deed?.subscription_active === 1) return 'Active';
    return 'Not Active';
  }

  subscriptionStatusClass(deed: any): string {
    if (deed?.subscription_active === 3) return 'soft-pill soft-pill--warning';
    if (deed?.subscription_active === 2) return 'soft-pill soft-pill--neutral';
    if (deed?.subscription_active === 1) return 'soft-pill soft-pill--success';
    return 'soft-pill soft-pill--neutral';
  }

  handleFileInput(event: any) {
    const file = event.target.files[0];
    const input = event.target as HTMLInputElement;

    if (!file) {
      this.form.patchValue({ file: null });
      return;
    }

    if (file.size > this.maxRentDeedFileSizeBytes) {
      this.form.patchValue({ file: null });
      if (input) input.value = '';
      this.toast.showError('Rent deed files must be 5MB or smaller.');
      return;
    }

    this.form.patchValue({ file: file });
    this.form.get('file')?.markAsDirty();
  }

  viewFile(url: string) {
    if (url) {
      window.open(url, '_blank');
    }
  }

  get viewingPropertyDetail(): boolean {
    return this.propertyId !== null;
  }

  get detailProperty(): any | null {
    return this.highlightedDeed?.property ?? this.filteredProperty ?? null;
  }

  get detailTenants(): any[] {
    if (Array.isArray(this.detailProperty?.tenants) && this.detailProperty.tenants.length) {
      return this.detailProperty.tenants;
    }

    return this.highlightedDeed?.tenant ? [this.highlightedDeed.tenant] : [];
  }

  get detailTenantNames(): string {
    return this.detailTenants.length
      ? this.detailTenants.map((tenant: any) => tenant.name).join(', ')
      : 'Vacant';
  }

  private syncFilteredPropertyContext(): void {
    if (!this.propertyId) {
      return;
    }

    this.filteredProperty = this.properties.find((property: any) => Number(property.id) === this.propertyId)
      ?? this.highlightedDeed?.property
      ?? null;
  }

  private normalizePayload(rawValue: any) {
    return {
      ...rawValue,
      estamp: rawValue.estamp?.trim() ?? '',
      otherDetails: rawValue.otherDetails?.trim() || null,
      rentDueDate: rawValue.rentDueDate === '' || rawValue.rentDueDate === null ? null : Number(rawValue.rentDueDate),
    };
  }

  private formatDate(value: Date | string | null | undefined): string | null {
    if (!value) return null;
    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) return null;

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
  }
}
