<?php

namespace App\Services;

use App\Models\Property;
use App\Models\PropertyTenant;
use App\Models\RentDeed;
use App\Models\RentSchedule;
use App\Models\User;
use App\Support\Currency;
use Carbon\Carbon;

class MessageService
{
    public static function notification(
        mixed $model,
        string $type,
        string $role,
        ?string $reason = null,
        array $context = []
    ): array {
        $propertyName = self::resolvePropertyName($model);
        $amount = self::resolveAmountFromContext($context) ?? self::resolveAmount($model);
        $dueDate = self::resolveDueDateFromContext($context) ?? self::resolveDueDate($model);
        $event = $context['event'] ?? null;
        $paymentType = $context['payment_type'] ?? null;
        $agreementEndDate = self::resolveAgreementEndDate($model, $context);
        $subscriptionCancelAt = self::resolveSubscriptionCancelAt($context);
        $daysRemaining = isset($context['days_remaining']) ? (int) $context['days_remaining'] : null;
        $subject = self::subjectFromType($type, $role, $event, $paymentType, $daysRemaining);
        $message = self::messageFromType($type, $role, $propertyName, $amount, $dueDate, $reason, $event, $paymentType, $agreementEndDate, $subscriptionCancelAt, $daysRemaining);

        return [
            'subject' => $subject,
            'message' => $message,
            'push_body' => $context['push_body'] ?? $message,
            'severity' => self::severityFor($type, $event),
            'preheader' => self::preheaderFor($subject, $propertyName),
            'greeting' => self::greetingFor($role),
            'intro' => self::introFor($type, $role, $propertyName, $amount, $dueDate, $reason, $event, $paymentType, $agreementEndDate, $subscriptionCancelAt, $daysRemaining),
            'details' => self::mailDetails($model, $type, $event, $propertyName, $amount, $dueDate, $paymentType, $reason, $agreementEndDate, $subscriptionCancelAt, $daysRemaining, $context),
            'closing' => self::closingFor($type, $event),
            'action_url' => self::actionUrlFor(),
            'action_label' => self::actionLabelFor($type, $event),
        ];
    }

    private static function messageFromType(
        string $type,
        string $role,
        string $propertyName,
        ?string $amount,
        ?string $dueDate,
        ?string $reason,
        ?string $event,
        ?string $paymentType,
        ?string $agreementEndDate,
        ?string $subscriptionCancelAt,
        ?int $daysRemaining
    ): string {
        $amountText = $amount ?? 'the applicable amount';
        $dueText = $dueDate ?? 'the scheduled date';
        $reasonText = $reason ?: 'No additional details were provided.';
        $paymentLabel = self::paymentLabel($paymentType);

        return match ($type) {
            'signup' => "Welcome to Property Listing. Your account is ready and your {$role} access has been prepared.",
            'rent_due' => match ($role) {
                'tenant' => "This is a reminder that rent of {$amountText} for {$propertyName} is due on {$dueText}.",
                'owner', 'manager' => "Rent of {$amountText} for {$propertyName} is due on {$dueText}. Please monitor collection.",
                default => "Rent of {$amountText} for {$propertyName} is due on {$dueText}.",
            },
            'rent_overdue' => match ($role) {
                'tenant' => "Our records show that rent of {$amountText} for {$propertyName} is overdue since {$dueText}.",
                'owner', 'manager' => "Rent of {$amountText} for {$propertyName} is overdue since {$dueText}. Please review the account.",
                default => "Rent of {$amountText} for {$propertyName} is overdue since {$dueText}.",
            },
            'rent_deposit' => self::rentDepositMessage($propertyName, $role, $event),
            'overdue' => match ($role) {
                'tenant' => "Your overdue rent payment for {$propertyName} has been recorded successfully.",
                'owner', 'manager' => "An overdue rent payment has been recorded for {$propertyName}.",
                default => "An overdue rent payment has been recorded for {$propertyName}.",
            },
            'security_deposit' => self::securityDepositMessage($propertyName, $role, $event),
            'security_deposit_paid' => "The security deposit for {$propertyName} has been recorded successfully.",
            'subscription' => self::subscriptionMessage($propertyName, $role, $event),
            'subscription_active' => "Automatic rent payment has been activated for {$propertyName}.",
            'subscription_cancelled' => "Automatic rent payment has been cancelled for {$propertyName}.",
            'payment_failed' => "A {$paymentLabel} payment for {$propertyName} was not successful. Reason: {$reasonText}",
            'agreement' => self::agreementMessage($propertyName, $role, $event, $agreementEndDate, $daysRemaining),
            'property' => self::propertyMessage($propertyName, $role, $event),
            default => "There is an update regarding {$propertyName}.",
        };
    }

