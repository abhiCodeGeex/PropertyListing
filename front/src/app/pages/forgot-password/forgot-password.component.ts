import { Component } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule, FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { CommonModule } from '@angular/common';
import { ToasterService } from '../../services/toaster.service';
import { AuthService } from '../../services/auth.service';
import { ButtonDirective } from '@coreui/angular';

@Component({
  selector: 'app-forgot-password',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule, RouterLink, ButtonDirective],
  templateUrl: './forgot-password.component.html'
})
export class ForgotPasswordComponent {
  form: FormGroup;
  loading = false;
  message = '';
  error = '';

  constructor(
    private fb: FormBuilder,
    private auth: AuthService,
    private toast: ToasterService,
    private router: Router
  ) {
    this.form = this.fb.group({
      email: ['', [Validators.required, Validators.email]]
    });
  }

  get f() {
    return this.form.controls;
  }

  submit() {
    if (this.form.invalid) return;

    this.loading = true;
    this.error = '';
    this.message = '';

    this.auth.forgotPassword(this.form.value.email).subscribe({
      next: (res: any) => {
        this.loading = false;
        this.message = res.message;
        this.toast.showSuccess(res.message || 'If the email is registered, a reset link will be sent.');
      },
      error: (err) => {
        this.loading = false;
        this.error = this.toast.extractErrorMessage(err, 'Something went wrong');
      }
    });
  }
}
