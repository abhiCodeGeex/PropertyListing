import { Component, OnInit } from '@angular/core';
import { RentService } from '../../services/rent.service';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  PageItemDirective,
  PageLinkDirective,
  PaginationComponent,
} from '@coreui/angular';
import { debounceTime, distinctUntilChanged, finalize } from 'rxjs/operators';
import { Subject, Subscription } from 'rxjs';
import { AuthService } from '../../services/auth.service';
import { ActivatedRoute } from '@angular/router';
import { LoaderComponent } from '../common/loader/loader.component';
import { IconModule } from '@coreui/icons-angular';
import { formatAppCurrency } from '../../shared/utils/currency.util';

@Component({
  selector: 'app-rent-history',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    LoaderComponent,
    IconModule,
    PaginationComponent,
    PageItemDirective,
    PageLinkDirective,
  ],
  templateUrl: './rent-history.component.html',
  styleUrls: ['./rent-history.component.scss'],
})
export class RentHistoryComponent implements OnInit {
  protected readonly formatCurrency = formatAppCurrency;
  payments: any[] = [];
  searchTerm = '';

  page = 1;
  perPage = 10;
  totalPages = 1;
  pages: number[] = [];

  loading = false;

  private searchSubject = new Subject<string>();
  private subscription = new Subscription();
  roles: any;
  user: any;
  propertyId: number | null = null;
  constructor(private service: RentService, public authService: AuthService, private route: ActivatedRoute) { }

  ngOnInit(): void {
    this.roles = JSON.parse(localStorage.getItem('roles') || '[]');
    this.user = JSON.parse(localStorage.getItem('user') || '[]');

    this.route.queryParams.subscribe(params => {
      this.propertyId = params['propertyId'] || null;
      this.page = 1;
      this.load(); // reload whenever query param changes
    });
    const searchSub = this.searchSubject
      .pipe(
        debounceTime(500),
        distinctUntilChanged()
      )
      .subscribe((query) => {
        this.searchTerm = query;
        this.page = 1;
        this.load();
      });

    this.subscription.add(searchSub);
    this.load();
  }

  ngOnDestroy(): void {
    this.subscription.unsubscribe();
  }

  load() {
    this.loading = true;
    const payload: any = {
      page: this.page,
      per_page: this.perPage,
      search: this.searchTerm,
    };

    if (this.roles.includes('tenant')) {
      payload.tenantId = this.user.user.id;
    }

    if (this.propertyId) {
      payload.propertyId = this.propertyId;
    }

    this.service
      .getHistory(payload)
      .pipe(
        finalize(() => {
          this.loading = false;
        })
      )
      .subscribe({
        next: (res) => {
          this.payments = res.data;
          this.totalPages = res.last_page;
          this.pages = Array.from(
            { length: this.totalPages },
            (_, i) => i + 1
          );
        },
        error: () => {
          this.payments = [];
          this.totalPages = 1;
          this.pages = [];
        },
      });
  }

  pageChanged(p: number) {
    if (p < 1 || p > this.totalPages || this.loading) return;
    this.page = p;
    this.load();
  }

  onSearchChange(value: string): void {
    this.searchSubject.next(value);
  }

  isOwnerOrManager(): boolean {
    return this.authService.hasAnyRole('owner', 'property_manager');
  }

  isSuperAdmin(): boolean {
    return this.authService.hasAnyRole('super-admin');
  }

  isTenant(): boolean {
    return this.authService.hasAnyRole('tenant');
  }

  canViewTenantColumn(): boolean {
    return this.authService.hasAnyRole('owner', 'property_manager', 'super-admin');
  }

  getStatusClass(status: string) {
    if (!status) return 'bg-secondary';

    status = status.toLowerCase();

    return {
      'bg-success': status === 'succeeded' || status === 'paid',
      'bg-danger': status === 'failed' || status === 'rejected',
      'bg-warning': status === 'pending',
      'bg-info': status === 'processing'
    };
  }
}
