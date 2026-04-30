import { CommonModule } from '@angular/common';
import { Component, DestroyRef, OnInit, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { auditTime } from 'rxjs';
import { PropertyService } from '../../../services/property.service';
import { ToasterService } from '../../../services/toaster.service';
import {
  MaintenanceRequest,
  MaintenanceWorkspaceSummary,
  maintenancePriorityBadgeClass,
  maintenancePriorityLabel,
  maintenanceStatusBadgeClass,
  maintenanceStatusLabel
} from '../maintenance.models';
import { MaintenanceService } from '../maintenance.service';
import { MaintenanceModuleWebsocketService } from '../websocket.service';

interface PropertyFilterOption {
  id: number;
  label: string;
}

@Component({
  selector: 'app-owner-dashboard',
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './owner-dashboard.component.html',
  styleUrl: './owner-dashboard.component.scss'
})
export class OwnerDashboardComponent implements OnInit {
  private readonly maintenanceService = inject(MaintenanceService);
  private readonly maintenanceWs = inject(MaintenanceModuleWebsocketService);
  private readonly propertyService = inject(PropertyService);
  private readonly toast = inject(ToasterService);
  private readonly destroyRef = inject(DestroyRef);

  readonly statusOptions = ['pending', 'approved', 'rejected', 'assigned', 'in_progress', 'on_hold', 'completed', 'cancelled'];
  readonly priorityOptions = ['low', 'medium', 'high', 'urgent'];

  requests: MaintenanceRequest[] = [];
  propertyOptions: PropertyFilterOption[] = [];
  loading = false;
  page = 1;
  lastPage = 1;
  total = 0;
  perPage = 10;
  search = '';
  selectedStatus = '';
  selectedPriority = '';
  selectedPropertyId = '';
  summary: MaintenanceWorkspaceSummary = {
    total: 0,
    pending: 0,
    in_progress: 0,
    urgent: 0
  };

  ngOnInit(): void {
    this.maintenanceWs.connect();
    this.loadPropertyOptions();
    this.loadSummary();
    this.loadRequests();

    this.maintenanceWs.events$
      .pipe(
        auditTime(750),
        takeUntilDestroyed(this.destroyRef)
      )
      .subscribe(() => {
        this.loadSummary();
        this.loadRequests(this.page);
      });
  }

  loadRequests(page: number = 1): void {
    this.loading = true;
    this.page = page;

    this.maintenanceService.getRequests(this.filters())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.loading = false;
          this.requests = response?.data ?? [];
          this.page = Number(response?.current_page ?? page);
          this.lastPage = Number(response?.last_page ?? 1);
          this.total = Number(response?.total ?? this.requests.length);
        },
        error: (error) => {
          this.loading = false;
          this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to load maintenance dashboard.'));
        }
      });
  }

  applyFilters(): void {
    this.loadRequests(1);
  }

  resetFilters(): void {
    this.search = '';
    this.selectedStatus = '';
    this.selectedPriority = '';
    this.selectedPropertyId = '';
    this.loadRequests(1);
  }

  previousPage(): void {
    if (this.page > 1) {
      this.loadRequests(this.page - 1);
    }
  }

  nextPage(): void {
    if (this.page < this.lastPage) {
      this.loadRequests(this.page + 1);
    }
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

  private filters() {
    return {
      page: this.page,
      per_page: this.perPage,
      search: this.search.trim() || undefined,
      status: this.selectedStatus || undefined,
      priority: this.selectedPriority || undefined,
      property_id: this.selectedPropertyId || undefined,
      sort_by: 'last_activity_at',
      sort_order: 'desc' as const
    };
  }

  private loadSummary(): void {
    this.maintenanceService.getWorkspaceSummary()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.summary = response?.summary ?? this.summary;
        },
        error: () => undefined
      });
  }

  private loadPropertyOptions(): void {
    this.propertyService.getProperties({ page: 1, per_page: 100 })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.propertyOptions = (response?.data ?? []).map((property: any) => ({
            id: Number(property.id),
            label: `${property.property_name}${property.city ? `, ${property.city}` : ''}`
          }));
        }
      });
  }
}
