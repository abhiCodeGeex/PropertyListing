import { Component, OnInit } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../services/auth.service';
import { FormBuilder, FormGroup, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { CommonModule } from '@angular/common';
import {
  ButtonDirective,
  FormControlDirective,
  FormFloatingDirective,
  FormSelectDirective,
} from '@coreui/angular';
import { IconDirective } from '@coreui/icons-angular';
import { ToasterService } from '../../services/toaster.service';
import { FormConfigService } from '../../services/form-config.service';
import { FormErrorService } from '../../services/form-error.service';

@Component({
  selector: 'app-login',
  templateUrl: './login.component.html',
  imports: [ReactiveFormsModule, FormsModule, CommonModule, IconDirective, FormControlDirective, FormSelectDirective, ButtonDirective, RouterLink, FormFloatingDirective]
})
export class LoginComponent implements OnInit {
  loading = false;
  error: string | null = null;
  unverified = false;
  formConfig: any;  // API response object
  loginForm: FormGroup = new FormGroup({});
  private readonly backendToFormFieldMap: Record<string, string> = {
    email: 'email',
    password: 'password',
  };

  constructor(private fb: FormBuilder, private auth: AuthService, private router: Router, private toast: ToasterService, private formConfigService: FormConfigService, private formErrorService: FormErrorService) {
    this.loginForm = this.fb.group({
      email: ['', [Validators.required, Validators.email]],
      password: ['', Validators.required]
    });
  }

  ngOnInit(): void {
    const params = new URLSearchParams(window.location.search);
    this.formConfigService.getFormConfig('login').subscribe({
      next: (res) => {
        this.formConfig = res;
        this.buildForm(this.formConfig.fields);
      },
      error: () => this.toast.showError('Failed to load login form configuration')
    });
    if (params.has('oauth')) {
      const encoded = params.get('oauth')!;
      const decoded = decodeURIComponent(encoded);
      const oauth = JSON.parse(atob(decoded));

      if (oauth.token) {
        localStorage.setItem('api_token', oauth.token);
        this.toast.showSuccess('Login successful');
        this.router.navigate(['/dashboard']);
      } else {
        this.toast.showError(oauth.message || 'Social login could not be completed. Please try again.');
      }
    }
  }

  buildForm(fields: any[]) {
    const controls: any = {};
    fields.forEach((field) => {
      const validators = [];
      if (field.validation?.includes('required')) validators.push(Validators.required);
      if (field.validation?.includes('email')) validators.push(Validators.email);
      controls[field.name] = ['', validators];
    });
    this.loginForm = this.fb.group(controls);
  }

  isInvalid(controlName: string): boolean {
    const control = this.loginForm.get(controlName);
    return !!(control && control.invalid && (control.touched || control.dirty));
  }

  submit() {
    this.formErrorService.clearServerErrors(this.loginForm, 'serverError');
    this.error = null;

    if (this.loginForm.invalid) {
      this.loginForm.markAllAsTouched();
      this.toast.showError('Please correct the highlighted fields.');
      return;
    }

    this.loading = true;
    this.auth.login(this.loginForm.value.email, this.loginForm.value.password).subscribe({
      next: () => {
        this.loading = false;
        this.toast.showSuccess('Login successful');
        this.router.navigate(['/dashboard']).then(() => {
          window.location.reload();
        });
      },
      error: (err) => {
        this.loading = false;
        if (err.status === 422 && err.error?.errors) {
          this.formErrorService.applyServerErrors(this.loginForm, err.error.errors, this.backendToFormFieldMap, 'serverError');
          this.toast.showError('Please correct the highlighted fields.');
          this.error = 'Please correct the highlighted fields.';
          return;
        }
        this.error = this.toast.extractErrorMessage(err, 'Login failed');
        this.unverified = !!err?.error?.unverified;
      }
    });
  }

  resendVerification() {
    this.auth.resendVerificationEmail(this.loginForm.value.email).subscribe({
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

  socialLogin(provider: any) {
    this.auth.socialLogin(provider);
  }
}
