import { CommonModule } from '@angular/common';
import {
  Component,
  computed,
  inject,
  input,
  OnInit,
  signal,
  DestroyRef,
  HostListener
} from '@angular/core';
import { Router, RouterLink, RouterLinkActive } from '@angular/router';
import { catchError, forkJoin, of } from 'rxjs';

import {
  AvatarComponent,
  ColorModeService,
  ContainerComponent,
  DropdownComponent,
  DropdownItemDirective,
  DropdownMenuDirective,
  DropdownToggleDirective,
  HeaderComponent,
  HeaderNavComponent,
  HeaderTogglerDirective,
  NavItemComponent,
  NavLinkDirective,
  SidebarToggleDirective
} from '@coreui/angular';

import { IconDirective } from '@coreui/icons-angular';
import { AuthService } from '../../../services/auth.service';
import { environment } from '../../../../environments/environment';
import { NotificationStore } from '../../../services/notification.store';
import { UsersService } from '../../../services/users.service';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ToasterService } from '../../../services/toaster.service';
import { ProfileService } from '../../../services/profile.service';

@Component({
  selector: 'app-default-header',
  standalone: true,
  templateUrl: './default-header.component.html',
  styleUrl: './default-header.component.scss',
  imports: [
    CommonModule,
    ContainerComponent,
    HeaderTogglerDirective,
    SidebarToggleDirective,
    IconDirective,
    HeaderNavComponent,
    NavItemComponent,
    NavLinkDirective,
    RouterLink,
    RouterLinkActive,
    DropdownComponent,
    DropdownToggleDirective,
    AvatarComponent,
    DropdownMenuDirective,
    DropdownItemDirective
  ]
})
export class DefaultHeaderComponent extends HeaderComponent implements OnInit {
  private readonly pageSize = 10;

  private readonly colorModeService = inject(ColorModeService);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly userService = inject(UsersService);
  readonly notificationStore = inject(NotificationStore);
  private readonly destroyRef = inject(DestroyRef);
  private readonly toast = inject(ToasterService);
  private readonly profileService = inject(ProfileService);

  sidebarId = input('sidebar1');

  /* -------------------- UI STATE -------------------- */

  readonly colorMode = this.colorModeService.colorMode;

  readonly colorModes = [
    { name: 'light', icon: 'cilSun' },
    { name: 'dark', icon: 'cilMoon' },
    { name: 'auto', icon: 'cilContrast' }
  ];

  readonly activeThemeIcon = computed(() =>
    this.colorModes.find(m => m.name === this.colorMode())?.icon ?? 'cilSun'
  );

  /* -------------------- PROFILE -------------------- */

  private readonly profileSignal = signal<any>(
    JSON.parse(localStorage.getItem('profile') || '{}')
  );

  readonly profileImage = computed(() => {
    const img = this.profileSignal()?.profile_image;
    return img
      ? `${environment.backendUrl}/storage/${img}`
      : 'https://www.gravatar.com/avatar?d=mp';
  });

  readonly roles = signal<string[]>(
    JSON.parse(localStorage.getItem('roles') || '[]')
  );

  /* -------------------- NOTIFICATIONS -------------------- */

  readonly notifications = this.notificationStore.notificationsSignal;
  readonly unreadCount = this.notificationStore.unreadCount;
  readonly hasNotifications = computed(() => this.notifications().length > 0);
  readonly readCount = computed(() =>
    this.notifications().filter(notification => !!notification.read_at).length
  );
  readonly isNotificationMenuOpen = signal(false);
  readonly isLoadingMore = signal(false);
  readonly hasMoreNotifications = signal(true);
  private currentPage = 1;

  /* -------------------- INIT -------------------- */

  ngOnInit(): void {
    this.profileService.profile$
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(profile => {
        this.profileSignal.set(profile ?? {});
      });

    this.loadNotifications();
  }


  /* -------------------- ACTIONS -------------------- */

  onImageError(event: Event): void {
    (event.target as HTMLImageElement).src =
      'https://www.gravatar.com/avatar?d=mp';
  }

  logout(): void {
    this.auth.logout();
    this.router.navigateByUrl('/login');
  }

  toggleNotificationMenu(): void {
    this.isNotificationMenuOpen.update(open => !open);
  }

  closeNotificationMenu(): void {
    this.isNotificationMenuOpen.set(false);
  }

  openNotification(notification: any): void {
    if (!notification.read_at) {
      this.notificationStore.markRead(notification.id);
      this.userService
        .markNotificationRead(notification.id)
        .pipe(takeUntilDestroyed(this.destroyRef))
        .subscribe({ error: () => console.warn('Read sync failed') });
    }

    this.closeNotificationMenu();
    this.router.navigateByUrl(this.notificationRoute(notification));
  }

  onNotificationScroll(event: Event): void {
    const element = event.target as HTMLElement | null;
    if (!element || this.isLoadingMore() || !this.hasMoreNotifications()) {
      return;
    }

    const distanceToBottom = element.scrollHeight - element.scrollTop - element.clientHeight;
    if (distanceToBottom > 80) {
      return;
    }

    this.loadNotifications(this.currentPage + 1, true);
  }

