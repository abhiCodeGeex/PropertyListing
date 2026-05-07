/**
 * Inline Loader Component
 * Small inline spinner for contextual loading states
 */
import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

export type InlineLoaderSize = 'xs' | 'sm' | 'md' | 'lg';

@Component({
  selector: 'app-inline-loader',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div 
      class="inline-loader" 
      [class]="'size-' + size"
      [class.inline]="inline"
      role="progressbar"
      aria-label="Loading..."
      aria-busy="true">
      <div class="inline-loader__spinner"></div>
      @if (label) {
        <span class="inline-loader__label">{{ label }}</span>
      }
    </div>
  `,
  styles: [`
    .inline-loader {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: #64748b;
    }

    .inline-loader.inline {
      display: inline-flex;
    }

    .inline-loader__spinner {
      border: 2px solid currentColor;
      border-right-color: transparent;
      border-radius: 50%;
      animation: spin 0.75s linear infinite;
    }

    .inline-loader__label {
      font-size: 0.875rem;
      line-height: 1;
    }

    /* Sizes */
    .size-xs .inline-loader__spinner {
      width: 12px;
      height: 12px;
      border-width: 1.5px;
    }

    .size-xs .inline-loader__label {
      font-size: 0.75rem;
    }

    .size-sm .inline-loader__spinner {
      width: 16px;
      height: 16px;
    }

    .size-sm .inline-loader__label {
      font-size: 0.813rem;
    }

    .size-md .inline-loader__spinner {
      width: 20px;
      height: 20px;
      border-width: 2.5px;
    }

    .size-md .inline-loader__label {
      font-size: 0.875rem;
    }

    .size-lg .inline-loader__spinner {
      width: 28px;
      height: 28px;
      border-width: 3px;
    }

    .size-lg .inline-loader__label {
      font-size: 1rem;
    }

    @keyframes spin {
      to {
        transform: rotate(360deg);
      }
    }

    /* Color variants */
    .inline-loader[data-color="primary"] {
      color: #3b82f6;
    }

    .inline-loader[data-color="success"] {
      color: #22c55e;
    }

    .inline-loader[data-color="warning"] {
      color: #f59e0b;
    }

    .inline-loader[data-color="error"] {
      color: #ef4444;
    }
  `]
})
export class InlineLoaderComponent {
  @Input() size: InlineLoaderSize = 'sm';
  @Input() label?: string;
  @Input() inline = false;
  @Input() color?: 'primary' | 'success' | 'warning' | 'error';
}