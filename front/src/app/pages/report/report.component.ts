import { Component, OnInit, OnDestroy } from '@angular/core';
import { Subject, Subscription } from 'rxjs';
import { debounceTime, distinctUntilChanged } from 'rxjs/operators';
import { RentService } from '../../services/rent.service';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, FormsModule, ReactiveFormsModule } from '@angular/forms';
import { PaginationComponent, PageItemDirective, PageLinkDirective } from '@coreui/angular';
import { IconModule } from '@coreui/icons-angular';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatNativeDateModule } from '@angular/material/core';
import { formatAppCurrency } from '../../shared/utils/currency.util';

@Component({
  selector: 'app-revenue-report',
  standalone: true,
  templateUrl: './report.component.html',
  imports: [
    CommonModule,
    FormsModule,
    ReactiveFormsModule,
    IconModule,
    PaginationComponent,
    PageItemDirective,
    PageLinkDirective,
    MatDatepickerModule,
    MatFormFieldModule,
    MatInputModule,
    MatNativeDateModule,
  ],
})
export class ReportComponent implements OnInit, OnDestroy {
  protected readonly formatCurrency = formatAppCurrency;

  reports: any[] = [];
  totalRevenue = 0;

  page = 1;
  perPage = 10;
  totalPages = 1;
  pages: number[] = [];

  filterForm: FormGroup;
  searchTerm = '';


  private search$ = new Subject<string>();
  private sub = new Subscription();

  constructor(private service: RentService, private fb: FormBuilder) {
    this.filterForm = this.fb.group({
      range: this.fb.group({
        start: [null],
        end: [null],
      })
    });
  }

  get rangeGroup(): FormGroup {
    return this.filterForm.get('range') as FormGroup;
  }

  ngOnInit() {
    this.sub.add(
      this.search$.pipe(debounceTime(500), distinctUntilChanged())
        .subscribe(v => {
          this.searchTerm = v;
          this.page = 1;
          this.load();
        })
    );
    
    this.load();
  }

  ngOnDestroy() {
    this.sub.unsubscribe();
  }

  load() {
    const params: any = {
      page: this.page,
      per_page: this.perPage,
      from_date: this.formatDate(this.filterForm.get('range.start')?.value),
      to_date: this.formatDate(this.filterForm.get('range.end')?.value),
      search: this.searchTerm
    };

    this.service.getRevenue(params).subscribe((res: any) => {
      this.reports = res.data;
      this.totalRevenue = res.total_revenue;
      this.totalPages = res.last_page;
      this.pages = Array.from({ length: this.totalPages }, (_, i) => i + 1);
    });
  }

  changePage(p: number) {
    if (p < 1 || p > this.totalPages) return;
    this.page = p;
    this.load();
  }

  onSearchChange(v: string) {
    this.search$.next(v);
  }


  onFromDateChange() {
    this.load();
  }

  onToDateChange() {
    this.load();
  }

  private formatDate(value: Date | string | null | undefined): string | null {
    if (!value) return null;
    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) return null;

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
  }
}
