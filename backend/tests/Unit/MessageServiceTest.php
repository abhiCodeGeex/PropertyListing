<?php

namespace Tests\Unit;

use App\Models\Property;
use App\Models\PropertyTenant;
use App\Models\User;
use App\Services\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_property_tenant_assigned_notification_uses_tenancy_details_not_payment_amount(): void
    {
        $owner = User::factory()->create(['name' => 'Owner User']);
        $tenant = User::factory()->create(['name' => 'Tenant User']);

        $property = Property::create([
            'user_id' => $owner->id,
            'property_name' => 'property24mar',
            'property_type' => 'Residential',
            'state' => 'State',
            'city' => 'City',
            'address' => '123 Main Street',
            'monthly_rent' => 1200,
            'payment_mode' => 'UPI',
            'security_amount' => 1000,
            'electricity_bill_paid_by' => 'tenant',
        ]);

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-03-30',
            'end_date' => '2027-03-30',
            'security_deposit_amount' => 1000,
        ]);

        $tenancy->load(['property', 'tenant']);

        $payload = MessageService::notification(
            model: $tenancy,
            type: 'property',
            role: 'owner',
            reason: null,
            context: ['event' => 'tenant_assigned']
        );

        $this->assertSame('Tenant Assigned to Property', $payload['subject']);
        $this->assertArrayNotHasKey('Recorded Amount', $payload['details']);
        $this->assertSame('property24mar', $payload['details']['Property Name']);
        $this->assertSame('Tenant User', $payload['details']['Tenant Name']);
        $this->assertSame('March 30, 2026', $payload['details']['Agreement Start Date']);
        $this->assertSame('March 30, 2027', $payload['details']['Agreement End Date']);
    }

    public function test_payment_notification_prefers_explicit_context_amounts_and_late_fee_details(): void
    {
        $owner = User::factory()->create(['name' => 'Owner User']);
        $tenant = User::factory()->create(['name' => 'Tenant User']);

        $property = Property::create([
            'user_id' => $owner->id,
            'property_name' => 'Clear Ledger Residency',
            'property_type' => 'Residential',
            'state' => 'State',
            'city' => 'City',
            'address' => '123 Main Street',
            'monthly_rent' => 1200,
            'payment_mode' => 'UPI',
            'security_amount' => 1000,
            'electricity_bill_paid_by' => 'tenant',
        ]);

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-03-01',
            'end_date' => '2027-03-01',
            'security_deposit_amount' => 1000,
        ]);

        $tenancy->load('property');

        $payload = MessageService::notification(
            model: $tenancy,
            type: 'overdue',
            role: 'tenant',
            reason: null,
            context: [
                'event' => 'paid',
                'payment_type' => 'overdue',
                'amount' => 1700,
                'base_amount' => 1200,
                'late_fee_amount' => 500,
                'due_date' => '2026-03-07',
            ]
        );

        $this->assertSame('Rs 1,700.00', $payload['details']['Recorded Amount']);
        $this->assertSame('Rs 1,200.00', $payload['details']['Base Rent']);
        $this->assertSame('Rs 500.00', $payload['details']['Late Fee']);
        $this->assertSame('March 7, 2026', $payload['details']['Scheduled Due Date']);
        $this->assertSame('Overdue rent', $payload['details']['Payment Category']);
    }

    public function test_subscription_notification_shows_monthly_auto_pay_amount_from_context(): void
    {
        $owner = User::factory()->create(['name' => 'Owner User']);
        $tenant = User::factory()->create(['name' => 'Tenant User']);

        $property = Property::create([
            'user_id' => $owner->id,
            'property_name' => 'AutoPay Residency',
            'property_type' => 'Residential',
            'state' => 'State',
            'city' => 'City',
            'address' => '123 Main Street',
            'monthly_rent' => 1200,
            'payment_mode' => 'UPI',
            'security_amount' => 1000,
            'electricity_bill_paid_by' => 'tenant',
        ]);

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-03-01',
            'end_date' => '2027-03-01',
        ]);

        $tenancy->load('property');

        $payload = MessageService::notification(
            model: $tenancy,
            type: 'subscription',
            role: 'tenant',
            reason: null,
            context: [
                'event' => 'scheduled',
                'amount' => 1200,
                'cancel_at' => '2026-03-31 23:59:59',
            ]
        );

        $this->assertSame('Rs 1,200.00', $payload['details']['Monthly Auto-Pay Amount']);
        $this->assertArrayNotHasKey('Recorded Amount', $payload['details']);
        $this->assertSame('March 31, 2026', $payload['details']['Subscription Ends On']);
    }
}
