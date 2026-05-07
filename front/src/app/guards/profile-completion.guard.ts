// src/app/guards/profile-completion.guard.ts
import { Injectable } from '@angular/core';
import { CanActivate, Router, RouterStateSnapshot, ActivatedRouteSnapshot } from '@angular/router';
import { ProfileService } from '../services/profile.service';
import { ToasterService } from '../services/toaster.service';

@Injectable({
  providedIn: 'root',
})
export class ProfileCompletionGuard implements CanActivate {
  constructor(
    private router: Router,
    private profileService: ProfileService,
    private toast: ToasterService
  ) {}

  canActivate(route: ActivatedRouteSnapshot, state: RouterStateSnapshot): boolean {
    if (!this.profileService.isProfileCompleted()) {
      if (state.url === '/profile' || state.url.startsWith('/dashboard')) {
        return true;
      }
      this.toast.showError('First complete your profile to do further actions.');
      this.router.navigate(['/profile']);
      return false;
    }
    return true;
  }
}
