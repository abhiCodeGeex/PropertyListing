<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class LateFeePolicyService
{
    public function parse(?string $policy): ?array
    {
        $policy = trim((string) $policy);

        if ($policy === '') {
            return null;
        }

        if (! preg_match('/(?:rs\.?|inr|₹)?\s*([0-9]+(?:\.[0-9]{1,2})?)/i', $policy, $amountMatch)) {
            return null;
        }

        $amount = round((float) $amountMatch[1], 2);

        if ($amount <= 0) {
            return null;
        }

        $graceDays = 0;

        if (preg_match('/after\s+(\d+)\s+day/i', $policy, $daysMatch)) {
            $graceDays = max(0, (int) $daysMatch[1]);
        }

        return [
            'type' => 'fixed',
            'amount' => $amount,
            'grace_days' => $graceDays,
            'policy_text' => $policy,
        ];
    }

    public function summarize(Collection $schedules, ?string $policy, Carbon $referenceDate): array
    {
        $parsed = $this->parse($policy);

        if (! $parsed) {
            return [
                'policy_text' => $policy,
                'type' => null,
                'grace_days' => 0,
                'base_total' => round((float) $schedules->sum('amount'), 2),
                'total' => 0.0,
                'applied_count' => 0,
                'items' => [],
            ];
        }

        $effectiveDate = $referenceDate->copy()->startOfDay();
        $items = $schedules->map(function ($schedule) use ($parsed, $effectiveDate) {
            $dueDate = Carbon::parse($schedule->due_date)->startOfDay();
            $lateAfter = $dueDate->copy()->addDays((int) $parsed['grace_days']);

            if (! $effectiveDate->gt($lateAfter)) {
                return null;
            }

            $labelMonth = $schedule->month
                ? Carbon::parse($schedule->month)->format('F Y')
                : $dueDate->format('F Y');

            return [
                'rent_schedule_id' => $schedule->id,
                'month' => $labelMonth,
                'due_date' => $dueDate->toDateString(),
                'amount' => round((float) $parsed['amount'], 2),
                'policy_text' => $parsed['policy_text'],
            ];
        })->filter()->values()->all();

        return [
            'policy_text' => $parsed['policy_text'],
            'type' => $parsed['type'],
            'grace_days' => $parsed['grace_days'],
            'base_total' => round((float) $schedules->sum('amount'), 2),
            'total' => round((float) collect($items)->sum('amount'), 2),
            'applied_count' => count($items),
            'items' => $items,
        ];
    }
}
