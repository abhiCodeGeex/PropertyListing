import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, Output, inject } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { DestroyRef } from '@angular/core';
import { ChatPropertySummary, ChatSummary, ChatUserSummary } from '../chat.models';
import { ChatService } from '../chat.service';
import { ToasterService } from '../../../services/toaster.service';

@Component({
  selector: 'app-group-create',
  imports: [CommonModule, FormsModule],
  templateUrl: './group-create.component.html',
  styleUrl: './group-create.component.scss'
})
export class GroupCreateComponent {
  @Input() visible = false;
  @Input() users: ChatUserSummary[] = [];
  @Input() properties: ChatPropertySummary[] = [];
  @Input() canCreate = false;

  @Output() readonly closed = new EventEmitter<void>();
  @Output() readonly created = new EventEmitter<ChatSummary>();

  private readonly chatService = inject(ChatService);
  private readonly toast = inject(ToasterService);
  private readonly destroyRef = inject(DestroyRef);

  title = '';
  description = '';
  propertyId: number | null = null;
  selectedUserIds: number[] = [];
  submitting = false;

  toggleUser(userId: number, checked: boolean): void {
    if (checked) {
      this.selectedUserIds = Array.from(new Set([...this.selectedUserIds, userId]));
      return;
    }

    this.selectedUserIds = this.selectedUserIds.filter(id => id !== userId);
  }

  submit(): void {
    if (!this.canCreate || this.submitting) {
      return;
    }

    if (!this.title.trim()) {
      this.toast.showError('Group title is required.');
      return;
    }

    if (this.selectedUserIds.length === 0) {
      this.toast.showError('Select at least one participant.');
      return;
    }

    this.submitting = true;
    this.chatService.createGroupChat({
      title: this.title.trim(),
      description: this.description.trim() || null,
      property_id: this.propertyId,
      participant_ids: this.selectedUserIds,
    }).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (response) => {
        this.submitting = false;
        this.reset();
        this.created.emit(response.chat);
      },
      error: (error) => {
        this.submitting = false;
        this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to create group chat.'));
      }
    });
  }

  close(): void {
    this.reset();
    this.closed.emit();
  }

  private reset(): void {
    this.title = '';
    this.description = '';
    this.propertyId = null;
    this.selectedUserIds = [];
  }
}
