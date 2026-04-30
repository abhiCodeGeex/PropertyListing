import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { InvoiceService } from '../../services/invoice.service';
import { ToasterService } from '../../services/toaster.service';
import { formatAppCurrency } from '../../shared/utils/currency.util';

@Component({
  selector: 'app-invoices',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './invoices.component.html',
  styleUrls: ['./invoices.component.scss']
})
export class InvoicesComponent implements OnInit {
  protected readonly formatCurrency = formatAppCurrency;
  invoices: any[] = [];
  loading = false;
  resendingId: number | null = null;
  page = 1;
  perPage = 10;
  totalPages = 1;
  selectedType = '';

  readonly invoiceTypes = [
    { value: '', label: 'All Invoices' },
    { value: 'rent', label: 'Rent' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'security_deposit', label: 'Security Deposit' },
  ];

  constructor(
    private readonly invoiceService: InvoiceService,
    private readonly toaster: ToasterService
  ) {}

  ngOnInit(): void {
    this.loadInvoices();
  }

  loadInvoices(): void {
    this.loading = true;

    this.invoiceService.getInvoices({
      page: this.page,
      per_page: this.perPage,
      type: this.selectedType || undefined,
    }).subscribe({
      next: (response) => {
        this.invoices = response.data ?? [];
        this.totalPages = response.last_page ?? 1;
        this.loading = false;
      },
      error: (err) => {
        this.loading = false;
        this.toaster.showError(this.toaster.extractErrorMessage(err, 'Failed to load invoices'));
      }
    });
  }

  changeType(type: string): void {
    this.selectedType = type;
    this.page = 1;
    this.loadInvoices();
  }

  pageChanged(page: number): void {
    if (page < 1 || page > this.totalPages || page === this.page) return;
    this.page = page;
    this.loadInvoices();
  }

  resend(invoice: any): void {
    if (this.resendingId) return;

    this.resendingId = invoice.id;
    this.invoiceService.resendInvoice(invoice.id).subscribe({
      next: (response) => {
        this.resendingId = null;
        this.toaster.showSuccess(response.message || 'Invoice email queued');
      },
      error: (err) => {
        this.resendingId = null;
        this.toaster.showError(this.toaster.extractErrorMessage(err, 'Failed to resend invoice'));
      }
    });
  }

  download(invoice: any): void {
    this.invoiceService.downloadInvoice(invoice.id).subscribe({
      next: (blob) => {
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `${invoice.invoice_number || 'invoice'}.pdf`;
        link.click();
        window.URL.revokeObjectURL(url);
      },
      error: (err) => {
        this.toaster.showError(this.toaster.extractErrorMessage(err, 'Failed to download invoice'));
      }
    });
  }

  get statusLabel(): string {
    if (this.loading) return 'Loading invoices...';
    if (this.invoices.length === 0) return 'No invoices found.';
    return `${this.invoices.length} invoice${this.invoices.length === 1 ? '' : 's'} on this page`;
  }

  typeLabel(type: string): string {
    return type.replace(/_/g, ' ').replace(/\b\w/g, (match) => match.toUpperCase());
  }

  deliveryLabel(status: string): string {
    if (status === 'sent') return 'Delivered';
    if (status === 'pending') return 'Queued';
    if (status === 'failed') return 'Failed';
    return this.typeLabel(status);
  }
}
