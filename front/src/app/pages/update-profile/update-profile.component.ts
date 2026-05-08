import { Component, OnInit } from '@angular/core';
import { AbstractControl, FormBuilder, FormGroup, ReactiveFormsModule, ValidationErrors, Validators } from '@angular/forms';
import {
  ButtonDirective,
  ModalModule
} from '@coreui/angular';
import { CommonModule } from '@angular/common';
import { UsersService } from '../../services/users.service';
import { ToasterService } from '../../services/toaster.service';
import { environment } from '../../../environments/environment';
import { ProfileService } from '../../services/profile.service';
import { FormErrorService } from '../../services/form-error.service';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatNativeDateModule } from '@angular/material/core';
import { ActivatedRoute } from '@angular/router';

@Component({
  selector: 'app-update-profile',
  templateUrl: './update-profile.component.html',
  standalone: true,
  styleUrls: ['./update-profile.component.scss'],
  imports: [
    ButtonDirective,
    ReactiveFormsModule,
    CommonModule,
    ModalModule,
    MatDatepickerModule,
    MatFormFieldModule,
    MatInputModule,
    MatNativeDateModule
  ]
})
export class UpdateProfileComponent implements OnInit {
  profileForm!: FormGroup;
  emailForm!: FormGroup;
  previewImage: string | ArrayBuffer | null = null;
  defaultImage: string = 'https://www.gravatar.com/avatar?d=mp';
  validated = false;

  // Wizard state
  step = 1;
  otpRequested = false;
  referenceId: string | null = null;
  otpLoading = false;
  verifyLoading = false;
  updateLoading = false;

  emailModalVisible = false;
  otpSent = false;
  emailOtpLoading = false;
  verifyOtpLoading = false;
  emailError = '';
  profileExists = false;
  readonly totalSteps = 4;
  roles: string[] = [];
  stripeConnectState: any = null;
  connectLoading = false;
  connectRefreshLoading = false;

  // list of controls to enable after verification
  private profileControls = [
    'first_name', 'last_name', 'username', 'password', 'password_confirmation',
    'profile_image', 'dob', 'current_address', 'native_address', 'aadhar_text',
    'pan', 'marital_status', 'gender', 'phone'
  ];
  private readonly emailFormFieldMap: Record<string, string> = {
    email: 'new_email',
    otp: 'otp',
  };

  constructor(
    private fb: FormBuilder,
    private usersService: UsersService,
    private toast: ToasterService,
    private profileService: ProfileService,
    private formErrorService: FormErrorService,
    private route: ActivatedRoute,
  ) {
    // initialize form: disable all profile fields except aadhar and aadhar_otp
    this.profileForm = this.fb.group({
      first_name: [{ value: '', disabled: true }, Validators.required],
      last_name: [{ value: '', disabled: true }, Validators.required],
      username: [{ value: '', disabled: true }, Validators.required],
      email: [{ value: '', disabled: true }, [Validators.required, Validators.email]],
      password: [{ value: '', disabled: true }],
      password_confirmation: [{ value: '', disabled: true }],
      profile_image: [null],
      dob: [{ value: null, disabled: true }, Validators.required],
      current_address: [{ value: '', disabled: true }, Validators.required],
      native_address: [{ value: '', disabled: true }, Validators.required],
      aadhar: ['', [Validators.required, Validators.pattern(/^\d{12}$/)]],
      aadhar_text: this.fb.control<string>(''),
      pan: [{ value: '', disabled: true }, Validators.pattern(/[A-Z]{5}[0-9]{4}[A-Z]{1}/)],
      marital_status: [{ value: '', disabled: true }, Validators.required],
      gender: [{ value: '', disabled: true }, Validators.required],
      phone: [{ value: '', disabled: true }, [Validators.required, Validators.pattern(/^[6-9]\d{9}$/)]],
      aadhar_otp: ['', [Validators.required, Validators.minLength(6), Validators.maxLength(6)]],
    }, { validators: this.passwordMatchValidator });
  }

