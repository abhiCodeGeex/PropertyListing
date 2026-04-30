<?php

namespace App\Console\Commands;

use App\Models\RentSchedule;
use Carbon\Carbon;
use Illuminate\Console\Command;

class UpdateOverdueRent extends Command
{
    protected $signature = 'rent:update-overdue';

    protected $description = 'Mark overdue rent schedules';

    public function handle(): int
    {
        $today = Carbon::today()->toDateString();
        $affected = RentSchedule::where('status', 'pending')
            ->whereDate('due_date', '<', $today)
            ->update([
                'status' => 'overdue',
            ]);

        $this->info("{$affected} rent schedules marked as overdue.");

        return Command::SUCCESS;
    }
}
