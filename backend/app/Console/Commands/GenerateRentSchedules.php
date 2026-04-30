<?php

namespace App\Console\Commands;

use App\Models\PropertyTenant;
use App\Models\RentSchedule;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateRentSchedules extends Command
{
    protected $signature = 'rent:generate {tenancy_id?}';

    protected $description = 'Generate rent schedules for a tenancy';

    public function handle(): int
    {
        $query = PropertyTenant::with('property');

        if ($this->argument('tenancy_id')) {
            $query->where('id', $this->argument('tenancy_id'));
        }

        $tenancies = $query->get();

        foreach ($tenancies as $tenancy) {
            if (! $tenancy->property) {
                $this->warn("Skipping tenancy {$tenancy->id} — property missing");

                continue;
            }

            $this->generateForTenancy($tenancy);
        }

        $this->info('Rent schedules generated successfully.');

        return Command::SUCCESS;
    }

    private function generateForTenancy(PropertyTenant $tenancy): void
    {
        $rentDeed = $tenancy->resolveRentDeed();
        Log::info("Generating rent schedules for tenancy {$tenancy->id}");
        // If no rent deed or tenancy range, do not generate schedule
        if (! $rentDeed || ! $rentDeed->rent_due_date || ! $tenancy->end_date) {
            Log::warning("Tenancy {$tenancy->id} missing rent_due_date");

            return;
        }

        $dueDay = (int) $rentDeed->rent_due_date;
        $dueDay = max(1, min(28, $dueDay));

        $start = Carbon::parse($tenancy->start_date)->startOfMonth();
        $end = Carbon::parse($tenancy->end_date)->startOfMonth();

        $monthlyRent = $tenancy->resolvedMonthlyRentAmount();

        while ($start <= $end) {
            $dueDate = $start->copy()->day($dueDay);
            $schedule = RentSchedule::firstOrNew([
                'tenancy_id' => $tenancy->id,
                'month' => $start->format('Y-m-01'),
            ]);

            if (! $schedule->exists || $schedule->status !== 'paid') {
                $schedule->amount = $monthlyRent;
                $schedule->due_date = $dueDate;
                $schedule->status = $schedule->exists ? $schedule->status : 'pending';
                $schedule->save();
            }

            $start->addMonth();
        }
    }
}
