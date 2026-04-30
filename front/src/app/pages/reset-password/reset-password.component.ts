import { Component, OnInit } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule, FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { CommonModule } from '@angular/common';
import { ToasterService } from '../../services/toaster.service';
import { AuthService } from '../../services/auth.service';
import { ButtonDirective } from '@coreui/angular';

@Component({
  selector: 'app-reset-password',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule, RouterLink, ButtonDirective],
  templateUrl: './reset-password.component.html'
})
export class ResetPasswordComponent implements OnInit {
  form: FormGroup;
  loading = false;
  error = '';
  message = '';
  token = '';
  email = '';

  constructor(
    private fb: FormBuilder,
    private route: ActivatedRoute,
    private router: Router,
    private auth: AuthService,
    private toast: ToasterService
  ) {
    this.form = this.fb.group({
      password: ['', [Validators.required, Validators.minLength(8)]],
      password_confirmation: ['', [Validators.required]]
    });
  }

  ngOnInit(): void {
    // Get token and email from query params
    this.token = this.route.snapshot.paramMap.get('token') || '';
    this.email = this.route.snapshot.queryParamMap.get('email') || '';
  }

  get f() {
    return this.form.controls;
  }

  submit() {
    if (this.form.invalid) return;

    this.loading = true;
    this.error = '';
    this.message = '';

    this.auth.resetPassword(
      this.token,
      this.email,
      this.form.value.password,
      this.form.value.password_confirmation
    ).subscribe({
      next: (res: any) => {
        this.loading = false;
        this.message = res.message || 'Password reset successful!';
        this.toast.showSuccess('Your password has been reset successfully!');
        this.router.navigate(['/login']);
      },
      error: (err) => {
        this.loading = false;
        this.error = this.toast.extractErrorMessage(err, 'Something went wrong');
      }
    });
  }
}
