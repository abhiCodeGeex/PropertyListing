import { Component, OnInit, ViewChild, OnDestroy, HostListener } from '@angular/core';
import { AbstractControl, FormBuilder, FormGroup, ValidationErrors, ValidatorFn, Validators, FormsModule, ReactiveFormsModule } from '@angular/forms';
import { PropertyService } from '../../../services/property.service';
import { Property } from '../../../models/property.model';
import { CommonModule } from '@angular/common';
import {
  ButtonCloseDirective,
  ButtonDirective,
  DropdownModule,
  FormSelectDirective,
  ModalBodyComponent,
  ModalComponent,
  ModalHeaderComponent,
  ModalTitleDirective,
  PageItemDirective,
  PageLinkDirective,
  PaginationComponent,
  TableModule,
} from '@coreui/angular';
import { ConfirmModalComponent } from '../../common/confirm-modal/confirm-modal.component';
import { debounceTime, distinctUntilChanged, Subject, Subscription } from 'rxjs';
import { ToasterService } from '../../../services/toaster.service';
import { PropertyTenantAssignComponent } from '../../property-tenant-assign/property-tenant-assign.component';
import { PropertyManagerAssignComponent } from '../../property-manager-assign/property-manager-assign.component';
import { ActivatedRoute, Router } from '@angular/router';
import { IconModule } from '@coreui/icons-angular';
import { FormErrorService } from '../../../services/form-error.service';
import { appCurrencySymbol, formatAppCurrency } from '../../../shared/utils/currency.util';

@Component({
  selector: 'app-property',
  templateUrl: './property.component.html',
  styleUrls: ['./property.component.scss'],
  imports: [
    CommonModule,
    FormsModule,
    ReactiveFormsModule,
    TableModule,
    ModalComponent,
    ModalHeaderComponent,
    ModalTitleDirective,
    ModalBodyComponent,
    ButtonDirective,
    ButtonCloseDirective,
    ConfirmModalComponent,
    PaginationComponent,
    PageItemDirective,
    PageLinkDirective,
    PropertyTenantAssignComponent,
    FormSelectDirective,
    PropertyManagerAssignComponent,
    DropdownModule,
    IconModule
  ],
})
export class PropertyComponent implements OnInit, OnDestroy {
  protected readonly formatCurrency = formatAppCurrency;
  /** Reads live symbol after /api/public-config syncs with Stripe. */
  protected get currencySymbol(): string {
    return appCurrencySymbol();
  }
  properties: Property[] = [];
  loading = false;
  form!: FormGroup;
  isEditMode = false;
  selectedProperty: Property | null = null;
  propertyModalVisible = false;
  visibleConfirmModal = false;
  propertyToDelete: Property | null = null;
  dropdownPosition: { [key: number]: { top: number; left: number } } = {};

  searchTerm = '';
  page = 1;
  perPage = 10;
  totalPages = 1;
  pages: number[] = [];
  assignTenantModalVisible = false;
  selectedPropertyForTenant: any = null;
  filterRole: string | null = null;
  filterUserId: string | null = null;
  openDropdownId: number | null = null;

  propertyTypes = ['Residential', 'Commercial'];
  furnishingTypes = ['Unfurnished', 'Semi-Furnished', 'Fully-Furnished'];
  paymentModes = ['UPI', 'Cash', 'Credit/Debit Cards'];
  electricityPaidByOptions = ['owner', 'tenant'];
  private searchSubject = new Subject<string>();
  private subscription = new Subscription();
  @ViewChild(PropertyTenantAssignComponent) tenantAssignModal!: PropertyTenantAssignComponent;
  @ViewChild(PropertyManagerAssignComponent) managerAssignModal!: PropertyManagerAssignComponent;
  role: any;
  private readonly backendToFormFieldMap: Record<string, string> = {
    property_name: 'propertyName',
    property_type: 'propertyType',
    furnishing_type: 'furnishingType',
    monthly_rent: 'monthlyRent',
    payment_mode: 'paymentMode',
    security_amount: 'securityAmount',
    refund_terms: 'refundTerms',
    agreement_duration: 'agreementDuration',
    maintenance_responsibilities: 'maintenanceResponsibilities',
    termination_clause: 'terminationClause',
    late_payment_penalty: 'latePaymentPenalty',
    electricity_bill_paid_by: 'electricityBillPaidBy',
  };

  constructor(private fb: FormBuilder, private propertyService: PropertyService, private toast: ToasterService, private route: ActivatedRoute, private router: Router, private formErrorService: FormErrorService) { }

