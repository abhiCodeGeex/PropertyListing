// src/app/services/loader.service.ts
import { Injectable, signal, computed } from '@angular/core';

@Injectable({ providedIn: 'root' })
export class LoaderService {
  private readonly requestCount = signal(0);
  private readonly visible = signal(false);
  private showDelayTimer: ReturnType<typeof setTimeout> | null = null;

  readonly loading = computed(() => this.visible());
  readonly activeRequests = computed(() => this.requestCount());

  show(): void {
    const nextCount = this.requestCount() + 1;
    this.requestCount.set(nextCount);

    if (nextCount !== 1) {
      return;
    }

    this.clearDelayTimer();
    this.showDelayTimer = setTimeout(() => {
      if (this.requestCount() > 0) {
        this.visible.set(true);
      }
      this.showDelayTimer = null;
    }, 160);
  }

  hide(): void {
    const nextCount = Math.max(this.requestCount() - 1, 0);
    this.requestCount.set(nextCount);

    if (nextCount > 0) {
      return;
    }

    this.clearDelayTimer();
    this.visible.set(false);
  }

  private clearDelayTimer(): void {
    if (!this.showDelayTimer) {
      return;
    }

    clearTimeout(this.showDelayTimer);
    this.showDelayTimer = null;
  }
}
