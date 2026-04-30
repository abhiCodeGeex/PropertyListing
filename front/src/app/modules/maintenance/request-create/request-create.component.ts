import { CommonModule } from '@angular/common';
import {
  Component,
  DestroyRef,
  EventEmitter,
  Input,
  OnChanges,
  OnInit,
  Output,
  SimpleChanges,
  inject
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import {
  ButtonCloseDirective,
  ModalBodyComponent,
  ModalComponent,
  ModalHeaderComponent,
  ModalTitleDirective
} from '@coreui/angular';
import { AuthService } from '../../../services/auth.service';
import { FormErrorService } from '../../../services/form-error.service';
import { ToasterService } from '../../../services/toaster.service';
import { UsersService } from '../../../services/users.service';
import { MaintenanceRequest } from '../maintenance.models';
import { MaintenanceService } from '../maintenance.service';

interface TenantPropertyOption {
  property_id: number;
  tenancy_id: number;
  property_name: string;
  city?: string;
  state?: string;
}

@Component({
  selector: 'app-request-create',
  imports: [
    CommonModule,
    ReactiveFormsModule,
    ModalComponent,
    ModalHeaderComponent,
    ModalTitleDirective,
    ModalBodyComponent,
    ButtonCloseDirective
  ],
  templateUrl: './request-create.component.html',
  styleUrl: './request-create.component.scss'
})
export class RequestCreateComponent implements OnInit, OnChanges {
  private readonly fb = inject(FormBuilder);
  private readonly usersService = inject(UsersService);
  private readonly maintenanceService = inject(MaintenanceService);
  private readonly toast = inject(ToasterService);
  private readonly auth = inject(AuthService);
  private readonly formErrorService = inject(FormErrorService);
  private readonly destroyRef = inject(DestroyRef);

  /**
   * Controls modal visibility. Parent component uses two-way binding via
   * `[(visible)]` or pairs `[visible]` with `(visibleChange)`.
   */
  @Input() visible = false;
  @Output() visibleChange = new EventEmitter<boolean>();
  @Output() created = new EventEmitter<MaintenanceRequest>();

  readonly form = this.fb.group({
    property_id: [null as number | null, Validators.required],
    tenancy_id: [null as number | null, Validators.required],
    title: ['', [Validators.required, Validators.maxLength(255)]],
    description: ['', [Validators.required, Validators.maxLength(5000)]],
    category: ['', Validators.maxLength(100)],
    priority: ['medium', Validators.required]
  });

  readonly priorities = ['low', 'medium', 'high', 'urgent'];
  properties: TenantPropertyOption[] = [];
  selectedFiles: File[] = [];
  loading = false;
  submitting = false;
  private propertiesLoaded = false;

  ngOnInit(): void {
    // Eager-load on first mount so the form is ready the moment the modal opens.
    this.loadAssignedProperties();
  }

  ngOnChanges(changes: SimpleChanges): void {
    const visibleChange = changes['visible'];

    if (!visibleChange) {
      return;
    }

    const current = visibleChange.currentValue === true;
    const previous = visibleChange.previousValue === true;

    // Opening: reset the form each time so stale values from a previous cancel
    // don't leak into a new request. Refresh properties if the first fetch failed.
    if (current && !previous) {
      this.resetForm();

      if (!this.propertiesLoaded) {
        this.loadAssignedProperties();
      }
    }
  }

  handleVisibleChange(visible: boolean): void {
    // Ignore CoreUI's backdrop-driven close while a submit is in-flight to
    // prevent losing form state mid-request.
    if (!visible && this.submitting) {
      return;
    }

    this.visible = visible;
    this.visibleChange.emit(visible);
  }

  close(): void {
    if (this.submitting) {
      return;
    }

    this.handleVisibleChange(false);
  }

  onPropertyChange(): void {
    const propertyId = Number(this.form.controls.property_id.value);
    const selected = this.properties.find(item => item.property_id === propertyId);
    this.form.patchValue({
      tenancy_id: selected?.tenancy_id ?? null
    });
  }

  onFileChange(event: Event): void {
    const input = event.target as HTMLInputElement | null;
    const files = Array.from(input?.files ?? []);
    this.selectedFiles = files.slice(0, 5);

    if (files.length > 5) {
      this.toast.showError('You can attach up to 5 files per request.');
    }

    if (input) {
      input.value = '';
    }
  }

  removeFile(index: number): void {
    this.selectedFiles = this.selectedFiles.filter((_, itemIndex) => itemIndex !== index);
  }

  submit(): void {
    this.formErrorService.clearServerErrors(this.form);

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.toast.showError('Please complete the maintenance request form.');
      return;
    }

    const value = this.form.getRawValue();
    this.submitting = true;

    this.maintenanceService.createRequest({
      property_id: Number(value.property_id),
      tenancy_id: Number(value.tenancy_id),
      title: value.title?.trim() ?? '',
      description: value.description?.trim() ?? '',
      category: value.category?.trim() || null,
      priority: value.priority ?? 'medium',
      attachments: this.selectedFiles
    })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.submitting = false;
          this.toast.showSuccess(response?.message || 'Maintenance request created successfully.');
          this.created.emit(response.request);
          this.handleVisibleChange(false);
        },
        error: (error) => {
          this.submitting = false;

          if (!this.formErrorService.applyServerErrors(this.form, error?.error?.errors)) {
            this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to create maintenance request.'));
            return;
          }

          this.toast.showError('Please correct the highlighted fields.');
        }
      });
  }

  fieldError(controlName: string): string | null {
    const control = this.form.get(controlName);
    const errors = control?.errors;

    if (!control || !errors || !(control.touched || control.dirty)) {
      return null;
    }

    if (errors['server']) return errors['server'];
    if (errors['required']) return 'This field is required.';
    if (errors['maxlength']) return `Keep this within ${errors['maxlength'].requiredLength} characters.`;

    return 'Invalid value.';
  }

  private resetForm(): void {
    this.formErrorService.clearServerErrors(this.form);
    this.form.reset({
      property_id: this.properties.length === 1 ? this.properties[0].property_id : null,
      tenancy_id: this.properties.length === 1 ? this.properties[0].tenancy_id : null,
      title: '',
      description: '',
      category: '',
      priority: 'medium'
    });
    this.selectedFiles = [];
    this.submitting = false;
  }

  private loadAssignedProperties(): void {
    const user = this.auth.user();
    const userId = user?.user?.id ?? user?.id ?? null;

    if (!userId) {
      return;
    }

    this.loading = true;

    this.usersService.assignedProperties(String(userId))
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.loading = false;
          this.propertiesLoaded = true;
          this.properties = (response?.property ?? []).map((property: any) => ({
            property_id: Number(property.id),
            tenancy_id: Number(property.tenancy_id),
            property_name: property.property_name,
            city: property.city,
            state: property.state
          }));

          if (this.properties.length === 1) {
            this.form.patchValue({
              property_id: this.properties[0].property_id,
              tenancy_id: this.properties[0].tenancy_id
            });
          }
        },
        error: (error) => {
          this.loading = false;
          this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to load your assigned properties.'));
        }
      });
  }
}
