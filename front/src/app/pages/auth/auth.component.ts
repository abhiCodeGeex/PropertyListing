import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { ReactiveFormsModule, FormsModule, FormBuilder, FormGroup, Validators, AbstractControl, ValidationErrors, ValidatorFn } from '@angular/forms';
import { DynamicFormComponent } from '../common/dynamic-form/dynamic-form.component';
import { environment } from '../../../environments/environment';
import { AuthService } from '../../services/auth.service';
import { FormConfigService } from '../../services/form-config.service';
import { UsersService } from '../../services/users.service';
import { ToasterService } from '../../services/toaster.service';
import { FormErrorService } from '../../services/form-error.service';
import { LoaderService } from '../../services/loder.service';

@Component({
  selector: 'app-auth',
  templateUrl: './auth.component.html',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    ReactiveFormsModule,
    RouterLink,
    DynamicFormComponent
  ]
})
export class AuthComponent implements OnInit {
  formType: 'login' | 'signup' = 'login';   // route-based
  formConfig: any;
  formGroup: FormGroup = new FormGroup({});
  roles: any[] = [];
  error: string | null = null;
  loading = false;
  unverified = false;
  siteKey = environment.siteKey;
  showRoleModal = false;
  selectedRole: string | null = null;
  oauthData: any = null;
  configLoading = true;
  private readonly backendToFormFieldMap: Record<string, string> = {
    email: 'email',
    password: 'password',
    name: 'name',
    role: 'role',
    recaptcha: 'recaptcha',
    password_confirmation: 'password_confirmation',
  };

  constructor(
    private fb: FormBuilder,
    private route: ActivatedRoute,
    private auth: AuthService,
    private usersService: UsersService,
    private formConfigService: FormConfigService,
    private router: Router,
    private toast: ToasterService,
    private formErrorService: FormErrorService,
    protected readonly loader: LoaderService
  ) { }

  ngOnInit(): void {
    this.route.data.subscribe((data) => {
      this.formType = this.normalizeFormType(data['form']);
      this.configLoading = true;
      this.error = null;
      const params = new URLSearchParams(window.location.search);
      if (params.has('oauth')) {
        const encoded = params.get('oauth')!;
        const decoded = decodeURIComponent(encoded);
        const oauth = this.oauthData = JSON.parse(atob(decoded));

        if (oauth.token) {
          if (!oauth.roles || oauth.roles.length === 0) {
            this.loadRoles();
            this.showRoleModal = true;
          } else {
            this.finishOauthLogin(oauth);
          }
        } else if (oauth.message) {
          this.error = oauth.message;
          this.toast.showError(oauth.message);
        } else {
          this.error = 'Social login could not be completed. Please try again.';
          this.toast.showError(this.error);
        }
      }
      this.formConfigService.getFormConfig(this.formType).subscribe({
        next: (res) => {
          this.formConfig = res;
          this.buildForm(this.formConfig.fields);
          this.configLoading = false;
        },
        error: () => {
          this.formConfig = this.formConfigService.getFallbackConfig(this.formType);
          this.buildForm(this.formConfig.fields);
          this.error = 'Form configuration could not be loaded from the server. Using a safe fallback.';
          this.configLoading = false;
        }
      });

      if (this.formType === 'signup') {
        this.loadRoles();
      }
    });
  }

  private normalizeFormType(value: unknown): 'login' | 'signup' {
    return String(value ?? '')
      .trim()
      .toLowerCase()
      .replace(/[^a-z]/g, '') === 'signup'
      ? 'signup'
      : 'login';
  }

  confirmRole() {
    if (!this.selectedRole || !this.oauthData) {
      this.toast.showError('Please select a role to continue.');
      return;
    }

    this.loading = true;

    this.usersService.assignRole(this.oauthData.user.id, this.selectedRole).subscribe({
      next: (res: any) => {
        this.oauthData.roles = [this.selectedRole];
        this.showRoleModal = false;
        this.finishOauthLogin(this.oauthData);
        this.loading = false;
      },
      error: (err: any) => {
        this.loading = false;
        this.toast.showError(this.toast.extractErrorMessage(err, 'Failed to assign role. Please try again.'));
      }
    });
  }


  buildForm(fields: any[]) {
    const controls: any = {};
    fields.forEach((field) => {
      controls[field.name] = ['', this.resolveValidators(field.validation)];
    });

    if (this.formType === 'signup') {
      controls['recaptcha'] = ['', Validators.required];
      this.formGroup = this.fb.group(controls, { validators: this.passwordMatchValidator });
    } else {
      this.formGroup = this.fb.group(controls);
    }
  }

