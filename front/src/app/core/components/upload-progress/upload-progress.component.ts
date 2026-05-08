/**
 * Upload Progress Component
 * Shows file upload progress with percentage and status
 */
import { Component, Input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';

export interface UploadFile {
  name: string;
  size: number;
  progress: number;
  status: 'pending' | 'uploading' | 'success' | 'error';
  error?: string;
  preview?: string;
}

@Component({
  selector: 'app-upload-progress',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="upload-progress" *ngIf="files.length > 0">
      <div class="upload-progress__header">
        <span class="upload-progress__count">
          {{ files.length }} file{{ files.length > 1 ? 's' : '' }}
        </span>
        <button 
          type="button" 
          class="upload-progress__clear"
          (click)="clearAll.emit()"
          *ngIf="showClearButton"
          [disabled]="isUploading">
          Clear all
        </button>
      </div>

      <div class="upload-progress__list">
        @for (file of files; track file.name) {
          <div class="upload-progress__item" [class]="'status-' + file.status">
            @if (file.preview) {
              <img [src]="file.preview" alt="{{ file.name }}" class="upload-progress__preview">
            } @else {
              <div class="upload-progress__icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                  <polyline points="14 2 14 8 20 8"/>
                </svg>
              </div>
            }

            <div class="upload-progress__info">
              <div class="upload-progress__name">{{ file.name }}</div>
              <div class="upload-progress__size">{{ formatSize(file.size) }}</div>

              @if (file.status === 'uploading') {
                <div class="upload-progress__bar">
                  <div class="upload-progress__bar-fill" [style.width.%]="file.progress"></div>
                </div>
                <div class="upload-progress__percentage">{{ file.progress }}%</div>
              } @else if (file.status === 'success') {
                <span class="upload-progress__status success">✓ Complete</span>
              } @else if (file.status === 'error') {
                <span class="upload-progress__status error">✗ {{ file.error || 'Failed' }}</span>
                <button type="button" class="upload-progress__retry" (click)="retry.emit(file)">
                  Retry
                </button>
              }
            </div>

            <button 
              type="button" 
              class="upload-progress__remove"
              (click)="remove.emit(file)"
              *ngIf="file.status !== 'uploading'">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>
        }
      </div>
    </div>
  `,
  styles: [`
    .upload-progress {
      background: #f8fafc;
      border-radius: 12px;
      padding: 16px;
      border: 1px solid #e2e8f0;
    }

    .upload-progress__header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 12px;
    }

    .upload-progress__count {
      font-size: 0.875rem;
      font-weight: 600;
      color: #64748b;
    }

    .upload-progress__clear {
      background: none;
      border: none;
      color: #ef4444;
      font-size: 0.813rem;
      font-weight: 500;
      cursor: pointer;
      padding: 4px 8px;
      border-radius: 4px;
      transition: background-color 0.2s;
    }

    .upload-progress__clear:hover:not(:disabled) {
      background: #fef2f2;
    }

    .upload-progress__clear:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .upload-progress__list {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .upload-progress__item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 8px 12px;
      background: white;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
      transition: border-color 0.2s;
    }

    .upload-progress__item:hover {
      border-color: #cbd5e1;
    }

    .upload-progress__preview {
      width: 40px;
      height: 40px;
      object-fit: cover;
      border-radius: 6px;
      flex-shrink: 0;
    }

    .upload-progress__icon {
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #f1f5f9;
      border-radius: 6px;
      color: #64748b;
      flex-shrink: 0;
    }

    .upload-progress__icon svg {
      width: 20px;
      height: 20px;
    }

    .upload-progress__info {
      flex: 1;
      min-width: 0;
    }

    .upload-progress__name {
      font-size: 0.875rem;
      font-weight: 500;
      color: #1e293b;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .upload-progress__size {
      font-size: 0.75rem;
      color: #94a3b8;
      margin-top: 2px;
    }

    .upload-progress__bar {
      height: 4px;
      background: #e2e8f0;
      border-radius: 2px;
      margin-top: 6px;
      overflow: hidden;
    }

    .upload-progress__bar-fill {
      height: 100%;
      background: linear-gradient(90deg, #3b82f6, #60a5fa);
      border-radius: 2px;
      transition: width 0.3s ease;
    }

    .upload-progress__percentage {
      font-size: 0.75rem;
      font-weight: 600;
      color: #3b82f6;
      margin-top: 4px;
    }

    .upload-progress__status {
      font-size: 0.75rem;
      font-weight: 500;
    }

    .upload-progress__status.success {
      color: #22c55e;
    }

    .upload-progress__status.error {
      color: #ef4444;
    }

    .upload-progress__retry {
      background: none;
      border: 1px solid #ef4444;
      color: #ef4444;
      font-size: 0.75rem;
      font-weight: 500;
      padding: 2px 8px;
      border-radius: 4px;
      cursor: pointer;
      margin-left: 8px;
      transition: all 0.2s;
    }

    .upload-progress__retry:hover {
      background: #fef2f2;
    }

    .upload-progress__remove {
      width: 28px;
      height: 28px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: none;
      border: none;
      color: #94a3b8;
      cursor: pointer;
      border-radius: 4px;
      transition: all 0.2s;
      flex-shrink: 0;
    }

    .upload-progress__remove:hover {
      background: #f1f5f9;
      color: #ef4444;
    }

    .upload-progress__remove svg {
      width: 16px;
      height: 16px;
    }

    /* Status-specific styles */
    .status-success {
      border-color: #dcfce7;
    }

    .status-error {
      border-color: #fee2e2;
    }
  `]
})
export class UploadProgressComponent {
  @Input() files: UploadFile[] = [];
  @Input() showClearButton = true;
  @Input() isUploading = false;
  
  @Output() remove = new EventEmitter<UploadFile>();
  @Output() retry = new EventEmitter<UploadFile>();
  @Output() clearAll = new EventEmitter<void>();

  formatSize(bytes: number): string {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  }
}