/**
 * Enterprise Request State Management Service
 * Handles request tracking, deduplication, and state management
 */
import { Injectable, signal, computed, Signal } from '@angular/core';
import { BehaviorSubject, Observable } from 'rxjs';

export type RequestPriority = 'low' | 'normal' | 'high' | 'blocking';
export type LoaderType = 'none' | 'silent' | 'button' | 'inline' | 'skeleton' | 'page' | 'blocking';

export interface RequestConfig {
  /** Unique request identifier for deduplication */
  requestId?: string;
  
  /** Loader type for this request */
  loaderType?: LoaderType;
  
  /** Priority level for request ordering */
  priority?: RequestPriority;
  
  /** Whether this request should be debounced */
  debounce?: boolean;
  
  /** Debounce delay in ms (default: 300) */
  debounceMs?: number;
  
  /** Whether to skip duplicate request detection */
  skipDedupe?: boolean;
  
  /** Context data for button loaders */
  buttonContext?: string;
  
  /** Whether this is a polling request */
  isPolling?: boolean;
  
  /** Whether this is a background/silent request */
  isBackground?: boolean;
}

export interface ActiveRequest {
  id: string;
  url: string;
  method: string;
  config: RequestConfig;
  startTime: number;
  priority: RequestPriority;
}

export interface RequestState {
  isLoading: boolean;
  activeRequests: ActiveRequest[];
  activeCount: number;
  blockingCount: number;
  pageLoading: boolean;
  buttonLoadingStates: Map<string, boolean>;
}

@Injectable({ providedIn: 'root' })
export class RequestStateService {
  private readonly activeRequests = signal<ActiveRequest[]>([]);
  private readonly buttonStates = signal<Map<string, boolean>>(new Map());
  private readonly inlineStates = signal<Map<string, boolean>>(new Map());
  private readonly requestQueue = new Map<string, BehaviorSubject<boolean>>();
  private readonly completedRequests = new Set<string>();
  
  // Computed signals for reactive state
  readonly activeCount = computed(() => this.activeRequests().length);
  readonly blockingCount = computed(() => 
    this.activeRequests().filter(r => r.priority === 'blocking').length
  );
  readonly pageLoading = computed(() => 
    this.activeRequests().some(r => r.config.loaderType === 'blocking' || r.config.loaderType === 'page')
  );
  
  constructor() {}
  
  /**
   * Start tracking a request
   */
  startRequest(
    url: string, 
    method: string, 
    config: RequestConfig = {}
  ): string {
    const requestId = config.requestId || this.generateRequestId(url, method, config);
    
    // Check for duplicate requests
    if (!config.skipDedupe && this.completedRequests.has(requestId)) {
      return requestId; // Request already completed, skip
    }
    
    const activeRequest: ActiveRequest = {
      id: requestId,
      url,
      method,
      config,
      startTime: Date.now(),
      priority: config.priority || 'normal'
    };
    
    // Add to active requests
    this.activeRequests.update(requests => [...requests, activeRequest]);
    
    // Update button state if applicable
    if (config.loaderType === 'button' && config.buttonContext) {
      this.setButtonLoading(config.buttonContext, true);
    }
    
    // Update inline state if applicable
    if (config.loaderType === 'inline' && config.buttonContext) {
      this.setInlineLoading(config.buttonContext, true);
    }
    
    return requestId;
  }
  
  /**
   * Complete a request
   */
  completeRequest(requestId: string, config: RequestConfig = {}): void {
    // Remove from active requests
    this.activeRequests.update(requests => 
      requests.filter(r => r.id !== requestId)
    );
    
    // Mark as completed for deduplication
    if (!config.skipDedupe) {
      this.completedRequests.add(requestId);
      
      // Clear completed requests after 5 seconds to prevent memory leak
      setTimeout(() => {
        this.completedRequests.delete(requestId);
      }, 5000);
    }
    
    // Update button state if applicable
    if (config.loaderType === 'button' && config.buttonContext) {
      this.setButtonLoading(config.buttonContext, false);
    }
    
    // Update inline state if applicable
    if (config.loaderType === 'inline' && config.buttonContext) {
      this.setInlineLoading(config.buttonContext, false);
    }
    
    // Notify queued requests
    const queueSubject = this.requestQueue.get(requestId);
    if (queueSubject) {
      queueSubject.next(true);
      queueSubject.complete();
      this.requestQueue.delete(requestId);
    }
  }
  
  /**
   * Check if a request should be skipped (duplicate)
   */
  shouldSkipRequest(requestId: string): boolean {
    return this.completedRequests.has(requestId);
  }
  
  /**
   * Get current request state as observable
   */
  getState(): Observable<RequestState> {
    return new Observable<RequestState>(subscriber => {
      // Use effect to track signal changes
      const pollInterval = setInterval(() => {
        subscriber.next({
          isLoading: this.activeCount() > 0,
          activeRequests: this.activeRequests(),
          activeCount: this.activeCount(),
          blockingCount: this.blockingCount(),
          pageLoading: this.pageLoading(),
          buttonLoadingStates: this.buttonStates()
        });
      }, 100);
      
      return () => clearInterval(pollInterval);
    });
  }
  
  /**
   * Check if any blocking requests are active
   */
  isPageLoading(): Signal<boolean> {
    return this.pageLoading;
  }
  
  /**
   * Check if a specific button is loading
   */
  isButtonLoading(context: string): Signal<boolean> {
    return computed(() => this.buttonStates().get(context) ?? false);
  }
  
  /**
   * Check if an inline element is loading
   */
  isInlineLoading(context: string): Signal<boolean> {
    return computed(() => this.inlineStates().get(context) ?? false);
  }
  
  /**
   * Set button loading state
   */
  private setButtonLoading(context: string, loading: boolean): void {
    this.buttonStates.update(states => {
      const newStates = new Map(states);
      newStates.set(context, loading);
      return newStates;
    });
  }
  
  /**
   * Set inline loading state
   */
  private setInlineLoading(context: string, loading: boolean): void {
    this.inlineStates.update(states => {
      const newStates = new Map(states);
      newStates.set(context, loading);
      return newStates;
    });
  }
  
  /**
   * Generate unique request ID
   */
  private generateRequestId(url: string, method: string, config: RequestConfig): string {
    const parts = [method.toLowerCase(), url];
    
    if (config.buttonContext) {
      parts.push(config.buttonContext);
    }
    
    return parts.join(':');
  }
  
  /**
   * Clear all requests (use with caution)
   */
  clearAll(): void {
    this.activeRequests.set([]);
    this.buttonStates.set(new Map());
    this.inlineStates.set(new Map());
    this.completedRequests.clear();
  }
  
  /**
   * Get active requests for debugging
   */
  getActiveRequests(): ActiveRequest[] {
    return this.activeRequests();
  }
}