import { HttpClient } from '@angular/common/http';
import { Component, DestroyRef, inject, OnDestroy, OnInit, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Title } from '@angular/platform-browser';
import { ActivatedRoute, NavigationCancel, NavigationEnd, NavigationError, NavigationStart, Router, RouterOutlet } from '@angular/router';
import { catchError, of } from 'rxjs';
import { delay, filter, map, tap } from 'rxjs/operators';

import { ColorModeService } from '@coreui/angular';
import { IconSetService } from '@coreui/icons-angular';
import { iconSubset } from './icons/icon-subset';
import { FormsModule, ReactiveFormsModule } from '@angular/forms';
import { WebsocketService } from './services/websocket.service';
import { environment } from '../environments/environment';
import { setAppCurrencyConfig } from './shared/utils/currency.util';

@Component({
  selector: 'app-root',
  template: `
    @if (navigationLoading()) {
      <div class="page-transition-loader" aria-hidden="true"></div>
    }
    <router-outlet />
  `,
  imports: [RouterOutlet, ReactiveFormsModule, FormsModule]
})
export class AppComponent implements OnInit, OnDestroy {
  title = 'Property Listing';
  readonly navigationLoading = signal(false);
  private navigationLoaderTimer: ReturnType<typeof setTimeout> | null = null;

  readonly #destroyRef: DestroyRef = inject(DestroyRef);
  readonly #activatedRoute: ActivatedRoute = inject(ActivatedRoute);
  readonly #router = inject(Router);
  readonly #titleService = inject(Title);

  readonly #colorModeService = inject(ColorModeService);
  readonly #iconSetService = inject(IconSetService);
  readonly #websocket = inject(WebsocketService);
  readonly #http = inject(HttpClient);
  constructor() {
    this.#titleService.setTitle(this.title);
    // iconSet singleton
    this.#iconSetService.icons = { ...iconSubset };
    this.#colorModeService.localStorageItemName.set('coreui-free-angular-admin-template-theme-default');
    this.#colorModeService.eventName.set('ColorSchemeChange');
  }

  ngOnInit(): void {
    this.#http
      .get<{ currency: { code: string; symbol: string; locale: string } }>(
        `${environment.apiUrl}/public-config`
      )
      .pipe(
        takeUntilDestroyed(this.#destroyRef),
        catchError(() => of(null))
      )
      .subscribe((res) => {
        if (res?.currency) {
          setAppCurrencyConfig(res.currency);
        }
      });

    const user = JSON.parse(localStorage.getItem('user') || 'null');
    const userId = user?.user?.id ?? user?.id ?? null;

    if (userId) {
      this.#websocket.connect(userId);
    }
    this.#router.events.pipe(
      takeUntilDestroyed(this.#destroyRef)
    ).subscribe((evt) => {
      if (evt instanceof NavigationStart) {
        this.clearNavigationLoaderTimer();
        this.navigationLoading.set(true);
        return;
      }

      if (evt instanceof NavigationEnd || evt instanceof NavigationCancel || evt instanceof NavigationError) {
        // keep the indicator visible briefly to avoid flicker on fast route changes
        this.clearNavigationLoaderTimer();
        this.navigationLoaderTimer = setTimeout(() => {
          this.navigationLoading.set(false);
          this.navigationLoaderTimer = null;
        }, 220);
      }
    });

    this.#activatedRoute.queryParams
      .pipe(
        delay(1),
        map(params => <string>params['theme']?.match(/^[A-Za-z0-9\s]+/)?.[0]),
        filter(theme => ['dark', 'light', 'auto'].includes(theme)),
        tap(theme => {
          this.#colorModeService.colorMode.set(theme);
        }),
        takeUntilDestroyed(this.#destroyRef)
      )
      .subscribe();
  }

  ngOnDestroy(): void {
    this.clearNavigationLoaderTimer();
  }

  private clearNavigationLoaderTimer(): void {
    if (this.navigationLoaderTimer) {
      clearTimeout(this.navigationLoaderTimer);
      this.navigationLoaderTimer = null;
    }
  }
}