  ngOnInit(): void {
    this.roles = JSON.parse(localStorage.getItem('roles') || '[]');
    this.loadProfile();
    this.route.queryParamMap.subscribe((params) => {
      if (params.get('stripe_connect')) {
        this.refreshStripeConnect();
      }
    });
    this.emailForm = this.fb.group({
      new_email: ['', [Validators.required, Validators.email]],
      otp: ['', [Validators.pattern(/^\d{6}$/)]]
    });
  }

  get stepItems() {
    return [
      {
        id: 1,
        badge: '01',
        title: 'Identity Verification',
        caption: 'Validate Aadhaar before editing protected profile data.',
      },
      {
        id: 2,
        badge: '02',
        title: 'Personal Details',
        caption: 'Confirm your legal name, birth date, and core identity fields.',
      },
      {
        id: 3,
        badge: '03',
        title: 'Contact Information',
        caption: 'Provide addresses and a reachable phone number.',
      },
      {
        id: 4,
        badge: '04',
        title: 'Account Security',
        caption: 'Review login credentials and finalize the profile update.',
      },
    ];
  }

  goBack() {
    if (this.profileExists) {
      this.step = 2;
      return;
    }

    this.toast.showError('Complete Aadhaar verification first.');
  }
  
  get emailInvalid() {
    const c = this.emailForm.get('new_email');
    return !!(c && c.touched && c.invalid);
  }
  get otpInvalid() {
    const c = this.emailForm.get('otp');
    return !!(c && c.touched && c.invalid);
  }

  get canEditProfile(): boolean {
    return this.step > 1;
  }

  get progressPercent(): number {
    return Math.round((this.step / this.totalSteps) * 100);
  }

  get currentStepTitle(): string {
    return this.stepItems.find(item => item.id === this.step)?.title || 'Profile';
  }

  get currentStepCaption(): string {
    return this.stepItems.find(item => item.id === this.step)?.caption || '';
  }

  get isFinalStep(): boolean {
    return this.step === this.totalSteps;
  }

  get isFirstStep(): boolean {
    return this.step === 1;
  }

  get canManageStripeConnect(): boolean {
    return this.roles.includes('owner') || this.roles.includes('property_manager');
  }

  canOpenStep(targetStep: number): boolean {
    if (targetStep < 1 || targetStep > this.totalSteps) {
      return false;
    }

    if (targetStep === 1) {
      return true;
    }

    if (!this.profileExists && !this.isVerificationComplete()) {
      return false;
    }

    return targetStep <= this.highestUnlockedStep();
  }

  openStep(targetStep: number): void {
    if (!this.canOpenStep(targetStep)) {
      if (targetStep > 1) {
        this.toast.showError('Complete Aadhaar verification before continuing.');
      }
      return;
    }

    this.step = targetStep;
  }

  nextStep(): void {
    if (this.step === 1 && !this.isVerificationComplete()) {
      this.toast.showError('Verify Aadhaar before continuing.');
      return;
    }

    if (this.step === 1 && this.isVerificationComplete()) {
      this.step = 2;
      return;
    }

    if (this.step > 1 && !this.validateCurrentStep()) {
      this.toast.showError('Please correct the highlighted fields before continuing.');
      return;
    }

    if (this.step < this.totalSteps) {
      this.step += 1;
    }
  }

  previousStep(): void {
    if (this.step > 1) {
      this.step -= 1;
    }
  }

  openEmailModal() {
    this.emailError = '';
    this.emailForm.reset();
    this.otpSent = false;
    this.emailModalVisible = true;
  }

  closeEmailModal() {
    this.emailError = '';
    this.emailModalVisible = false;
  }

  // Step 1: Send OTP to New Email
  sendEmailOtp() {
    this.emailError = '';
    this.formErrorService.clearServerErrors(this.emailForm, 'serverError');
    if (this.emailForm.get('new_email')?.invalid) {
      this.emailForm.get('new_email')?.markAsTouched();
      return;
    }
    this.emailOtpLoading = true;
    this.usersService.sendEmailOtp({
      email: this.emailForm.value.new_email
    }).subscribe({
      next: (res: any) => {
        this.toast.showSuccess(res.message || 'OTP sent to your new email');
        this.otpSent = true;
        this.emailOtpLoading = false;
      },
      error: err => {
        if (!this.formErrorService.applyServerErrors(this.emailForm, err?.error?.errors, this.emailFormFieldMap, 'serverError')) {
          this.emailError = this.toast.extractErrorMessage(err, 'Failed to send OTP');
          this.toast.showError(this.emailError);
          return;
        }

        this.toast.showError('Please correct the highlighted fields.');
        this.emailOtpLoading = false;
      }
    });
  }

