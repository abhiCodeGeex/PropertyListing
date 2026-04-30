// src/app/core/services/base-api.service.ts
import { HttpClient, HttpContext, HttpParams } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { environment } from '../../environments/environment';
import { Observable } from 'rxjs';
import { SKIP_GLOBAL_LOADER } from '../interceptors/loader.interceptor';

@Injectable({ providedIn: 'root' })
export abstract class BaseApiService {

    protected readonly baseUrl = environment.apiUrl;

    constructor(protected http: HttpClient) { }

    /* -------------------- HELPERS -------------------- */

    private buildUrl(endpoint: string): string {
        return `${this.baseUrl}${endpoint}`;
    }

    protected buildParams(params?: Record<string, any>): HttpParams {
        let httpParams = new HttpParams();

        if (!params) return httpParams;

        Object.keys(params).forEach(key => {
            const value = params[key];

            if (value === null || value === undefined || value === '') return;

            // Array support (roles[], ids[])
            if (Array.isArray(value)) {
                value.forEach(v => {
                    httpParams = httpParams.append(`${key}[]`, v);
                });
            }
            // Object support (nested filters)
            else if (typeof value === 'object') {
                Object.keys(value).forEach(subKey => {
                    httpParams = httpParams.set(`${key}[${subKey}]`, value[subKey]);
                });
            }
            // Normal values
            else {
                httpParams = httpParams.set(key, value);
            }
        });

        return httpParams;
    }

    protected buildContext(options?: { skipLoader?: boolean }): HttpContext {
        let context = new HttpContext();

        if (options?.skipLoader) {
            context = context.set(SKIP_GLOBAL_LOADER, true);
        }

        return context;
    }

    /* -------------------- HTTP METHODS -------------------- */

    protected get<T>(
        endpoint: string,
        params?: Record<string, any>,
        options?: { skipLoader?: boolean }
    ): Observable<T> {
        return this.http.get<T>(this.buildUrl(endpoint), {
            params: this.buildParams(params),
            context: this.buildContext(options),
        });
    }

    protected post<T>(
        endpoint: string,
        body?: unknown,
        options?: { skipLoader?: boolean }
    ): Observable<T> {
        return this.http.post<T>(this.buildUrl(endpoint), body, {
            context: this.buildContext(options),
        });
    }

    protected put<T>(
        endpoint: string,
        body?: unknown,
        options?: { skipLoader?: boolean }
    ): Observable<T> {
        return this.http.put<T>(this.buildUrl(endpoint), body, {
            context: this.buildContext(options),
        });
    }

    protected delete<T>(
        endpoint: string,
        options?: { skipLoader?: boolean }
    ): Observable<T> {
        return this.http.delete<T>(this.buildUrl(endpoint), {
            context: this.buildContext(options),
        });
    }
}
