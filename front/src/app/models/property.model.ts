export interface PropertyUser {
  id: number;
  name: string;
  email?: string;
}

export interface Property {
  id: number;
  propertyName: string;
  propertyType: 'Residential' | 'Commercial';
  state: string;
  city: string;
  address: string;
  monthlyRent: number;
  paymentMode: 'UPI' | 'Cash' | 'Credit/Debit Cards';
  securityAmount: number;
  refundTerms: string;
  agreementDuration: string;
  maintenanceResponsibilities: string;
  terminationClause: string;
  latePaymentPenalty: string;
  electricityBillPaidBy: 'owner' | 'tenant';
  owner?: PropertyUser | null;
  manager?: PropertyUser | null;
  tenants?: PropertyUser[];
  manager_id?: number | null;
}
