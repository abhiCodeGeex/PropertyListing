<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;

class StripeConnectService
{
    public function userCanManageConnect(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'property_manager']);
    }

    public function ensureEligibleUser(User $user): void
    {
        if (! $this->userCanManageConnect($user)) {
            throw ValidationException::withMessages([
                'stripe_connect' => ['Stripe Connect is only available for owners and property managers.'],
            ]);
        }
    }

    public function createOrGetConnectedAccount(User $user): string
    {
        $this->ensureEligibleUser($user);

        if ($user->stripe_connect_account_id) {
            return $user->stripe_connect_account_id;
        }

        $stripe = new StripeClient((string) config('services.stripe.secret'));
        $account = $stripe->accounts->create([
            'type' => 'express',
            'email' => $user->email,
            'metadata' => [
                'user_id' => (string) $user->id,
                'roles' => implode(',', $user->roles()->pluck('name')->all()),
            ],
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
        ]);

        $user->forceFill([
            'stripe_connect_account_id' => $account->id,
        ])->save();

        $this->syncAccountStatus($user);

        return $account->id;
    }

    public function createOnboardingLink(User $user): array
    {
        $accountId = $this->createOrGetConnectedAccount($user);
        $stripe = new StripeClient((string) config('services.stripe.secret'));
        $frontendUrl = rtrim((string) config('app.url_frontend'), '/');

        $link = $stripe->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => $frontendUrl.'/profile?stripe_connect=refresh',
            'return_url' => $frontendUrl.'/profile?stripe_connect=return',
            'type' => 'account_onboarding',
        ]);

        return [
            'account_id' => $accountId,
            'url' => $link->url,
            'expires_at' => $link->expires_at ?? null,
        ];
    }

    public function syncAccountStatus(User $user): array
    {
        $this->ensureEligibleUser($user);

        if (! $user->stripe_connect_account_id) {
            return $this->statusPayload($user);
        }

        $stripe = new StripeClient((string) config('services.stripe.secret'));
        $account = $stripe->accounts->retrieve($user->stripe_connect_account_id, []);

        $user->forceFill([
            'stripe_connect_details_submitted' => (bool) ($account->details_submitted ?? false),
            'stripe_connect_charges_enabled' => (bool) ($account->charges_enabled ?? false),
            'stripe_connect_payouts_enabled' => (bool) ($account->payouts_enabled ?? false),
            'stripe_connect_onboarded_at' => ($account->details_submitted ?? false) ? ($user->stripe_connect_onboarded_at ?? Carbon::now()) : null,
        ])->save();

        return $this->statusPayload($user->fresh());
    }

    public function statusPayload(User $user): array
    {
        return [
            'eligible' => $this->userCanManageConnect($user),
            'account_id' => $user->stripe_connect_account_id,
            'details_submitted' => (bool) $user->stripe_connect_details_submitted,
            'charges_enabled' => (bool) $user->stripe_connect_charges_enabled,
            'payouts_enabled' => (bool) $user->stripe_connect_payouts_enabled,
            'onboarded_at' => $user->stripe_connect_onboarded_at?->toIso8601String(),
            'ready_for_payouts' => (bool) $user->stripe_connect_account_id
                && (bool) $user->stripe_connect_details_submitted
                && (bool) $user->stripe_connect_charges_enabled
                && (bool) $user->stripe_connect_payouts_enabled,
        ];
    }
}
