import { Injectable, signal, computed } from '@angular/core';

export interface AppNotification {
  id: number;
  title: string;
  type: string;
  message: string;
  read_at: string | null;
  created_at: string;
}

@Injectable({ providedIn: 'root' })
export class NotificationStore {
  private readonly _notifications = signal<AppNotification[]>([]);

  readonly notificationsSignal = computed(() => this._notifications());

  readonly unreadCount = computed(() =>
    this._notifications().filter(n => !n.read_at).length
  );

  set(list: AppNotification[]): void {
    this._notifications.set(this.normalize(list));
  }

  append(list: AppNotification[]): void {
    this._notifications.set(this.normalize([...this._notifications(), ...list]));
  }

  prepend(notification: AppNotification): void {
    this._notifications.set(this.normalize([notification, ...this._notifications()]));
  }

  markRead(id: number): void {
    this._notifications.update(list =>
      list.map(n =>
        n.id === id ? { ...n, read_at: new Date().toISOString() } : n
      )
    );
  }

  markAllRead(): void {
    const readAt = new Date().toISOString();
    this._notifications.update(list =>
      list.map(n => ({ ...n, read_at: n.read_at ?? readAt }))
    );
  }

  clear(): void {
    this._notifications.set([]);
  }

  private normalize(list: AppNotification[]): AppNotification[] {
    const deduped = new Map<number, AppNotification>();

    for (const notification of list) {
      if (notification?.id === undefined || notification?.id === null) {
        continue;
      }

      deduped.set(Number(notification.id), {
        ...notification,
        id: Number(notification.id),
        title: notification.title ?? '',
        type: notification.type ?? '',
        message: notification.message ?? '',
        read_at: notification.read_at ?? null,
        created_at: notification.created_at ?? new Date().toISOString(),
      });
    }

    return Array.from(deduped.values())
      .sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime());
  }
}