  // Step 2: Verify OTP & Update Email
  verifyEmailOtp() {
    this.emailError = '';
    this.formErrorService.clearServerErrors(this.emailForm, 'serverError');
    if (this.emailForm.invalid) {
      this.emailForm.markAllAsTouched();
      return;
    }
    this.verifyOtpLoading = true;
    this.usersService.verifyEmailOtp({
      email: this.emailForm.value.new_email!,
      otp: this.emailForm.value.otp!
    }).subscribe({
      next: (res: any) => {
        this.toast.showSuccess(res.message || 'Email updated successfully');
        this.profileForm.get('email')?.setValue(this.emailForm.value.new_email);
        this.closeEmailModal();
        this.verifyOtpLoading = false;
      },
      error: err => {
        if (!this.formErrorService.applyServerErrors(this.emailForm, err?.error?.errors, this.emailFormFieldMap, 'serverError')) {
          this.emailError = this.toast.extractErrorMessage(err, 'Invalid OTP');
          this.toast.showError(this.emailError);
          return;
        }

        this.toast.showError('Please correct the highlighted fields.');
        this.verifyOtpLoading = false;
      }
    });
  }

  // custom password match validator for the whole form group
  passwordMatchValidator(group: AbstractControl): ValidationErrors | null {
    const passControl = group.get('password');
    const confirmControl = group.get('password_confirmation');

    if (!passControl || !confirmControl) return null;
    // avoid overwriting unrelated errors
    if (confirmControl.errors && !confirmControl.errors['passwordMismatch']) {
      return null;
    }

    if (passControl.value !== confirmControl.value) {
      confirmControl.setErrors({ passwordMismatch: true });
    } else {
      confirmControl.setErrors(null);
    }

    return null;
  }



  // enable the profile fields after Aadhaar verification (or when profile already exists)
  private enableProfileFields() {
    this.profileControls.forEach(name => {
      const ctl = this.profileForm.get(name);
      if (ctl && ctl.disabled) ctl.enable({ emitEvent: false });
    });
  }

  // disable profile fields (revert to step 1)
  private disableProfileFields() {
    this.profileControls.forEach(name => {
      const ctl = this.profileForm.get(name);
      if (ctl && ctl.enabled) ctl.disable({ emitEvent: false });
    });
  }

  fileInputClick() {
    const input = document.getElementById('profileFile') as HTMLInputElement;
    if (input) {
      input.click();
    }
  }

  sanitizeDigits(controlName: string, maxLength?: number, form: FormGroup = this.profileForm): void {
    const control = form.get(controlName);
    if (!control) return;

    let value = String(control.value ?? '').replace(/\D+/g, '');
    if (maxLength) {
      value = value.slice(0, maxLength);
    }

    if (control.value !== value) {
      control.setValue(value, { emitEvent: false });
    }
  }

  normalizeUppercase(controlName: string, form: FormGroup = this.profileForm): void {
    const control = form.get(controlName);
    if (!control) return;

    const value = String(control.value ?? '').toUpperCase().replace(/\s+/g, '');
    if (control.value !== value) {
      control.setValue(value, { emitEvent: false });
    }
  }

  handleImageError(event: Event): void {
    const imgElement = event.target as HTMLImageElement;
    imgElement.src = this.defaultImage;
  }

