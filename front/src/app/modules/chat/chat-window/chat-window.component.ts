import { CommonModule } from '@angular/common';
import {
  AfterViewChecked,
  Component,
  ElementRef,
  EventEmitter,
  Input,
  OnChanges,
  Output,
  SimpleChanges,
  ViewChild,
  inject
} from '@angular/core';
import { ChatAttachment, ChatMessage, ChatSummary, chatDisplayName } from '../chat.models';
import { MessageInputComponent } from '../message-input/message-input.component';
import { ChatService } from '../chat.service';
import { ToasterService } from '../../../services/toaster.service';

@Component({
  selector: 'app-chat-window',
  imports: [CommonModule, MessageInputComponent],
  templateUrl: './chat-window.component.html',
  styleUrl: './chat-window.component.scss'
})
export class ChatWindowComponent implements OnChanges, AfterViewChecked {
  @Input() chat: ChatSummary | null = null;
  @Input() messages: ChatMessage[] = [];
  @Input() loading = false;
  @Input() loadingOlder = false;
  @Input() sending = false;
  @Input() hasMore = false;
  @Input() currentUserId: number | null = null;
  @Input() typingLabel = '';
  @Input() canManageParticipants = false;
  @Input() deletingGroup = false;
  @Input() removingParticipantIds: number[] = [];

  @Output() readonly loadOlder = new EventEmitter<void>();
  @Output() readonly send = new EventEmitter<{ message: string; attachments: File[] }>();
  @Output() readonly typing = new EventEmitter<void>();
  @Output() readonly deleteGroup = new EventEmitter<void>();
  @Output() readonly removeParticipant = new EventEmitter<number>();

  @ViewChild('viewport') private viewport?: ElementRef<HTMLDivElement>;

  private readonly chatService = inject(ChatService);
  private readonly toast = inject(ToasterService);

  private previousMessageCount = 0;
  private preserveScroll = false;
  private previousScrollHeight = 0;
  private previousScrollTop = 0;
  showParticipants = false;

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['chat']) {
      const previousChatId = Number(changes['chat'].previousValue?.id ?? 0) || 0;
      const currentChatId = Number(changes['chat'].currentValue?.id ?? 0) || 0;

      if (previousChatId !== currentChatId) {
        this.showParticipants = false;
      }
    }

    if (changes['loadingOlder']?.currentValue === true && this.viewport) {
      this.preserveScroll = true;
      this.previousScrollHeight = this.viewport.nativeElement.scrollHeight;
      this.previousScrollTop = this.viewport.nativeElement.scrollTop;
    }
  }

  ngAfterViewChecked(): void {
    const viewport = this.viewport?.nativeElement;
    if (!viewport) {
      return;
    }

    if (this.preserveScroll) {
      const delta = viewport.scrollHeight - this.previousScrollHeight;
      viewport.scrollTop = this.previousScrollTop + delta;
      this.preserveScroll = false;
      return;
    }

    if (this.messages.length !== this.previousMessageCount) {
      const nearBottom =
        viewport.scrollHeight - viewport.scrollTop - viewport.clientHeight < 140;

      if (nearBottom || this.previousMessageCount === 0) {
        viewport.scrollTop = viewport.scrollHeight;
      }
    }

    this.previousMessageCount = this.messages.length;
  }

  displayName(): string {
    return this.chat ? chatDisplayName(this.chat) : 'Conversation';
  }

  toggleParticipants(): void {
    this.showParticipants = !this.showParticipants;
  }

  onScroll(): void {
    const viewport = this.viewport?.nativeElement;
    if (!viewport || this.loadingOlder || !this.hasMore) {
      return;
    }

    if (viewport.scrollTop <= 24) {
      this.loadOlder.emit();
    }
  }

  isMine(message: ChatMessage): boolean {
    return Number(message.sender_id) === Number(this.currentUserId ?? 0);
  }

  formatTimestamp(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.getTime())
      ? ''
      : date.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  readState(message: ChatMessage): string {
    if (!this.isMine(message)) {
      return '';
    }

    const recipientReads = message.read_by_ids.filter(id => id !== this.currentUserId);
    return recipientReads.length > 0 ? 'Seen' : 'Sent';
  }

  participantLabel(participant: ChatSummary['participants'][number]): string {
    return participant.roles.join(', ') || participant.email;
  }

  isRemovingParticipant(userId: number): boolean {
    return this.removingParticipantIds.includes(userId);
  }

  download(chatId: number, attachment: ChatAttachment): void {
    this.chatService.downloadAttachment(chatId, attachment.id).subscribe({
      next: (blob) => {
        const url = window.URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = attachment.original_name;
        anchor.click();
        window.URL.revokeObjectURL(url);
      },
      error: (error) => {
        this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to download attachment.'));
      }
    });
  }
}
