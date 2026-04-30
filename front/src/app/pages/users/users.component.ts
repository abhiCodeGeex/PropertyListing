import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { TableModule, ModalComponent, ModalHeaderComponent, ModalTitleDirective, ModalBodyComponent, ButtonDirective, ButtonCloseDirective, PaginationComponent, PageItemDirective, PageLinkDirective } from '@coreui/angular';
import { UsersService } from '../../services/users.service';
import { debounceTime, distinctUntilChanged, Subject, Subscription } from 'rxjs';
import { LoaderComponent } from '../common/loader/loader.component';
import { ConfirmModalComponent } from '../common/confirm-modal/confirm-modal.component';
import { ToasterService } from '../../services/toaster.service';
import { NgSelectModule } from '@ng-select/ng-select';
import { Router } from '@angular/router';
import { IconModule } from '@coreui/icons-angular';
import { FormErrorService } from '../../services/form-error.service';

@Component({
  selector: 'app-users',
  standalone: true,
  templateUrl: './users.component.html',
  styleUrls: ['./users.component.scss'],
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
    LoaderComponent,
    ConfirmModalComponent,
    PaginationComponent,
    PageItemDirective,
    PageLinkDirective,
    NgSelectModule,
    IconModule
  ],
})
export class UsersComponent implements OnInit {
  users: any[] = [];
  roles: any[] = [];
  totalRecords = 0;
  totalPages = 0;
  pages: number[] = [];
  page = 1;
  perPage = 10;
  loading = false;
  form: FormGroup;

  userModalVisible = false;
  isEditMode = false;
  selectedUserId: number | null = null;
  visibleConfirmModal: boolean = false;
  userToDelete: any = null;
  searchTerm: string = '';
  private searchSubject = new Subject<string>();
  private searchSub!: Subscription;
  selectedRoles: string[] = [];
  filteredUsers: any[] = [];
  private readonly backendToFormFieldMap: Record<string, string> = {
    name: 'name',
    email: 'email',
    password: 'password',
    roles: 'roles',
  };

  constructor(private usersService: UsersService, private fb: FormBuilder, private toast: ToasterService, private router: Router, private formErrorService: FormErrorService) {
    this.form = this.fb.group({
      name: ['', [Validators.required, Validators.maxLength(255)]],
      email: ['', [Validators.required, Validators.email]],
      password: ['', Validators.minLength(6)],
      roles: [[], Validators.required]
    });
  }

  ngOnInit(): void {
    this.loadUsers();
    this.loadRoles();
    this.searchSub = this.searchSubject
      .pipe(debounceTime(500), distinctUntilChanged())
      .subscribe((term: string) => this.loadUsers(term));
  }

  get f() {
    return this.form.controls;
  }

  onSearchChange() {
    this.searchSubject.next(this.searchTerm);
  }

  loadUsers(search: string = '') {
    this.loading = true;

    // pass selectedRoles also
    this.usersService.getUsers(this.page, this.perPage, search, this.selectedRoles).subscribe({
      next: (res: any) => {
        this.users = res.data.map((user: any) => ({
          ...user,
          roleNames: (user.roles ?? []).map((r: any) => r.name),
        }));
        this.filteredUsers = this.users;

        this.totalRecords = res.total;
        this.perPage = res.per_page;
        this.page = res.current_page;
        this.totalPages = res.last_page;
        this.pages = Array.from({ length: this.totalPages }, (_, i) => i + 1);

        this.loading = false;
      },
      error: () => {
        this.loading = false;
        this.toast.showError('Failed to load users');
      }
    });
  }


  loadRoles(): void {
    this.usersService.getRoles().subscribe({
      next: res => this.roles = res,
      error: () => this.toast.showError('Failed to load roles')
    });
  }

  toggleModal() {
    this.userModalVisible = !this.userModalVisible;
  }

  // Open modal for create
  openCreateModal() {
    this.isEditMode = false;
    this.selectedUserId = null;
    this.formErrorService.clearServerErrors(this.form);
    this.form.reset({
      name: '',
      email: '',
      password: '',
      roles: []
    });
    this.form.get('password')?.setValidators([Validators.required, Validators.minLength(6)]);
    this.form.get('password')?.updateValueAndValidity();
    this.form.markAsPristine();
    this.form.markAsUntouched();
    this.userModalVisible = true;
  }