    private static function introFor(
        string $type,
        string $role,
        string $propertyName,
        ?string $amount,
        ?string $dueDate,
        ?string $reason,
        ?string $event,
        ?string $paymentType,
        ?string $agreementEndDate,
        ?string $subscriptionCancelAt,
        ?int $daysRemaining
    ): string {
        $amountText = $amount ?? 'the applicable amount';
        $dueText = $dueDate ?? 'the scheduled date';
        $reasonText = $reason ?: 'No additional details were provided.';
        $paymentLabel = self::paymentLabel($paymentType);

        return match ($type) {
            'signup' => 'Your account has been created successfully. You can continue using the platform after completing any required verification steps.',
            'rent_due' => "Please ensure the rent payment for {$propertyName} is completed by {$dueText}. The amount currently due is {$amountText}.",
            'rent_overdue' => "The rent payment for {$propertyName} is overdue. Please review and take action as soon as possible.",
            'rent_deposit' => self::rentDepositIntro($propertyName, $role, $event),
            'overdue' => "The overdue rent payment for {$propertyName} has been processed and the account has been updated accordingly.",
            'security_deposit' => self::securityDepositIntro($propertyName, $role, $event),
            'security_deposit_paid' => "The security deposit for {$propertyName} has been confirmed and recorded.",
            'subscription' => self::subscriptionIntro($propertyName, $role, $event),
            'subscription_active' => "Automatic rent billing is now enabled for {$propertyName}. Future recurring payments will follow your subscription schedule.",
            'subscription_cancelled' => "Automatic rent billing for {$propertyName} has been cancelled. Future recurring charges will not be attempted unless a new subscription is activated.",
            'payment_failed' => "We were unable to complete a {$paymentLabel} payment for {$propertyName}. Please review the failure details and retry if required. Reason: {$reasonText}",
            'agreement' => self::agreementIntro($propertyName, $role, $event, $agreementEndDate, $daysRemaining),
            'property' => "There is an operational update related to {$propertyName}. Please review the details below.",
            default => "There is an update related to {$propertyName}. Please review the details below.",
        };
    }

    private static function agreementMessage(
        string $propertyName,
        string $role,
        ?string $event,
        ?string $agreementEndDate,
        ?int $daysRemaining
    ): string {
        $endDateText = $agreementEndDate ?? 'the recorded end date';
        $daysText = $daysRemaining === 1 ? '1 day' : "{$daysRemaining} days";

        return match ($event) {
            'created' => match ($role) {
                'tenant' => "Your rent deed for {$propertyName} has been created successfully.",
                'owner', 'manager' => "A rent deed has been created for {$propertyName}.",
                default => "A rent deed has been created for {$propertyName}.",
            },
            'updated' => match ($role) {
                'tenant' => "Your rent deed for {$propertyName} has been updated.",
                'owner', 'manager' => "The rent deed for {$propertyName} has been updated.",
                default => "The rent deed for {$propertyName} has been updated.",
            },
            'expiring' => match ($role) {
                'tenant' => "Your tenancy agreement for {$propertyName} will expire in {$daysText}, on {$endDateText}.",
                'owner', 'manager' => "The tenancy agreement for {$propertyName} will expire in {$daysText}, on {$endDateText}.",
                default => "The tenancy agreement for {$propertyName} will expire in {$daysText}, on {$endDateText}.",
            },
            'expired' => match ($role) {
                'tenant' => "Your tenancy agreement for {$propertyName} expired on {$endDateText}.",
                'owner', 'manager' => "The tenancy agreement for {$propertyName} expired on {$endDateText}.",
                default => "The tenancy agreement for {$propertyName} expired on {$endDateText}.",
            },
            default => "There is an agreement update for {$propertyName}.",
        };
    }