  loadProfile() {
    this.usersService.getProfile().subscribe({
      next: (user) => {
        const profile = user.profile;
        if (profile) {
          this.profileExists = true;
          // patch values (works even if controls are disabled)
          this.profileForm.patchValue({
            first_name: profile.first_name || '',
            last_name: profile.last_name || '',
            dob: this.parseDate(profile.dob),
            current_address: profile.current_address || '',
            native_address: profile.native_address || '',
            aadhar: profile.aadhar ? profile.aadhar.toString() : '',
            aadhar_text: profile.aadhar_text || '',
            aadhar_otp: '',
            pan: profile.pan || '',
            marital_status: profile.marital_status || '',
            gender: profile.gender || '',
            phone: profile.phone || '',
            username: user.username || '',
            email: user.email || ''
          });

          if (profile.profile_image) {
            this.previewImage = `${environment.backendUrl}/storage/${profile.profile_image}`;
          }

          // if profile already has name or aadhar info, assume verification already done -> go to step 2
          if (profile.first_name || profile.aadhar) {
            this.enableProfileFields();
            this.step = 2;
            this.otpRequested = true;
            // keep aadhar field editable or disable it? We disable to avoid accidental changes:
            this.profileForm.get('aadhar')?.disable({ emitEvent: false });
            this.profileForm.get('aadhar_otp')?.disable({ emitEvent: false });
          }
        }

        // ensure email and username are set (email stays disabled)
        this.profileForm.get('email')?.setValue(user.email);
        this.profileForm.get('username')?.setValue(user.username);
        this.stripeConnectState = user.payment_settings?.stripe_connect ?? null;
      },
      error: (err) => {
        this.toast.showError(this.toast.extractErrorMessage(err, 'Failed to load profile'));
      }
    });
  }

  onFileChange(event: any) {
    const file = event.target.files?.[0];
    if (file) {
      // store File object in form control
      this.profileForm.patchValue({ profile_image: file });
      const reader = new FileReader();
      reader.onload = () => this.previewImage = reader.result;
      reader.readAsDataURL(file);
    }
  }

  // 🔹 Send Aadhaar OTP
  sendOtp() {
    const aadhaar = this.profileForm.get('aadhar')?.value;
    if (!aadhaar || !/^\d{12}$/.test(aadhaar)) {
      this.profileForm.get('aadhar')?.markAsTouched();
      return;
    }

    this.otpLoading = true;
    this.usersService.generateAadhaarOtp({ aadhaar_number: aadhaar }).subscribe({
      next: (res: any) => {
        if (res?.data?.reference_id) {
          this.referenceId = res.data.reference_id;
          this.otpRequested = true;
          this.toast.showSuccess('OTP sent to registered mobile number');
        } else {
          this.toast.showError(res?.message || 'Failed to send OTP');
        }
        this.otpLoading = false;
      },
      error: (err) => {
        this.otpLoading = false;
        if (!this.formErrorService.applyServerErrors(this.profileForm, err?.error?.errors, {}, 'serverError')) {
          this.toast.showError(this.toast.extractErrorMessage(err, 'OTP request failed'));
          return;
        }

        this.toast.showError('Please correct the highlighted fields.');
      }
    });
  }

  // 🔹 Verify Aadhaar OTP
  verifyOtp() {
    this.formErrorService.clearServerErrors(this.profileForm, 'serverError');
    const otp = this.profileForm.get('aadhar_otp')?.value?.toString();
    if (!otp || otp.length !== 6) {
      this.profileForm.get('aadhar_otp')?.markAsTouched();
      return;
    }

    if (!this.referenceId) {
      this.toast.showError('Reference ID missing. Please request OTP again.');
      return;
    }

    this.verifyLoading = true;
    this.usersService.verifyOtp({
      reference_id: this.referenceId.toString(),
      otp: otp
    }).subscribe({
      next: (res: any) => {
        this.verifyLoading = false;
        if (res?.data?.name) {
          // split name into first + last (basic)
          const fullName = (res.data.name || '').trim();
          const parts = fullName.split(/\s+/);
          const first = parts.shift() || '';
          const last = parts.join(' ');

          this.profileForm.patchValue({
            first_name: first,
            last_name: last,
            aadhar_text: res.data.name || '',
            dob: this.parseDate(res.data.date_of_birth),
            gender: (res.data.gender || '').toLowerCase(),
            current_address: res.data.full_address || ''
          });

          // enable all profile fields now that Aadhaar is verified
          this.enableProfileFields();

          // disable aadhar controls to prevent accidental changes (user can change via "Change Aadhaar")
          this.profileForm.get('aadhar')?.disable({ emitEvent: false });
          this.profileForm.get('aadhar_otp')?.disable({ emitEvent: false });

          this.toast.showSuccess('Aadhaar verified successfully!');
          this.step = 2;
        } else {
          this.toast.showError(res?.message || 'OTP verification failed');
        }
      },
      error: (err) => {
        this.verifyLoading = false;
        if (!this.formErrorService.applyServerErrors(this.profileForm, err?.error?.errors, {}, 'serverError')) {
          this.toast.showError(this.toast.extractErrorMessage(err, 'Failed to verify OTP'));
          return;
        }

        this.toast.showError('Please correct the highlighted fields.');
      }
    });
  }