  // Open modal for edit
  openEditModal(user: any) {
    this.isEditMode = true;
    this.selectedUserId = user.id;
    this.formErrorService.clearServerErrors(this.form);
    this.form.patchValue({
      name: user.name,
      email: user.email,
      password: '',
      roles: user.roles.map((r: any) => r.id)
    });
    this.form.get('password')?.setValidators([Validators.minLength(6)]);
    this.form.get('password')?.updateValueAndValidity();
    this.form.markAsPristine();
    this.form.markAsUntouched();
    this.userModalVisible = true;
  }

  handleModalChange(event: boolean) {
    this.userModalVisible = event;
  }

  submit(): void {
    this.formErrorService.clearServerErrors(this.form);

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.toast.showError('Please correct the highlighted fields.');
      return;
    }

    const formData = {
      ...this.form.getRawValue(),
      name: this.form.getRawValue().name?.trim(),
      email: this.form.getRawValue().email?.trim(),
    };
    if (this.isEditMode && this.selectedUserId) {
      if (!formData.password) {
        delete formData.password;
      }
      this.usersService.updateUser(this.selectedUserId, formData).subscribe({
        next: () => {
          this.loadUsers();
          this.userModalVisible = false;
          this.form.reset();
          this.toast.showSuccess('User updated successfully!');
        },
        error: err => {
          if (!this.formErrorService.applyServerErrors(this.form, err?.error?.errors, this.backendToFormFieldMap)) {
            this.toast.showError('Failed to update user');
            return;
          }

          this.toast.showError('Please correct the highlighted fields.');
        }
      });
    } else {
      this.usersService.createUser(formData).subscribe({
        next: () => {
          this.loadUsers();
          this.userModalVisible = false;
          this.form.reset();
          this.toast.showSuccess('User created successfully!');
        },
        error: err => {
          if (!this.formErrorService.applyServerErrors(this.form, err?.error?.errors, this.backendToFormFieldMap)) {
            this.toast.showError('Failed to create user');
            return;
          }

          this.toast.showError('Please correct the highlighted fields.');
        }
      });
    }
  }

  // Open confirm modal
  confirmDelete(user: any): void {
    this.userToDelete = user;
    this.visibleConfirmModal = true;
  }

  // Close confirm modal
  closeConfirm(): void {
    this.visibleConfirmModal = false;
    this.userToDelete = null;
  }

  // Actually delete user
  deleteUser(): void {
    if (!this.userToDelete) return;

    this.usersService.deleteUser(this.userToDelete.id).subscribe({
      next: () => {
        this.users = this.users.filter(u => u.id !== this.userToDelete.id);
        this.closeConfirm();
        this.toast.showSuccess('User deleted successfully!');
      },
      error: (err) => {
        this.toast.showError('Failed to delete user');
        this.closeConfirm();
      }
    });
  }

  toggleRoleFilter(role: string) {
    if (this.selectedRoles.includes(role)) {
      this.selectedRoles = this.selectedRoles.filter(r => r !== role);
    } else {
      this.selectedRoles.push(role);
    }

    this.page = 1;
    this.loadUsers();
  }

  hasPropertyRole(user: any): boolean {
    if (!user?.roleNames) return false;

    const allowed = ['owner', 'tenant', 'property_manager'];
    return user.roleNames.some((r: string) => allowed.includes(r));
  }

  goToProperties(user: any) {
    // pick first valid role
    const allowedRoles = ['owner', 'tenant', 'property_manager'];
    const role = user.roleNames.find((r: string) => allowedRoles.includes(r));

    if (!role) return;

    this.router.navigate(['/properties'], {
      queryParams: {
        role: role,
        id: user.id
      }
    });
  }

  pageChanged(newPage: number): void {
    if (newPage < 1 || newPage > this.totalPages) return;
    this.page = newPage;
    this.loadUsers();
  }


  ngOnDestroy(): void {
    if (this.searchSub) this.searchSub.unsubscribe();
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
    if (control.errors['required']) return `${this.prettyFieldName(name)} is required.`;
    if (control.errors['email']) return 'Enter a valid email address.';
    if (control.errors['minlength']) return `${this.prettyFieldName(name)} must be at least ${control.errors['minlength'].requiredLength} characters.`;
    if (control.errors['maxlength']) return `${this.prettyFieldName(name)} must be at most ${control.errors['maxlength'].requiredLength} characters.`;

    return 'Invalid value.';
  }

  private prettyFieldName(field: string): string {
    const labels: Record<string, string> = {
      name: 'Name',
      email: 'Email',
      password: 'Password',
      roles: 'Roles',
    };

    return labels[field] ?? field;
  }
}