    private static function agreementIntro(
        string $propertyName,
        string $role,
        ?string $event,
        ?string $agreementEndDate,
        ?int $daysRemaining
    ): string {
        $endDateText = $agreementEndDate ?? 'the recorded end date';
        $daysText = $daysRemaining === 1 ? '1 day' : "{$daysRemaining} days";

        return match ($event) {
            'created' => $role === 'tenant'
                ? "A new rent deed has been recorded for {$propertyName}. You can review the agreement details from your dashboard."
                : "A new rent deed has been recorded for {$propertyName}. Please review the agreement details and keep your records updated.",
            'updated' => $role === 'tenant'
                ? "The rent deed for {$propertyName} has changed. Please review the updated agreement details from your dashboard."
                : "The rent deed for {$propertyName} has been updated. Please review the latest agreement details and ensure the records remain accurate.",
            'expiring' => $role === 'tenant'
                ? "Please coordinate renewal or move-out planning for {$propertyName}. The current tenancy end date is {$endDateText}, which is {$daysText} away."
                : "Please review renewal, vacancy planning, and final settlement for {$propertyName}. The current tenancy end date is {$endDateText}, which is {$daysText} away.",
            'expired' => $role === 'tenant'
                ? "The tenancy period for {$propertyName} has ended. Please contact the property team immediately to renew the agreement or complete move-out formalities."
                : "The tenancy period for {$propertyName} has ended. Please complete renewal, handover, and settlement actions as required.",
            default => "There is an agreement update for {$propertyName}.",
        };
    }

    private static function rentDepositMessage(string $propertyName, string $role, ?string $event): string
    {
        return match ($event) {
            'manual_requested' => $role === 'tenant'
                ? "Your manual rent payment request for {$propertyName} has been submitted and is awaiting review."
                : "A manual rent payment request for {$propertyName} requires your review.",
            'approved' => $role === 'tenant'
                ? "Your manual rent payment for {$propertyName} has been approved."
                : "The manual rent payment for {$propertyName} has been approved.",
            'declined' => $role === 'tenant'
                ? "Your manual rent payment for {$propertyName} has been declined."
                : "The manual rent payment for {$propertyName} has been declined.",
            'paid' => $role === 'tenant'
                ? "Your rent payment for {$propertyName} has been recorded successfully."
                : "A rent payment has been recorded for {$propertyName}.",
            default => "The rent payment status for {$propertyName} has been updated.",
        };
    }

    private static function rentDepositIntro(string $propertyName, string $role, ?string $event): string
    {
        return match ($event) {
            'manual_requested' => $role === 'tenant'
                ? 'Your request has been shared with the property owner or manager for review.'
                : "Please review the submitted rent payment request for {$propertyName} and take the appropriate action from your dashboard.",
            'approved' => $role === 'tenant'
                ? 'The submitted manual rent payment was accepted and the tenancy record has been updated.'
                : "The rent payment review has been completed successfully for {$propertyName}.",
            'declined' => $role === 'tenant'
                ? 'The submitted manual rent payment was not approved. Please contact the property owner or manager if you need clarification.'
                : 'The rent payment request was declined and the tenant will need to retry or use an alternate payment method.',
            'paid' => "The rent ledger for {$propertyName} has been updated to reflect the completed payment.",
            default => "There has been a rent payment update for {$propertyName}.",
        };
    }

    private static function securityDepositMessage(string $propertyName, string $role, ?string $event): string
    {
        return match ($event) {
            'manual_requested' => $role === 'tenant'
                ? "Your manual security deposit request for {$propertyName} has been submitted and is awaiting review."
                : "A manual security deposit request for {$propertyName} requires your review.",
            'approved' => $role === 'tenant'
                ? "Your security deposit for {$propertyName} has been approved."
                : "The security deposit for {$propertyName} has been approved.",
            'declined' => $role === 'tenant'
                ? "Your security deposit for {$propertyName} has been declined."
                : "The security deposit for {$propertyName} has been declined.",
            'paid' => "The security deposit for {$propertyName} has been recorded successfully.",
            default => "The security deposit status for {$propertyName} has been updated.",
        };
    }

    private static function securityDepositIntro(string $propertyName, string $role, ?string $event): string
    {
        return match ($event) {
            'manual_requested' => $role === 'tenant'
                ? 'Your request has been shared with the relevant property stakeholder for approval.'
                : "Please review the submitted security deposit request for {$propertyName}.",
            'approved' => 'The security deposit record has been approved and updated successfully.',
            'declined' => $role === 'tenant'
                ? 'The security deposit request was not approved. Please contact the property team if needed.'
                : 'The security deposit request was declined and no payment has been recorded.',
            'paid' => 'The tenancy deposit record has been updated to reflect the completed payment.',
            default => "There has been a security deposit update for {$propertyName}.",
        };
    }