  // allow user to go back and change Aadhaar (re-enable aadhaar inputs and reset step)
  changeAadhaar() {
    this.step = 1;
    this.referenceId = null;
    this.otpRequested = false;
    this.profileForm.get('aadhar')?.enable({ emitEvent: false });
    this.profileForm.get('aadhar_otp')?.enable({ emitEvent: false });
    // clear OTP control
    this.profileForm.patchValue({ aadhar_otp: '' });
    // disable profile fields again (they remain populated but disabled)
    this.disableProfileFields();
  }

  submit() {
    this.validated = true;
    this.formErrorService.clearServerErrors(this.profileForm, 'serverError');

    // only allow submit when step 2 (profile fields enabled)
    if (this.step !== this.totalSteps) {
      this.toast.showError('Complete all steps and return to the final review before submitting.');
      return;
    }

    if (!this.validateCurrentStep() || this.profileForm.invalid) {
      this.profileForm.markAllAsTouched();
      this.toast.showError('Please correct the highlighted fields.');
      return;
    }
    this.updateLoading = true;

    const raw = this.profileForm.getRawValue(); // includes disabled controls (like email)
    const formData = new FormData();

    // Append fields to FormData. Handle file specially.
    Object.entries(raw).forEach(([key, value]) => {
      if (value === null || value === undefined || value === '') {
        // skip empty values (optional)
        return;
      }

      // profile_image -> expect File
      if (key === 'profile_image') {
        if (value instanceof File) {
          formData.append(key, value);
        }
        // if it's a string (existing path) we skip sending it (backend likely already has it)
        return;
      }

      // do not send otp with profile (optional). If your backend expects it, remove this condition.
      if (key === 'aadhar_otp') {
        return;
      }

      // convert objects to JSON
      if (value instanceof Date) {
        formData.append(key, this.formatDate(value));
        return;
      }

      if (typeof value === 'object') {
        formData.append(key, JSON.stringify(value));
      } else {
        formData.append(key, value.toString());
      }
    });

    this.usersService.updateProfile(formData).subscribe({
      next: (res: any) => {
        this.updateLoading = false;
        this.profileExists = true;
        localStorage.setItem('user', JSON.stringify(res.user));
        localStorage.setItem('profile', JSON.stringify(res.profile));
        this.profileService.setProfile(res.profile);
        this.toast.showSuccess(res.message || 'Profile updated successfully!');
      },
      error: (err) => {
        this.updateLoading = false;
        if (!this.formErrorService.applyServerErrors(this.profileForm, err?.error?.errors, {}, 'serverError')) {
          this.toast.showError(this.toast.extractErrorMessage(err, 'Update failed'));
          return;
        }

        this.toast.showError('Please correct the highlighted fields.');
      }
    });
  }

  openStripeConnectOnboarding(): void {
    this.connectLoading = true;
    this.usersService.createStripeConnectOnboardingLink().subscribe({
      next: (res: any) => {
        this.connectLoading = false;
        this.stripeConnectState = res?.payment_settings?.stripe_connect ?? this.stripeConnectState;

        const onboardingUrl = res?.link?.url;
        if (!onboardingUrl) {
          this.toast.showError('Stripe onboarding link was not returned.');
          return;
        }

        window.location.assign(onboardingUrl);
      },
      error: (err) => {
        this.connectLoading = false;
        this.toast.showError(this.toast.extractErrorMessage(err, 'Failed to start Stripe onboarding'));
      }
    });
  }

