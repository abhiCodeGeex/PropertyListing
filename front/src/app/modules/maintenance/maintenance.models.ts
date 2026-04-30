export interface MaintenanceUserSummary {
  id: number;
  name: string;
  email: string;
}

export interface MaintenancePropertySummary {
  id: number;
  property_name: string;
  city?: string | null;
  state?: string | null;
  address?: string | null;
  owner?: MaintenanceUserSummary | null;
  manager?: MaintenanceUserSummary | null;
}

export interface MaintenanceAttachment {
  id: number;
  maintenance_comment_id?: number | null;
  original_name: string;
  mime_type: string;
  size: number;
  created_at: string;
  download_endpoint: string;
  uploaded_by?: MaintenanceUserSummary | null;
}

export interface MaintenanceComment {
  id: number;
  body: string;
  created_at: string;
  updated_at: string;
  user?: MaintenanceUserSummary | null;
  attachments: MaintenanceAttachment[];
}

export interface MaintenanceStatusHistory {
  id: number;
  action: string;
  from_status?: string | null;
  to_status?: string | null;
  reason?: string | null;
  message?: string | null;
  metadata?: Record<string, any> | null;
  created_at: string;
  user?: MaintenanceUserSummary | null;
}

export interface MaintenanceRequest {
  id: number;
  property_id: number;
  tenancy_id: number | null;
  tenant_id: number;
  assigned_to: number | null;
  title: string;
  description: string;
  category?: string | null;
  priority: string;
  status: string;
  attachments_count: number;
  comments_count: number;
  last_activity_at?: string | null;
  resolved_at?: string | null;
  created_at: string;
  updated_at: string;
  property: MaintenancePropertySummary;
  tenant?: MaintenanceUserSummary | null;
  assignee?: MaintenanceUserSummary | null;
  attachments?: MaintenanceAttachment[];
  comments?: MaintenanceComment[];
  status_history?: MaintenanceStatusHistory[];
}

export interface MaintenanceFilters {
  page?: number;
  per_page?: number;
  status?: string;
  priority?: string;
  property_id?: number | string;
  search?: string;
  sort_by?: string;
  sort_order?: 'asc' | 'desc';
}

export interface MaintenanceRealtimePayload {
  request_id: number;
  status: string;
  message: string;
  timestamp: string;
  property_id?: number;
  comment_id?: number;
  action?: string;
  previous_status?: string | null;
  assigned_to?: number | null;
  user_id?: number;
}

export interface MaintenanceWorkspaceSummary {
  total: number;
  pending: number;
  in_progress: number;
  urgent: number;
}

export const MAINTENANCE_PRIORITIES = ['low', 'medium', 'high', 'urgent'];

export const MAINTENANCE_STATUS_LABELS: Record<string, string> = {
  pending: 'Pending',
  approved: 'Approved',
  rejected: 'Rejected',
  assigned: 'Assigned',
  in_progress: 'In Progress',
  on_hold: 'On Hold',
  completed: 'Completed',
  cancelled: 'Cancelled'
};

export function maintenanceStatusLabel(status: string): string {
  return MAINTENANCE_STATUS_LABELS[status] ?? status.replace(/_/g, ' ');
}

export function maintenanceStatusBadgeClass(status: string): string {
  return ({
    pending: 'text-bg-warning',
    approved: 'text-bg-info',
    rejected: 'text-bg-danger',
    assigned: 'text-bg-primary',
    in_progress: 'text-bg-primary',
    on_hold: 'text-bg-secondary',
    completed: 'text-bg-success',
    cancelled: 'text-bg-dark'
  } as Record<string, string>)[status] ?? 'text-bg-secondary';
}

export function maintenancePriorityBadgeClass(priority: string): string {
  return ({
    low: 'text-bg-light',
    medium: 'text-bg-info',
    high: 'text-bg-warning',
    urgent: 'text-bg-danger'
  } as Record<string, string>)[priority] ?? 'text-bg-secondary';
}

export function maintenancePriorityLabel(priority: string): string {
  return priority ? `${priority.charAt(0).toUpperCase()}${priority.slice(1)}` : 'Unknown';
}