    private static function subscriptionMessage(string $propertyName, string $role, ?string $event): string
    {
        return match ($event) {
            'activated' => "Automatic rent payment is now active for {$propertyName}.",
            'scheduled' => "Automatic rent payment for {$propertyName} is scheduled to end at the close of this month.",
            'past_due' => $role === 'tenant'
                ? "Your automatic rent payment for {$propertyName} is currently past due."
                : "The automatic rent payment for {$propertyName} is currently past due.",
            'cancelled' => "Automatic rent payment for {$propertyName} has been cancelled.",
            default => "The subscription status for {$propertyName} has changed.",
        };
    }

    private static function subscriptionIntro(string $propertyName, string $role, ?string $event): string
    {
        return match ($event) {
            'activated' => "Recurring billing has been set up successfully for {$propertyName}.",
            'scheduled' => 'Recurring billing remains active until the end of the current month. No further automatic rent charges will be attempted after the scheduled cancellation date.',
            'past_due' => $role === 'tenant'
                ? 'Please review your saved payment method or billing details to avoid interruption.'
                : 'Please review the tenant billing status and follow up if action is required.',
            'cancelled' => "Recurring billing for {$propertyName} has been stopped. Future invoices will require alternate payment handling.",
            default => "A subscription update has been recorded for {$propertyName}.",
        };
    }

    private static function propertyMessage(string $propertyName, string $role, ?string $event): string
    {
        return match ($event ?? 'default') {
            'tenant_assigned' => match ($role) {
                'tenant' => "A property has been assigned to you for {$propertyName}. You can now review your tenancy and payment details from the dashboard.",
                'owner' => "A tenant assignment has been recorded for {$propertyName}.",
                'manager' => "A tenant assignment has been recorded for {$propertyName}. Please review the tenancy details if follow-up is required.",
                default => "The property assignment for {$propertyName} has been updated.",
            },
            'manager_assigned' => match ($role) {
                'manager' => "You have been assigned as the property manager for {$propertyName}.",
                'owner' => "A property manager assignment has been updated for {$propertyName}.",
                'tenant' => "Your property record for {$propertyName} has been updated.",
                default => "The property record for {$propertyName} has been updated.",
            },
            default => "The property record for {$propertyName} has been updated.",
        };
    }

