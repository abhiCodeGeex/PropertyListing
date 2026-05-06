export interface PropertyUser {
  id: number;
  name: string;
  email?: string;
}

export interface PropertyMedia {
  id: number;
  file_url: string;
  file_type: 'image' | 'document';
  mime_type?: string | null;
}

export interface Property {
  id: number;
  propertyName: string;
  propertyType: 'Residential' | 'Commercial';
  furnishingType: 'Unfurnished' | 'Semi-Furnished' | 'Fully-Furnished';
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
  media?: PropertyMedia[];
  manager_id?: number | null;
}
