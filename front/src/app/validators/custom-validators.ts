import { AbstractControl, ValidationErrors } from '@angular/forms';

export class CustomValidators {

    // ✅ Aadhaar Validation (12 digits)
    static aadhaar(control: AbstractControl): ValidationErrors | null {
        const value = control.value;
        if (!value) return null;
        return /^\d{12}$/.test(value) ? null : { aadhaarInvalid: true };
    }

    // ✅ PAN Validation (ABCDE1234F)
    static pan(control: AbstractControl): ValidationErrors | null {
        const value = control.value;
        if (!value) return null;
        return /^[A-Z]{5}[0-9]{4}[A-Z]{1}$/.test(value) ? null : { panInvalid: true };
    }

    // ✅ Indian Phone Validation
    static phone(control: AbstractControl): ValidationErrors | null {
        const value = control.value;
        if (!value) return null;
        return /^[6-9]\d{9}$/.test(value) ? null : { phoneInvalid: true };
    }

    // ✅ Strong Password Validator
    static strongPassword(control: AbstractControl): ValidationErrors | null {
        const value = control.value;
        if (!value) return null;

        const strong =
            /^(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/.test(value);

        return strong ? null : { weakPassword: true };
    }

    // ✅ Password Match Validator (FormGroup level)
    static matchPassword(group: AbstractControl): ValidationErrors | null {
        const pass = group.get('password')?.value;
        const confirm = group.get('password_confirmation')?.value;

        if (!pass || !confirm) return null;

        return pass === confirm ? null : { passwordMismatch: true };
    }
}
