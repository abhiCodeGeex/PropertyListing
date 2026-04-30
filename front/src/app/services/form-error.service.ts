import { Injectable } from '@angular/core';
import { FormGroup } from '@angular/forms';

@Injectable({ providedIn: 'root' })
export class FormErrorService {
  clearServerErrors(form: FormGroup, serverKey = 'server'): void {
    Object.keys(form.controls).forEach((key) => {
      const control = form.get(key);
      if (!control?.errors?.[serverKey]) {
        return;
      }

      const { [serverKey]: _server, ...rest } = control.errors;
      control.setErrors(Object.keys(rest).length ? rest : null);
    });
  }

  applyServerErrors(
    form: FormGroup,
    errors?: Record<string, string[] | string>,
    fieldMap: Record<string, string> = {},
    serverKey = 'server'
  ): boolean {
    if (!errors || typeof errors !== 'object') {
      return false;
    }

    Object.entries(errors).forEach(([backendField, messages]) => {
      const controlName = fieldMap[backendField] ?? backendField;
      const control = form.get(controlName);
      const message = Array.isArray(messages) ? messages[0] : messages;

      if (!control || !message) {
        return;
      }

      control.setErrors({
        ...(control.errors ?? {}),
        [serverKey]: message,
      });
      control.markAsTouched();
      control.markAsDirty();
    });

    return true;
  }
}
