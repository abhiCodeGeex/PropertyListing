import { Injectable } from '@angular/core';
import { MatSnackBar } from '@angular/material/snack-bar';
import { AppToastComponent } from '../components/common/app-toast/app-toast.component';
import { extractApiErrorMessage } from '../shared/utils/api-error.util';

@Injectable({ providedIn: 'root' })
export class ToasterService {
  private lastMessage = '';

  constructor(private snackBar: MatSnackBar) {}

  showSuccess(message: string, duration: number = 3000) {
    this.show(message, 'success', 'Success', duration);
  }

  showError(message: string, duration: number = 4000) {
    this.show(message, 'error', 'Action failed', duration);
  }

  extractErrorMessage(error: unknown, fallback: string): string {
    return extractApiErrorMessage(error, fallback);
  }

  private show(
    message: string,
    variant: 'success' | 'error',
    title: string,
    duration: number
  ): void {
    const normalized = (message || 'Something went wrong.').trim();
    if (!normalized) {
      return;
    }

    if (this.lastMessage !== normalized) {
      this.snackBar.dismiss();
    }

    this.lastMessage = normalized;
    this.snackBar.openFromComponent(AppToastComponent, {
      duration,
      panelClass: ['app-toast-panel', `app-toast-panel--${variant}`],
      horizontalPosition: 'right',
      verticalPosition: 'top',
      announcementMessage: normalized,
      data: {
        message: normalized,
        title,
        variant,
      },
    });
  }
}