    private static function subjectFromType(string $type, string $role, ?string $event, ?string $paymentType, ?int $daysRemaining): string
    {
        return match ($type) {
            'signup' => 'Welcome to Property Listing',
            'rent_due' => $role === 'tenant' ? 'Rent Payment Reminder' : 'Tenant Rent Reminder',
            'rent_overdue' => $role === 'tenant' ? 'Rent Payment Overdue' : 'Tenant Rent Overdue',
            'rent_deposit' => match ($event) {
                'manual_requested' => $role === 'tenant' ? 'Manual Rent Payment Submitted' : 'Manual Rent Payment Review Required',
                'approved' => 'Manual Rent Payment Approved',
                'declined' => 'Manual Rent Payment Declined',
                'paid' => 'Rent Payment Confirmed',
                default => 'Rent Payment Update',
            },
            'security_deposit' => match ($event) {
                'manual_requested' => $role === 'tenant' ? 'Manual Security Deposit Submitted' : 'Security Deposit Review Required',
                'approved' => 'Security Deposit Approved',
                'declined' => 'Security Deposit Declined',
                'paid' => 'Security Deposit Confirmed',
                default => 'Security Deposit Update',
            },
            'subscription' => match ($event) {
                'activated' => 'Automatic Rent Payment Activated',
                'scheduled' => 'Automatic Rent Payment Cancellation Scheduled',
                'past_due' => 'Automatic Rent Payment Past Due',
                'cancelled' => 'Automatic Rent Payment Cancelled',
                default => 'Subscription Update',
            },
            'payment_failed' => self::paymentLabel($paymentType).' Payment Failed',
            'agreement' => match ($event) {
                'created' => 'Rent Deed Created',
                'updated' => 'Rent Deed Updated',
                'expiring' => 'Agreement Expiry Reminder: '.($daysRemaining === 1 ? '1 Day Remaining' : "{$daysRemaining} Days Remaining"),
                'expired' => 'Tenancy Agreement Expired',
                default => 'Agreement Update',
            },
            'property' => match ($event) {
                'tenant_assigned' => $role === 'tenant' ? 'Property Assigned to You' : 'Tenant Assigned to Property',
                'manager_assigned' => $role === 'manager' ? 'Property Assignment' : 'Property Manager Assigned',
                default => 'Property Update',
            },
            'overdue' => 'Overdue Rent Payment Confirmed',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    private static function severityFor(string $type, ?string $event): string
    {
        if ($type === 'payment_failed' || $event === 'declined' || $event === 'past_due' || $event === 'expired') {
            return 'danger';
        }

        if ($type === 'rent_due' || $type === 'rent_overdue' || $event === 'manual_requested' || $event === 'expiring') {
            return 'warning';
        }

        return 'success';
    }

    private static function preheaderFor(string $subject, string $propertyName): string
    {
        return "{$subject} for {$propertyName}.";
    }

    private static function greetingFor(string $role): string
    {
        return match ($role) {
            'owner' => 'Hello Owner,',
            'manager' => 'Hello Property Manager,',
            'tenant' => 'Hello Tenant,',
            default => 'Hello,',
        };
    }

    private static function closingFor(string $type, ?string $event): string
    {
        return match (true) {
            $type === 'payment_failed' => 'Please review the issue and retry the payment or contact support if the problem continues.',
            $type === 'agreement' && in_array($event, ['created', 'updated'], true) => 'You can review the latest agreement details from your dashboard at any time.',
            $type === 'agreement' && $event === 'expiring' => 'Please review the agreement timeline and complete renewal or exit planning before the tenancy end date.',
            $type === 'agreement' && $event === 'expired' => 'Please complete the next tenancy action immediately, including renewal, handover, and any pending settlement.',
            $event === 'manual_requested' => 'You can review the latest status from your dashboard at any time.',
            default => 'If you need help, please contact your property administrator or support team.',
        };
    }

    private static function actionUrlFor(): string
    {
        return rtrim(config('app.url'), '/').'/dashboard';
    }

    private static function actionLabelFor(string $type, ?string $event): string
    {
        return match (true) {
            $type === 'payment_failed' => 'Review Payment',
            $type === 'agreement' => $event === 'expired' ? 'Review Tenancy' : 'Review Agreement',
            $event === 'manual_requested' => 'Review Request',
            $type === 'subscription' => 'View Subscription',
            default => 'Open Dashboard',
        };
    }

    private static function mailDetails(
        mixed $model,
        string $type,
        ?string $event,
        string $propertyName,
        ?string $amount,
        ?string $dueDate,
        ?string $paymentType,
        ?string $reason,
        ?string $agreementEndDate,
        ?string $subscriptionCancelAt,
        ?int $daysRemaining,
        array $context = []
    ): array {
        if ($type === 'property' && $event === 'tenant_assigned' && $model instanceof PropertyTenant) {
            $details = [
                'Property Name' => $propertyName !== 'the property' ? $propertyName : null,
                'Tenant Name' => $model->tenant?->name,
                'Agreement Start Date' => self::formatDate($model->start_date),
                'Agreement End Date' => $agreementEndDate,
            ];

            return array_filter($details, fn ($value) => filled($value));
        }

        if ($type === 'agreement' && in_array($event, ['created', 'updated'], true) && $model instanceof RentDeed) {
            $details = [
                'Property Name' => $propertyName !== 'the property' ? $propertyName : null,
                'Agreement Number' => $model->agreement_number,
                'Agreement Date' => self::formatDate($model->agreement_date),
                'Rent Due Day' => $model->rent_due_date ? (string) $model->rent_due_date : null,
                'Maintenance Charges' => $model->maintenance_charges,
            ];

            return array_filter($details, fn ($value) => filled($value));
        }

        $details = [
            'Property Name' => $propertyName !== 'the property' ? $propertyName : null,
            'Recorded Amount' => self::detailAmountLabel($type, $event) ? $amount : null,
            'Base Rent' => self::formatCurrencyIfPresent($context['base_amount'] ?? null),
            'Late Fee' => self::formatCurrencyIfPresent($context['late_fee_amount'] ?? null),
            'Scheduled Due Date' => $dueDate,
            'Payment Category' => $paymentType ? self::paymentLabel($paymentType) : null,
            'Monthly Auto-Pay Amount' => $type === 'subscription' ? $amount : null,
            'Agreement End Date' => $agreementEndDate,
            'Subscription Ends On' => $subscriptionCancelAt,
            'Days Remaining' => $daysRemaining !== null ? (string) $daysRemaining : null,
            'Failure Reason' => $reason,
        ];

        return array_filter($details, fn ($value) => filled($value));
    }

    private static function paymentLabel(?string $paymentType): string
    {
        return match ($paymentType) {
            'rent_deposit' => 'Rent',
            'security_deposit' => 'Security deposit',
            'overdue' => 'Overdue rent',
            'late_fee' => 'Late fee',
            default => 'Payment',
        };
    }

    private static function detailAmountLabel(string $type, ?string $event): bool
    {
        return ! ($type === 'subscription' || ($type === 'property' && $event === 'tenant_assigned'));
    }

    private static function resolvePropertyName(mixed $model): string
    {
        if ($model instanceof RentSchedule) {
            return $model->tenancy?->property?->property_name ?? 'the property';
        }

        if ($model instanceof PropertyTenant) {
            return $model->property?->property_name ?? 'the property';
        }

        if ($model instanceof RentDeed) {
            return $model->property?->property_name ?? 'the property';
        }

        if ($model instanceof Property) {
            return $model->property_name ?? 'the property';
        }

        if ($model instanceof User) {
            return 'your account';
        }

        return 'the property';
    }

    private static function resolveAmount(mixed $model): ?string
    {
        if ($model instanceof RentSchedule) {
            return self::formatCurrency($model->amount);
        }

        if ($model instanceof PropertyTenant) {
            if ($model->relationLoaded('rentSchedule') && $model->rentSchedule) {
                $firstSchedule = $model->rentSchedule instanceof \Illuminate\Support\Collection
                    ? $model->rentSchedule->first()
                    : $model->rentSchedule;

                if ($firstSchedule?->amount) {
                    return self::formatCurrency($firstSchedule->amount);
                }
            }

            if ($model->security_deposit_amount !== null) {
                return self::formatCurrency($model->security_deposit_amount);
            }
        }

        return null;
    }

    private static function resolveDueDate(mixed $model): ?string
    {
        $date = null;

        if ($model instanceof RentSchedule) {
            $date = $model->due_date;
        } elseif ($model instanceof PropertyTenant && $model->relationLoaded('rentSchedule') && $model->rentSchedule) {
            $firstSchedule = $model->rentSchedule instanceof \Illuminate\Support\Collection
                ? $model->rentSchedule->first()
                : $model->rentSchedule;

            $date = $firstSchedule?->due_date;
        }

        if (! $date) {
            return null;
        }

        try {
            return Carbon::parse($date)->format('F j, Y');
        } catch (\Throwable) {
            return (string) $date;
        }
    }

    private static function resolveAgreementEndDate(mixed $model, array $context): ?string
    {
        $date = $context['agreement_end_date'] ?? null;

        if (! $date && $model instanceof PropertyTenant) {
            $date = $model->end_date;
        }

        if (! $date) {
            return null;
        }

        try {
            return Carbon::parse($date)->format('F j, Y');
        } catch (\Throwable) {
            return (string) $date;
        }
    }

    private static function resolveSubscriptionCancelAt(array $context): ?string
    {
        return self::formatDate($context['cancel_at'] ?? null);
    }

    private static function resolveAmountFromContext(array $context): ?string
    {
        if (! array_key_exists('amount', $context)) {
            return null;
        }

        return self::formatCurrency($context['amount']);
    }

    private static function resolveDueDateFromContext(array $context): ?string
    {
        return self::formatDate($context['due_date'] ?? null);
    }

    private static function formatDate(mixed $date): ?string
    {
        if (! $date) {
            return null;
        }

        try {
            return Carbon::parse($date)->format('F j, Y');
        } catch (\Throwable) {
            return (string) $date;
        }
    }

    private static function formatCurrency(mixed $amount): string
    {
        return Currency::format($amount);
    }

    private static function formatCurrencyIfPresent(mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        if (! is_numeric($amount)) {
            return null;
        }

        if ((float) $amount <= 0) {
            return null;
        }

        return self::formatCurrency($amount);
    }
}
