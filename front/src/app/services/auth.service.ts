// src/app/core/services/auth.service.ts
import { Injectable, signal, computed } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { ProfileService } from './profile.service';
import { BaseApiService } from './base-api.service';
import { HttpClient } from '@angular/common/http';

@Injectable({ providedIn: 'root' })
export class AuthService extends BaseApiService {

  private readonly tokenKey = 'api_token';
  private readonly userKey = 'user';
  private readonly roleKey = 'roles';
  private readonly profileKey = 'profile';

  /* ---------- SIGNAL STATE ---------- */

  private readonly _user = signal<any | null>(this.readStoredUser());

  /** Public readonly signals */
  readonly user = this._user.asReadonly();

  readonly roles = computed<string[]>(() => {
    const userRoles = this._user()?.roles;

    if (Array.isArray(userRoles) && userRoles.length > 0) {
      return userRoles;
    }

    try {
      return JSON.parse(localStorage.getItem(this.roleKey) || '[]');
    } catch {
      return [];
    }
  });

  readonly isLoggedIn = computed<boolean>(() =>
    !!this.getToken() && !!this._user()
  );

  readonly isSuperAdmin = computed<boolean>(() =>
    this.roles().includes('super-admin')
  );

  constructor(
    http: HttpClient,
    private readonly profileService: ProfileService
  ) {
    super(http);
  }

  /* ---------- AUTH ---------- */

  login(email: string, password: string): Observable<any> {
    return this.post('/login', { email, password }).pipe(
      tap((res: any) => {
        this.persistAuthState(res);
        this.profileService.setProfile(res.profile);
      })
    );
  }

  logout(): void {
    if (this.getToken()) {
      this.post('/logout').subscribe();
    }
    localStorage.clear();
    this._user.set(null);
  }

  loadMe(options?: { skipLoader?: boolean }): Observable<any> {
    return this.get('/me', undefined, options).pipe(
      tap((user: any) => {
        if (user?.user?.roles) {
          user.roles = user.user.roles.map((r: any) => r.name);
        }
        this.persistUserState(user);
        this.profileService.setProfile(user?.profile ?? null);
        this._user.set(user);
      })
    );
  }

  /* ---------- TOKEN ---------- */

  getToken(): string | null {
    return localStorage.getItem(this.tokenKey);
  }

  hasStoredSession(): boolean {
    return !!this.getToken() && !!this.readStoredUser();
  }

  /* ---------- PASSWORD / EMAIL ---------- */

  forgotPassword(email: string): Observable<any> {
    return this.post('/forgot-password', { email });
  }

  resetPassword(
    token: string,
    email: string,
    password: string,
    password_confirmation: string
  ): Observable<any> {
    return this.post('/reset-password', {
      token,
      email,
      password,
      password_confirmation
    });
  }

  resendVerificationEmail(email: string): Observable<any> {
    return this.post('/email/verification-notification', { email });
  }

  /**
   * Backend sends a signed full URL
   */
  verifyEmail(verificationUrl: string): Observable<any> {
    return this.http.get(verificationUrl, {
      headers: {
        Authorization: `Bearer ${this.getToken()}`
      }
    });
  }

  /* ---------- SOCIAL LOGIN ---------- */

  socialLogin(provider: 'google' | 'facebook'): void {
    window.location.href = `${this.baseUrl}/auth/${provider}/redirect`;
  }

  /* ---------- PRIVATE HELPERS ---------- */

  private persistAuthState(res: any): void {
    const storage: Record<string, string> = {
      [this.tokenKey]: res.token,
      [this.userKey]: JSON.stringify(res.user),
      [this.profileKey]: JSON.stringify(res.profile),
      [this.roleKey]: JSON.stringify(res.roles)
    };

    Object.entries(storage).forEach(([k, v]) =>
      localStorage.setItem(k, v)
    );

    this._user.set(res.user);
  }

  private persistUserState(user: any): void {
    const storage: Record<string, string> = {
      [this.userKey]: JSON.stringify(user),
      [this.roleKey]: JSON.stringify(user.roles ?? []),
      [this.profileKey]: JSON.stringify(user.profile ?? {})
    };

    Object.entries(storage).forEach(([k, v]) =>
      localStorage.setItem(k, v)
    );
  }

  private readStoredUser(): any | null {
    try {
      const stored = localStorage.getItem(this.userKey);
      return stored ? JSON.parse(stored) : null;
    } catch {
      return null;
    }
  }

  public hasAnyRole(...allowed: string[]): boolean {
    return allowed.some(role => this.roles().includes(role));
  }
}
