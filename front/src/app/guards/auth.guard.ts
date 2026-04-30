import { Injectable } from '@angular/core';
import { CanActivate, Router } from '@angular/router';
import { AuthService } from '../services/auth.service';
import { catchError, map, of } from 'rxjs';

@Injectable({ providedIn: 'root' })
export class AuthGuard implements CanActivate {
  constructor(private auth: AuthService, private router: Router) {}

  canActivate() {
    if (this.auth.hasStoredSession()) {
      return of(true);
    }

    if (!this.auth.getToken()) {
      this.router.navigate(['/login']);
      return of(false);
    }

    return this.auth.loadMe({ skipLoader: true }).pipe(
      map(user => {
        if (user) {
          return true;
        }
        this.router.navigate(['/login']);
        return false;
      }),
      catchError(() => {
        this.router.navigate(['/login']);
        return of(false);
      })
    );
  }
}
