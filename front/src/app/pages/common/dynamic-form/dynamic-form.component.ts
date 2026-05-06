import {
  Component,
  Input,
  Output,
  EventEmitter,
  ElementRef,
  NgZone,
  ViewChild,
  AfterViewChecked
} from '@angular/core';
import { AbstractControl, FormGroup, ReactiveFormsModule, FormsModule } from '@angular/forms';
import { CommonModule, TitleCasePipe } from '@angular/common';
import { RouterLink } from '@angular/router';

declare const grecaptcha: any;

@Component({
  selector: 'app-dynamic-form',
  templateUrl: './dynamic-form.component.html',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    ReactiveFormsModule,
    TitleCasePipe,
    RouterLink
  ],
})
export class DynamicFormComponent implements AfterViewChecked {
  @Input() formConfig: any;
  @Input() formGroup!: FormGroup;
  @Input() roles: any[] = [];
  @Input() error: string | null = null;
  @Input() unverified: boolean = false;
  @Input() loading: boolean = false;
  @Input() siteKey: string = '';
  @Input() socialProviders: string[] = [];

  @Output() formSubmit = new EventEmitter<void>();
  @Output() resendVerificationClick = new EventEmitter<void>();
  @Output() socialLoginClick = new EventEmitter<string>();

  captchaResponse: string | null = null;
  captchaRendered = false;

  // Use ViewChildren since captcha div is inside *ngFor
 @ViewChild('captchaContainer', { static: false }) captchaContainer!: ElementRef;

  constructor(private ngZone: NgZone) {
    (window as any).onCaptchaResolved = (response: string) => {
      this.ngZone.run(() => {
        this.captchaResponse = response;
        this.formGroup.get('recaptcha')?.setValue(response);
      });
    };
  }

  ngAfterViewChecked() {
    if (this.captchaContainer && !this.captchaRendered && this.siteKey) {
      this.captchaRendered = true;
      grecaptcha.render(this.captchaContainer.nativeElement, {
        sitekey: this.siteKey,
        callback: (response: string) => {
          this.ngZone.run(() => {
            this.formGroup.get('recaptcha')?.setValue(response);
          });
        },
      });
    }
  }

  private getControl(fieldName: string): AbstractControl | null {
    return this.formGroup.get(fieldName);
  }

  isInvalid(fieldName: string): boolean {
    const control = this.getControl(fieldName);
    return !!(control && control.invalid && (control.touched || control.dirty));
  }

  isRequiredField(field: any): boolean {
    return !!field?.validation?.includes('required');
  }

  getFieldErrorMessage(field: any): string | null {
    const control = this.getControl(field.name);

    if (!control?.errors || !this.isInvalid(field.name)) {
      return null;
    }

    if (control.errors['serverError']) return control.errors['serverError'];
    if (control.errors['required']) return `${field.label} is required.`;
    if (control.errors['email']) return 'Invalid email format.';
    if (control.errors['minlength']) return `Minimum length is ${control.errors['minlength'].requiredLength}.`;
    if (control.errors['maxlength']) return `Maximum length is ${control.errors['maxlength'].requiredLength}.`;
    if (control.errors['pattern']) return `${field.label} format is invalid.`;
    if (control.errors['min']) return `${field.label} must be ${control.errors['min'].min} or more.`;
    if (control.errors['max']) return `${field.label} must be ${control.errors['max'].max} or less.`;

    return 'Please enter a valid value.';
  }

  getGroupErrorMessage(): string | null {
    if (
      this.formGroup.errors?.['passwordMismatch'] &&
      this.getControl('password_confirmation')?.touched
    ) {
      return 'Passwords do not match.';
    }

    return null;
  }

  submit() {
    if (this.formGroup.valid) {
      this.formSubmit.emit();
    } else {
      this.formGroup.markAllAsTouched();
    }
  }

  resendVerification() {
    this.resendVerificationClick.emit();
  }

  socialLogin(provider: string) {
    this.socialLoginClick.emit(provider);
  }
}
