<?php

namespace App\Jobs;

use App\Models\RentSchedule;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendRentReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        Log::info('========== RENT REMINDER JOB STARTED ==========');

        try {
            $today = Carbon::today();
            Log::info("Today Date: {$today->toDateString()}");

            // Fetch all unpaid schedules
            $schedules = RentSchedule::with([
                'tenancy.tenant',
                'tenancy.property.owner',
                'tenancy.property.manager',
            ])
                ->whereIn('status', ['pending', 'overdue'])
                ->where('month', '<=', $today->format('Y-m-01'))
                ->where(function ($query) use ($today) {
                    $query->whereNull('last_reminder_at')
                        ->orWhereDate('last_reminder_at', '<', $today->toDateString());
                })
                ->get();

            Log::info("Total schedules found: {$schedules->count()}");

            foreach ($schedules as $schedule) {
                try {
                    Log::info("---- Processing Schedule ID: {$schedule->id} ----");

                    $tenant = $schedule->tenancy?->tenant;
                    $property = $schedule->tenancy?->property;

                    if (! $tenant || ! $property) {
                        Log::warning("Missing tenant/property for schedule ID: {$schedule->id}");

                        continue;
                    }

                    $dueDate = Carbon::parse($schedule->due_date);
                    $isOverdue = $today->gt($dueDate);

                    // Auto-mark overdue
                    if ($isOverdue && $schedule->status === 'pending') {
                        $schedule->update(['status' => 'overdue']);
                        Log::warning("Schedule marked as OVERDUE. ID: {$schedule->id}");
                    }

                    // Determine notification type
                    $type = $isOverdue ? 'rent_overdue' : 'rent_due';

                    // Send notifications & emails using NotificationService
                    foreach (NotificationService::stakeholdersForTenancy($schedule->tenancy) as $stakeholder) {
                        $role = $stakeholder['role'];
                        $user = $stakeholder['user'];
                        $title = match ($role) {
                            'tenant' => $isOverdue ? 'Rent OVERDUE' : 'Rent Reminder',
                            'owner' => $isOverdue ? 'Tenant Rent OVERDUE' : 'Tenant Rent Reminder',
                            'manager' => $isOverdue ? 'Rent OVERDUE Alert' : 'Rent Reminder Alert',
                            default => 'Rent Notification',
                        };

                        NotificationService::create(
                            $user->id,
                            $type,
                            $title,
                            $schedule,
                            \App\Mail\GenericMail::class, // optional mail class
                            null,
                            $role
                        );

                        Log::info("Notification created for {$role}: UserID {$user->id}");
                    }

                    // Update last reminder timestamp
                    $schedule->update(['last_reminder_at' => now()]);
                    Log::info("last_reminder_at updated for Schedule ID {$schedule->id}");

                    Log::info("---- DONE Schedule ID: {$schedule->id} ----");
                } catch (\Throwable $e) {
                    Log::error('Rent reminder skipped for schedule', [
                        'schedule_id' => $schedule->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('========== RENT REMINDER JOB COMPLETED ==========');
        } catch (\Throwable $e) {
            Log::critical('Rent Reminder Job CRASHED: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
