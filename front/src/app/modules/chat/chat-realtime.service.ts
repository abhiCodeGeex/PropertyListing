import { DestroyRef, Injectable, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Subject } from 'rxjs';
import { AuthService } from '../../services/auth.service';
import { WebsocketService } from '../../services/websocket.service';
import { ChatService } from './chat.service';
import { ChatRealtimeEvent } from './chat.models';

@Injectable({ providedIn: 'root' })
export class ChatRealtimeService {
  private readonly auth = inject(AuthService);
  private readonly websocket = inject(WebsocketService);
  private readonly chatService = inject(ChatService);
  private readonly destroyRef = inject(DestroyRef);

  private readonly userEventsSubject = new Subject<ChatRealtimeEvent>();
  private readonly chatEventsSubject = new Subject<ChatRealtimeEvent>();
  private readonly channelCleanups = new Map<number, Array<() => void>>();

  private connected = false;
  private userChannelCleanup?: () => void;
  private presenceIntervalId: number | null = null;

  readonly userEvents$ = this.userEventsSubject.asObservable();
  readonly chatEvents$ = this.chatEventsSubject.asObservable();

  connect(): void {
    if (this.connected) {
      return;
    }

    const userId = this.currentUserId();
    if (!userId) {
      return;
    }

    this.websocket.connect(userId);
    this.connected = true;

    this.userChannelCleanup = this.websocket.listen('user.' + userId, '.chat.created', (payload: any) => {
      this.userEventsSubject.next({
        type: 'chat.created',
        payload
      });
    });

    this.refreshPresence();
    this.presenceIntervalId = window.setInterval(() => this.refreshPresence(), 60000);

    this.userEvents$
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({ complete: () => this.dispose() });
  }

  subscribeToChats(chatIds: number[]): void {
    for (const chatId of chatIds) {
      this.subscribeToChat(chatId);
    }
  }

  subscribeToChat(chatId: number): void {
    if (!chatId || this.channelCleanups.has(chatId)) {
      return;
    }

    const cleanups = [
      this.websocket.listen(`chat.${chatId}`, '.chat.updated', (payload: any) => {
        this.chatEventsSubject.next({ type: 'chat.updated', chatId, payload });
      }),
      this.websocket.listen(`chat.${chatId}`, '.message.sent', (payload: any) => {
        this.chatEventsSubject.next({ type: 'message.sent', chatId, payload });
      }),
      this.websocket.listen(`chat.${chatId}`, '.message.read', (payload: any) => {
        this.chatEventsSubject.next({ type: 'message.read', chatId, payload });
      }),
      this.websocket.listen(`chat.${chatId}`, '.user.typing', (payload: any) => {
        this.chatEventsSubject.next({ type: 'user.typing', chatId, payload });
      })
    ];

    this.channelCleanups.set(chatId, cleanups);
  }

  unsubscribeFromChat(chatId: number): void {
    const cleanups = this.channelCleanups.get(chatId) ?? [];
    cleanups.forEach(cleanup => cleanup());
    this.channelCleanups.delete(chatId);
    this.websocket.leavePrivateChannel(`chat.${chatId}`);
  }

  private currentUserId(): number | null {
    const user = this.auth.user();
    return Number(user?.user?.id ?? user?.id ?? 0) || null;
  }

  private refreshPresence(): void {
    this.chatService.markPresenceOnline()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({ error: () => undefined });
  }

  private dispose(): void {
    this.userChannelCleanup?.();
    this.userChannelCleanup = undefined;

    for (const [chatId] of this.channelCleanups) {
      this.unsubscribeFromChat(chatId);
    }

    if (this.presenceIntervalId !== null) {
      window.clearInterval(this.presenceIntervalId);
      this.presenceIntervalId = null;
    }
  }
}
