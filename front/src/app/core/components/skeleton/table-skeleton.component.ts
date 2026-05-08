/**
 * Table Skeleton Loader Component
 * Provides skeleton loading state for tables and lists
 */
import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { SkeletonComponent } from './skeleton.component';

@Component({
  selector: 'app-table-skeleton',
  standalone: true,
  imports: [CommonModule, SkeletonComponent],
  template: `
    <div class="table-skeleton" [class.compact]="compact">
      @for (row of rows; track row) {
        <div class="table-skeleton__row">
          @for (cell of columns; track cell) {
            <div class="table-skeleton__cell" [style.flex]="cell === 'actions' ? '0 0 auto' : '1'">
              @if (cell === 'avatar') {
                <app-skeleton variant="avatar" [style.width.px]="24" [style.height.px]="24"></app-skeleton>
              } @else if (cell === 'actions' || cell === 'status') {
                <app-skeleton variant="button" [style.width.px]="cell === 'status' ? 60 : 32" [style.height.px]="24"></app-skeleton>
              } @else {
                <app-skeleton variant="text" [style.width]="cell === 'title' ? '60%' : '100%'"></app-skeleton>
              }
            </div>
          }
        </div>
      }
    </div>
  `,
  styles: [`
    .table-skeleton {
      display: flex;
      flex-direction: column;
      gap: 12px;
      padding: 16px;
    }

    .table-skeleton.compact {
      gap: 8px;
      padding: 8px;
    }

    .table-skeleton__row {
      display: flex;
      gap: 16px;
      align-items: center;
      padding: 8px 0;
    }

    .table-skeleton.compact .table-skeleton__row {
      gap: 8px;
      padding: 4px 0;
    }

    .table-skeleton__cell {
      display: flex;
      align-items: center;
    }

    /* Responsive */
    @media (max-width: 768px) {
      .table-skeleton__row {
        gap: 8px;
      }
    }
  `]
})
export class TableSkeletonComponent {
  @Input() rows = 5;
  @Input() columns: string[] = ['avatar', 'title', 'text', 'status', 'actions'];
  @Input() compact = false;
}