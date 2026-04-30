import { Component, EventEmitter, Input, OnInit, Output } from '@angular/core';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule } from '@angular/forms';
import { ModalComponent, ModalHeaderComponent, ModalBodyComponent, ModalTitleDirective, ButtonDirective, ButtonCloseDirective } from '@coreui/angular';
import { ToasterService } from '../../services/toaster.service';
import { TenantService } from '../../services/tenant.service';
import { NgSelectModule } from '@ng-select/ng-select';
import { FormErrorService } from '../../services/form-error.service';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatNativeDateModule } from '@angular/material/core';


@Component({
  selector: 'app-property-tenant-assign',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    ReactiveFormsModule,
    ModalComponent,
    ModalHeaderComponent,
    ModalTitleDirective,
    ModalBodyComponent,
    ButtonDirective,
    ButtonCloseDirective,
    NgSelectModule,
    MatDatepickerModule,
    MatFormFieldModule,
    MatInputModule,
    MatNativeDateModule
  ],
  templateUrl: './property-tenant-assign.component.html'
})
export class PropertyTenantAssignComponent implements OnInit {
  @Input() property: any;
  form: FormGroup;
  tenantsList: any[] = [];
  modalVisible = false;
  tenantSearch = '';
  today = new Date();
  @Output() tenantAssigned = new EventEmitter<void>();
  private readonly backendToFormFieldMap: Record<string, string> = {
    tenants: 'tenants',
    'tenants.0.id': 'tenants',
    'tenants.0.start_date': 'start_date',
    'tenants.0.end_date': 'end_date',
  };

  constructor(private fb: FormBuilder, private tenantService: TenantService, private toast: ToasterService, private formErrorService: FormErrorService) {
    this.form = this.fb.group({
      propertyId: ['', Validators.required],
      tenants: [null, Validators.required],
      start_date: [null, Validators.required],
      end_date: [null]
    });
  }

  ngOnInit(): void {
    this.tenantService.searchTenants('').subscribe(res => {
      // Ensure array of objects with 'id' and 'name'
      this.tenantsList = res.map((t: any) => ({
        id: t.id,
        name: t.name,
        email: t.email,
        profile: t.profile || {}
      }));
    });

    this.form.get('start_date')?.valueChanges.subscribe(startDate => {
      const endControl = this.form.get('end_date');
      if (endControl?.value && startDate && new Date(endControl.value) < new Date(startDate)) {
        endControl.setValue('');
      }
    });
  }

  openModal(propertyId: number, existingTenants: any[] = []) {
    this.formErrorService.clearServerErrors(this.form);
    this.form.reset({
      propertyId,
      tenants: null,
      start_date: null,
      end_date: null
    });

    if (existingTenants.length) {
      const tenant = existingTenants[0];
      this.form.patchValue({
        tenants: tenant.id,
        start_date: this.parseDate(tenant.start_date),
        end_date: this.parseDate(tenant.end_date)
      });
    }

    this.modalVisible = true;
    this.form.markAsPristine();
    this.form.markAsUntouched();
  }


  closeModal() {
    this.modalVisible = false;
  }

  submit() {
    this.formErrorService.clearServerErrors(this.form);

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.toast.showError('Please correct the highlighted fields.');
      return;
    }

    const payload = {
      tenants: [
        {
          id: this.form.value.tenants,
          start_date: this.formatDate(this.form.value.start_date),
          end_date: this.formatDate(this.form.value.end_date)
        }
      ]
    };

    this.tenantService.assignTenants(this.form.value.propertyId, payload).subscribe({
      next: () => {
        this.toast.showSuccess('Tenants assigned successfully!');
        this.tenantAssigned.emit();
        this.closeModal();
      },
      error: err => {
        if (!this.formErrorService.applyServerErrors(this.form, err?.error?.errors, this.backendToFormFieldMap)) {
          this.toast.showError(this.toast.extractErrorMessage(err, 'Error assigning tenants'));
          return;
        }

        this.toast.showError('Please correct the highlighted fields.');
      }
    });
  }

  filteredTenants() {
    return this.tenantsList.filter(t =>
      t.name.toLowerCase().includes(this.tenantSearch.toLowerCase()) ||
      t.email.toLowerCase().includes(this.tenantSearch.toLowerCase())
    );
  }

  isInvalid(name: string): boolean {
    const control = this.form.get(name);
    return !!(control && control.invalid && (control.touched || control.dirty));
  }

  getError(name: string): string | null {
    const control = this.form.get(name);
    if (!control?.errors || !(control.touched || control.dirty)) {
      return null;
    }

    if (control.errors['server']) return control.errors['server'];
    if (control.errors['required']) {
      return name === 'tenants' ? 'Tenant is required.' : name === 'start_date' ? 'Start date is required.' : 'This field is required.';
    }

    return 'Invalid value.';
  }

  private parseDate(value: string | null | undefined): Date | null {
    if (!value) return null;
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
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
