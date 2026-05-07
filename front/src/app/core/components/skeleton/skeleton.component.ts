/**
 * Skeleton Loader Component
 * Provides animated shimmer effect for content loading states
 */
import { Component, Input, ViewEncapsulation } from '@angular/core';
import { CommonModule } from '@angular/common';

export type SkeletonVariant = 'text' | 'title' | 'avatar' | 'image' | 'card' | 'table-row' | 'button';

@Component({
  selector: 'app-skeleton',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div 
      class="skeleton-wrapper" 
      [class]="variant"
      [style.width]="width"
      [style.height]="height"
      [style.borderRadius]="borderRadius"
      role="progressbar"
      aria-label="Loading content..."
      aria-busy="true">
      <div class="skeleton-shimmer"></div>
    </div>
  `,
  styles: [`
    :host {
      display: inline-block;
    }

    .skeleton-wrapper {
      position: relative;
      overflow: hidden;
      background: linear-gradient(
        90deg,
        #f0f0f0 0%,
        #e0e0e0 20%,
        #f5f5f5 40%,
        #e0e0e0 60%,
        #f0f0f0 100%
      );
      background-size: 200% 100%;
      animation: shimmer 1.5s infinite linear;
    }

    .skeleton-shimmer {
      position: absolute;
      inset: 0;
      background: linear-gradient(
        90deg,
        transparent 0%,
        rgba(255, 255, 255, 0.4) 50%,
        transparent 100%
      );
      animation: shimmer-slide 1.5s infinite ease-in-out;
    }

    @keyframes shimmer {
      0% {
        background-position: 200% 0;
      }
      100% {
        background-position: -200% 0;
      }
    }

    @keyframes shimmer-slide {
      0% {
        transform: translateX(-100%);
      }
      100% {
        transform: translateX(100%);
      }
    }

    /* Variants */
    .text {
      height: 1em;
      border-radius: 4px;
      width: 100%;
    }

    .title {
      height: 1.5em;
      border-radius: 6px;
      width: 60%;
    }

    .avatar {
      border-radius: 50%;
      width: 40px;
      height: 40px;
    }

    .image {
      border-radius: 8px;
      width: 100%;
      height: 200px;
    }

    .card {
      border-radius: 12px;
      width: 100%;
      height: 200px;
    }

    .table-row {
      height: 48px;
      border-radius: 4px;
      width: 100%;
    }

    .button {
      height: 36px;
      border-radius: 6px;
      width: 100px;
    }

    /* Dark mode support */
    @media (prefers-color-scheme: dark) {
      .skeleton-wrapper {
        background: linear-gradient(
          90deg,
          #2a2a2a 0%,
          #3a3a3a 20%,
          #353535 40%,
          #3a3a3a 60%,
          #2a2a2a 100%
        );
      }

      .skeleton-shimmer {
        background: linear-gradient(
          90deg,
          transparent 0%,
          rgba(255, 255, 255, 0.1) 50%,
          transparent 100%
        );
      }
    }
  `],
  encapsulation: ViewEncapsulation.None,
})
export class SkeletonComponent {
  @Input() variant: SkeletonVariant = 'text';
  @Input() width?: string;
  @Input() height?: string;
  @Input() borderRadius?: string;

  get defaultBorderRadius(): string {
    switch (this.variant) {
      case 'avatar': return '50%';
      case 'text': return '4px';
      case 'title': return '6px';
      case 'image': return '8px';
      case 'card': return '12px';
      case 'table-row': return '4px';
      case 'button': return '6px';
      default: return '4px';
    }
  }
}