  private resolveValidators(validation: string | string[] | null | undefined): ValidatorFn[] {
    const rules = Array.isArray(validation)
      ? validation
      : String(validation ?? '')
          .split('|')
          .map((rule) => rule.trim())
          .filter(Boolean);

    return rules.flatMap((rule) => {
      if (rule === 'required') return [Validators.required];
      if (rule === 'email') return [Validators.email];
      if (rule === 'string') return [];

      if (rule.startsWith('min:')) {
        const length = Number(rule.split(':')[1]);
        return Number.isFinite(length) ? [Validators.minLength(length)] : [];
      }

      if (rule.startsWith('max:')) {
        const length = Number(rule.split(':')[1]);
        return Number.isFinite(length) ? [Validators.maxLength(length)] : [];
      }

      if (rule.startsWith('regex:')) {
        const pattern = rule.slice('regex:'.length);
        return pattern ? [Validators.pattern(pattern)] : [];
      }

      return [];
    });
  }

  private clearServerErrors(): void {
    this.formErrorService.clearServerErrors(this.formGroup, 'serverError');
    this.error = null;
  }

  private applyServerErrors(errors: Record<string, string[] | string>): void {
    this.formErrorService.applyServerErrors(
      this.formGroup,
      errors,
      this.backendToFormFieldMap,
      'serverError'
    );
  }

  passwordMatchValidator(group: AbstractControl): ValidationErrors | null {
    const password = group.get('password')?.value;
    const confirm = group.get('password_confirmation')?.value;
    return password === confirm ? null : { passwordMismatch: true };
  }

  loadRoles() {
    this.usersService.getRoles().subscribe({
      next: (allRoles: any[]) => {
        this.roles = allRoles.filter(role => role.name === 'tenant' || role.name === 'owner');
      },
      error: () => this.toast.showError('Failed to load roles')
    });
  }

  isInvalid(controlName: string): boolean {
    const control = this.formGroup.get(controlName);
    return !!(control && control.invalid && (control.touched || control.dirty));
  }

  submit() {
    this.clearServerErrors();

    if (this.formGroup.invalid) {
      this.formGroup.markAllAsTouched();
      this.error = 'Please correct the highlighted fields.';
      this.toast.showError(this.error);
      return;
    }

    this.error = null;

    if (this.formType === 'login') {
      this.handleLogin();
    } else {
      this.handleSignup();
    }
  }

  handleLogin() {
    this.loading = true;
    this.auth.login(this.formGroup.value.email, this.formGroup.value.password).subscribe({
      next: () => {
        this.loading = false;
        this.toast.showSuccess('Login successful');
        this.router.navigateByUrl('/dashboard');
      },
      error: (err: any) => {
        this.loading = false;
        if (err.status === 422 && err.error?.errors) {
          this.applyServerErrors(err.error.errors);
          this.toast.showError('Please correct the highlighted fields.');
          this.error = 'Please correct the highlighted fields.';
          return;
        }
        this.error = this.toast.extractErrorMessage(err, 'Login failed');
        this.unverified = !!err?.error?.unverified;
      }
    });
  }

  handleSignup() {
    this.loading = true;
    this.usersService.register(this.formGroup.value).subscribe({
      next: () => {
        this.loading = false;
        this.toast.showSuccess(
          'We have sent you a verification email. Please check and verify.'
        );
        setTimeout(() => {
          this.router.navigate(['/login']);
        }, 2000);
      },
      error: (err: any) => {
        this.loading = false;

        if (err.status === 422 && err.error?.errors) {
          this.applyServerErrors(err.error.errors);
          this.toast.showError('Please correct the highlighted fields.');
          this.error = 'Please correct the highlighted fields.';
          return;
        }
        this.error = this.toast.extractErrorMessage(err, 'Failed to register');
      },
    });
  }


  handleResendVerification() {
    this.auth.resendVerificationEmail(this.formGroup.value.email).subscribe({
      next: (res: any) => {
        this.loading = false;
        this.toast.showSuccess(res.message || 'Verification email sent!');
      },
      error: (err) => {
        this.loading = false;
        this.toast.showError(this.toast.extractErrorMessage(err, 'Failed to resend email'));
      }
    });
  }

  handleSocialLogin(provider: any) {
    this.auth.socialLogin(provider);
  }

  private finishOauthLogin(oauth: any) {
    localStorage.setItem('api_token', oauth.token);
    localStorage.setItem('user', JSON.stringify(oauth.user ?? null));
    localStorage.setItem('roles', JSON.stringify(oauth.roles ?? []));
    localStorage.setItem('profile', JSON.stringify(oauth.profile ?? null));
    this.toast.showSuccess('Login successful');
    this.router.navigateByUrl('/dashboard');
  }

}
