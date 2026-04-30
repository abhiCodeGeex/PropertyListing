import { CommonModule } from '@angular/common';
import { Component, Input } from '@angular/core';
import { MaintenanceStatusHistory, maintenanceStatusLabel } from '../maintenance.models';

@Component({
  selector: 'app-status-timeline',
  imports: [CommonModule],
  templateUrl: './status-timeline.component.html',
  styleUrl: './status-timeline.component.scss'
})
export class StatusTimelineComponent {
  @Input() items: MaintenanceStatusHistory[] = [];

  statusLabel(status?: string | null): string {
    return status ? maintenanceStatusLabel(status) : 'No status change';
  }

  formatTimestamp(value?: string | null): string {
    if (!value) {
      return 'Not available';
    }

    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Not available' : date.toLocaleString();
  }
}
