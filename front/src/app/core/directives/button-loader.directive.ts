/**
 * Button Loader Directive
 * Adds inline loading state to buttons with spinner and text changes
 * 
 * Usage: <button appButtonLoader="login" [buttonLoaderText]="'Login'">Login</button>
 */
import {
  Directive,
  ElementRef,
  Input,
  OnDestroy,
  OnInit,
  Renderer2,
  computed,
  inject,
  signal
} from '@angular/core';
import { RequestStateService } from '../services/request-state.service';

export interface ButtonLoaderTexts {
  /** Default button text */
  default: string;
  /** Text to show while loading */
  loading: string;
  /** Optional success text */
  success?: string;
  /** Optional error text */
  error?: string;
}

@Directive({
  selector: '[appButtonLoader]',
  standalone: true,
})
export class ButtonLoaderDirective implements OnInit, OnDestroy {
  private readonly requestState = inject(RequestStateService);
  private readonly renderer = inject(Renderer2);
  private readonly elementRef = inject(ElementRef);
  
  @Input() appButtonLoader!: string; // Button context identifier
  @Input() buttonLoaderText?: string; // Override default text
  @Input() buttonLoaderLoadingText?: string; // Override loading text
  @Input() buttonLoaderSuccessText?: string; // Override success text
  @Input() buttonLoaderErrorText?: string; // Override error text
  @Input() buttonLoaderSuccessDelay = 800; // ms to show success state
  @Input() buttonLoaderErrorDelay = 2000; // ms to show error state
  
  // Internal state
  private readonly loading = signal(false);
  private readonly state = signal<'default' | 'loading' | 'success' | 'error'>('default');
  private originalText = '';
  private unsubscribe?: () => void;
  
  // Computed loading signal
  readonly isLoading = computed(() => this.loading());
  readonly currentState = computed(() => this.state());
  
  ngOnInit(): void {
    const element = this.elementRef.nativeElement as HTMLElement;
    
    // Store original text
    this.originalText = this.buttonLoaderText || element.textContent?.trim() || 'Submit';
    
    // Subscribe to loading state
    const loadingSignal = this.requestState.isButtonLoading(this.appButtonLoader);
    
    // Use effect-like pattern with computed
    let lastValue: boolean | null = null;
    const checkLoading = () => {
      const currentValue = loadingSignal();
      if (currentValue !== lastValue) {
        lastValue = currentValue;
        this.loading.set(currentValue);
        this.updateButtonState(element);
      }
    };
    
    // Initial check
    checkLoading();
    
    // Poll for changes (signals don't have subscribe, so we poll)
    const interval = setInterval(checkLoading, 100);
    
    this.unsubscribe = () => clearInterval(interval);
  }
  
  ngOnDestroy(): void {
    this.unsubscribe?.();
  }
  
  /**
   * Manually set loading state (for non-HTTP operations)
   */
  setLoading(loading: boolean): void {
    this.loading.set(loading);
    this.updateButtonState(this.elementRef.nativeElement);
  }
  
  /**
   * Set success state temporarily
   */
  setSuccess(): void {
    this.state.set('success');
    this.updateButtonState(this.elementRef.nativeElement);
    
    // Return to default after delay
    setTimeout(() => {
      this.state.set('default');
      this.updateButtonState(this.elementRef.nativeElement);
    }, this.buttonLoaderSuccessDelay);
  }
  
  /**
   * Set error state temporarily
   */
  setError(): void {
    this.state.set('error');
    this.updateButtonState(this.elementRef.nativeElement);
    
    // Return to default after delay
    setTimeout(() => {
      this.state.set('default');
      this.updateButtonState(this.elementRef.nativeElement);
    }, this.buttonLoaderErrorDelay);
  }
  
  private updateButtonState(element: HTMLElement): void {
    const loading = this.loading();
    const state = this.state();
    
    // Get texts
    const defaultText = this.originalText;
    const loadingText = this.buttonLoaderLoadingText || `${defaultText}...`;
    const successText = this.buttonLoaderSuccessText || '✓ Done';
    const errorText = this.buttonLoaderErrorText || '✗ Failed';
    
    // Determine current text
    let newText = defaultText;
    if (loading) {
      newText = loadingText;
    } else if (state === 'success') {
      newText = successText;
    } else if (state === 'error') {
      newText = errorText;
    }
    
    // Set text with spinner if loading
    if (loading) {
      element.innerHTML = `
        <span class="btn-loader-wrapper">
          <span class="btn-loader-spinner"></span>
          <span class="btn-loader-text">${newText}</span>
        </span>
      `;
    } else {
      element.textContent = newText;
    }
    
    // Update disabled state
    if (loading) {
      this.renderer.setAttribute(element, 'disabled', 'true');
    } else {
      this.renderer.removeAttribute(element, 'disabled');
    }
    
    // Update ARIA attributes
    this.renderer.setAttribute(element, 'aria-busy', loading ? 'true' : 'false');
    
    // Add/remove loading class
    if (loading) {
      this.renderer.addClass(element, 'btn-loading');
    } else {
      this.renderer.removeClass(element, 'btn-loading');
    }
    
    // Add state-specific classes
    this.renderer.removeClass(element, 'btn-success');
    this.renderer.removeClass(element, 'btn-error');
    
    if (state === 'success') {
      this.renderer.addClass(element, 'btn-success');
    } else if (state === 'error') {
      this.renderer.addClass(element, 'btn-error');
    }
  }
}