import { CommonModule } from '@angular/common';
import { Component, DestroyRef, EventEmitter, Input, Output, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { ToasterService } from '../../../services/toaster.service';
import { MaintenanceAttachment, MaintenanceComment } from '../maintenance.models';
import { MaintenanceService } from '../maintenance.service';

@Component({
  selector: 'app-comments-section',
  imports: [CommonModule, FormsModule],
  templateUrl: './comments-section.component.html',
  styleUrl: './comments-section.component.scss'
})
export class CommentsSectionComponent {
  private readonly maintenanceService = inject(MaintenanceService);
  private readonly toast = inject(ToasterService);
  private readonly destroyRef = inject(DestroyRef);

  @Input() requestId: number | null = null;
  @Input() comments: MaintenanceComment[] = [];
  @Input() readOnly = false;
  @Output() commentAdded = new EventEmitter<void>();

  body = '';
  selectedFiles: File[] = [];
  submitting = false;

  onFileChange(event: Event): void {
    const input = event.target as HTMLInputElement | null;
    const files = Array.from(input?.files ?? []);
    this.selectedFiles = files.slice(0, 5);

    if (files.length > 5) {
      this.toast.showError('You can attach up to 5 files per comment.');
    }

    if (input) {
      input.value = '';
    }
  }

  removeFile(index: number): void {
    this.selectedFiles = this.selectedFiles.filter((_, itemIndex) => itemIndex !== index);
  }

  submitComment(): void {
    if (!this.requestId || !this.body.trim()) {
      this.toast.showError('Enter a comment before sending.');
      return;
    }

    this.submitting = true;

    this.maintenanceService.addComment(this.requestId, {
      body: this.body,
      attachments: this.selectedFiles
    })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          this.submitting = false;
          this.body = '';
          this.selectedFiles = [];
          this.toast.showSuccess('Comment added successfully.');
          this.commentAdded.emit();
        },
        error: (error) => {
          this.submitting = false;
          this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to add maintenance comment.'));
        }
      });
  }

  downloadAttachment(attachment: MaintenanceAttachment): void {
    if (!this.requestId) {
      return;
    }

    this.maintenanceService.downloadAttachment(this.requestId, attachment.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
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

  formatTimestamp(value?: string | null): string {
    if (!value) {
      return 'Not available';
    }

    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Not available' : date.toLocaleString();
  }
}