  refreshStripeConnect(): void {
    this.connectRefreshLoading = true;
    this.usersService.refreshStripeConnectStatus().subscribe({
      next: (res: any) => {
        this.connectRefreshLoading = false;
        this.stripeConnectState = res?.payment_settings?.stripe_connect ?? this.stripeConnectState;
        this.toast.showSuccess(res?.message || 'Stripe Connect status refreshed successfully.');
      },
      error: (err) => {
        this.connectRefreshLoading = false;
        this.toast.showError(this.toast.extractErrorMessage(err, 'Failed to refresh Stripe Connect status'));
      }
    });
  }

  reset() {
    this.validated = false;
    this.profileForm.reset();
    this.previewImage = null;
    this.referenceId = null;
    this.otpRequested = false;
    this.step = 1;
    this.disableProfileFields();
    this.profileForm.get('aadhar')?.enable({ emitEvent: false });
    this.profileForm.get('aadhar_otp')?.enable({ emitEvent: false });
  }

  isStepVisible(stepId: number): boolean {
    return this.step === stepId;
  }

  sectionHasError(stepId: number): boolean {
    const fields = this.fieldsForStep(stepId);

    return fields.some(field => {
      const control = this.profileForm.get(field);
      return !!control && control.invalid && (control.touched || control.dirty || this.validated);
    });
  }

  private validateCurrentStep(): boolean {
    const fields = this.fieldsForStep(this.step);

    fields.forEach(field => {
      this.profileForm.get(field)?.markAsTouched();
      this.profileForm.get(field)?.updateValueAndValidity({ emitEvent: false });
    });

    return fields.every(field => !this.profileForm.get(field)?.invalid);
  }

  private fieldsForStep(stepId: number): string[] {
    switch (stepId) {
      case 2:
        return ['first_name', 'last_name', 'dob', 'gender', 'marital_status', 'pan', 'aadhar'];
      case 3:
        return ['current_address', 'native_address', 'phone'];
      case 4:
        return ['username', 'password', 'password_confirmation'];
      default:
        return [];
    }
  }

  private highestUnlockedStep(): number {
    if (!this.isVerificationComplete()) {
      return 1;
    }

    let unlocked = 2;

    if (this.step > 2 || this.isStepDataComplete(2)) {
      unlocked = 3;
    }

    if (this.step > 3 || this.isStepDataComplete(2) && this.isStepDataComplete(3)) {
      unlocked = 4;
    }

    return unlocked;
  }

  private isStepDataComplete(stepId: number): boolean {
    return this.fieldsForStep(stepId).every(field => {
      const control = this.profileForm.get(field);
      return !!control && control.valid && !!control.value;
    });
  }

  private isVerificationComplete(): boolean {
    return this.profileExists || !!this.profileForm.get('aadhar')?.disabled;
  }

  isInvalid(controlName: string, form: FormGroup = this.profileForm): boolean {
    const control = form.get(controlName);
    return !!(control && control.invalid && (control.touched || control.dirty || this.validated));
  }

  isRequiredControl(controlName: string, form: FormGroup = this.profileForm): boolean {
    const control = form.get(controlName);
    return !!control?.hasValidator?.(Validators.required);
  }

  getError(controlName: string, form: FormGroup = this.profileForm, label?: string): string | null {
    const control = form.get(controlName);
    if (!control?.errors || !(control.touched || control.dirty || this.validated)) {
      return null;
    }

    const fieldLabel = label ?? controlName.replace(/_/g, ' ');

    if (control.errors['serverError']) return control.errors['serverError'];
    if (control.errors['required']) return `${fieldLabel} is required.`;
    if (control.errors['email']) return 'Enter a valid email address.';
    if (control.errors['pattern']) return `${fieldLabel} format is invalid.`;
    if (control.errors['minlength']) return `${fieldLabel} must be at least ${control.errors['minlength'].requiredLength} characters.`;
    if (control.errors['maxlength']) return `${fieldLabel} must be at most ${control.errors['maxlength'].requiredLength} characters.`;
    if (control.errors['passwordMismatch']) return 'Passwords do not match.';

    return 'Invalid value.';
  }

  private parseDate(value: string | null | undefined): Date | null {
    if (!value) return null;
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
  }

  private formatDate(value: Date): string {
    const year = value.getFullYear();
    const month = String(value.getMonth() + 1).padStart(2, '0');
    const day = String(value.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
  }
}
