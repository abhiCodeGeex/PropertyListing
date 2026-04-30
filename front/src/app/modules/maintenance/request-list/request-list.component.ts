import { CommonModule } from '@angular/common';
import { Component, DestroyRef, OnInit, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { auditTime } from 'rxjs';
import { AuthService } from '../../../services/auth.service';
import { PropertyService } from '../../../services/property.service';
import { ToasterService } from '../../../services/toaster.service';
import { UsersService } from '../../../services/users.service';
import {
  MaintenanceRequest,
  maintenancePriorityBadgeClass,
  maintenancePriorityLabel,
  maintenanceStatusBadgeClass,
  maintenanceStatusLabel
} from '../maintenance.models';
import { MaintenanceService } from '../maintenance.service';
import { MaintenanceModuleWebsocketService } from '../websocket.service';
import { RequestCreateComponent } from '../request-create/request-create.component';

interface PropertyFilterOption {
  id: number;
  label: string;
}

@Component({
  selector: 'app-request-list',
  imports: [CommonModule, FormsModule, RouterLink, RequestCreateComponent],
  templateUrl: './request-list.component.html',
  styleUrl: './request-list.component.scss'
})
export class RequestListComponent implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly maintenanceService = inject(MaintenanceService);
  private readonly maintenanceWs = inject(MaintenanceModuleWebsocketService);
  private readonly usersService = inject(UsersService);
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
  tenantMode = true;
  search = '';
  selectedStatus = '';
  selectedPriority = '';
  selectedPropertyId = '';
  createModalVisible = false;

  ngOnInit(): void {
    this.tenantMode = this.auth.hasAnyRole('tenant') && !this.auth.hasAnyRole('owner', 'property_manager', 'super-admin');
    this.maintenanceWs.connect();
    this.loadPropertyOptions();
    this.loadRequests();

    this.maintenanceWs.events$
      .pipe(
        auditTime(750),
        takeUntilDestroyed(this.destroyRef)
      )
      .subscribe(() => this.loadRequests(this.page));
  }

  loadRequests(page: number = 1): void {
    this.loading = true;
    this.page = page;

    const request$ = this.tenantMode
      ? this.maintenanceService.getMyRequests(this.filters())
      : this.maintenanceService.getRequests(this.filters());

    request$
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
          this.toast.showError(this.toast.extractErrorMessage(error, 'Failed to load maintenance requests.'));
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

  openCreateModal(): void {
    this.createModalVisible = true;
  }

  onCreateModalVisibleChange(visible: boolean): void {
    this.createModalVisible = visible;
  }

  onRequestCreated(_request: MaintenanceRequest): void {
    // Reload the first page so the newly-created request is visible at the top.
    this.loadRequests(1);
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

  private loadPropertyOptions(): void {
    if (this.tenantMode) {
      const user = this.auth.user();
      const userId = user?.user?.id ?? user?.id ?? null;

      if (!userId) {
        return;
      }

      this.usersService.assignedProperties(String(userId))
        .pipe(takeUntilDestroyed(this.destroyRef))
        .subscribe({
          next: (response) => {
            this.propertyOptions = (response?.property ?? []).map((property: any) => ({
              id: Number(property.id),
              label: `${property.property_name}${property.city ? `, ${property.city}` : ''}`
            }));
          }
        });

      return;
    }

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
