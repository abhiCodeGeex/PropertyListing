<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\PropertyTenant;
use App\Models\User;
use App\Services\MessageService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAgreementExpiryNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const REMINDER_DAYS = [30, 7, 1];

    public function handle(): void
    {
        $today = Carbon::today();
        $latestReminderDate = $today->copy()->addDays(max(self::REMINDER_DAYS));

        PropertyTenant::with(['tenant', 'property.owner', 'property.manager'])
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<=', $latestReminderDate)
            ->get()
            ->each(function (PropertyTenant $tenancy) use ($today): void {
                try {
                    $this->processTenancy($tenancy, $today);
                } catch (\Throwable $e) {
                    Log::error('Agreement expiry notification processing failed', [
                        'tenancy_id' => $tenancy->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }

    private function processTenancy(PropertyTenant $tenancy, Carbon $today): void
    {
        $endDate = Carbon::parse($tenancy->end_date)->startOfDay();

        if ($endDate->lt($today)) {
            if ($tenancy->hasLiveSubscription()) {
                $tenancy->forceFill([
                    'subscription_active' => 2,
                    'subscription_cancel_at' => null,
                ])->save();
            }

            $this->notifyStakeholdersOnce($tenancy, [
                'event' => 'expired',
                'agreement_end_date' => $endDate->toDateString(),
            ]);

            return;
        }

        $daysRemaining = (int) $today->diffInDays($endDate, false);

        if (! in_array($daysRemaining, self::REMINDER_DAYS, true)) {
            return;
        }

        $this->notifyStakeholdersOnce($tenancy, [
            'event' => 'expiring',
            'agreement_end_date' => $endDate->toDateString(),
            'days_remaining' => $daysRemaining,
        ]);
    }

    private function notifyStakeholdersOnce(PropertyTenant $tenancy, array $context): void
    {
        foreach (NotificationService::stakeholdersForTenancy($tenancy) as $stakeholder) {
            $role = $stakeholder['role'];
            /** @var User $user */
            $user = $stakeholder['user'];
            $payload = MessageService::notification($tenancy, 'agreement', $role, null, $context);

            if ($this->alreadySent($user->id, $tenancy->id, $payload['subject'])) {
                continue;
            }

            NotificationService::create(
                userId: $user->id,
                type: 'agreement',
                title: $payload['subject'],
                model: $tenancy,
                mailClass: null,
                reason: null,
                role: $role,
                context: $context
            );

            NotificationService::sendPush(
                $user,
                $payload['subject'],
                $payload['push_body'] ?? $payload['message'],
                [
                    'tenancy_id' => $tenancy->id,
                    'event' => $context['event'] ?? 'updated',
                ]
            );
        }
    }

    private function alreadySent(int $userId, int $tenancyId, string $title): bool
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->where('type', 'agreement')
            ->where('title', $title)
            ->where('notifiable_type', PropertyTenant::class)
            ->where('notifiable_id', $tenancyId)
            ->exists();
    }
}
