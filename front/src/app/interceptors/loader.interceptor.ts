// loader.interceptor.ts
import { HttpContextToken, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { finalize } from 'rxjs';
import { LoaderService } from '../services/loder.service';

export const SKIP_GLOBAL_LOADER = new HttpContextToken<boolean>(() => false);

export const loaderInterceptor: HttpInterceptorFn = (req, next) => {
  const loaderService = inject(LoaderService);
  
  if (
    req.context.get(SKIP_GLOBAL_LOADER) ||
    req.url.includes('/assets') ||
    req.url.includes('.json') ||
    req.url.includes('icon') ||
    req.url.includes('manifest')
  ) {
    return next(req);
  }

  loaderService.show();

  return next(req).pipe(
    finalize(() => loaderService.hide())
  );
};
