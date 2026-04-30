<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Mail\GenericNotificationMail;
use App\Models\Notification as NotificationModel;
use App\Models\PropertyTenant;
use App\Models\RentSchedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    /**
     * Create notification (DB + broadcast + email)
     */
    public static function create(
        int $userId,
        string $type,
        ?string $title,
        mixed $model = null,
        ?string $mailClass = null,
        ?string $reason = null,
        string $role = 'tenant',
        array $context = []
    ): void {
        Log::channel('daily')->info('Notification::create started', compact(
            'userId',
            'type',
            'title',
            'role',
            'reason'
        ));

        Log::info('Building notification message', [
            'type' => $type,
            'role' => $role,
            'model_type' => is_object($model) ? get_class($model) : null,
            'model_id' => is_object($model) ? $model->id : null,
        ]);

        $data = MessageService::notification($model, $type, $role, $reason, $context);
        $message = $data['message'];
        $subject = $title ?? $data['subject'];

        $existingNotification = NotificationModel::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('title', $subject)
            ->where('message', strip_tags($message))
            ->where('notifiable_type', is_object($model) ? get_class($model) : null)
            ->where('notifiable_id', is_object($model) ? $model->id : null)
            ->where('created_at', '>=', Carbon::now()->subMinutes(10))
            ->latest('id')
            ->first();

        if ($existingNotification) {
            Log::info('Skipping duplicate notification', [
                'user_id' => $userId,
                'type' => $type,
                'notification_id' => $existingNotification->id,
            ]);

            return;
        }

        $notification = NotificationModel::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $subject,
            'message' => strip_tags($message),
            'notifiable_type' => is_object($model) ? get_class($model) : null,
            'notifiable_id' => is_object($model) ? $model->id : null,
        ]);

        try {
            broadcast(new NotificationCreated(
                notification: $notification->toArray(),
                userId: $userId,
                context: $context
            ))->toOthers();
        } catch (\Throwable $e) {
            Log::error('Notification broadcast failed', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $user = User::find($userId);

            if ($user && $user->email) {
                self::sendGenericNotificationEmail($user, $data);
            } else {
                Log::warning('User not found or email missing', [
                    'user_id' => $userId,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Email flow failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function sendGenericNotificationEmail(User $user, array $payload): void
    {
        try {
            Mail::to($user->email)->queue(new GenericNotificationMail($payload));
        } catch (\Throwable $e) {
            Log::warning('Queued notification email failed, falling back to immediate send', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            try {
                Mail::to($user->email)->send(new GenericNotificationMail($payload));
            } catch (\Throwable $fallbackError) {
                Log::error('Notification email failed', [
                    'user_id' => $user->id,
                    'error' => $fallbackError->getMessage(),
                ]);
            }
        }
    }

    public static function notifyStakeholders(
        Collection $stakeholders,
        string $type,
        mixed $model = null,
        ?string $reason = null,
        array $context = [],
        array $pushData = []
    ): void {
        foreach ($stakeholders as $stakeholder) {
            $role = $stakeholder['role'];
            $user = $stakeholder['user'];
            $payload = MessageService::notification($model, $type, $role, $reason, $context);

            self::create(
                userId: $user->id,
                type: $type,
                title: $payload['subject'],
                model: $model,
                mailClass: null,
                reason: $reason,
                role: $role,
                context: $context
            );
        }
    }

    public static function notifyTenancyStakeholders(
        PropertyTenant $tenancy,
        string $type,
        ?string $reason = null,
        array $context = [],
        array $roles = ['tenant', 'owner', 'manager'],
        array $pushData = []
    ): void {
        self::notifyStakeholders(
            stakeholders: self::stakeholdersForTenancy($tenancy, $roles),
            type: $type,
            model: $tenancy,
            reason: $reason,
            context: $context,
            pushData: array_merge(['tenancy_id' => $tenancy->id], $pushData)
        );
    }

    public static function sendInvoice(
        int $userId,
        RentSchedule|PropertyTenant $model,
        string $invoiceType = 'rent_and_deposit'
    ): void {
        Log::warning('Legacy sendInvoice call received', [
            'user_id' => $userId,
            'invoice_type' => $invoiceType,
            'model_type' => get_class($model),
            'model_id' => $model->id,
        ]);

        if (! $model instanceof PropertyTenant || (int) $model->tenant_id !== (int) $userId) {
            return;
        }

        $type = match ($invoiceType) {
            'deposit' => 'security_deposit',
            default => $invoiceType,
        };

        $context = [];

        if ($type === 'rent') {
            $context['rent_schedules'] = $model->rentSchedule instanceof Collection
                ? $model->rentSchedule
                : collect($model->rentSchedule ? [$model->rentSchedule] : []);
        }

        if ($type === 'overdue' && $model->relationLoaded('overdueSummary')) {
            $context['overdue_summary'] = $model->overdueSummary instanceof Collection
                ? $model->overdueSummary->all()
                : (array) $model->overdueSummary;
        }

        app(InvoiceIssueService::class)->issueTenantInvoice(
            tenancy: $model,
            type: $type,
            sourceKey: sprintf('legacy:%s:%s:%s', $type, $model->id, $userId),
            context: $context
        );
    }

    public static function sendPush(
        User $user,
        string $title,
        string $body,
        array $data = []
    ): void {
        Log::info('Push notification skipped: no push transport configured', [
            'user_id' => $user->id,
            'title' => $title,
            'data' => $data,
        ]);
    }

    public static function stakeholdersForTenancy(
        PropertyTenant $tenancy,
        array $roles = ['tenant', 'owner', 'manager']
    ): Collection {
        $property = $tenancy->property;

        $users = collect([
            'tenant' => $tenancy->tenant,
            'owner' => $property?->owner,
            'manager' => $property?->manager,
        ]);

        return $users
            ->only($roles)
            ->filter()
            ->unique(fn (User $user) => $user->id)
            ->map(fn (User $user, string $role) => [
                'role' => $role,
                'user' => $user,
            ])
            ->values();
    }
}
