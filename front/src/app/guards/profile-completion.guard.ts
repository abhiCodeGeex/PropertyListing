// src/app/guards/profile-completion.guard.ts
import { Injectable } from '@angular/core';
import { CanActivate, Router, RouterStateSnapshot, ActivatedRouteSnapshot } from '@angular/router';
import { ProfileService } from '../services/profile.service';

@Injectable({
  providedIn: 'root',
})
export class ProfileCompletionGuard implements CanActivate {
  constructor(private router: Router, private profileService: ProfileService) {}

  canActivate(route: ActivatedRouteSnapshot, state: RouterStateSnapshot): boolean {
    if (!this.profileService.isProfileCompleted()) {
      if (state.url === '/profile' || state.url.startsWith('/dashboard')) {
        return true;
      }
      this.router.navigate(['/profile']);
      return false;
    }
    return true;
  }
}