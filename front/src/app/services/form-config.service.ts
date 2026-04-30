// src/app/core/services/form-config.service.ts
import { Injectable } from '@angular/core';
import { Observable, of } from 'rxjs';
import { BaseApiService } from './base-api.service';
import { catchError } from 'rxjs/operators';

@Injectable({ providedIn: 'root' })
export class FormConfigService extends BaseApiService {

    private readonly endpoint = '/form-config';
    private readonly fallbackConfigs: Record<string, any> = {
        login: {
            form: 'login',
            fields: [
                { name: 'email', label: 'Email', type: 'email', placeholder: 'Enter email', validation: ['required', 'email'] },
                { name: 'password', label: 'Password', type: 'password', placeholder: 'Enter password', validation: ['required'] },
            ],
        },
        signup: {
            form: 'signup',
            fields: [
                { name: 'name', label: 'Name', type: 'text', placeholder: 'Enter full name', validation: ['required'] },
                { name: 'email', label: 'Email', type: 'email', placeholder: 'Enter email', validation: ['required', 'email'] },
                { name: 'password', label: 'Password', type: 'password', placeholder: 'Enter password', validation: ['required', 'min:8'] },
                { name: 'password_confirmation', label: 'Confirm Password', type: 'password', placeholder: 'Confirm password', validation: ['required'] },
                { name: 'role', label: 'Role', type: 'select', placeholder: 'Select role', validation: ['required'] },
                { name: 'recaptcha', label: 'Captcha', type: 'recaptcha', placeholder: '', validation: ['required'] },
            ],
        },
    };

    private normalizeFormName(formName: string): string {
        const normalized = formName.trim().toLowerCase().replace(/[^a-z]/g, '');
        return normalized === 'signup' ? 'signup' : 'login';
    }

    getFormConfig(formName: string): Observable<any> {
        const normalized = this.normalizeFormName(formName);

        return this.get(`${this.endpoint}/${normalized}`).pipe(
            catchError(() => of(this.getFallbackConfig(normalized)))
        );
    }

    getFallbackConfig(formName: string): any {
        const normalized = this.normalizeFormName(formName);
        return this.fallbackConfigs[normalized];
    }
}
