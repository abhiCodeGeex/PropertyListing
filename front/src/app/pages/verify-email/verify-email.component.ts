import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { AuthService } from '../../services/auth.service';
import { ToasterService } from '../../services/toaster.service';

@Component({
  selector: 'app-verify-email',
  template: `<p>Verifying your email...</p>`
})
export class VerifyEmailComponent implements OnInit {
  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private auth: AuthService,
    private toast: ToasterService
  ) { }

  ngOnInit(): void {
    const url = this.route.snapshot.queryParamMap.get('url');
    if (url) {
      this.auth.verifyEmail(url).subscribe({
        next: (res: any) => {
          this.toast.showSuccess(res.message || 'Email verified successfully!');
          setTimeout(() => {
            this.router.navigate(['/login']);
          }, 2000);
        },
        error: (err) => {
          this.toast.showError(this.toast.extractErrorMessage(err, 'Email verification failed'));
          setTimeout(() => {
            this.router.navigate(['/login']);
          }, 2000);
        }
      });
    }
  }

}
