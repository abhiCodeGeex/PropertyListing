<?php

namespace App\Support;

class Currency
{
    /**
     * ISO 4217 code (uppercase). Driven by STRIPE_CURRENCY so Stripe and UI stay aligned.
     */
    public static function code(): string
    {
        return strtoupper((string) config('services.stripe.currency', 'INR'));
    }

    /**
     * Locale for number grouping (e.g. en-IN). Must match the Angular `currencyLocale` default.
     */
    public static function locale(): string
    {
        return (string) config('services.stripe.currency_locale', 'en-IN');
    }

    public static function symbol(): string
    {
        return match (self::code()) {
            'INR' => 'Rs',
            'USD' => '$',
            'EUR' => 'EUR',
            'GBP' => 'GBP',
            'AUD' => 'A$',
            'CAD' => 'C$',
            'SGD' => 'S$',
            default => self::code(),
        };
    }

    public static function format(mixed $amount): string
    {
        $value = (float) $amount;

        return self::symbol().' '.self::formatNumber($value);
    }

    private static function formatNumber(float $amount): string
    {
        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter(self::locale(), \NumberFormatter::DECIMAL);
            $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 2);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
            $formatted = $formatter->format($amount);

            if ($formatted !== false) {
                return (string) $formatted;
            }
        }

        if (self::locale() === 'en-IN') {
            return self::formatIndianNumber($amount);
        }

        return number_format($amount, 2, '.', ',');
    }

    private static function formatIndianNumber(float $amount): string
    {
        $formatted = number_format(abs($amount), 2, '.', '');
        [$wholePart, $fractionPart] = explode('.', $formatted);
        $lastThree = substr($wholePart, -3);
        $remaining = substr($wholePart, 0, -3);

        if ($remaining !== '') {
            $remaining = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $remaining);
            $wholePart = $remaining.','.$lastThree;
        } else {
            $wholePart = $lastThree;
        }

        $prefix = $amount < 0 ? '-' : '';

        return $prefix.$wholePart.'.'.$fractionPart;
    }
}
