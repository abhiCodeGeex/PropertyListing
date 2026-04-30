import { HttpErrorResponse } from '@angular/common/http';

/** Angular default when response can't be parsed or CORS blocks body */
const HTTP_FAILURE_PREFIX = /^Http failure response for/i;
const UNKNOWN_HTTP_MESSAGE = /^0 Unknown Error$/i;

function firstValidationMessage(errors: unknown): string | null {
  if (!errors || typeof errors !== 'object') {
    return null;
  }

  for (const value of Object.values(errors as Record<string, unknown>)) {
    if (Array.isArray(value)) {
      const found = value.find((x): x is string => typeof x === 'string' && x.trim() !== '');
      if (found) {
        return found.trim();
      }
    } else if (typeof value === 'string' && value.trim() !== '') {
      return value.trim();
    }
  }

  return null;
}

function isLikelyHtml(s: string): boolean {
  return /<(!doctype\s+html|html|body|head)[\s>]/i.test(s);
}

function stripeClientMessage(err: unknown): string | null {
  if (!err || typeof err !== 'object') {
    return null;
  }

  const message = (err as { message?: unknown }).message;

  return typeof message === 'string' && message.trim() !== '' ? message.trim() : null;
}

/**
 * Turns Laravel/Stripe HTTP errors and Stripe.js client errors into a single user-facing string.
 * Avoids exposing raw Angular transport text, HTML error pages, and huge JSON blobs.
 */
export function extractApiErrorMessage(error: unknown, fallback: string): string {
  if (typeof error === 'string') {
    const t = error.trim();

    return t && !isLikelyHtml(t) ? t : fallback;
  }

  const stripeMsg = stripeClientMessage(error);

  if (
    stripeMsg
    && !HTTP_FAILURE_PREFIX.test(stripeMsg)
    && !UNKNOWN_HTTP_MESSAGE.test(stripeMsg)
  ) {
    return stripeMsg;
  }

  /** Laravel JSON body or nested `error` payloads (not an HttpErrorResponse wrapper). */
  if (
    error
    && typeof error === 'object'
    && !(error instanceof HttpErrorResponse)
  ) {
    const o = error as Record<string, unknown>;

    if ('errors' in o || 'message' in o || 'reason' in o) {
      const fromValidation = firstValidationMessage(o['errors']);

      if (fromValidation) {
        return fromValidation;
      }

      const reason = typeof o['reason'] === 'string' ? o['reason'].trim() : '';
      const message = typeof o['message'] === 'string' ? o['message'].trim() : '';

      if (reason) {
        return reason;
      }

      if (message) {
        return message;
      }
    }

    if ('error' in o) {
      return extractApiErrorMessage(o['error'], fallback);
    }
  }

  if (error instanceof HttpErrorResponse) {
    const body = error.error;

    if (typeof body === 'string') {
      const t = body.trim();

      if (t && !isLikelyHtml(t)) {
        try {
          const parsed: unknown = JSON.parse(t);

          return extractApiErrorMessage(parsed, fallback);
        } catch {
          return t.length > 400 ? fallback : t;
        }
      }

      if (error.status === 0) {
        return 'Network error. Check your connection and try again.';
      }

      return fallback;
    }

    if (body && typeof body === 'object') {
      const o = body as Record<string, unknown>;
      const fromValidation = firstValidationMessage(o['errors']);

      if (fromValidation) {
        return fromValidation;
      }

      const reason = typeof o['reason'] === 'string' ? o['reason'].trim() : '';
      const message = typeof o['message'] === 'string' ? o['message'].trim() : '';

      if (reason) {
        return reason;
      }

      if (message) {
        return message;
      }
    }

    const transport = (error.message ?? '').trim();

    if (
      transport
      && !HTTP_FAILURE_PREFIX.test(transport)
      && !UNKNOWN_HTTP_MESSAGE.test(transport)
    ) {
      return transport;
    }

    if (error.status === 0) {
      return 'Network error. Check your connection and try again.';
    }

    if (error.status === 503) {
      return 'Service temporarily unavailable. Please try again shortly.';
    }

    if (error.status === 404) {
      return 'The requested resource was not found.';
    }

    if (error.status === 403) {
      return 'You do not have permission to perform this action.';
    }

    return fallback;
  }

  return fallback;
}
