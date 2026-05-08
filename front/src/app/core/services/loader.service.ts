/**
 * Enterprise Loader Service
 * Intelligent loader management with debouncing, minimum display time, and smooth UX
 */
import { Injectable, signal, computed, Signal, inject } from '@angular/core';
import { RequestStateService, RequestConfig } from './request-state.service';

export interface LoaderConfig {
  /** Delay before showing loader (ms) - prevents flicker for fast requests */
  showDelay?: number;
  
  /** Minimum time to show loader once displayed (ms) - prevents jarring hide */
  minDisplayTime?: number;
  
  /** Whether to use debouncing */
  debounce?: boolean;
  
  /** Debounce delay (ms) */
  debounceMs?: number;
}

@Injectable({ providedIn: 'root' })
export class LoaderService {
  private readonly requestState = inject(RequestStateService);
  
  // Signal-based state
  private readonly visible = signal(false);
  private readonly showDelayTimer = signal<ReturnType<typeof setTimeout> | null>(null);
  private readonly minDisplayTimer = signal<ReturnType<typeof setTimeout> | null>(null);
  private readonly requestCount = signal(0);
  
  // Computed signals
  readonly loading = computed(() => this.visible());
  readonly activeRequests = computed(() => this.requestCount());
  readonly pageLoading = computed(() => this.requestState.isPageLoading());
  
  // Default configuration
  private readonly defaultConfig: LoaderConfig = {
    showDelay: 160,        // Wait 160ms before showing (prevents flicker)
    minDisplayTime: 300,   // Show for at least 300ms once visible
    debounce: true,
    debounceMs: 50
  };
  
  constructor() {}
  
  /**
   * Show global loader with intelligent delay
   */
  show(config: LoaderConfig = {}): void {
    const finalConfig = { ...this.defaultConfig, ...config };
    
    // Clear any pending hide timer
    this.clearMinDisplayTimer();
    
    // Increment request count
    this.requestCount.update(c => c + 1);
    
    // If already visible, no need to show again
    if (this.visible()) {
      return;
    }
    
    // Clear any existing show delay timer
    this.clearShowDelayTimer();
    
    // Set up delayed showing (prevents flicker for fast requests)
    const timer = setTimeout(() => {
      if (this.requestCount() > 0) {
        this.visible.set(true);
      }
      this.showDelayTimer.set(null);
    }, finalConfig.showDelay);
    
    this.showDelayTimer.set(timer);
  }
  
  /**
   * Hide global loader with minimum display time
   */
  hide(config: LoaderConfig = {}): void {
    const finalConfig = { ...this.defaultConfig, ...config };
    
    // Decrement request count
    this.requestCount.update(c => Math.max(c - 1, 0));
    const count = this.requestCount();
    
    // If there are still active requests, don't hide
    if (count > 0) {
      return;
    }
    
    // Clear show delay timer (request completed before showing)
    this.clearShowDelayTimer();
    
    // If not visible, nothing to hide
    if (!this.visible()) {
      return;
    }
    
    // If minimum display time is set, wait before hiding
    if (finalConfig.minDisplayTime && finalConfig.minDisplayTime > 0) {
      const timer = setTimeout(() => {
        this.visible.set(false);
        this.minDisplayTimer.set(null);
      }, finalConfig.minDisplayTime);
      
      this.minDisplayTimer.set(timer);
    } else {
      this.visible.set(false);
    }
  }
  
  /**
   * Force hide immediately (use for errors/cancellation)
   */
  forceHide(): void {
    this.clearShowDelayTimer();
    this.clearMinDisplayTimer();
    this.requestCount.set(0);
    this.visible.set(false);
  }
  
  /**
   * Check if page-level loading is active
   */
  isPageLoading(): Signal<boolean> {
    return computed(() => this.requestState.isPageLoading()());
  }
  
  /**
   * Set loader visibility directly (for special cases)
   */
  setVisible(visible: boolean): void {
    this.clearShowDelayTimer();
    this.clearMinDisplayTimer();
    this.visible.set(visible);
  }
  
  /**
   * Get current loader state
   */
  getState(): { visible: boolean; requestCount: number; pageLoading: boolean } {
    return {
      visible: this.visible(),
      requestCount: this.requestCount(),
      pageLoading: this.isPageLoading()()
    };
  }
  
  private clearShowDelayTimer(): void {
    const timer = this.showDelayTimer();
    if (timer) {
      clearTimeout(timer);
      this.showDelayTimer.set(null);
    }
  }
  
  private clearMinDisplayTimer(): void {
    const timer = this.minDisplayTimer();
    if (timer) {
      clearTimeout(timer);
      this.minDisplayTimer.set(null);
    }
  }
}