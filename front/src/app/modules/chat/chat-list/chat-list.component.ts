import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, Output } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ChatSummary, ChatUserSummary, chatDisplayName, chatPreview } from '../chat.models';

@Component({
  selector: 'app-chat-list',
  imports: [CommonModule, FormsModule],
  templateUrl: './chat-list.component.html',
  styleUrl: './chat-list.component.scss'
})
export class ChatListComponent {
  @Input() chats: ChatSummary[] = [];
  @Input() contacts: ChatUserSummary[] = [];
  @Input() selectedChatId: number | null = null;
  @Input() loading = false;
  @Input() canCreateGroup = false;

  @Output() readonly selectChat = new EventEmitter<number>();
  @Output() readonly startPrivateChat = new EventEmitter<number>();
  @Output() readonly createGroup = new EventEmitter<void>();

  search = '';

  recentChats(): ChatSummary[] {
    return this.chats.filter(chat => this.hasConversation(chat));
  }

  filteredChats(): ChatSummary[] {
    const term = this.search.trim().toLowerCase();
    const chats = this.recentChats();

    if (!term) {
      return chats;
    }

    return chats.filter(chat => {
      const haystack = [
        chatDisplayName(chat),
        chatPreview(chat),
        chat.property?.property_name ?? '',
      ].join(' ').toLowerCase();

      return haystack.includes(term);
    });
  }

  filteredContacts(): ChatUserSummary[] {
    const term = this.search.trim().toLowerCase();
    if (!term) {
      return this.contacts.filter(contact => contact.can_message !== false);
    }

    return this.contacts.filter(contact => {
      if (contact.can_message === false) {
        return false;
      }

      return `${contact.name} ${contact.email}`.toLowerCase().includes(term);
    });
  }

  displayName(chat: ChatSummary): string {
    return chatDisplayName(chat);
  }

  preview(chat: ChatSummary): string {
    return chatPreview(chat);
  }

  hasConversation(chat: ChatSummary): boolean {
    return !!chat.last_message || !!chat.last_message_at;
  }

  formatTime(value: string | null): string {
    if (!value) {
      return '';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return '';
    }

    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }
}
