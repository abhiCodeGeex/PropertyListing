import { CommonModule } from '@angular/common';
import { Component, DestroyRef, OnInit, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { interval } from 'rxjs';
import { ChatListComponent } from './chat-list/chat-list.component';
import { ChatWindowComponent } from './chat-window/chat-window.component';
import { GroupCreateComponent } from './group-create/group-create.component';
import { ChatRealtimeService } from './chat-realtime.service';
import { ChatService } from './chat.service';
import {
  ChatContextResponse,
  ChatMessage,
  ChatRealtimeEvent,
  ChatSummary,
  ChatUserSummary,
  chatDisplayName
} from './chat.models';
import { AuthService } from '../../services/auth.service';
import { ToasterService } from '../../services/toaster.service';

@Component({
  selector: 'app-chat-page',
  imports: [
    CommonModule,
    ChatListComponent,
    ChatWindowComponent,
    GroupCreateComponent
  ],
  templateUrl: './chat-page.component.html',
  styleUrl: './chat-page.component.scss'
})
export class ChatPageComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);
  private readonly chatService = inject(ChatService);
  private readonly realtime = inject(ChatRealtimeService);
  private readonly toast = inject(ToasterService);
  private readonly destroyRef = inject(DestroyRef);

  chats: ChatSummary[] = [];
  contacts: ChatUserSummary[] = [];
  properties = [] as ChatContextResponse['properties'];
  selectedChat: ChatSummary | null = null;
  messages: ChatMessage[] = [];

  loadingChats = true;
  loadingMessages = false;
  loadingOlder = false;
  sending = false;
  hasMoreMessages = false;
  nextBeforeId: number | null = null;
  selectedChatId: number | null = null;
  showGroupCreate = false;
  canCreateGroup = false;
  typingLabel = '';
  deletingGroup = false;
  removingParticipantIds: number[] = [];
  mobileSidebarOpen = false;
  private loadingChatId: number | null = null;

  private readonly typingTimeouts = new Map<number, number>();
  private readonly typingRequestTimestamps = new Map<number, number>();

  ngOnInit(): void {
    this.realtime.connect();
    this.bindRealtime();
    this.bindPollingFallback();
    this.loadContext();
    this.loadChats();

    this.route.paramMap
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((params) => {
        const chatId = Number(params.get('id') ?? 0) || null;
        this.selectedChatId = chatId;

        if (chatId) {
          this.openChat(chatId);
        } else {
          const initialChat = this.defaultChat();
          if (initialChat && !this.selectedChat) {
            this.openChat(initialChat.id, false);
          }
        }
      });
  }

  currentUserId(): number | null {
    const user = this.auth.user();
    return Number(user?.user?.id ?? user?.id ?? 0) || null;
  }

  canManageSelectedGroup(): boolean {
    return !!this.selectedChat
      && this.selectedChat.type === 'group'
      && this.auth.hasAnyRole('super-admin', 'owner', 'property_manager');
  }

  selectedChatName(): string {
    return this.selectedChat ? chatDisplayName(this.selectedChat) : 'Select a conversation';
  }

  toggleMobileSidebar(): void {
    this.mobileSidebarOpen = !this.mobileSidebarOpen;
  }

  closeMobileSidebar(): void {
    this.mobileSidebarOpen = false;
  }

  openChat(chatId: number, navigate: boolean = true): void {
    if (!chatId) {
      return;
    }

    this.closeMobileSidebar();

    if (this.loadingChatId === chatId) {
      return;
    }

    if (this.selectedChat?.id === chatId && this.messages.length > 0 && !this.loadingMessages) {
      return;
    }

    if (navigate && this.selectedChatId !== chatId) {
      this.router.navigate(['/chat', chatId]);
    }

    this.loadingMessages = true;
    this.loadingChatId = chatId;
    this.chatService.getChat(chatId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.loadingChatId = null;
          this.selectedChat = response.chat;
          this.selectedChatId = response.chat.id;
          this.upsertChat(response.chat);
          this.realtime.subscribeToChat(response.chat.id);
          this.loadMessages(response.chat.id);
        },
        error: (error) => {
          this.loadingChatId = null;
          this.loadingMessages = false;
          this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to load chat.'));
        }
      });
  }

  startPrivateChat(userId: number): void {
    this.closeMobileSidebar();

    this.chatService.createPrivateChat(userId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.upsertChat(response.chat);
          this.realtime.subscribeToChat(response.chat.id);
          this.router.navigate(['/chat', response.chat.id]);
        },
        error: (error) => {
          this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to open direct chat.'));
        }
      });
  }

  handleGroupCreated(chat: ChatSummary): void {
    this.showGroupCreate = false;
    this.closeMobileSidebar();
    this.upsertChat(chat);
    this.realtime.subscribeToChat(chat.id);
    this.router.navigate(['/chat', chat.id]);
    this.toast.showSuccess('Group chat created.');
  }

  deleteSelectedGroup(): void {
    if (!this.selectedChat || this.selectedChat.type !== 'group' || this.deletingGroup) {
      return;
    }

    if (!window.confirm(`Delete "${this.selectedChat.title?.trim() || 'this group'}" for all participants?`)) {
      return;
    }

    const chatId = this.selectedChat.id;
    this.deletingGroup = true;

    this.chatService.deleteGroupChat(chatId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          this.deletingGroup = false;
          this.removeChatFromState(chatId);
          this.toast.showSuccess('Group chat deleted.');
          this.navigateAfterRemovedChat();
        },
        error: (error) => {
          this.deletingGroup = false;
          this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to delete group chat.'));
        }
      });
  }

  removeParticipant(userId: number): void {
    if (!this.selectedChat || this.selectedChat.type !== 'group' || this.removingParticipantIds.includes(userId)) {
      return;
    }

    const participant = this.selectedChat.participants.find(item => Number(item.id) === Number(userId));
    if (!participant) {
      return;
    }

    const removingSelf = Number(userId) === Number(this.currentUserId() ?? 0);
    const confirmationMessage = removingSelf
      ? 'Remove yourself from this group?'
      : `Remove ${participant.name} from this group?`;

    if (!window.confirm(confirmationMessage)) {
      return;
    }

    const chatId = this.selectedChat.id;
    this.removingParticipantIds = [...this.removingParticipantIds, userId];

    this.chatService.removeGroupParticipant(chatId, userId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.removingParticipantIds = this.removingParticipantIds.filter(id => id !== userId);

          if (response.data.chat_deleted || removingSelf) {
            this.removeChatFromState(chatId);
            this.toast.showSuccess(removingSelf ? 'You left the group.' : 'Participant removed from group.');
            this.navigateAfterRemovedChat();
            return;
          }

          this.refreshChatDetails(chatId);
          this.toast.showSuccess(`${participant.name} removed from group.`);
        },
        error: (error) => {
          this.removingParticipantIds = this.removingParticipantIds.filter(id => id !== userId);
          this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to remove participant.'));
        }
      });
  }

  sendMessage(payload: { message: string; attachments: File[] }): void {
    if (!this.selectedChat) {
      return;
    }

    this.sending = true;
    this.chatService.sendMessage(this.selectedChat.id, payload)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.sending = false;
          const message = this.normalizeMessage(response.data);
          this.appendMessage(message);
          this.syncChatFromMessage(this.selectedChat!.id, message, 0);
        },
        error: (error) => {
          this.sending = false;
          this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to send message.'));
        }
      });
  }

  loadOlderMessages(): void {
    if (!this.selectedChat || this.loadingOlder || !this.hasMoreMessages || !this.nextBeforeId) {
      return;
    }

    this.loadingOlder = true;
    this.chatService.getMessages(this.selectedChat.id, {
      before_id: this.nextBeforeId,
      per_page: 30,
    }).pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.loadingOlder = false;
          this.hasMoreMessages = response.has_more;
          this.nextBeforeId = response.next_before_id;
          this.messages = this.mergeMessages([...response.data, ...this.messages]);
        },
        error: (error) => {
          this.loadingOlder = false;
          this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to load older messages.'));
        }
      });
  }

  emitTyping(): void {
    if (!this.selectedChat) {
      return;
    }

    const chatId = this.selectedChat.id;
    const lastSentAt = this.typingRequestTimestamps.get(chatId) ?? 0;
    const now = Date.now();

    if (now - lastSentAt < 1500) {
      return;
    }

    this.typingRequestTimestamps.set(chatId, now);
    this.chatService.sendTyping(chatId, true)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({ error: () => undefined });
  }

  private bindRealtime(): void {
    this.realtime.userEvents$
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((event) => {
        if (event.type === 'chat.created' && event.payload?.chat?.id) {
          const chatId = Number(event.payload.chat.id);
          this.realtime.subscribeToChat(chatId);
          this.chatService.getChat(chatId)
            .pipe(takeUntilDestroyed(this.destroyRef))
            .subscribe({
              next: (response) => this.upsertChat(response.chat),
              error: () => this.loadChats(),
            });
        }
      });

    this.realtime.chatEvents$
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((event) => this.handleChatEvent(event));
  }

  private bindPollingFallback(): void {
    interval(3000)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(() => this.refreshSelectedChatMessages());
  }

  private loadContext(): void {
    this.chatService.getContext()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.contacts = response.users;
          this.properties = response.properties;
          this.canCreateGroup = response.can_create_group;
        },
        error: () => undefined
      });
  }

  private loadChats(): void {
    this.loadingChats = true;
    this.chatService.getChats({ per_page: 50 })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.loadingChats = false;
          this.chats = this.sortChats(response.data);
          this.realtime.subscribeToChats(this.chats.map(chat => chat.id));

          const initialChat = this.defaultChat();
          if (!this.selectedChatId && initialChat) {
            this.openChat(initialChat.id, false);
          } else if (this.selectedChatId) {
            this.openChat(this.selectedChatId, false);
          }
        },
        error: (error) => {
          this.loadingChats = false;
          this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to load chats.'));
        }
      });
  }

  private loadMessages(chatId: number): void {
    this.chatService.getMessages(chatId, { per_page: 30 })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.loadingMessages = false;
          this.messages = this.mergeMessages(response.data);
          this.hasMoreMessages = response.has_more;
          this.nextBeforeId = response.next_before_id;
          this.markVisibleMessagesRead();
        },
        error: (error) => {
          this.loadingMessages = false;
          this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to load messages.'));
        }
      });
  }

  private refreshChatDetails(chatId: number): void {
    this.chatService.getChat(chatId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.selectedChat = response.chat;
          this.upsertChat(response.chat);
        },
        error: () => this.loadChats()
      });
  }

  private refreshSelectedChatMessages(): void {
    const chatId = this.selectedChat?.id ?? this.selectedChatId;

    if (!chatId || this.loadingMessages || this.loadingOlder || this.loadingChatId === chatId) {
      return;
    }

    this.chatService.getMessages(chatId, { per_page: 30 })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          const previousMessageCount = this.messages.length;
          const previousMessageIds = new Set(this.messages.map(message => Number(message.id)));
          const mergedMessages = this.mergeMessages([...this.messages, ...response.data]);
          const hasNewIncomingMessage = mergedMessages.some(message =>
            !previousMessageIds.has(Number(message.id))
            && Number(message.sender_id) !== Number(this.currentUserId() ?? 0)
          );

          this.messages = mergedMessages;

          if (previousMessageCount === 0) {
            this.hasMoreMessages = response.has_more;
            this.nextBeforeId = response.next_before_id;
          }

          const latestMessage = mergedMessages[mergedMessages.length - 1] ?? null;
          if (latestMessage) {
            this.syncChatFromMessage(chatId, latestMessage, 0);
          }

          if (hasNewIncomingMessage) {
            this.markVisibleMessagesRead();
          }
        },
        error: () => undefined
      });
  }

  private markVisibleMessagesRead(): void {
    if (!this.selectedChat || this.messages.length === 0) {
      return;
    }

    const latestIncoming = [...this.messages]
      .reverse()
      .find(message => message.sender_id !== this.currentUserId());

    if (!latestIncoming) {
      return;
    }

    this.chatService.markRead(this.selectedChat.id, latestIncoming.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          if (!this.selectedChat) {
            return;
          }

          this.selectedChat.unread_count = 0;
          const chat = this.chats.find(item => item.id === this.selectedChat?.id);
          if (chat) {
            chat.unread_count = 0;
          }
        },
        error: () => undefined
      });
  }

  private handleChatEvent(event: ChatRealtimeEvent): void {
    if (!event.chatId) {
      return;
    }

    switch (event.type) {
      case 'message.sent':
        const message = this.normalizeMessage(event.payload);
        this.syncChatFromMessage(
          event.chatId,
          message,
          this.incrementUnreadForIncoming({ ...event, payload: message })
        );
        if (this.selectedChat?.id === event.chatId) {
          this.appendMessage(message);
          if (Number(message.sender_id) !== Number(this.currentUserId() ?? 0)) {
            this.markVisibleMessagesRead();
          }
        }
        break;
      case 'chat.updated':
        this.applyChatUpdate(event.chatId, event.payload);
        break;
      case 'message.read':
        this.applyReadReceipt(event.payload);
        break;
      case 'user.typing':
        this.applyTyping(event.payload);
        break;
    }
  }

  private incrementUnreadForIncoming(event: ChatRealtimeEvent): number {
    const isCurrentChat = this.selectedChat?.id === event.chatId;
    const fromSelf = Number(event.payload?.sender_id) === Number(this.currentUserId() ?? 0);

    if (isCurrentChat || fromSelf) {
      return 0;
    }

    return 1;
  }

  private syncChatFromMessage(chatId: number, message: ChatMessage, unreadIncrement: number): void {
    const chat = this.chats.find(item => item.id === chatId);
    if (!chat) {
      this.loadChats();
      return;
    }

    chat.last_message = message;
    chat.last_message_at = message.timestamp;
    chat.updated_at = message.timestamp;
    chat.unread_count = Math.max(0, (chat.unread_count ?? 0) + unreadIncrement);

    if (this.selectedChat?.id === chatId) {
      this.selectedChat.last_message = message;
      this.selectedChat.last_message_at = message.timestamp;
      if (unreadIncrement === 0) {
        this.selectedChat.unread_count = 0;
        chat.unread_count = 0;
      }
    }

    this.chats = this.sortChats([...this.chats]);
  }

  private applyReadReceipt(payload: any): void {
    const readById = Number(payload?.read_by_id ?? 0);
    const messageId = Number(payload?.message_id ?? 0);

    if (!readById || !messageId) {
      return;
    }

    this.messages = this.messages.map(message => {
      if (message.id > messageId || Number(message.sender_id) !== Number(this.currentUserId() ?? 0)) {
        return message;
      }

      if (message.read_by_ids.includes(readById)) {
        return message;
      }

      return {
        ...message,
        read_by_ids: [...message.read_by_ids, readById]
      };
    });
  }

  private applyTyping(payload: any): void {
    if (this.selectedChat?.id !== Number(payload?.chat_id ?? 0)) {
      return;
    }

    const userId = Number(payload?.user_id ?? 0);
    if (!userId || userId === this.currentUserId()) {
      return;
    }

    this.typingLabel = `${payload?.user_name ?? 'Someone'} is typing...`;

    const existingTimeout = this.typingTimeouts.get(userId);
    if (existingTimeout) {
      window.clearTimeout(existingTimeout);
    }

    const timeout = window.setTimeout(() => {
      this.typingLabel = '';
      this.typingTimeouts.delete(userId);
    }, 2500);

    this.typingTimeouts.set(userId, timeout);
  }

  private applyChatUpdate(chatId: number, payload: any): void {
    const action = String(payload?.action ?? '');
    const affectedUserId = Number(payload?.affected_user_id ?? 0) || null;
    const currentUserId = Number(this.currentUserId() ?? 0) || null;
    const chatDeleted = payload?.chat_deleted === true;

    if (chatDeleted || (action === 'participant_removed' && affectedUserId === currentUserId)) {
      const wasSelected = this.selectedChat?.id === chatId;
      const selfRemovalInFlight = currentUserId !== null && this.removingParticipantIds.includes(currentUserId);
      this.removeChatFromState(chatId);

      if (affectedUserId === currentUserId && !selfRemovalInFlight) {
        this.toast.showError('You no longer have access to this group.');
      }

      if (wasSelected) {
        this.navigateAfterRemovedChat();
      }

      return;
    }

    if (action === 'participant_removed' && affectedUserId) {
      this.removeParticipantFromLocalChat(chatId, affectedUserId);
    }
  }

  private removeParticipantFromLocalChat(chatId: number, userId: number): void {
    const existingIndex = this.chats.findIndex(chat => chat.id === chatId);

    if (existingIndex !== -1) {
      const existing = this.chats[existingIndex];
      const next = [...this.chats];
      next[existingIndex] = {
        ...existing,
        participants: existing.participants.filter(participant => Number(participant.id) !== Number(userId))
      };
      this.chats = this.sortChats(next);
    }

    if (this.selectedChat?.id === chatId) {
      this.selectedChat = {
        ...this.selectedChat,
        participants: this.selectedChat.participants.filter(participant => Number(participant.id) !== Number(userId))
      };
    }
  }

  private appendMessage(message: ChatMessage): void {
    this.messages = this.mergeMessages([...this.messages, this.normalizeMessage(message)]);
  }

  private mergeMessages(messages: ChatMessage[]): ChatMessage[] {
    const map = new Map<number, ChatMessage>();

    for (const message of messages) {
      const normalized = this.normalizeMessage(message);
      const id = Number(normalized.id ?? 0) || 0;
      if (!id) {
        continue;
      }
      map.set(id, normalized);
    }

    return Array.from(map.values()).sort((left, right) => Number(left.id) - Number(right.id));
  }

  private normalizeMessage(payload: any): ChatMessage {
    if (!payload) {
      return payload;
    }

    // HTTP payloads use `id`; realtime broadcasts use `message_id`.
    const id = Number(payload.id ?? payload.message_id ?? 0) || 0;
    const chatId = Number(payload.chat_id ?? payload.chatId ?? 0) || 0;
    const senderId = Number(payload.sender_id ?? payload.senderId ?? 0) || 0;

    return {
      id,
      chat_id: chatId,
      sender_id: senderId,
      sender: payload.sender ?? null,
      message: payload.message ?? null,
      type: payload.type ?? 'text',
      timestamp: payload.timestamp ?? payload.created_at ?? new Date().toISOString(),
      updated_at: payload.updated_at ?? null,
      read_by_ids: Array.isArray(payload.read_by_ids)
        ? payload.read_by_ids
            .map((value: any) => Number(value) || 0)
            .filter((value: number) => value > 0)
        : [],
      attachments: Array.isArray(payload.attachments)
        ? payload.attachments.map((attachment: any) => ({
            id: Number(attachment?.id ?? 0) || 0,
            original_name: String(attachment?.original_name ?? ''),
            mime_type: String(attachment?.mime_type ?? ''),
            size: Number(attachment?.size ?? 0) || 0,
            download_endpoint:
              attachment?.download_endpoint ??
              `/api/v1/chat/chats/${chatId}/attachments/${Number(attachment?.id ?? 0) || 0}`,
          }))
        : [],
    };
  }

  private upsertChat(chat: ChatSummary): void {
    const existingIndex = this.chats.findIndex(item => item.id === chat.id);

    if (existingIndex === -1) {
      this.chats = this.sortChats([chat, ...this.chats]);
    } else {
      const next = [...this.chats];
      next[existingIndex] = {
        ...next[existingIndex],
        ...chat,
      };
      this.chats = this.sortChats(next);
    }

    if (this.selectedChat?.id === chat.id) {
      this.selectedChat = {
        ...this.selectedChat,
        ...chat,
      };
    }
  }

  private removeChatFromState(chatId: number): void {
    this.realtime.unsubscribeFromChat(chatId);
    this.chats = this.chats.filter(chat => chat.id !== chatId);

    if (this.selectedChat?.id !== chatId) {
      return;
    }

    this.selectedChat = null;
    this.selectedChatId = null;
    this.messages = [];
    this.hasMoreMessages = false;
    this.nextBeforeId = null;
    this.typingLabel = '';
    this.deletingGroup = false;
    this.removingParticipantIds = [];

    for (const timeout of this.typingTimeouts.values()) {
      window.clearTimeout(timeout);
    }

    this.typingTimeouts.clear();
  }

  private navigateAfterRemovedChat(): void {
    const nextChat = this.defaultChat();

    if (nextChat) {
      this.router.navigate(['/chat', nextChat.id]);
      return;
    }

    this.router.navigate(['/chat']);
  }

  private sortChats(chats: ChatSummary[]): ChatSummary[] {
    return [...chats].sort((left, right) => {
      const leftTime = new Date(left.last_message_at ?? left.updated_at ?? left.created_at ?? 0).getTime();
      const rightTime = new Date(right.last_message_at ?? right.updated_at ?? right.created_at ?? 0).getTime();

      return rightTime - leftTime;
    });
  }

  private defaultChat(): ChatSummary | null {
    return this.chats.find(chat => !!chat.last_message || !!chat.last_message_at) ?? null;
  }
}