  ngOnInit(): void {
    this.initForm();
    this.route.queryParams.subscribe(params => {
      this.filterRole = params['role'] || null;
      this.filterUserId = params['id'] || null;

      this.page = 1;
      this.loadProperties();
    });
    const searchSub = this.searchSubject
      .pipe(debounceTime(500), distinctUntilChanged())
      .subscribe((query) => {
        this.searchTerm = query;
        this.page = 1;
        this.loadProperties();
      });

    this.subscription.add(searchSub);
    this.loadProperties();
    this.role = JSON.parse(localStorage.getItem('roles') || '[]');
  }

  ngOnDestroy(): void {
    this.subscription.unsubscribe();
  }

  initForm() {
    this.form = this.fb.group({
      propertyName: ['', [this.requiredTrimmed(), Validators.maxLength(255)]],
      propertyType: ['', Validators.required],
      furnishingType: ['Unfurnished', Validators.required],
      state: ['', [this.requiredTrimmed(), Validators.maxLength(100)]],
      city: ['', [this.requiredTrimmed(), Validators.maxLength(100)]],
      address: ['', [this.requiredTrimmed(), Validators.minLength(10)]],
      monthlyRent: [null, [Validators.required, Validators.min(0)]],
      paymentMode: ['', Validators.required],
      securityAmount: [0, [Validators.required, Validators.min(0)]],
      refundTerms: [''],
      agreementDuration: [''],
      maintenanceResponsibilities: [''],
      terminationClause: [''],
      latePaymentPenalty: [''],
      electricityBillPaidBy: ['', Validators.required],
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

  toggleDropdown(id: number, event?: MouseEvent) {
    if (this.openDropdownId === id) {
      this.openDropdownId = null;
      return;
    }

    this.openDropdownId = id;

    // Calculate dropdown coordinates
    const button = event?.target as HTMLElement;
    if (button) {
      const rect = button.getBoundingClientRect();
      this.dropdownPosition[id] = { top: rect.bottom + 4, left: rect.left };
    }
  }

  closeDropdown() {
    this.openDropdownId = null;
  }

  // Detect click outside
  @HostListener('document:click', ['$event'])
  handleClickOutside(event: Event) {
    const target = event.target as HTMLElement;

    // Check if click is inside any dropdown button
    if (!target.closest('.dropdown')) {
      this.closeDropdown();
    }
  }

  isDropdownOpen(propertyId: number) {
    return this.openDropdownId === propertyId;
  }
  loadProperties() {
    this.loading = true;
    this.propertyService.getProperties({
      page: this.page,
      search: this.searchTerm,
      filter_role: this.filterRole ?? '',
      filter_user_id: this.filterUserId ?? ''
    }).subscribe({
      next: (res) => {
        this.properties = res.data.map((p: any) => ({
          id: p.id,
          userId: p.user_id,
          propertyName: p.property_name,
          propertyType: p.property_type,
          furnishingType: p.furnishing_type || 'Unfurnished',
          state: p.state,
          city: p.city,
          address: p.address,
          monthlyRent: +p.monthly_rent,
          paymentMode: p.payment_mode,
          securityAmount: +p.security_amount,
          refundTerms: p.refund_terms,
          agreementDuration: p.agreement_duration,
          maintenanceResponsibilities: p.maintenance_responsibilities,
          terminationClause: p.termination_clause,
          latePaymentPenalty: p.late_payment_penalty,
          electricityBillPaidBy: p.electricity_bill_paid_by,
          owner: p.owner ?? null,
          manager_id: p.manager_id,
          manager: p.manager ?? null,
          tenants: Array.isArray(p.tenants) ? p.tenants : [],
          media: Array.isArray(p.media) ? p.media : [],
        }));

        this.totalPages = res.last_page;
        this.pages = Array.from({ length: this.totalPages }, (_, i) => i + 1);
        this.loading = false;
      },
      error: (err) => {
        this.loading = false;
        this.toast.showError(this.toast.extractErrorMessage(err, 'Failed to load properties'));
      },
    });
  }

  openCreateModal() {
    if (!this.canCreateProperty()) {
      return;
    }

    this.isEditMode = false;
    this.selectedProperty = null;
    this.formErrorService.clearServerErrors(this.form);
    this.selectedMediaFiles = [];
    this.existingMediaFiles = [];
    this.form.reset({
      propertyName: '',
      propertyType: '',
      furnishingType: 'Unfurnished',
      state: '',
      city: '',
      address: '',
      monthlyRent: null,
      paymentMode: '',
      securityAmount: 0,
      refundTerms: '',
      agreementDuration: '',
      maintenanceResponsibilities: '',
      terminationClause: '',
      latePaymentPenalty: '',
      electricityBillPaidBy: ''
    });
    this.form.markAsPristine();
    this.form.markAsUntouched();
    this.propertyModalVisible = true;
  }

  openEditModal(property: Property) {
    if (!this.canManageProperty(property)) {
      return;
    }

    this.isEditMode = true;
    this.selectedProperty = property;
    this.formErrorService.clearServerErrors(this.form);
    this.selectedMediaFiles = [];
    this.existingMediaFiles = Array.isArray((property as any)?.media) ? (property as any).media : [];
    this.form.patchValue(property);
    this.form.markAsPristine();
    this.form.markAsUntouched();
    this.propertyModalVisible = true;
  }

  handleModalChange(visible: boolean) {
    this.propertyModalVisible = visible;
  }

  private markAllAsTouched() {
    Object.keys(this.form.controls).forEach(key => {
      const control = this.form.get(key);
      control?.markAsTouched();
      control?.markAsDirty();
    });
  }

  isInvalid(controlName: string): boolean {
    const c = this.form.get(controlName);
    return !!(c && c.invalid && (c.touched || c.dirty));
  }

  submit() {
    this.formErrorService.clearServerErrors(this.form);

    if (this.form.invalid) {
      this.markAllAsTouched();
      this.toast.showError('Form is invalid. Please check fields.');
      return;
    }

    const payload = this.normalizePayload(this.form.getRawValue());

    if (this.isEditMode && this.selectedProperty?.id) {
      this.propertyService.updateProperty(this.selectedProperty.id, payload).subscribe({
        next: () => {
          this.loadProperties();
          this.toast.showSuccess('Property updated successfully!');
          this.propertyModalVisible = false;
        },
        error: (error) => {
          if (!this.formErrorService.applyServerErrors(this.form, error?.error?.errors, this.backendToFormFieldMap)) {
            this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to update property'));
            return;
          }

          this.toast.showError('Please correct the highlighted fields.');
        }
      });
    } else {
      this.propertyService.createProperty(payload).subscribe({
        next: () => {
          this.loadProperties();
          this.toast.showSuccess('Property created successfully!');
          this.propertyModalVisible = false;
        },
        error: (error) => {
          if (!this.formErrorService.applyServerErrors(this.form, error?.error?.errors, this.backendToFormFieldMap)) {
            this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to create property'));
            return;
          }

          this.toast.showError('Please correct the highlighted fields.');
        }
      });
    }
  }

  deleteProperty() {
    if (!this.propertyToDelete?.id) return;
    this.propertyService.deleteProperty(this.propertyToDelete.id).subscribe({
      next: () => {
        this.loadProperties();
        this.visibleConfirmModal = false;
        this.toast.showSuccess('Property deleted successfully!');
      },
      error: (err) => {
        this.toast.showError(this.toast.extractErrorMessage(err, 'Failed to delete property'));
        this.visibleConfirmModal = false;
      },
    });
  }

  confirmDelete(property: Property) {
    if (!this.canManageProperty(property)) {
      return;
    }

    this.propertyToDelete = property;
    this.visibleConfirmModal = true;
  }

  toggleModal() {
    this.propertyModalVisible = !this.propertyModalVisible;
  }

  pageChanged(p: number) {
    this.page = p;
    this.loadProperties();
  }

  onSearchChange(query: string) {
    this.searchSubject.next(query);
  }

  openAssignTenantModal(propertyId: number) {
    this.propertyService.getPropertyTenants(propertyId).subscribe({
      next: (tenants: any[]) => {
        this.tenantAssignModal.openModal(propertyId, tenants);
      },
      error: (err) => {
        this.toast.showError(this.toast.extractErrorMessage(err, 'Failed to load tenants for this property'));
      }
    });
  }

  closeAssignTenantModal() {
    this.assignTenantModalVisible = false;
    this.selectedPropertyForTenant = null;
  }

  onManagerAssigned() {
    this.loadProperties();
  }

  onTenantAssigned() {
    this.loadProperties();
  }

  canCreateProperty(): boolean {
    return this.role?.includes('owner') || this.role?.includes('super-admin');
  }

  canAssignManager(): boolean {
    return this.role?.includes('owner') || this.role?.includes('super-admin');
  }

  canManageProperty(property: Property): boolean {
    if (this.role?.includes('super-admin')) {
      return true;
    }

    if (this.role?.includes('owner')) {
      return true;
    }

    return this.role?.includes('property_manager') && property.manager_id === this.currentUserId();
  }

  private currentUserId(): number | null {
    try {
      const stored = JSON.parse(localStorage.getItem('user') || '{}');
      return stored?.user?.id ?? stored?.id ?? null;
    } catch {
      return null;
    }
  }

  viewRentDeed(propertyId: number) {
    this.router.navigate(['/rent-deeds'], { queryParams: { propertyId } });
  }

  viewPaymentHistory(propertyId: number) {
    this.router.navigate(['/rent-history'], { queryParams: { propertyId } });
  }

  getFormValidationErrors() {
    const errors: any = {};
    Object.keys(this.form.controls).forEach((key) => {
      const controlErrors = this.form.get(key)?.errors;
      if (controlErrors) {
        errors[key] = controlErrors;
      }
    });
    return errors;
  }

  getErrorMessage(controlName: string): string | null {
    const control = this.form.get(controlName);
    const errors = control?.errors;

    if (!control || !errors || !(control.touched || control.dirty)) {
      return null;
    }

    if (errors['server']) return errors['server'];
    if (errors['required']) return `${this.getFieldLabel(controlName)} is required.`;
    if (errors['minlength']) return `${this.getFieldLabel(controlName)} must be at least ${errors['minlength'].requiredLength} characters.`;
    if (errors['maxlength']) return `${this.getFieldLabel(controlName)} must be at most ${errors['maxlength'].requiredLength} characters.`;
    if (errors['min']) return `${this.getFieldLabel(controlName)} must be ${errors['min'].min} or more.`;

    return 'Invalid value.';
  }

  private getFieldLabel(controlName: string): string {
    const labels: Record<string, string> = {
      propertyName: 'Property Name',
      propertyType: 'Property Type',
      state: 'State',
      city: 'City',
      address: 'Address',
      monthlyRent: 'Monthly Rent',
      paymentMode: 'Mode of Payment',
      securityAmount: 'Security Amount',
      refundTerms: 'Refund Terms',
      agreementDuration: 'Agreement Duration',
      maintenanceResponsibilities: 'Maintenance Responsibilities',
      terminationClause: 'Termination Clause',
      latePaymentPenalty: 'Late Payment Penalty',
      electricityBillPaidBy: 'Electricity Bill Paid By',
      furnishingType: 'Furnishing Type',
    };

    return labels[controlName] ?? controlName;
  }

  selectedMediaFiles: File[] = [];
  existingMediaFiles: any[] = [];

  onMediaSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    this.selectedMediaFiles = Array.from(input.files || []);
  }

  removeSelectedMediaFile(index: number) {
    this.selectedMediaFiles = this.selectedMediaFiles.filter((_, i) => i !== index);
  }

  openMedia(url: string) {
    if (url) {
      window.open(url, '_blank', 'noopener');
    }
  }

  shouldShowMediaSection(): boolean {
    const furnishingType = this.form?.get('furnishingType')?.value;
    return furnishingType === 'Semi-Furnished' || furnishingType === 'Fully-Furnished';
  }

  private normalizePayload(rawValue: any) {
    const payload = {
      ...rawValue,
      propertyName: rawValue.propertyName?.trim() ?? '',
      state: rawValue.state?.trim() ?? '',
      city: rawValue.city?.trim() ?? '',
      address: rawValue.address?.trim() ?? '',
      refundTerms: rawValue.refundTerms?.trim() || null,
      agreementDuration: rawValue.agreementDuration?.trim() || null,
      maintenanceResponsibilities: rawValue.maintenanceResponsibilities?.trim() || null,
      terminationClause: rawValue.terminationClause?.trim() || null,
      latePaymentPenalty: rawValue.latePaymentPenalty?.trim() || null,
      monthlyRent: rawValue.monthlyRent === '' || rawValue.monthlyRent === null ? null : Number(rawValue.monthlyRent),
      securityAmount: rawValue.securityAmount === '' || rawValue.securityAmount === null ? 0 : Number(rawValue.securityAmount),
    };

    if (this.selectedMediaFiles.length) {
      const formData = new FormData();
      Object.entries(payload).forEach(([key, value]) => formData.append(key, value as any));
      this.selectedMediaFiles.forEach((file) => formData.append('media[]', file));
      return formData;
    }

    return payload;
  }
}
