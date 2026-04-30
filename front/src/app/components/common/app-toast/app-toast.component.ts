import { CommonModule } from '@angular/common';
import { Component, inject } from '@angular/core';
import { MAT_SNACK_BAR_DATA, MatSnackBarRef } from '@angular/material/snack-bar';

type ToastVariant = 'success' | 'error';

interface ToastData {
  message: string;
  title: string;
  variant: ToastVariant;
}

@Component({
  selector: 'app-toast',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="app-toast" [attr.data-variant]="data.variant">
      <div class="app-toast__icon" aria-hidden="true">
        <span>{{ data.variant === 'success' ? 'OK' : '!' }}</span>
      </div>

      <div class="app-toast__content">
        <div class="app-toast__title">{{ data.title }}</div>
        <div class="app-toast__message">{{ data.message }}</div>
      </div>

      <button type="button" class="app-toast__close" (click)="dismiss()">
        Close
      </button>
    </div>
  `,
  styles: [`
    :host {
      display: block;
      width: 100%;
    }

    .app-toast {
      position: relative;
      display: grid;
      grid-template-columns: auto 1fr auto;
      gap: 14px;
      align-items: center;
      min-width: 320px;
      max-width: min(420px, calc(100vw - 24px));
      padding: 16px 18px;
      border-radius: 18px;
      overflow: hidden;
      color: #f8fafc;
      box-shadow:
        0 20px 45px rgba(15, 23, 42, 0.28),
        0 6px 16px rgba(15, 23, 42, 0.2);
      backdrop-filter: blur(14px);
    }

    .app-toast::before {
      content: '';
      position: absolute;
      inset: 0 auto 0 0;
      width: 5px;
      background: rgba(255, 255, 255, 0.9);
    }

    .app-toast[data-variant='success'] {
      background:
        radial-gradient(circle at top left, rgba(110, 231, 183, 0.26), transparent 34%),
        linear-gradient(135deg, #0f766e, #166534 62%, #14532d);
    }

    .app-toast[data-variant='error'] {
      background:
        radial-gradient(circle at top left, rgba(254, 202, 202, 0.22), transparent 34%),
        linear-gradient(135deg, #b91c1c, #be123c 58%, #7f1d1d);
    }

    .app-toast__icon {
      width: 42px;
      height: 42px;
      border-radius: 14px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: rgba(255, 255, 255, 0.16);
      border: 1px solid rgba(255, 255, 255, 0.22);
      font-size: 0.9rem;
      font-weight: 800;
      letter-spacing: 0.04em;
      flex-shrink: 0;
    }

    .app-toast__content {
      min-width: 0;
    }

    .app-toast__title {
      margin-bottom: 4px;
      font-size: 0.95rem;
      font-weight: 800;
      letter-spacing: 0.01em;
      color: #ffffff;
    }

    .app-toast__message {
      font-size: 0.88rem;
      line-height: 1.45;
      color: rgba(248, 250, 252, 0.96);
      word-break: break-word;
    }

    .app-toast__close {
      border: 0;
      background: rgba(255, 255, 255, 0.12);
      color: #ffffff;
      border-radius: 999px;
      padding: 8px 12px;
      font-size: 0.76rem;
      font-weight: 700;
      letter-spacing: 0.02em;
      cursor: pointer;
      transition: background-color 160ms ease, transform 160ms ease;
    }

    .app-toast__close:hover {
      background: rgba(255, 255, 255, 0.2);
      transform: translateY(-1px);
    }

    .app-toast__close:focus-visible {
      outline: 2px solid rgba(255, 255, 255, 0.85);
      outline-offset: 2px;
    }

    @media (max-width: 640px) {
      .app-toast {
        min-width: 0;
        gap: 12px;
        padding: 14px 14px 14px 16px;
        grid-template-columns: auto 1fr;
      }

      .app-toast__close {
        grid-column: 2;
        justify-self: start;
      }
    }
  `]
})
export class AppToastComponent {
  protected readonly data = inject<ToastData>(MAT_SNACK_BAR_DATA);
  private readonly snackBarRef = inject(MatSnackBarRef<AppToastComponent>);

  dismiss(): void {
    this.snackBarRef.dismiss();
  }
}
