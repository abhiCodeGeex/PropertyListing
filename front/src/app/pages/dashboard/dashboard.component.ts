import { Component, OnDestroy, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';

import { RentSubscriptionComponent } from '../rent-subscription/rent-subscription.component';
import { OverduePaymentComponent } from '../overdue-payment/overdue-payment.component';
import { SecurityDepositeComponent } from '../security-deposite/security-deposite.component';

import { UsersService } from '../../services/users.service';
import { RentService } from '../../services/rent.service';
import { ToasterService } from '../../services/toaster.service';
import { WebsocketService } from '../../services/websocket.service';
import { ConfirmModalComponent } from '../common/confirm-modal/confirm-modal.component';
import { formatAppCurrency } from '../../shared/utils/currency.util';
import { ButtonCloseDirective, ModalBodyComponent, ModalComponent, ModalHeaderComponent, ModalTitleDirective } from '@coreui/angular';

@Component({
  templateUrl: 'dashboard.component.html',
  styleUrls: ['dashboard.component.scss'],
  imports: [
    CommonModule,
    RentSubscriptionComponent,
    OverduePaymentComponent,
    SecurityDepositeComponent,
    ConfirmModalComponent,
    ModalComponent,
    ModalHeaderComponent,
    ModalTitleDirective,
    ModalBodyComponent,
    ButtonCloseDirective
  ]
})
export class DashboardComponent implements OnInit, OnDestroy {
  protected readonly formatCurrency = formatAppCurrency;
  user: any;
  roles: string[] = [];
  displayNameText = 'User';
  isTenant = false;
  isOwnerManager = false;

  properties: any[] = [];
  approvals: any[] = [];
  rentApprovals: any[] = [];
  notifications: any[] = [];
  ownerManagerSummary = {
    properties: 0,
    overdue: 0,
    auto_pay_active: 0,
    approvals: 0
  };

  loadingNotifications = false;
  loading = false;
  approvingId: number | null = null;
  actionTenancyId: number | null = null;
  actionType: 'security' | 'rent' | null = null;

  selectedTenancyId: number | null = null;
  selectedRent: number | null = null;
  selectedBaseRent: number | null = null;
  selectedLateFee: number | null = null;
  selectedSecurity: number | null = null;

  showPayModal = false;
  showOverdueModal = false;
  showSecurityModal = false;
  payModalMode: 'pay_and_subscribe' | 'autopay_only' = 'pay_and_subscribe';

  selectedProperty: any = null;
  showConfirmModal = false;
  expandedPropertyDetails = new Set<number>();
  mediaPreviewVisible = false;
  mediaPreviewTitle = '';
  mediaPreviewItems: Array<{ url: string; name: string; isImage: boolean }> = [];
  private websocketUnsubscribers: Array<() => void> = [];

  constructor(
    private userService: UsersService,
    private rentService: RentService,
    private toaster: ToasterService,
    private websocketService: WebsocketService
  ) { }

  ngOnInit(): void {
    this.user = JSON.parse(localStorage.getItem('user') || '{}');
    this.roles = JSON.parse(localStorage.getItem('roles') || '[]');
    this.syncIdentityState();
    const userId = this.getCurrentUserId();
    if (!userId) return;
    this.setupWebsocket();

    if (this.isTenant) this.getAssignedProperties();
    if (this.isOwnerManager) {
      this.getOwnerManagerDashboardSummary();
      this.getSecurityApprovals();
      this.getRentApprovals();
    }
  }

  ngOnDestroy(): void {
    for (const unsubscribe of this.websocketUnsubscribers) {
      unsubscribe();
    }

    this.websocketUnsubscribers = [];
  }

  /** -------------------- Websocket Handling -------------------- */
  private setupWebsocket(): void {
    const userId = this.getCurrentUserId();
    if (!userId) return;
    this.websocketService.connect(userId);

    this.websocketUnsubscribers.push(this.websocketService.on('security_deposit', () => {
      if (this.isTenant) {
        this.getAssignedProperties();
      }

      if (this.isOwnerManager) {
        this.getOwnerManagerDashboardSummary();
        this.getSecurityApprovals();
      }
    }));

    this.websocketUnsubscribers.push(this.websocketService.on('rent_deposit', () => {
      if (this.isTenant) {
        this.getAssignedProperties();
      }

      if (this.isOwnerManager) {
        this.getOwnerManagerDashboardSummary();
        this.getRentApprovals();
      }
    }));

    this.websocketUnsubscribers.push(this.websocketService.on('rent_paid', () => {
      if (this.isTenant) {
        this.getAssignedProperties();
      }
    }));

    this.websocketUnsubscribers.push(this.websocketService.on('security_deposit_paid', () => {
      this.refreshRealtimeData();
    }));

    this.websocketUnsubscribers.push(this.websocketService.on('overdue_paid', () => {
      this.refreshRealtimeData();
    }));

    this.websocketUnsubscribers.push(this.websocketService.on('subscription_activated', () => {
      this.refreshRealtimeData();
    }));

    this.websocketUnsubscribers.push(this.websocketService.on('subscription_scheduled', () => {
      this.refreshRealtimeData();
    }));

    this.websocketUnsubscribers.push(this.websocketService.on('subscription_past_due', () => {
      this.refreshRealtimeData();
    }));

    this.websocketUnsubscribers.push(this.websocketService.on('subscription_cancelled', () => {
      this.refreshRealtimeData();
    }));

    this.websocketUnsubscribers.push(this.websocketService.on('payment_failed', () => {
      this.refreshRealtimeData();
    }));
  }


  /** -------------------- Properties & Approvals -------------------- */
  private fetchData(apiCall: () => any, assignTo: (data: any) => void, errorMessage: string): void {
    apiCall().subscribe({
      next: assignTo,
      error: (err: any) => this.toaster.showError(this.toaster.extractErrorMessage(err, errorMessage))
    });
  }

  getAssignedProperties(): void {
    const userId = this.getCurrentUserId();
    if (!userId) return;

    this.fetchData(
      () => this.userService.assignedProperties(String(userId)),
      (res: any) => {
        this.properties = res.property || [];
      },
      'Failed to load assigned properties'
    );
  }

  openPropertyMediaPreview(property: any): void {
    const media = (property?.media ?? [])
      .map((item: any) => {
        const url = item?.url || item?.file_url || item?.file;
        const name = item?.name || item?.original_name || (typeof url === 'string' ? url.split('/').pop() : 'Media');
        return { url, name, isImage: typeof url === 'string' && /\.(png|jpe?g|gif|webp|svg)$/i.test(url) };
      })
      .filter((item: any) => !!item.url);

    this.mediaPreviewItems = media;
    this.mediaPreviewTitle = property?.property_name || 'Property';
    this.mediaPreviewVisible = true;
  }

  getSecurityApprovals(): void {
    const userId = this.getCurrentUserId();
    if (!userId) return;

    this.fetchData(
      () => this.userService.securityApprovals(String(userId)),
      (res: any) => {
        this.approvals = res || [];
      },
      'Failed to load security approvals'
    );
  }

  getOwnerManagerDashboardSummary(): void {
    this.fetchData(
      () => this.userService.dashboardSummary(),
      (res: any) => {
        this.ownerManagerSummary = {
          properties: res?.properties ?? 0,
          overdue: res?.overdue ?? 0,
          auto_pay_active: res?.auto_pay_active ?? 0,
          approvals: res?.approvals ?? 0
        };
        this.syncIdentityState();
      },
      'Failed to load dashboard summary'
    );
  }

  getRentApprovals(): void {
    const userId = this.getCurrentUserId();
    if (!userId) return;

    this.fetchData(
      () => this.userService.manualRentApprovals(String(userId)),
      (res: any) => {
        this.rentApprovals = res || [];
      },
      'Failed to load rent approvals'
    );
  }


  updateSecurityDeposit(tenancyId: number, status: 'approved' | 'declined'): void {
    if (this.approvingId) return;
    this.approvingId = tenancyId;

    this.userService.updateManualSecurityDeposit({ tenancy_id: tenancyId, status }).subscribe({
      next: () => {
        this.approvals = this.approvals.filter(a => a.tenancy_id !== tenancyId);
        this.toaster.showSuccess(`Security deposit ${status}`);

        if (status === 'declined' && this.isTenant) this.getAssignedProperties();
        this.approvingId = null;
      },
      error: (err: any) => {
        this.toaster.showError(this.toaster.extractErrorMessage(err, `Security deposit ${status} failed`));
        this.approvingId = null;
      }
    });
  }

  updateManualRent(tenancyId: number, status: 'approved' | 'declined', rent_schedule_id: number): void {
    if (this.approvingId) return;
    this.approvingId = tenancyId;

    this.userService.updateManualRent({ tenancy_id: tenancyId, status, rent_schedule_id }).subscribe({
      next: () => {
        this.rentApprovals = this.rentApprovals.filter(r => r.tenancy_id !== tenancyId);

        const property = this.properties.find(p => p.tenancy_id === tenancyId);
        if (property) {
          property.rent_status = status === 'approved' ? 'paid' : 'pending';
          if (status === 'approved') property.has_subscription = 1;
        }

        this.toaster.showSuccess(`Rent ${status}`);

        if (status === 'declined' && this.isTenant) this.getAssignedProperties();
        this.approvingId = null;
      },
      error: (err: any) => {
        this.toaster.showError(this.toaster.extractErrorMessage(err, `Rent ${status} failed`));
        this.approvingId = null;
      }
    });
  }

  /** -------------------- Request Manual Payments -------------------- */
  requestManual(property: any): void {
    if (this.actionTenancyId) return;
    this.actionTenancyId = property.tenancy_id;
    this.actionType = 'security';

    this.rentService.requestManualSecurityDeposit({ tenancy_id: property.tenancy_id }).subscribe({
      next: (res: any) => {
        property.security_deposit_status = 'manual_pending';
        this.toaster.showSuccess(res?.message || 'Manual payment requested. Awaiting owner approval.');
        this.resetActionState();
      },
      error: (err) => {
        this.resetActionState();
        this.toaster.showError(this.rentService.extractPaymentError(err, 'Failed to notify owner'));
      }
    });
  }

  requestManualRent(property: any): void {
    if (this.actionTenancyId) return;
    this.actionTenancyId = property.tenancy_id;
    this.actionType = 'rent';

    this.rentService.requestManualRent({ tenancy_id: property.tenancy_id }).subscribe({
      next: (res: any) => {
        property.rent_status = 'manual_pending';
        this.toaster.showSuccess(res?.message || 'Manual rent payment requested. Awaiting owner approval.');
        this.resetActionState();
      },
      error: (err) => {
        this.resetActionState();
        this.toaster.showError(this.rentService.extractPaymentError(err, 'Failed to notify owner'));
      }
    });
  }



  /** -------------------- Modal Handling -------------------- */
  openModal(type: 'pay' | 'autopay' | 'overdue' | 'security', property: any): void {
    if (!property?.has_rent_deed) {
      this.toaster.showError('Rent deed is not created yet. Payment will be available after the rent deed is created.');
      return;
    }

    this.selectedProperty = property;
    this.selectedTenancyId = property.tenancy_id;
    if (type === 'pay' || type === 'autopay') {
      this.selectedRent = type === 'autopay'
        ? property.autopay_monthly_amount ?? property.monthly_rent
        : property.payable_now_total ?? property.monthly_rent;
      this.selectedBaseRent = type === 'autopay'
        ? property.autopay_monthly_amount ?? property.monthly_rent
        : property.payable_now_base_total ?? property.monthly_rent;
      this.selectedLateFee = type === 'autopay' ? 0 : property.late_fee_amount ?? 0;
    }
    if (type === 'security') this.selectedSecurity = property.security_deposit_amount;
    this.payModalMode = type === 'autopay' ? 'autopay_only' : 'pay_and_subscribe';

    this.showPayModal = type === 'pay' || type === 'autopay';
    this.showOverdueModal = type === 'overdue';
    this.showSecurityModal = type === 'security';
  }

  closeModal(type: 'pay' | 'overdue' | 'security'): void {
    if (type === 'pay') this.showPayModal = false;
    if (type === 'overdue') this.showOverdueModal = false;
    if (type === 'security') this.showSecurityModal = false;
    if (!this.showPayModal && !this.showOverdueModal && !this.showSecurityModal) {
      this.selectedProperty = null;
    }
  }

  markPaid(tenancyId: number, type: 'rent' | 'overdue' | 'security', payload?: any): void {
    const property = this.properties.find(p => p.tenancy_id === tenancyId);
    if (!property) return;

    if (type === 'rent') {
      property.rent_status = 'paid';
      property.has_subscription = payload?.subscription_active ?? property.has_subscription ?? 0;
      property.stripe_subscription_id = payload?.subscription_id ?? property.stripe_subscription_id ?? null;
      property.subscription_cancel_at = payload?.subscription_cancel_at ?? null;
      this.toaster.showSuccess(
        payload?.flow === 'autopay_only'
          ? 'Automatic rent payment activated successfully'
          : 'Rent paid and subscription activated successfully'
      );
      this.getAssignedProperties();
    } else if (type === 'overdue') {
      property.rent_status = 'paid';
      property.overdue_count = 0;
      property.overdue_total = 0;
      property.overdue_months = [];
      property.has_subscription = payload?.subscription_active ?? property.has_subscription ?? 0;
      property.stripe_subscription_id = payload?.subscription_id ?? property.stripe_subscription_id ?? null;
      property.subscription_cancel_at = payload?.subscription_cancel_at ?? null;
      this.toaster.showSuccess(
        payload?.flow === 'autopay_only'
          ? 'Missed rent paid and automatic rent payment activated successfully'
          : 'Missed rent paid successfully'
      );
      this.getAssignedProperties();
    } else {
      property.security_deposit_status = 'paid';
      this.toaster.showSuccess('Security deposit paid successfully');
    }
  }

  openCancelSubscription(property: any): void {
    this.selectedProperty = property;
    this.showConfirmModal = true;
  }

  confirmCancelSubscription(): void {
    if (!this.selectedProperty) return;

    this.rentService.cancelSubscription(this.selectedProperty.tenancy_id).subscribe({
      next: (response: any) => {
        this.selectedProperty.has_subscription = 3;
        this.selectedProperty.subscription_cancel_at = response?.cancel_at ?? null;
        this.toaster.showSuccess(response?.message || 'Subscription cancellation scheduled successfully');
        this.selectedProperty = null;
      },
      error: (err: any) => {
        this.toaster.showError(this.toaster.extractErrorMessage(err, 'Failed to cancel subscription'));
        this.selectedProperty = null;
      }
    });
  }

  approve(
    type: 'security' | 'rent',
    tenancyId: number,
    status: 'approved' | 'declined',
    rentScheduleId?: number | null
  ): void {

    if (type === 'security') {
      this.updateSecurityDeposit(tenancyId, status);
      return;
    }

    // rent case
    if (rentScheduleId == null) {
      this.toaster.showError('Rent schedule is missing for this approval.');
      return;
    }

    this.updateManualRent(tenancyId, status, rentScheduleId);
  }

  isActionLoading(property: any, type: 'security' | 'rent'): boolean {
    return this.actionTenancyId === property.tenancy_id && this.actionType === type;
  }

  canUseOnlineRentPayment(property: any): boolean {
    return property?.payment_mode === 'Credit/Debit Cards';
  }

  canRequestManualRent(property: any): boolean {
    return !this.canUseOnlineRentPayment(property);
  }

  canUseOnlineSecurityPayment(property: any): boolean {
    return this.canUseOnlineRentPayment(property);
  }

  canRequestManualSecurityPayment(property: any): boolean {
    return !this.canUseOnlineSecurityPayment(property);
  }

  private resetActionState(): void {
    this.actionTenancyId = null;
    this.actionType = null;
  }

  private refreshRealtimeData(): void {
    if (this.isTenant) {
      this.getAssignedProperties();
    }

    if (this.isOwnerManager) {
      this.getOwnerManagerDashboardSummary();
      this.getSecurityApprovals();
      this.getRentApprovals();
    }
  }

  private getCurrentUserId(): number | null {
    return this.user?.user?.id ?? this.user?.id ?? null;
  }

  private syncIdentityState(): void {
    this.displayNameText = this.user?.profile
      ? `${this.user.profile.first_name} ${this.user.profile.last_name}`
      : this.user?.username || this.user?.user?.name || 'User';
    this.isTenant = this.roles.includes('tenant');
    this.isOwnerManager = this.hasAnyRole('owner', 'property_manager', 'super-admin');
  }

  hasAnyRole(...allowed: string[]): boolean {
    return allowed.some(role => this.roles.includes(role));
  }

  subscriptionEndsLabel(property: any): string | null {
    return property?.subscription_cancel_at
      ? new Date(property.subscription_cancel_at).toLocaleDateString()
      : null;
  }

  subscriptionStateLabel(property: any): string {
    if (property?.has_subscription === 1) return 'Auto-pay active';
    if (property?.has_subscription === 3) return 'Cancellation scheduled';
    if (property?.has_subscription === 2) return 'Subscription cancelled';
    return 'Auto-pay not active';
  }

  subscriptionStateClass(property: any): string {
    if (property?.has_subscription === 1) return 'soft-pill soft-pill--success';
    if (property?.has_subscription === 3) return 'soft-pill soft-pill--warning';
    return 'soft-pill soft-pill--neutral';
  }

  subscriptionSummaryTitle(property: any): string {
    if (property?.has_subscription === 3) return 'Cancellation scheduled';
    if (property?.has_subscription === 1) return 'Auto-pay is active';
    if (property?.has_subscription === 2) return 'Auto-pay is cancelled';
    return 'Auto-pay is not active';
  }

  subscriptionSummaryText(property: any): string {
    if (property?.has_subscription === 3) {
      return `Charges stay active until ${this.subscriptionEndsLabel(property) || 'month end'}. No new cancellation action is needed.`;
    }

    if (property?.has_subscription === 1) {
      return 'Future monthly rent will be charged automatically until you schedule a cancellation.';
    }

    if (property?.has_subscription === 2) {
      return 'Automatic charging has ended. You can start auto-pay again from the dashboard when needed.';
    }

    return 'Future rent will not be charged automatically until auto-pay is activated.';
  }

  activePropertyCount(): number {
    if (this.hasAnyRole('owner', 'property_manager', 'super-admin')) {
      return this.ownerManagerSummary.properties;
    }

    return this.properties.length;
  }

  isTenantDashboard(): boolean {
    return this.isTenant;
  }

  isOwnerManagerDashboard(): boolean {
    return this.isOwnerManager;
  }

  overduePropertyCount(): number {
    if (this.hasAnyRole('owner', 'property_manager', 'super-admin')) {
      return this.ownerManagerSummary.overdue;
    }

    return this.properties.filter(property => property.rent_status === 'overdue').length;
  }

  autoPayActiveCount(): number {
    if (this.hasAnyRole('owner', 'property_manager', 'super-admin')) {
      return this.ownerManagerSummary.auto_pay_active;
    }

    return this.properties.filter(property => property.has_subscription === 1).length;
  }

  pendingApprovalCount(): number {
    if (this.hasAnyRole('owner', 'property_manager', 'super-admin')) {
      return this.ownerManagerSummary.approvals;
    }

    return (this.approvals?.length ?? 0) + (this.rentApprovals?.length ?? 0);
  }

  primaryAmountLabel(property: any): string {
    if (property?.rent_status === 'overdue') {
      return 'Overdue total';
    }

    if (property?.rent_status === 'paid') {
      return 'Monthly rent';
    }

    return 'Due now';
  }

  primaryAmountValue(property: any): number {
    if (property?.rent_status === 'overdue') {
      return property?.overdue_total || 0;
    }

    if (property?.rent_status === 'paid') {
      return property?.monthly_rent || 0;
    }

    return property?.payable_now_total || property?.monthly_rent || 0;
  }

  supportText(property: any): string {
    if (!property?.has_rent_deed) {
      return 'Payments are locked until the rent deed is created.';
    }

    if (property?.has_subscription === 3) {
      return `Auto-pay cancellation is scheduled${this.subscriptionEndsLabel(property) ? ` for ${this.subscriptionEndsLabel(property)}` : ' for month end'}.`;
    }

    if (property?.has_subscription === 2) {
      return 'Automatic rent payment has been cancelled.';
    }

    if (property?.rent_status === 'overdue' && property?.overdue_count > 0) {
      return `${property.overdue_count} missed rent ${property.overdue_count === 1 ? 'cycle' : 'cycles'} need attention.`;
    }

    if (property?.rent_status === 'pending') {
      return property?.late_fee_applied
        ? `Includes late fee of ${this.formatCurrency(property.late_fee_amount || 0)}.`
        : 'Current cycle is ready for payment.';
    }

    if (property?.has_subscription === 1) {
      return 'Future monthly rent will be charged automatically.';
    }

    return 'No payment action is required right now.';
  }

  shouldShowDetails(property: any): boolean {
    return !!property?.late_fee_policy_active
      || !!property?.overdue_count
      || property?.security_deposit_status === 'pending'
      || property?.security_deposit_status === 'manual_pending'
      || property?.has_subscription === 3
      || !!property?.media?.length
      || !property?.has_rent_deed;
  }

  togglePropertyDetails(tenancyId: number): void {
    if (this.expandedPropertyDetails.has(tenancyId)) {
      this.expandedPropertyDetails.delete(tenancyId);
      return;
    }

    this.expandedPropertyDetails.add(tenancyId);
  }

  isPropertyDetailsExpanded(tenancyId: number): boolean {
    return this.expandedPropertyDetails.has(tenancyId);
  }

}
