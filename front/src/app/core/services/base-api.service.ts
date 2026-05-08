/**
 * Base API Service
 * Provides HTTP methods with intelligent loader management
 */
import { HttpClient, HttpContext, HttpParams } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { 
  SKIP_LOADER, 
  LOADER_TYPE, 
  LOADER_CONTEXT,
  REQUEST_PRIORITY 
} from '../interceptors/smart-loader.interceptor';
import { LoaderType, RequestPriority } from './request-state.service';

export interface ApiRequestOptions {
  /** Skip all loaders for this request */
  skipLoader?: boolean;
  
  /** Override loader type */
  loaderType?: LoaderType;
  
  /** Context for button loaders */
  loaderContext?: string;
  
  /** Request priority */
  priority?: RequestPriority;
  
  /** Custom HTTP headers */
  headers?: Record<string, string>;
  
  /** Response type */
  responseType?: 'json' | 'blob' | 'text';
}

@Injectable({ providedIn: 'root' })
export abstract class BaseApiService {
  protected readonly baseUrl = environment.apiUrl;

  constructor(protected http: HttpClient) {}

  /* -------------------- HELPERS -------------------- */

  private buildUrl(endpoint: string): string {
    // Avoid double slashes
    const base = this.baseUrl.replace(/\/$/, '');
    const path = endpoint.replace(/^\//, '');
    return `${base}/${path}`;
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
      else if (typeof value === 'object' && !(value instanceof Date)) {
        Object.keys(value).forEach(subKey => {
          httpParams = httpParams.set(`${key}[${subKey}]`, value[subKey]);
        });
      }
      // Date support
      else if (value instanceof Date) {
        httpParams = httpParams.set(key, value.toISOString());
      }
      // Normal values
      else {
        httpParams = httpParams.set(key, value);
      }
    });

    return httpParams;
  }

  protected buildContext(options?: ApiRequestOptions): HttpContext {
    let context = new HttpContext();

    if (options?.skipLoader) {
      context = context.set(SKIP_LOADER, true);
    }

    if (options?.loaderType) {
      context = context.set(LOADER_TYPE, options.loaderType);
    }

    if (options?.loaderContext) {
      context = context.set(LOADER_CONTEXT, options.loaderContext);
    }

    if (options?.priority) {
      context = context.set(REQUEST_PRIORITY, options.priority);
    }

    return context;
  }

  protected buildHeaders(options?: ApiRequestOptions): Record<string, string> {
    return options?.headers || {};
  }

  /* -------------------- HTTP METHODS -------------------- */

  protected get<T>(
    endpoint: string,
    params?: Record<string, any>,
    options?: ApiRequestOptions
  ): Observable<T> {
    const httpOptions: any = {
      params: this.buildParams(params),
      context: this.buildContext(options),
      headers: this.buildHeaders(options),
    };

    if (options?.responseType) {
      httpOptions.responseType = options.responseType;
    }

    return this.http.get<T>(this.buildUrl(endpoint), httpOptions);
  }

  protected post<T>(
    endpoint: string,
    body?: unknown,
    options?: ApiRequestOptions
  ): Observable<T> {
    const httpOptions: any = {
      context: this.buildContext(options),
      headers: this.buildHeaders(options),
    };

    if (options?.responseType) {
      httpOptions.responseType = options.responseType;
    }

    return this.http.post<T>(this.buildUrl(endpoint), body, httpOptions);
  }

  protected put<T>(
    endpoint: string,
    body?: unknown,
    options?: ApiRequestOptions
  ): Observable<T> {
    const httpOptions: any = {
      context: this.buildContext(options),
      headers: this.buildHeaders(options),
    };

    if (options?.responseType) {
      httpOptions.responseType = options.responseType;
    }

    return this.http.put<T>(this.buildUrl(endpoint), body, httpOptions);
  }

  protected patch<T>(
    endpoint: string,
    body?: unknown,
    options?: ApiRequestOptions
  ): Observable<T> {
    const httpOptions: any = {
      context: this.buildContext(options),
      headers: this.buildHeaders(options),
    };

    if (options?.responseType) {
      httpOptions.responseType = options.responseType;
    }

    return this.http.patch<T>(this.buildUrl(endpoint), body, httpOptions);
  }

  protected delete<T>(
    endpoint: string,
    options?: ApiRequestOptions
  ): Observable<T> {
    return this.http.delete<T>(this.buildUrl(endpoint), {
      context: this.buildContext(options),
      headers: this.buildHeaders(options),
    });
  }

  /* -------------------- UPLOAD METHODS -------------------- */

  protected upload<T>(
    endpoint: string,
    formData: FormData,
    onProgress?: (progress: number) => void,
    options?: Omit<ApiRequestOptions, 'responseType'>
  ): Observable<T> {
    // Note: Progress events require a different approach with HttpClient
    // This is a simplified version - for full progress tracking,
    // use the UploadService directly
    return this.post<T>(endpoint, formData, {
      ...options,
      loaderType: options?.loaderType || 'button',
    });
  }
}