  markAllAsRead(): void {
    const unread = this.notifications().filter(n => !n.read_at);
    if (!unread.length) return;

    this.notificationStore.markAllRead();

    forkJoin(
      unread.map(notification =>
        this.userService.markNotificationRead(notification.id)
          .pipe(catchError(() => of(null)))
      )
    )
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: results => {
          const failed = results.filter(result => result === null).length;

          if (failed > 0) {
            this.toast.showError('Some notifications could not be updated.');
            return;
          }

          this.toast.showSuccess('Notifications marked as read.');
        },
        error: () => this.toast.showError('Some notifications could not be updated.')
      });
  }

  @HostListener('document:click', ['$event'])
  onDocumentClick(event: MouseEvent): void {
    const target = event.target as HTMLElement | null;

    if (!target?.closest('.notification-dropdown')) {
      this.closeNotificationMenu();
    }
  }

  @HostListener('document:keydown.escape')
  onEscapeKey(): void {
    this.closeNotificationMenu();
  }

  notificationSummary(): string {
    if (!this.hasNotifications()) {
      return 'Your workspace activity will appear here as payments, approvals, and agreements change.';
    }

    if (this.unreadCount() === 0) {
      return 'Everything is up to date. Review recent activity or open an item for detail.';
    }

    return `${this.unreadCount()} unread update${this.unreadCount() === 1 ? '' : 's'} require attention.`;
  }

  private loadNotifications(page: number = 1, append: boolean = false): void {
    if (append) {
      this.isLoadingMore.set(true);
    }

    this.userService.getNotifications(page, this.pageSize)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: response => {
          const payload = Array.isArray(response) ? response : response?.data ?? [];
          const mapped = payload.map((n: any) => this.mapNotification(n));

          if (append) {
            this.notificationStore.append(mapped);
          } else {
            this.notificationStore.set(mapped);
          }

          this.currentPage = Array.isArray(response) ? 1 : Number(response?.current_page ?? page);
          const lastPage = Array.isArray(response) ? 1 : Number(response?.last_page ?? this.currentPage);
          this.hasMoreNotifications.set(this.currentPage < lastPage);
          this.isLoadingMore.set(false);
        },
        error: () => {
          this.isLoadingMore.set(false);
          this.toast.showError('Failed to load notifications');
        }
      });
  }

  private mapNotification(n: any): any {
    return {
      id: n.id ?? n.notifiable_id,
      title: n.title ?? '',
      message: n.message ?? '',
      type: n.type ?? '',
      read_at: n.read_at ?? (n.is_read ? new Date().toISOString() : null),
      created_at: n.created_at ?? new Date().toISOString(),
      ...n
    };
  }

  notificationCategory(notification: any): string {
    return ({
      chat_message: 'Chat Message',
      rent_deposit: 'Rent Payment',
      security_deposit: 'Security Deposit',
      overdue: 'Overdue Rent',
      subscription: 'Subscription',
      payment_failed: 'Payment Failure',
      invoice: 'Invoice',
      property: 'Property Update',
      maintenance_request: 'Maintenance Request',
      maintenance_status: 'Maintenance Update',
      maintenance_comment: 'Maintenance Comment',
      agreement: 'Agreement',
      rent_due: 'Rent Reminder',
      rent_overdue: 'Rent Overdue',
      signup: 'Account'
    } as Record<string, string>)[notification.type] ?? 'Notification';
  }

  notificationGlyph(notification: any): string {
    return ({
      chat_message: 'CM',
      rent_deposit: 'RP',
      security_deposit: 'SD',
      overdue: 'OD',
      subscription: 'SB',
      payment_failed: 'PF',
      invoice: 'IN',
      property: 'PR',
      maintenance_request: 'MR',
      maintenance_status: 'MS',
      maintenance_comment: 'MC',
      agreement: 'AG',
      rent_due: 'RD',
      rent_overdue: 'RO',
      signup: 'AC'
    } as Record<string, string>)[notification.type] ?? 'NT';
  }

  notificationTone(notification: any): string {
    return ({
      chat_message: 'primary',
      rent_deposit: 'success',
      security_deposit: 'primary',
      overdue: 'warning',
      subscription: 'primary',
      payment_failed: 'danger',
      invoice: 'success',
      property: 'neutral',
      maintenance_request: 'warning',
      maintenance_status: 'primary',
      maintenance_comment: 'warning',
      agreement: 'warning',
      rent_due: 'warning',
      rent_overdue: 'danger',
      signup: 'success'
    } as Record<string, string>)[notification.type] ?? 'neutral';
  }

  notificationTime(notification: any): string {
    const createdAt = notification?.created_at ? new Date(notification.created_at) : null;

    if (!createdAt || Number.isNaN(createdAt.getTime())) {
      return 'Just now';
    }

    const diffMs = Date.now() - createdAt.getTime();
    const diffMinutes = Math.max(0, Math.floor(diffMs / 60000));

    if (diffMinutes < 1) return 'Just now';
    if (diffMinutes < 60) return `${diffMinutes}m ago`;

    const diffHours = Math.floor(diffMinutes / 60);
    if (diffHours < 24) return `${diffHours}h ago`;

    const diffDays = Math.floor(diffHours / 24);
    if (diffDays < 7) return `${diffDays}d ago`;

    return createdAt.toLocaleDateString(undefined, {
      day: 'numeric',
      month: 'short',
      year: createdAt.getFullYear() === new Date().getFullYear() ? undefined : 'numeric'
    });
  }

  private notificationRoute(notification: any): string {
    if (['rent_deposit', 'security_deposit', 'overdue', 'payment_failed', 'rent_due', 'rent_overdue', 'invoice'].includes(notification.type)) {
      return '/rent-history';
    }

    if (notification.type === 'property') {
      return '/properties';
    }

    if (notification.type === 'chat_message') {
      const chatId = Number(notification?.notifiable_id ?? 0);

      return chatId ? `/chat/${chatId}` : '/chat';
    }

    if (['maintenance_request', 'maintenance_status', 'maintenance_comment'].includes(notification.type)) {
      const requestId = notification?.notifiable_id;

      return requestId ? `/maintenance/requests/${requestId}` : '/maintenance/requests';
    }

    if (notification.type === 'signup') {
      return '/profile';
    }

    return '/dashboard';
  }
}
