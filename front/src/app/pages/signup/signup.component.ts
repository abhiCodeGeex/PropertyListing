import { Component, NgZone, ViewChild, ElementRef, AfterViewChecked } from '@angular/core';
import { FormBuilder, FormGroup, Validators, AbstractControl, ValidationErrors, ReactiveFormsModule, FormsModule } from '@angular/forms';
import { ButtonDirective, FormControlDirective, FormFloatingDirective, FormSelectDirective } from '@coreui/angular';
import { UsersService } from '../../services/users.service';
import { CommonModule } from '@angular/common';
import { environment } from '../../../environments/environment';
import { Router, RouterLink } from '@angular/router';
import { ToasterService } from '../../services/toaster.service';
import { FormConfigService } from '../../services/form-config.service';
import { FormErrorService } from '../../services/form-error.service';

declare const grecaptcha: any;

@Component({
  selector: 'app-signup',
  templateUrl: './signup.component.html',
  imports: [ReactiveFormsModule, FormsModule, CommonModule, FormControlDirective, ButtonDirective, RouterLink, FormFloatingDirective, FormSelectDirective]
})
export class SignupComponent implements AfterViewChecked {
  formConfig: any;
  signupForm: FormGroup = new FormGroup({});
  siteKey: string = environment.siteKey;
  captchaResponse: string | null = null;
  roles: any[] = [];
  error: string | null = null;
  captchaRendered = false;
  loading = false;
  @ViewChild('captchaContainer', { static: false }) captchaContainer!: ElementRef;
  private readonly backendToFormFieldMap: Record<string, string> = {
    name: 'name',
    email: 'email',
    password: 'password',
    password_confirmation: 'password_confirmation',
    role: 'role',
    recaptcha: 'recaptcha',
  };

  constructor(private fb: FormBuilder, private usersService: UsersService, private ngZone: NgZone, private router: Router, private toast: ToasterService, private formConfigService: FormConfigService, private formErrorService: FormErrorService) {
    (window as any).onCaptchaResolved = (response: string) => {
      this.ngZone.run(() => {
        this.captchaResponse = response;
        this.signupForm.get('recaptcha')?.setValue(response);
      });
    };
  }

  isInvalid(controlName: string): boolean {
    const control = this.signupForm.get(controlName);
    return !!(control && control.invalid && (control.touched || control.dirty));
  }

  ngOnInit() {
    this.loadRoles();
    this.formConfigService.getFormConfig('signup').subscribe({
      next: (res) => {
        this.formConfig = res;
        this.buildForm(this.formConfig.fields);
      },
      error: () => this.toast.showError('Failed to load signup form configuration')
    });
  }

  buildForm(fields: any[]) {
    const controls: any = {};

    fields.forEach((field) => {
      const validators = [];
      if (field.validation?.includes('required')) validators.push(Validators.required);
      if (field.validation?.includes('email')) validators.push(Validators.email);
      if (field.validation?.includes('min:6')) validators.push(Validators.minLength(6));
      controls[field.name] = ['', validators];
    });

    controls['recaptcha'] = ['', Validators.required];

    this.signupForm = this.fb.group(controls, {
      validators: this.passwordMatchValidator
    });
  }

  ngAfterViewChecked() {
    if (this.captchaContainer && !this.captchaRendered) {
      this.captchaRendered = true;
      try {
        grecaptcha.render(this.captchaContainer.nativeElement, {
          sitekey: this.siteKey,
          callback: (response: string) => {
            // Must run inside NgZone so Angular change detection fires
            // and the [disabled] binding on the submit button updates.
            this.ngZone.run(() => {
              this.captchaResponse = response;
              this.signupForm.get('recaptcha')?.setValue(response);
            });
          },
          'expired-callback': () => {
            // Token expired — clear form control so the form goes invalid
            // and the submit button disables itself again.
            this.ngZone.run(() => {
              this.captchaResponse = null;
              this.signupForm.get('recaptcha')?.setValue('');
            });
          },
          'error-callback': () => {
            this.ngZone.run(() => {
              this.captchaResponse = null;
              this.signupForm.get('recaptcha')?.setValue('');
            });
          },
        });
      } catch (e) {
        // grecaptcha not ready yet — reset flag so we retry on next cycle
        this.captchaRendered = false;
      }
    }
  }


  loadRoles() {
    this.usersService.getRoles().subscribe({
      next: (allRoles: any[]) => {
        this.roles = allRoles.filter(role => role['name'] === 'tenant' || role['name'] === 'owner');
      },
      error: () => this.toast.showError('Failed to load roles')
    });
  }

  passwordMatchValidator(group: AbstractControl): ValidationErrors | null {
    const password = group.get('password')?.value;
    const confirm = group.get('password_confirmation')?.value;
    return password === confirm ? null : { passwordMismatch: true };
  }

  submit() {
    this.formErrorService.clearServerErrors(this.signupForm, 'serverError');
    this.error = null;

    if (this.signupForm.invalid) {
      this.signupForm.markAllAsTouched();
      this.toast.showError('Please correct the highlighted fields.');
      return;
    }

    // Ensure CAPTCHA token is present
    if (!this.captchaResponse || this.captchaResponse.trim() === '') {
      this.toast.showError('Please complete the CAPTCHA verification.');
      return;
    }

    const payload = { ...this.signupForm.value };
    this.loading = true;

    console.log('[Signup] Submitting registration with CAPTCHA token:', {
      tokenLength: this.captchaResponse.length,
      tokenStart: this.captchaResponse.substring(0, 10) + '...'
    });

    this.usersService.register(payload).subscribe({
      next: () => {
        this.loading = false;
        this.toast.showSuccess('Registered successfully');
        this.router.navigate(['/login']);
      },
      error: (err) => {
        this.loading = false;

        console.error('[Signup] Registration error:', err);

        // Reset CAPTCHA widget so the user gets a fresh token on retry.
        // Without this, the submitted (now-used) token stays in the form
        // and subsequent attempts always fail CAPTCHA validation.
        try {
          if (typeof grecaptcha !== 'undefined') {
            grecaptcha.reset();
          }
        } catch (e) {
          console.warn('[Signup] Error resetting reCAPTCHA:', e);
        }
        this.captchaResponse = null;
        this.signupForm.get('recaptcha')?.setValue('');

        if (err.status === 422 && err.error?.errors) {
          this.formErrorService.applyServerErrors(this.signupForm, err.error.errors, this.backendToFormFieldMap, 'serverError');
          this.error = 'Please correct the highlighted fields.';
        } else {
          this.error = this.toast.extractErrorMessage(err, 'Failed to register');
        }
        this.toast.showError(this.error || 'Failed to register');
      }
    });
  }
}
