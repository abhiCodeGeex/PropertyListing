import { CommonModule } from '@angular/common';
import { Component, DestroyRef, OnInit, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { auditTime } from 'rxjs';
import { AuthService } from '../../../services/auth.service';
import { ToasterService } from '../../../services/toaster.service';
import { CommentsSectionComponent } from '../comments-section/comments-section.component';
import {
  MaintenanceAttachment,
  MaintenanceRequest,
  MaintenanceUserSummary,
  maintenancePriorityBadgeClass,
  maintenancePriorityLabel,
  maintenanceStatusBadgeClass,
  maintenanceStatusLabel
} from '../maintenance.models';
import { MaintenanceService } from '../maintenance.service';
import { StatusTimelineComponent } from '../status-timeline/status-timeline.component';
import { MaintenanceModuleWebsocketService } from '../websocket.service';

@Component({
  selector: 'app-request-detail',
  imports: [
    CommonModule,
    FormsModule,
    RouterLink,
    StatusTimelineComponent,
    CommentsSectionComponent
  ],
  templateUrl: './request-detail.component.html',
  styleUrl: './request-detail.component.scss'
})
export class RequestDetailComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly auth = inject(AuthService);
  private readonly maintenanceService = inject(MaintenanceService);
  private readonly maintenanceWs = inject(MaintenanceModuleWebsocketService);
  private readonly toast = inject(ToasterService);
  private readonly destroyRef = inject(DestroyRef);

  requestId = 0;
  request: MaintenanceRequest | null = null;
  loading = true;
  actionLoading = false;
  decisionReason = '';
  decisionMessage = '';
  selectedStatus = '';
  statusMessage = '';
  statusReason = '';
  selectedAssignee: number | null = null;
  assignMessage = '';
  assigneeOptions: MaintenanceUserSummary[] = [];

  ngOnInit(): void {
    this.route.paramMap
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((params) => {
        this.requestId = Number(params.get('id'));
        if (this.requestId) {
          this.loadRequest();
        }
      });

    this.maintenanceWs.connect();
    this.maintenanceWs.events$
      .pipe(
        auditTime(350),
        takeUntilDestroyed(this.destroyRef)
      )
      .subscribe((event) => {
        if (event.payload.request_id === this.requestId) {
          if (Number(event.payload.user_id ?? 0) === Number(this.currentUserId() ?? 0)) {
            return;
          }
          this.loadRequest(false);
        }
      });
  }

  canManageRequest(): boolean {
    return this.auth.hasAnyRole('owner', 'property_manager', 'super-admin');
  }

  currentUserId(): number | null {
    const user = this.auth.user();
    return Number(user?.user?.id ?? user?.id ?? 0) || null;
  }

  isTerminalStatus(): boolean {
    return ['rejected', 'completed', 'cancelled'].includes(this.request?.status ?? '');
  }

  availableStatusOptions(): string[] {
    switch (this.request?.status) {
      case 'assigned':
        return ['in_progress', 'on_hold', 'cancelled'];
      case 'in_progress':
        return ['on_hold', 'completed', 'cancelled'];
      case 'on_hold':
        return ['in_progress', 'cancelled'];
      default:
        return [];
    }
  }

  canAssign(): boolean {
    return this.canManageRequest() && ['approved', 'assigned', 'in_progress', 'on_hold'].includes(this.request?.status ?? '');
  }

  approve(): void {
    if (!this.request || !this.decisionReason.trim()) {
      this.toast.showError('Approval reason is required.');
      return;
    }

    this.actionLoading = true;
    this.maintenanceService.approveRequest(this.request.id, {
      reason: this.decisionReason,
      message: this.decisionMessage || null
    })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.actionLoading = false;
          this.applyResponse(response);
          this.decisionReason = '';
          this.decisionMessage = '';
          this.toast.showSuccess(response?.message || 'Maintenance request approved.');
        },
        error: (error) => {
          this.actionLoading = false;
          this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to approve maintenance request.'));
        }
      });
  }

  reject(): void {
    if (!this.request || !this.decisionReason.trim()) {
      this.toast.showError('Rejection reason is required.');
      return;
    }

    this.actionLoading = true;
    this.maintenanceService.rejectRequest(this.request.id, {
      reason: this.decisionReason,
      message: this.decisionMessage || null
    })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.actionLoading = false;
          this.applyResponse(response);
          this.decisionReason = '';
          this.decisionMessage = '';
          this.toast.showSuccess(response?.message || 'Maintenance request rejected.');
        },
        error: (error) => {
          this.actionLoading = false;
          this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to reject maintenance request.'));
        }
      });
  }

  updateStatus(): void {
    if (!this.request || !this.selectedStatus) {
      this.toast.showError('Select a new status first.');
      return;
    }

    this.actionLoading = true;
    this.maintenanceService.updateStatus(this.request.id, {
      status: this.selectedStatus,
      reason: this.statusReason || null,
      message: this.statusMessage || null
    })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.actionLoading = false;
          this.applyResponse(response);
          this.selectedStatus = '';
          this.statusMessage = '';
          this.statusReason = '';
          this.toast.showSuccess(response?.message || 'Maintenance status updated.');
        },
        error: (error) => {
          this.actionLoading = false;
          this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to update maintenance status.'));
        }
      });
  }

  assignRequest(): void {
    if (!this.request || !this.selectedAssignee) {
      this.toast.showError('Select an assignee first.');
      return;
    }

    this.actionLoading = true;
    this.maintenanceService.assignRequest(this.request.id, {
      assigned_to: this.selectedAssignee,
      message: this.assignMessage || null
    })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.actionLoading = false;
          this.applyResponse(response);
          this.assignMessage = '';
          this.toast.showSuccess(response?.message || 'Maintenance request assigned successfully.');
        },
        error: (error) => {
          this.actionLoading = false;
          this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to assign maintenance request.'));
        }
      });
  }

  downloadAttachment(attachment: MaintenanceAttachment): void {
    if (!this.request) {
      return;
    }

    this.maintenanceService.downloadAttachment(this.request.id, attachment.id)
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

  backLink(): string {
    return this.canManageRequest() ? '/maintenance/dashboard' : '/maintenance/requests';
  }

  statusClass(status: string): string {
    return maintenanceStatusBadgeClass(status);
  }

  statusLabel(status: string): string {
    return maintenanceStatusLabel(status);
  }

  priorityClass(priority: string): string {
    return maintenancePriorityBadgeClass(priority);
  }

  priorityLabel(priority: string): string {
    return maintenancePriorityLabel(priority);
  }

  formatTimestamp(value?: string | null): string {
    if (!value) {
      return 'Not available';
    }

    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Not available' : date.toLocaleString();
  }

  fileSize(bytes: number): string {
    if (!bytes) return '0 B';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }

  loadRequest(showLoader: boolean = true): void {
    if (!this.requestId) {
      return;
    }

    if (showLoader) {
      this.loading = true;
    }

    this.maintenanceService.getRequest(this.requestId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.loading = false;
          this.request = response.request;
          this.selectedAssignee = this.request?.assignee?.id ?? null;
          this.buildAssigneeOptions();
        },
        error: (error) => {
          this.loading = false;
          this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to load maintenance request.'));
        }
      });
  }

  private buildAssigneeOptions(): void {
    const options = [
      this.request?.property?.owner ?? null,
      this.request?.property?.manager ?? null
    ];

    const currentUser = this.auth.user();
    const userId = currentUser?.user?.id ?? currentUser?.id ?? null;
    const userName = currentUser?.user?.name ?? currentUser?.name ?? null;
    const userEmail = currentUser?.user?.email ?? currentUser?.email ?? null;

    if (this.auth.hasAnyRole('super-admin') && userId && userName && userEmail) {
      options.push({
        id: Number(userId),
        name: userName,
        email: userEmail
      });
    }

    const unique = new Map<number, MaintenanceUserSummary>();

    for (const option of options) {
      if (option?.id) {
        unique.set(Number(option.id), option);
      }
    }

    this.assigneeOptions = Array.from(unique.values());
  }

  private applyResponse(response: any): void {
    this.request = response?.request ?? this.request;
    this.selectedAssignee = this.request?.assignee?.id ?? null;
    this.buildAssigneeOptions();
  }
}
