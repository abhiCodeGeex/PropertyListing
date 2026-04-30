import { Component, EventEmitter, Output } from '@angular/core';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule } from '@angular/forms';
import {
  ModalComponent,
  ModalHeaderComponent,
  ModalBodyComponent,
  ModalTitleDirective,
  ButtonDirective,
  ButtonCloseDirective
} from '@coreui/angular';
import { NgSelectModule } from '@ng-select/ng-select';
import { ToasterService } from '../../services/toaster.service';
import { PropertyService } from '../../services/property.service';
import { UsersService } from '../../services/users.service';
import { FormErrorService } from '../../services/form-error.service';

@Component({
  selector: 'app-property-manager-assign',
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
    NgSelectModule
  ],
  templateUrl: './property-manager-assign.component.html'
})
export class PropertyManagerAssignComponent {

  modalVisible = false;
  form: FormGroup;
  managers: any[] = [];
  @Output() managerAssigned = new EventEmitter<void>();
  private readonly backendToFormFieldMap: Record<string, string> = {
    manager_id: 'manager_id',
  };

  constructor(
    private fb: FormBuilder,
    private userService: UsersService,
    private propertyService: PropertyService,
    private toast: ToasterService,
    private formErrorService: FormErrorService
  ) {
    this.form = this.fb.group({
      propertyId: ['', Validators.required],
      manager_id: ['', Validators.required]
    });
  }

  loadManagers(currentManagerId: number | null = null) {
    this.userService.getPropertyManagers().subscribe({
      next: (res: any[]) => {
        // ensure id is a number
        this.managers = res.map(m => ({ ...m, id: Number(m.id) }));

        if (currentManagerId != null) {
          const id = Number(currentManagerId);
          const exists = this.managers.some(m => m.id === id);

          if (exists) {
            // set value after items are loaded/rendered
            setTimeout(() => {
              this.form.get('manager_id')?.setValue(id);
            });
          }
        }
      },
      error: () => this.toast.showError('Failed to load managers')
    });
  }

  compareManagers = (a: any, b: any) => (a && a.id ? a.id : a) === b;
  
  openAssignManagerModal(propertyId: number, currentManagerId: number | null = null) {
    this.modalVisible = true;
    this.formErrorService.clearServerErrors(this.form);

    this.form.reset({
      propertyId,
      manager_id: null // set later
    });

    this.form.markAsPristine();
    this.form.markAsUntouched();
    this.loadManagers(currentManagerId);
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

    const { propertyId, manager_id } = this.form.value;

    this.propertyService.assignManager(propertyId, manager_id).subscribe({
      next: () => {
        this.toast.showSuccess('Manager assigned successfully');
        this.managerAssigned.emit();
        this.closeModal();
      },
      error: err => {
        if (!this.formErrorService.applyServerErrors(this.form, err?.error?.errors, this.backendToFormFieldMap)) {
          this.toast.showError(this.toast.extractErrorMessage(err, 'Failed to assign manager'));
          return;
        }

        this.toast.showError('Please correct the highlighted fields.');
      }
    });
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
    if (control.errors['required']) return 'Property manager is required.';

    return 'Invalid value.';
  }
}
