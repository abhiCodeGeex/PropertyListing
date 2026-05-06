import { Injectable, computed, signal } from '@angular/core';
import { take } from 'rxjs';
import { ChatService } from './chat.service';

@Injectable({ providedIn: 'root' })
export class ChatUnreadService {
  private readonly countSignal = signal(0);

  readonly count = computed(() => this.countSignal());

  constructor(private readonly chatService: ChatService) {}

  refresh(): void {
    this.chatService.getUnreadCount()
      .pipe(take(1))
      .subscribe({
        next: (response) => this.countSignal.set(Math.max(0, Number(response?.count ?? 0))),
        error: () => undefined,
      });
  }
}
