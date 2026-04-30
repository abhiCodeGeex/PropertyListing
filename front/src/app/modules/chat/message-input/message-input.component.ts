import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, Output } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ChatMessageComposerPayload } from '../chat.models';

@Component({
  selector: 'app-message-input',
  imports: [CommonModule, FormsModule],
  templateUrl: './message-input.component.html',
  styleUrl: './message-input.component.scss'
})
export class MessageInputComponent {
  @Input() disabled = false;
  @Input() sending = false;

  @Output() readonly sendMessage = new EventEmitter<ChatMessageComposerPayload>();
  @Output() readonly typing = new EventEmitter<void>();

  draft = '';
  attachments: File[] = [];

  onFileSelection(event: Event): void {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);

    this.attachments = [...this.attachments, ...files].slice(0, 5);
    input.value = '';
    this.typing.emit();
  }

  removeAttachment(index: number): void {
    this.attachments = this.attachments.filter((_, currentIndex) => currentIndex !== index);
  }

  submit(): void {
    const trimmed = this.draft.trim();

    if (this.disabled || this.sending || (!trimmed && this.attachments.length === 0)) {
      return;
    }

    this.sendMessage.emit({
      message: trimmed,
      attachments: this.attachments,
    });

    this.draft = '';
    this.attachments = [];
  }

  handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      this.submit();
      return;
    }

    this.typing.emit();
  }
}
