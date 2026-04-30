import { DestroyRef, Injectable, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Subject } from 'rxjs';
import { AuthService } from '../../services/auth.service';
import { WebsocketService } from '../../services/websocket.service';
import { MaintenanceRealtimePayload } from './maintenance.models';

export interface MaintenanceRealtimeEvent {
  type: 'request.created' | 'request.updated' | 'comment.added';
  payload: MaintenanceRealtimePayload;
}

@Injectable({ providedIn: 'root' })
export class MaintenanceModuleWebsocketService {
  private readonly auth = inject(AuthService);
  private readonly websocket = inject(WebsocketService);
  private readonly destroyRef = inject(DestroyRef);

  private readonly eventSubject = new Subject<MaintenanceRealtimeEvent>();
  private subscribed = false;

  readonly events$ = this.eventSubject.asObservable();

  connect(): void {
    if (this.subscribed) {
      return;
    }

    const user = this.auth.user();
    const userId = user?.user?.id ?? user?.id ?? null;

    if (!userId) {
      return;
    }

    this.websocket.connect(userId);
    this.subscribed = true;

    const cleanups = [
      this.websocket.listenToPrivateChannel(
        `user.${userId}`,
        '.request.created',
        (payload: MaintenanceRealtimePayload) => {
          this.eventSubject.next({ type: 'request.created', payload });
        }
      ),
      this.websocket.listenToPrivateChannel(
        `user.${userId}`,
        '.request.updated',
        (payload: MaintenanceRealtimePayload) => {
          this.eventSubject.next({ type: 'request.updated', payload });
        }
      ),
      this.websocket.listenToPrivateChannel(
        `user.${userId}`,
        '.comment.added',
        (payload: MaintenanceRealtimePayload) => {
          this.eventSubject.next({ type: 'comment.added', payload });
        }
      )
    ];

    this.events$
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({ complete: () => cleanups.forEach(cleanup => cleanup()) });
  }
}
