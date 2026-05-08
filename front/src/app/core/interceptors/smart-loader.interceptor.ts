/**
 * Smart Loader Interceptor
 * Intelligently routes requests to appropriate loader types
 * Prevents global loader for small/fast operations
 */
import { HttpInterceptorFn, HttpRequest, HttpHandlerFn, HttpContextToken } from '@angular/common/http';
import { inject } from '@angular/core';
import { finalize, tap } from 'rxjs';
import { RequestStateService, RequestConfig, LoaderType, RequestPriority } from '../services/request-state.service';
import { LoaderService } from '../services/loader.service';

/**
 * Context token to explicitly skip all loaders
 */
export const SKIP_LOADER = new HttpContextToken<boolean>(() => false);

/**
 * Context token to specify loader type
 */
export const LOADER_TYPE = new HttpContextToken<LoaderType>(() => 'page');

/**
 * Context token for button loader context
 */
export const LOADER_CONTEXT = new HttpContextToken<string>(() => '');

/**
 * Context token for request priority
 */
export const REQUEST_PRIORITY = new HttpContextToken<RequestPriority>(() => 'normal');

/**
 * URLs that should never trigger global loader
 */
const SILENT_URL_PATTERNS = [
  /\/api\/v1\/chat\//i,           // Chat endpoints
  /\/api\/v1\/presence/i,         // Presence updates
  /\/api\/v1\/typing/i,           // Typing indicators
  /\/api\/v1\/unread/i,           // Unread counts
  /\/api\/v1\/notifications/i,    // Notifications polling
  /\/api\/v1\/ping/i,             // Health checks
  /\/api\/v1\/heartbeat/i,        // Heartbeat
  /\.json$/i,                      // JSON files
  /\/assets\//i,                   // Assets
  /manifest/i,                     // Manifest files
  /icon/i,                         // Icons
];

/**
 * URLs that should use skeleton loaders instead of global
 */
const SKELETON_URL_PATTERNS = [
  /\/api\/v1\/users/i,            // User lists
  /\/api\/v1\/properties/i,       // Property lists
  /\/api\/v1\/maintenance/i,      // Maintenance requests
  /\/api\/v1\/invoices/i,         // Invoice lists
  /\/api\/v1\/reports/i,          // Report data
];

/**
 * URLs that should use button loaders
 */
const BUTTON_URL_PATTERNS = [
  /\/api\/v1\/login/i,            // Login
  /\/api\/v1\/register/i,         // Registration
  /\/api\/v1\/profile/i,          // Profile updates
  /\/api\/v1\/settings/i,         // Settings updates
  /\/api\/v1\/upload/i,           // File uploads
  /\/api\/v1\/otp/i,              // OTP operations
];

/**
 * Determine the appropriate loader type for a request
 */
function determineLoaderType(request: HttpRequest<unknown>): LoaderType {
  const url = request.url;
  const method = request.method;
  
  // Check explicit context token first
  const explicitType = request.context.get(LOADER_TYPE);
  if (explicitType !== 'page') {
    return explicitType;
  }
  
  // Check if should skip loader entirely
  if (request.context.get(SKIP_LOADER)) {
    return 'none';
  }
  
  // POST/PUT/PATCH/DELETE are typically actions -> button loader
  if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
    // But not for chat messages
    if (!url.includes('/chat/')) {
      return 'button';
    }
  }
  
  // Check for silent URLs
  if (SILENT_URL_PATTERNS.some(pattern => pattern.test(url))) {
    return 'none';
  }
  
  // Check for skeleton URLs (lists/tables)
  if (SKELETON_URL_PATTERNS.some(pattern => pattern.test(url))) {
    return 'skeleton';
  }
  
  // Check for button URLs (form submissions)
  if (BUTTON_URL_PATTERNS.some(pattern => pattern.test(url))) {
    return 'button';
  }
  
  // GET requests for data loading -> skeleton or none
  if (method === 'GET') {
    // Initial page loads might need skeleton
    if (url.includes('/api/v1/')) {
      return 'skeleton';
    }
    return 'none';
  }
  
  // Default: use skeleton for data operations
  return 'skeleton';
}

/**
 * Get button context from URL for button loaders
 */
function getButtonContext(request: HttpRequest<unknown>): string {
  const explicitContext = request.context.get(LOADER_CONTEXT);
  if (explicitContext) {
    return explicitContext;
  }
  
  // Extract meaningful context from URL
  const url = request.url;
  const parts = url.split('/').filter(Boolean);
  
  // Get last meaningful parts
  const endpoint = parts.slice(-2).join('-');
  return endpoint || 'action';
}

/**
 * Get priority from request
 */
function getPriority(request: HttpRequest<unknown>): RequestPriority {
  const explicitPriority = request.context.get(REQUEST_PRIORITY);
  if (explicitPriority !== 'normal') {
    return explicitPriority;
  }
  
  // Blocking operations
  if (request.url.includes('/auth/') || request.url.includes('/login')) {
    return 'blocking';
  }
  
  // High priority for critical operations
  if (request.method === 'POST' && !request.url.includes('/chat/')) {
    return 'high';
  }
  
  return 'normal';
}

/**
 * Smart loader interceptor
 */
export const smartLoaderInterceptor: HttpInterceptorFn = (
  request: HttpRequest<unknown>,
  next: HttpHandlerFn
) => {
  const requestState = inject(RequestStateService);
  const loaderService = inject(LoaderService);
  
  // Determine loader type
  const loaderType = determineLoaderType(request);
  
  // Skip all loading for 'none' type
  if (loaderType === 'none') {
    return next(request);
  }
  
  // Build request config
  const config: RequestConfig = {
    loaderType,
    priority: getPriority(request),
    buttonContext: getButtonContext(request),
    skipDedupe: request.url.includes('/chat/') || request.url.includes('/presence/'),
  };
  
  // Start tracking request
  const requestId = requestState.startRequest(request.url, request.method, config);
  
  // For blocking/page loaders, show global loader
  if (loaderType === 'blocking' || loaderType === 'page') {
    loaderService.show();
  }
  
  // Pass request through
  return next(request).pipe(
    finalize(() => {
      // Complete tracking
      requestState.completeRequest(requestId, config);
      
      // Hide global loader if shown
      if (loaderType === 'blocking' || loaderType === 'page') {
        loaderService.hide();
      }
    })
  );
};