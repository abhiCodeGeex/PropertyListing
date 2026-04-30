<?php

namespace Tests\Feature;

use App\Jobs\SendAgreementExpiryNotificationsJob;
use App\Jobs\SendInvoiceEmail;
use App\Jobs\SendRentReminderJob;
use App\Mail\RentDeedMail;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Property;
use App\Models\PropertyTenant;
use App\Models\RentDeed;
use App\Models\RentSchedule;
use App\Models\User;
use App\Services\StripePriceService;
use App\Services\StripePayoutService;
use App\Services\StripeSubscriptionService;
use App\Services\InvoicePdfService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RentAccessAndHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_rent_history_filters_by_payment_property_id(): void
    {
        $owner = User::factory()->create();
        Role::findOrCreate('owner', 'web');
        $owner->assignRole('owner');

        $tenant = User::factory()->create();

        $firstProperty = $this->createProperty($owner->id, 'Alpha Residency');
        $secondProperty = $this->createProperty($owner->id, 'Beta Residency');

        $firstTenancy = PropertyTenant::create([
            'property_id' => $firstProperty->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01',
        ]);

        $secondTenancy = PropertyTenant::create([
            'property_id' => $secondProperty->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01',
        ]);

        $firstSchedule = RentSchedule::create([
            'tenancy_id' => $firstTenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-05',
            'amount' => 1200,
            'status' => 'paid',
        ]);

        $secondSchedule = RentSchedule::create([
            'tenancy_id' => $secondTenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-05',
            'amount' => 1450,
            'status' => 'paid',
        ]);

        Payment::create([
            'rent_schedule_id' => $firstSchedule->id,
            'tenant_id' => $tenant->id,
            'property_id' => $firstProperty->id,
            'amount' => 1200,
            'status' => 'succeeded',
            'type' => 'rent_deposit',
        ]);

        Payment::create([
            'rent_schedule_id' => $secondSchedule->id,
            'tenant_id' => $tenant->id,
            'property_id' => $secondProperty->id,
            'amount' => 1450,
            'status' => 'succeeded',
            'type' => 'rent_deposit',
        ]);

        $response = $this
            ->actingAs($owner, 'api')
            ->getJson('/api/rent-history?propertyId='.$firstProperty->id);

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.propertyName', 'Alpha Residency');
    }

    public function test_tenant_cannot_fetch_overdues_for_another_tenant_tenancy(): void
    {
        $owner = User::factory()->create();
        $tenant = User::factory()->create();
        $otherTenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Gamma Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $otherTenant->id,
            'start_date' => '2026-01-01',
        ]);

        RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-05',
            'amount' => 1500,
            'status' => 'overdue',
        ]);

        $this
            ->actingAs($tenant, 'api')
            ->getJson('/api/rents/overdue/'.$tenancy->id)
            ->assertNotFound();
    }

    public function test_pay_overdues_returns_validation_error_when_no_overdues_exist(): void
    {
        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Delta Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01',
        ]);

        RentDeed::create([
            'agreement_number' => 'AGR-CATCHUP-001',
            'agreement_date' => '2026-01-01',
            'owner_id' => $owner->id,
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'rent_due_date' => 7,
            'maintenance_charges' => 'Tenant',
        ]);

        RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-05',
            'amount' => 1500,
            'status' => 'paid',
        ]);

        $this
            ->actingAs($tenant, 'api')
            ->postJson('/api/rents/pay-overdue', ['tenancy_id' => $tenancy->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tenancy_id');
    }

    public function test_rent_deed_creation_requires_property_to_have_an_assigned_tenant(): void
    {
        Role::findOrCreate('owner', 'web');

        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $property = $this->createProperty($owner->id, 'Validation Residency');

        $this
            ->actingAs($owner, 'api')
            ->postJson('/api/properties/rent-deeds', [
                'agreementNumber' => 'AGR-VALIDATION-001',
                'agreementDate' => '2026-04-01',
                'propertyId' => ['id' => $property->id],
                'size' => '1200 sq ft',
                'usage' => 'Residential',
                'rentDueDate' => 5,
                'maintenanceCharges' => 'Tenant',
                'otherDetails' => 'Test deed',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('propertyId.id')
            ->assertJsonFragment([
                'Assign a tenant to this property before creating a rent deed.',
            ]);
    }

    public function test_rent_deed_creation_emails_pdf_to_related_property_users(): void
    {
        Mail::fake();
        Role::findOrCreate('owner', 'web');
        Role::findOrCreate('tenant', 'web');
        Role::findOrCreate('property_manager', 'web');

        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $owner->assignRole('owner');

        $tenant = User::factory()->create(['email' => 'tenant@example.com']);
        $tenant->assignRole('tenant');

        $manager = User::factory()->create(['email' => 'manager@example.com']);
        $manager->assignRole('property_manager');

        $property = $this->createProperty($owner->id, 'Mail Residency');
        $property->update(['manager_id' => $manager->id]);

        PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
        ]);

        $this
            ->actingAs($owner, 'api')
            ->postJson('/api/properties/rent-deeds', [
                'agreementNumber' => 'AGR-MAIL-001',
                'agreementDate' => '2026-04-01',
                'propertyId' => ['id' => $property->id],
                'size' => '1200 sq ft',
                'usage' => 'Residential',
                'rentDueDate' => 5,
                'maintenanceCharges' => 'Tenant',
                'otherDetails' => 'PDF should be mailed',
            ])
            ->assertCreated();

        Mail::assertQueued(RentDeedMail::class, 3);
        Mail::assertQueued(RentDeedMail::class, fn (RentDeedMail $mail) => $mail->hasTo('owner@example.com'));
        Mail::assertQueued(RentDeedMail::class, fn (RentDeedMail $mail) => $mail->hasTo('tenant@example.com'));
        Mail::assertQueued(RentDeedMail::class, fn (RentDeedMail $mail) => $mail->hasTo('manager@example.com'));

        $this->assertSame(3, Notification::where('type', 'agreement')->count());
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'agreement',
            'title' => 'Rent Deed Created',
            'notifiable_type' => RentDeed::class,
        ]);
    }

    public function test_overdue_listing_includes_all_due_unpaid_rent_through_current_month(): void
    {
        Carbon::setTestNow('2026-03-30 10:00:00');

        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Catchup Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01',
        ]);

        RentDeed::create([
            'agreement_number' => 'AGR-CATCHUP-002',
            'agreement_date' => '2026-01-01',
            'owner_id' => $owner->id,
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'rent_due_date' => 7,
            'maintenance_charges' => 'Tenant',
        ]);

        RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-02-01',
            'due_date' => '2026-02-07',
            'amount' => 1500,
            'status' => 'overdue',
        ]);

        RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-07',
            'amount' => 1500,
            'status' => 'pending',
        ]);

        RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-04-01',
            'due_date' => '2026-04-07',
            'amount' => 1500,
            'status' => 'pending',
        ]);

        $this
            ->actingAs($tenant, 'api')
            ->getJson('/api/rents/overdue/'.$tenancy->id)
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('total', 3000)
            ->assertJsonPath('late_payment_penalty_policy', null)
            ->assertJsonPath('late_fee_applied', false);

        Carbon::setTestNow();
    }

    public function test_overdue_listing_respects_late_fee_grace_period_boundary(): void
    {
        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Grace Boundary Residency');
        $property->update([
            'late_payment_penalty' => '500 fixed penalty after 3 days',
        ]);

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01',
        ]);

        RentDeed::create([
            'agreement_number' => 'AGR-GRACE-001',
            'agreement_date' => '2026-01-01',
            'owner_id' => $owner->id,
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'rent_due_date' => 7,
            'maintenance_charges' => 'Tenant',
        ]);

        RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-07',
            'amount' => 1500,
            'status' => 'pending',
        ]);

        Carbon::setTestNow('2026-03-10 10:00:00');

        $this
            ->actingAs($tenant, 'api')
            ->getJson('/api/rents/overdue/'.$tenancy->id)
            ->assertOk()
            ->assertJsonPath('late_fee_total', 0)
            ->assertJsonPath('late_fee_applied', false)
            ->assertJsonPath('total', 1500);

        Carbon::setTestNow('2026-03-11 10:00:00');

        $this
            ->actingAs($tenant, 'api')
            ->getJson('/api/rents/overdue/'.$tenancy->id)
            ->assertOk()
            ->assertJsonPath('late_fee_total', 500)
            ->assertJsonPath('late_fee_applied', true)
            ->assertJsonPath('total', 2000);

        Carbon::setTestNow();
    }

    public function test_assigned_properties_exposes_payable_amount_with_configured_late_fee(): void
    {
        Carbon::setTestNow('2026-03-30 10:00:00');

        Role::findOrCreate('tenant', 'web');

        $owner = User::factory()->create();
        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $property = $this->createProperty($owner->id, 'Fair Billing Residency');
        $property->update([
            'monthly_rent' => 1200,
            'late_payment_penalty' => '500 fixed penalty after due date',
            'payment_mode' => 'Credit/Debit Cards',
        ]);

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01',
        ]);

        RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-02-01',
            'due_date' => '2026-02-07',
            'amount' => 1200,
            'status' => 'overdue',
        ]);

        RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-07',
            'amount' => 1200,
            'status' => 'pending',
        ]);

        $this
            ->actingAs($tenant, 'api')
            ->postJson('/api/users/assigned-properties', ['userId' => $tenant->id])
            ->assertOk()
            ->assertJsonPath('property.0.payable_now_total', 3400)
            ->assertJsonPath('property.0.payable_now_base_total', 2400)
            ->assertJsonPath('property.0.current_month_due_amount', 1200)
            ->assertJsonPath('property.0.current_month_late_fee_amount', 500)
            ->assertJsonPath('property.0.missed_rent_total', 1200)
            ->assertJsonPath('property.0.missed_rent_payable_total', 1700)
            ->assertJsonPath('property.0.missed_rent_late_fee_total', 500)
            ->assertJsonPath('property.0.autopay_monthly_amount', 1200)
            ->assertJsonPath('property.0.late_fee_amount', 1000)
            ->assertJsonPath('property.0.late_fee_applied', true)
            ->assertJsonPath('property.0.late_payment_penalty_policy', '500 fixed penalty after due date')
            ->assertJsonPath('property.0.late_fee_policy_active', true);

        Carbon::setTestNow();
    }

    public function test_webhook_failure_marks_existing_payment_attempt_as_failed_with_reason(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');

        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Epsilon Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01',
            'security_deposit_amount' => 750,
        ]);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'amount' => 750,
            'type' => 'security_deposit',
            'status' => 'pending',
            'payment_mode' => 'stripe',
            'stripe_payment_intent_id' => 'pi_test_failed',
        ]);

        $payload = json_encode([
            'id' => 'evt_test_failed',
            'object' => 'event',
            'type' => 'payment_intent.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'pi_test_failed',
                    'object' => 'payment_intent',
                    'amount' => 75000,
                    'metadata' => [
                        'tenancy_id' => (string) $tenancy->id,
                        'type' => 'security_deposit',
                    ],
                    'last_payment_error' => [
                        'message' => 'Your card was declined.',
                        'charge' => 'ch_test_failed',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $signature = $this->stripeSignature($payload, config('services.stripe.webhook_secret'));

        $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => $signature,
            ],
            $payload
        )->assertOk();

        $payment->refresh();

        $this->assertSame('failed', $payment->status);
        $this->assertSame('Your card was declined.', $payment->failure_reason);
        $this->assertSame('ch_test_failed', $payment->stripe_charge_id);
    }

    public function test_invoice_failure_webhook_maps_back_to_payment_intent_attempt(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');

        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Invoice Failure Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01',
        ]);

        $schedule = RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-05',
            'amount' => 1400,
            'status' => 'pending',
        ]);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'rent_schedule_id' => $schedule->id,
            'amount' => 1400,
            'type' => 'rent_deposit',
            'status' => 'pending',
            'payment_mode' => 'stripe',
            'stripe_payment_intent_id' => 'pi_invoice_failed',
        ]);

        $payload = json_encode([
            'id' => 'evt_invoice_failed',
            'object' => 'event',
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'in_test_failed',
                    'object' => 'invoice',
                    'amount' => 140000,
                    'payment_intent' => 'pi_invoice_failed',
                    'latest_charge' => 'ch_invoice_failed',
                    'metadata' => [
                        'tenancy_id' => (string) $tenancy->id,
                    ],
                    'lines' => [
                        'data' => [[
                            'period' => [
                                'start' => Carbon::parse('2026-03-01')->timestamp,
                            ],
                        ]],
                    ],
                    'last_payment_error' => [
                        'message' => 'Recurring payment failed.',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $signature = $this->stripeSignature($payload, config('services.stripe.webhook_secret'));

        $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => $signature,
            ],
            $payload
        )->assertOk();

        $payment->refresh();
        $schedule->refresh();

        $this->assertSame('failed', $payment->status);
        $this->assertSame('Recurring payment failed.', $payment->failure_reason);
        $this->assertSame('ch_invoice_failed', $payment->stripe_charge_id);
        $this->assertSame('overdue', $schedule->status);
    }

    public function test_subscription_creation_requires_payment_intent_id(): void
    {
        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Zeta Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01',
            'stripe_customer_id' => 'cus_test',
            'stripe_price_id' => 'price_test',
        ]);

        $this
            ->actingAs($tenant, 'api')
            ->postJson('/api/rent/create-subscription-after-payment', [
                'tenancy_id' => $tenancy->id,
                'payment_method' => 'pm_test',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_intent_id');
    }

    public function test_activate_auto_pay_returns_existing_subscription_when_already_active(): void
    {
        config()->set('services.stripe.secret', 'sk_test_local');
        config()->set('services.stripe.rent_product_id', 'prod_test');
        config()->set('services.stripe.currency', 'inr');
        config()->set('broadcasting.default', 'null');

        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Future AutoPay Residency');
        $property->update(['payment_mode' => 'Credit/Debit Cards']);

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
            'stripe_customer_id' => 'cus_existing',
            'stripe_price_id' => 'price_existing',
            'stripe_subscription_id' => 'sub_existing_active',
            'subscription_active' => 1,
        ]);

        RentDeed::create([
            'agreement_number' => 'AGR-AUTOPAY-001',
            'agreement_date' => '2026-03-01',
            'owner_id' => $owner->id,
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'rent_due_date' => 7,
            'maintenance_charges' => 'Tenant',
        ]);

        $priceService = Mockery::mock(StripePriceService::class);
        $priceService->shouldReceive('resolveRecurringPriceId')
            ->once()
            ->with(
                'prod_test',
                'inr',
                120000,
                Mockery::on(function (array $payload): bool {
                    return ($payload['source'] ?? null) === 'property_listing'
                        && ($payload['monthly_rent'] ?? null) === '1200.00'
                        && array_key_exists('tenancy_id', $payload);
                }),
                'price_existing'
            )
            ->andReturn('price_existing_inr');
        $this->app->instance(StripePriceService::class, $priceService);

        $service = Mockery::mock(StripeSubscriptionService::class);
        $service->shouldReceive('ensureSubscriptionPrice')
            ->once()
            ->with('sub_existing_active', 'price_existing_inr')
            ->andReturnNull();
        $this->app->instance(StripeSubscriptionService::class, $service);

        $this
            ->actingAs($tenant, 'api')
            ->postJson('/api/rent/activate-autopay', [
                'tenancy_id' => $tenancy->id,
                'payment_method' => 'pm_autopay',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('subscription_id', 'sub_existing_active')
            ->assertJsonPath('subscription_active', 1)
            ->assertJsonPath('status', 'active');

        $tenancy->refresh();

        $this->assertSame('sub_existing_active', $tenancy->stripe_subscription_id);
        $this->assertSame('price_existing_inr', $tenancy->stripe_price_id);
        $this->assertSame(1, (int) $tenancy->subscription_active);
        $this->assertNull($tenancy->subscription_cancel_at);
    }

    public function test_activate_auto_pay_returns_scheduled_cancellation_state_for_existing_subscription(): void
    {
        config()->set('services.stripe.secret', 'sk_test_local');
        config()->set('services.stripe.rent_product_id', 'prod_test');
        config()->set('services.stripe.currency', 'inr');
        config()->set('broadcasting.default', 'null');

        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Scheduled AutoPay Residency');
        $property->update(['payment_mode' => 'Credit/Debit Cards']);

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
            'stripe_customer_id' => 'cus_existing',
            'stripe_price_id' => 'price_existing',
            'stripe_subscription_id' => 'sub_scheduled_existing',
            'subscription_active' => 3,
            'subscription_cancel_at' => '2026-03-31 23:59:59',
        ]);

        RentDeed::create([
            'agreement_number' => 'AGR-AUTOPAY-002',
            'agreement_date' => '2026-03-01',
            'owner_id' => $owner->id,
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'rent_due_date' => 7,
            'maintenance_charges' => 'Tenant',
        ]);

        $priceService = Mockery::mock(StripePriceService::class);
        $priceService->shouldReceive('resolveRecurringPriceId')
            ->once()
            ->andReturn('price_existing_inr');
        $this->app->instance(StripePriceService::class, $priceService);

        $service = Mockery::mock(StripeSubscriptionService::class);
        $service->shouldReceive('ensureSubscriptionPrice')
            ->once()
            ->with('sub_scheduled_existing', 'price_existing_inr')
            ->andReturnNull();
        $this->app->instance(StripeSubscriptionService::class, $service);

        $this
            ->actingAs($tenant, 'api')
            ->postJson('/api/rent/activate-autopay', [
                'tenancy_id' => $tenancy->id,
                'payment_method' => 'pm_autopay',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('subscription_id', 'sub_scheduled_existing')
            ->assertJsonPath('subscription_active', 3)
            ->assertJsonPath('status', 'scheduled_for_cancellation')
            ->assertJsonPath('subscription_cancel_at', '2026-03-31T23:59:59+00:00');
    }

    public function test_assigned_properties_includes_scheduled_subscription_cancellation_without_crashing(): void
    {
        Role::findOrCreate('tenant', 'web');

        $owner = User::factory()->create();
        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $property = $this->createProperty($owner->id, 'Scheduled Visibility Residency');
        $property->update(['payment_mode' => 'Credit/Debit Cards']);

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
            'stripe_subscription_id' => 'sub_visible_scheduled',
            'subscription_active' => 3,
            'subscription_cancel_at' => '2026-03-31 23:59:59',
        ]);

        RentDeed::create([
            'agreement_number' => 'AGR-AUTOPAY-003',
            'agreement_date' => '2026-03-01',
            'owner_id' => $owner->id,
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'rent_due_date' => 7,
            'maintenance_charges' => 'Tenant',
        ]);

        RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-07',
            'amount' => 1200,
            'status' => 'paid',
        ]);

        $this
            ->actingAs($tenant, 'api')
            ->postJson('/api/users/assigned-properties', ['userId' => $tenant->id])
            ->assertOk()
            ->assertJsonPath('property.0.tenancy_id', $tenancy->id)
            ->assertJsonPath('property.0.has_subscription', 3)
            ->assertJsonPath('property.0.stripe_subscription_id', 'sub_visible_scheduled')
            ->assertJsonPath('property.0.subscription_cancel_at', '2026-03-31T23:59:59+00:00');
    }

    public function test_owner_can_schedule_subscription_cancellation_for_month_end(): void
    {
        config()->set('broadcasting.default', 'null');
        Carbon::setTestNow('2026-03-30 10:00:00');

        Role::findOrCreate('owner', 'web');

        $owner = User::factory()->create();
        $owner->assignRole('owner');
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Cancel Ready Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
            'stripe_subscription_id' => 'sub_cancel_ready',
            'subscription_active' => 1,
        ]);

        $service = Mockery::mock(StripeSubscriptionService::class);
        $service->shouldReceive('scheduleCancellation')
            ->once()
            ->with('sub_cancel_ready', Mockery::on(function ($value): bool {
                return $value instanceof \DateTimeInterface
                    && $value->format('Y-m-d H:i:s') === '2026-03-31 23:59:59';
            }))
            ->andReturnNull();

        $this->app->instance(StripeSubscriptionService::class, $service);

        $this
            ->actingAs($owner, 'api')
            ->postJson('/api/tenancy/'.$tenancy->id.'/cancel-subscription')
            ->assertOk()
            ->assertJsonPath('status', 'scheduled_for_cancellation')
            ->assertJsonPath('cancel_at', '2026-03-31T23:59:59+00:00');

        $tenancy->refresh();

        $this->assertSame(3, (int) $tenancy->subscription_active);
        $this->assertSame('2026-03-31 23:59:59', $tenancy->subscription_cancel_at?->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_property_manager_can_schedule_subscription_cancellation_for_month_end(): void
    {
        config()->set('broadcasting.default', 'null');
        Carbon::setTestNow('2026-03-30 10:00:00');

        Role::findOrCreate('property_manager', 'web');

        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $manager->assignRole('property_manager');
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Manager Cancel Residency');
        $property->update(['manager_id' => $manager->id]);

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
            'stripe_subscription_id' => 'sub_manager_cancel',
            'subscription_active' => 1,
        ]);

        $service = Mockery::mock(StripeSubscriptionService::class);
        $service->shouldReceive('scheduleCancellation')
            ->once()
            ->with('sub_manager_cancel', Mockery::on(fn ($value) => $value instanceof \DateTimeInterface))
            ->andReturnNull();

        $this->app->instance(StripeSubscriptionService::class, $service);

        $this
            ->actingAs($manager, 'api')
            ->postJson('/api/tenancy/'.$tenancy->id.'/cancel-subscription')
            ->assertOk()
            ->assertJsonPath('status', 'scheduled_for_cancellation');

        $tenancy->refresh();
        $this->assertSame(3, (int) $tenancy->subscription_active);

        Carbon::setTestNow();
    }

    public function test_manual_security_request_creates_pending_payment_without_rent_schedule(): void
    {
        config()->set('broadcasting.default', 'null');

        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Theta Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01',
            'security_deposit_amount' => 900,
            'security_deposit_status' => 'pending',
        ]);

        $this
            ->actingAs($tenant, 'api')
            ->postJson('/api/security-deposit/manual', [
                'tenancy_id' => $tenancy->id,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Owner approval required');

        $payment = Payment::where('tenant_id', $tenant->id)
            ->where('property_id', $property->id)
            ->where('type', 'security_deposit')
            ->first();

        $this->assertNotNull($payment);
        $this->assertNull($payment->rent_schedule_id);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('manual', $payment->payment_mode);
    }

    public function test_owner_rent_approval_requires_valid_manual_pending_schedule(): void
    {
        config()->set('broadcasting.default', 'null');

        Role::findOrCreate('owner', 'web');

        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Iota Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01',
        ]);

        $schedule = RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-05',
            'amount' => 1300,
            'status' => 'pending',
        ]);

        $this
            ->actingAs($owner, 'api')
            ->postJson('/api/users/owner/rent-approve', [
                'tenancy_id' => $tenancy->id,
                'rent_schedule_id' => $schedule->id,
                'status' => 'approved',
            ])
            ->assertNotFound();
    }

    public function test_manual_rent_approval_issues_persistent_invoice_for_tenant(): void
    {
        config()->set('broadcasting.default', 'null');
        Storage::fake('local');
        Queue::fake();
        Mail::fake();

        Role::findOrCreate('owner', 'web');

        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Invoice Rent Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
        ]);

        $schedule = RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-05',
            'amount' => 1300,
            'status' => 'manual_pending',
        ]);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'rent_schedule_id' => $schedule->id,
            'amount' => 1300,
            'status' => 'pending',
            'type' => 'rent_deposit',
            'payment_mode' => 'manual',
        ]);

        $this
            ->actingAs($owner, 'api')
            ->postJson('/api/users/owner/rent-approve', [
                'tenancy_id' => $tenancy->id,
                'rent_schedule_id' => $schedule->id,
                'status' => 'approved',
            ])
            ->assertOk();

        $invoice = Invoice::query()->where('source_key', 'rent:manual:payment:'.$payment->id)->first();

        $this->assertNotNull($invoice);
        $this->assertSame('rent', $invoice->type);
        $this->assertSame($tenant->id, $invoice->recipient_user_id);
        $this->assertSame($payment->id, $invoice->primary_payment_id);
        $this->assertNotNull($invoice->invoice_number);
        $this->assertNotNull($invoice->pdf_path);
        Storage::disk('local')->assertExists($invoice->pdf_path);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $tenant->id,
            'type' => 'invoice',
            'notifiable_type' => Invoice::class,
            'notifiable_id' => $invoice->id,
        ]);

        Queue::assertPushed(SendInvoiceEmail::class);
    }

    public function test_duplicate_rent_success_webhooks_do_not_duplicate_payment_or_invoice(): void
    {
        config()->set('broadcasting.default', 'null');
        config()->set('services.stripe.webhook_secret', 'whsec_test');
        Storage::fake('local');
        Queue::fake();
        Mail::fake();

        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Webhook Safe Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
        ]);

        $schedule = RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-05',
            'amount' => 1200,
            'status' => 'pending',
        ]);

        Payment::create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'rent_schedule_id' => $schedule->id,
            'amount' => 1200,
            'status' => 'pending',
            'type' => 'rent_deposit',
            'payment_mode' => 'stripe',
            'stripe_payment_intent_id' => 'pi_rent_success',
        ]);

        $basePayload = [
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_rent_success',
                    'object' => 'payment_intent',
                    'amount' => 120000,
                    'amount_received' => 120000,
                    'latest_charge' => 'ch_rent_success',
                    'metadata' => [
                        'tenancy_id' => (string) $tenancy->id,
                        'type' => 'rent_subscription',
                        'schedule_ids' => (string) $schedule->id,
                        'expected_amount' => '120000',
                    ],
                ],
            ],
        ];

        foreach (['evt_rent_success_1', 'evt_rent_success_2'] as $eventId) {
            $payload = json_encode(array_merge($basePayload, ['id' => $eventId]), JSON_THROW_ON_ERROR);
            $signature = $this->stripeSignature($payload, config('services.stripe.webhook_secret'));

            $this->call(
                'POST',
                '/api/stripe/webhook',
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_Stripe-Signature' => $signature,
                ],
                $payload
            )->assertOk();
        }

        $schedule->refresh();
        $payment = Payment::where('stripe_payment_intent_id', 'pi_rent_success')
            ->where('rent_schedule_id', $schedule->id)
            ->first();

        $this->assertNotNull($payment);
        $this->assertSame('succeeded', $payment->status);
        $this->assertSame('ch_rent_success', $payment->stripe_charge_id);
        $this->assertNull($payment->failure_reason);
        $this->assertSame('paid', $schedule->status);
        $this->assertSame(1, Payment::where('stripe_payment_intent_id', 'pi_rent_success')->count());
        $this->assertSame(1, Invoice::where('source_key', 'rent:intent:pi_rent_success')->count());

        $invoice = Invoice::where('source_key', 'rent:intent:pi_rent_success')->first();
        $this->assertNotNull($invoice);
        Storage::disk('local')->assertExists($invoice->pdf_path);
    }

    public function test_rent_success_webhook_triggers_stripe_connect_payout_distribution(): void
    {
        config()->set('broadcasting.default', 'null');
        config()->set('services.stripe.webhook_secret', 'whsec_test');

        $owner = User::factory()->create([
            'stripe_connect_account_id' => 'acct_owner_ready',
            'stripe_connect_details_submitted' => true,
            'stripe_connect_charges_enabled' => true,
            'stripe_connect_payouts_enabled' => true,
        ]);
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Webhook Payout Residency');
        $property->update([
            'payment_mode' => 'Credit/Debit Cards',
            'owner_commission_percent' => 85,
            'manager_commission_percent' => 0,
        ]);

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
        ]);

        $schedule = RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-05',
            'amount' => 1200,
            'status' => 'pending',
        ]);

        Payment::create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'rent_schedule_id' => $schedule->id,
            'amount' => 1200,
            'status' => 'pending',
            'type' => 'rent_deposit',
            'payment_mode' => 'stripe',
            'stripe_payment_intent_id' => 'pi_connect_payout',
        ]);

        $payoutService = Mockery::mock(StripePayoutService::class);
        $payoutService->shouldReceive('distributeStripePayment')
            ->once()
            ->with(
                Mockery::on(fn ($value) => $value instanceof PropertyTenant && (int) $value->id === (int) $tenancy->id),
                120000,
                'payment_intent',
                'pi_connect_payout',
                'rent_deposit',
                'ch_connect_payout'
            )
            ->andReturnNull();
        $this->app->instance(StripePayoutService::class, $payoutService);

        $payload = json_encode([
            'id' => 'evt_connect_payout',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_connect_payout',
                    'object' => 'payment_intent',
                    'amount' => 120000,
                    'amount_received' => 120000,
                    'latest_charge' => 'ch_connect_payout',
                    'metadata' => [
                        'tenancy_id' => (string) $tenancy->id,
                        'type' => 'rent_subscription',
                        'schedule_ids' => (string) $schedule->id,
                        'expected_amount' => '120000',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $signature = $this->stripeSignature($payload, config('services.stripe.webhook_secret'));

        $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => $signature,
            ],
            $payload
        )->assertOk();
    }

    public function test_super_admin_can_update_property_commission_settings(): void
    {
        Role::findOrCreate('super-admin', 'web');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');
        $owner = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Commission Ready Residency');

        $this
            ->actingAs($superAdmin, 'api')
            ->putJson('/api/properties/'.$property->id.'/commission-settings', [
                'owner_commission_percent' => 82.5,
                'manager_commission_percent' => 12.5,
            ])
            ->assertOk()
            ->assertJsonPath('property.owner_commission_percent', '82.50')
            ->assertJsonPath('property.manager_commission_percent', '12.50');

        $property->refresh();

        $this->assertSame('82.50', $property->owner_commission_percent);
        $this->assertSame('12.50', $property->manager_commission_percent);
    }

    public function test_super_admin_commission_settings_reject_total_above_one_hundred_percent(): void
    {
        Role::findOrCreate('super-admin', 'web');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');
        $owner = User::factory()->create();
        $property = $this->createProperty($owner->id, 'Commission Guard Residency');

        $this
            ->actingAs($superAdmin, 'api')
            ->putJson('/api/properties/'.$property->id.'/commission-settings', [
                'owner_commission_percent' => 90,
                'manager_commission_percent' => 15,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('manager_commission_percent');
    }

    public function test_rent_success_webhook_requires_schedule_snapshot_to_settle_payment(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');

        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Strict Snapshot Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
        ]);

        $schedule = RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-05',
            'amount' => 1200,
            'status' => 'pending',
        ]);

        Payment::create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'rent_schedule_id' => $schedule->id,
            'amount' => 1200,
            'status' => 'pending',
            'type' => 'rent_deposit',
            'payment_mode' => 'stripe',
            'stripe_payment_intent_id' => 'pi_rent_missing_snapshot',
        ]);

        $payload = json_encode([
            'id' => 'evt_rent_missing_snapshot',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_rent_missing_snapshot',
                    'object' => 'payment_intent',
                    'amount' => 120000,
                    'amount_received' => 120000,
                    'latest_charge' => 'ch_rent_missing_snapshot',
                    'metadata' => [
                        'tenancy_id' => (string) $tenancy->id,
                        'type' => 'rent_subscription',
                        'expected_amount' => '120000',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $signature = $this->stripeSignature($payload, config('services.stripe.webhook_secret'));

        $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => $signature,
            ],
            $payload
        )->assertOk();

        $schedule->refresh();

        $this->assertSame('pending', $schedule->status);
        $this->assertSame('pending', Payment::where('stripe_payment_intent_id', 'pi_rent_missing_snapshot')->first()?->status);
        $this->assertDatabaseMissing('invoices', [
            'source_key' => 'rent:intent:pi_rent_missing_snapshot',
        ]);
    }

    public function test_overdue_success_webhook_marks_late_fee_and_issues_invoice_with_exact_total(): void
    {
        config()->set('broadcasting.default', 'null');
        config()->set('services.stripe.webhook_secret', 'whsec_test');
        Storage::fake('local');
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-03-30 10:00:00');

        $pdfService = Mockery::mock(InvoicePdfService::class);
        $pdfService->shouldReceive('store')
            ->once()
            ->andReturnUsing(function (Invoice $invoice) {
                $invoice->forceFill([
                    'pdf_disk' => 'local',
                    'pdf_path' => 'invoices/test-overdue.pdf',
                    'pdf_hash' => 'hash-overdue',
                    'pdf_generated_at' => now(),
                ])->save();

                return $invoice->fresh();
            });
        $this->app->instance(InvoicePdfService::class, $pdfService);

        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Late Fee Webhook Residency');
        $property->update([
            'late_payment_penalty' => '500 fixed penalty after due date',
        ]);

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
        ]);

        $schedule = RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-05',
            'amount' => 1200,
            'status' => 'overdue',
        ]);

        Payment::create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'rent_schedule_id' => $schedule->id,
            'amount' => 1200,
            'status' => 'pending',
            'type' => 'overdue',
            'payment_mode' => 'stripe',
            'stripe_payment_intent_id' => 'pi_overdue_success',
        ]);

        Payment::create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'amount' => 500,
            'status' => 'pending',
            'type' => 'late_fee',
            'payment_mode' => 'stripe',
            'stripe_payment_intent_id' => 'pi_overdue_success',
        ]);

        $payload = json_encode([
            'id' => 'evt_overdue_success',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_overdue_success',
                    'object' => 'payment_intent',
                    'amount' => 170000,
                    'amount_received' => 170000,
                    'latest_charge' => 'ch_overdue_success',
                    'metadata' => [
                        'tenancy_id' => (string) $tenancy->id,
                        'type' => 'overdue',
                        'schedule_ids' => (string) $schedule->id,
                        'expected_amount' => '170000',
                        'late_fee_total' => '50000',
                        'late_fee_reference_date' => '2026-03-30',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $signature = $this->stripeSignature($payload, config('services.stripe.webhook_secret'));

        $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => $signature,
            ],
            $payload
        )->assertOk();

        $schedule->refresh();
        $lateFeePayment = Payment::where('stripe_payment_intent_id', 'pi_overdue_success')
            ->where('type', 'late_fee')
            ->first();
        $invoice = Invoice::where('source_key', 'overdue:intent:pi_overdue_success')->first();
        $rentPayment = $invoice?->primary_payment_id
            ? Payment::find($invoice->primary_payment_id)
            : null;

        $this->assertSame('paid', $schedule->status);
        $this->assertNotNull($invoice);
        $this->assertNotNull($rentPayment);
        $this->assertSame('succeeded', $rentPayment->status);
        $this->assertSame('pi_overdue_success', $rentPayment->stripe_payment_intent_id);
        $this->assertSame($schedule->id, $rentPayment->rent_schedule_id);
        $this->assertNotNull($lateFeePayment);
        $this->assertSame('succeeded', $lateFeePayment->status);
        $this->assertSame(500.0, (float) $lateFeePayment->amount);
        $this->assertSame(1700.0, (float) $invoice->total);
        $this->assertTrue(collect($invoice->line_items)->contains(fn (array $item): bool => str_contains((string) ($item['label'] ?? ''), 'Late Fee')));
        $this->assertSame('invoices/test-overdue.pdf', $invoice->pdf_path);

        Carbon::setTestNow();
    }

    public function test_overdue_success_webhook_uses_payment_intent_late_fee_snapshot_when_policy_changes_before_settlement(): void
    {
        config()->set('broadcasting.default', 'null');
        config()->set('services.stripe.webhook_secret', 'whsec_test');
        Storage::fake('local');
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-03-30 10:00:00');

        $pdfService = Mockery::mock(InvoicePdfService::class);
        $pdfService->shouldReceive('store')
            ->once()
            ->andReturnUsing(function (Invoice $invoice) {
                $invoice->forceFill([
                    'pdf_disk' => 'local',
                    'pdf_path' => 'invoices/test-overdue-snapshot.pdf',
                    'pdf_hash' => 'hash-overdue-snapshot',
                    'pdf_generated_at' => now(),
                ])->save();

                return $invoice->fresh();
            });
        $this->app->instance(InvoicePdfService::class, $pdfService);

        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Late Fee Snapshot Residency');
        $property->update([
            'late_payment_penalty' => '500 fixed penalty after due date',
        ]);

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
        ]);

        $schedule = RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-05',
            'amount' => 1200,
            'status' => 'overdue',
        ]);

        Payment::create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'rent_schedule_id' => $schedule->id,
            'amount' => 1200,
            'status' => 'pending',
            'type' => 'overdue',
            'payment_mode' => 'stripe',
            'stripe_payment_intent_id' => 'pi_overdue_policy_snapshot',
        ]);

        Payment::create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'amount' => 500,
            'status' => 'pending',
            'type' => 'late_fee',
            'payment_mode' => 'stripe',
            'stripe_payment_intent_id' => 'pi_overdue_policy_snapshot',
        ]);

        $property->update([
            'late_payment_penalty' => '900 fixed penalty after due date',
        ]);

        $payload = json_encode([
            'id' => 'evt_overdue_policy_snapshot',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_overdue_policy_snapshot',
                    'object' => 'payment_intent',
                    'amount' => 170000,
                    'amount_received' => 170000,
                    'latest_charge' => 'ch_overdue_policy_snapshot',
                    'metadata' => [
                        'tenancy_id' => (string) $tenancy->id,
                        'type' => 'overdue',
                        'schedule_ids' => (string) $schedule->id,
                        'expected_amount' => '170000',
                        'late_fee_total' => '50000',
                        'late_fee_reference_date' => '2026-03-30',
                        'late_fee_schedule_ids' => (string) $schedule->id,
                        'late_fee_unit_amount' => '50000',
                        'late_fee_policy_text' => '500 fixed penalty after due date',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $signature = $this->stripeSignature($payload, config('services.stripe.webhook_secret'));

        $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => $signature,
            ],
            $payload
        )->assertOk();

        $lateFeePayment = Payment::where('stripe_payment_intent_id', 'pi_overdue_policy_snapshot')
            ->where('type', 'late_fee')
            ->first();
        $invoice = Invoice::where('source_key', 'overdue:intent:pi_overdue_policy_snapshot')->first();

        $this->assertNotNull($lateFeePayment);
        $this->assertSame(500.0, (float) $lateFeePayment->amount);
        $this->assertNotNull($invoice);
        $this->assertSame(1700.0, (float) $invoice->total);
        $this->assertTrue(collect($invoice->line_items)->contains(function (array $item): bool {
            return str_contains((string) ($item['label'] ?? ''), 'Late Fee')
                && (float) ($item['amount'] ?? 0) === 500.0;
        }));
        $this->assertSame('invoices/test-overdue-snapshot.pdf', $invoice->pdf_path);

        Carbon::setTestNow();
    }

    public function test_manual_security_approval_issues_persistent_invoice_for_tenant(): void
    {
        config()->set('broadcasting.default', 'null');
        Storage::fake('local');
        Queue::fake();
        Mail::fake();

        Role::findOrCreate('owner', 'web');

        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Invoice Deposit Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
            'security_deposit_amount' => 900,
            'security_deposit_status' => 'manual_pending',
        ]);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'amount' => 900,
            'status' => 'pending',
            'type' => 'security_deposit',
            'payment_mode' => 'manual',
        ]);

        $this
            ->actingAs($owner, 'api')
            ->postJson('/api/users/owner/security-deposit-approve', [
                'tenancy_id' => $tenancy->id,
                'status' => 'approved',
            ])
            ->assertOk();

        $invoice = Invoice::query()->where('source_key', 'deposit:manual:payment:'.$payment->id)->first();

        $this->assertNotNull($invoice);
        $this->assertSame('security_deposit', $invoice->type);
        $this->assertSame($tenant->id, $invoice->recipient_user_id);
        $this->assertSame($payment->id, $invoice->primary_payment_id);
        $this->assertNotNull($invoice->invoice_number);
        $this->assertNotNull($invoice->pdf_path);
        Storage::disk('local')->assertExists($invoice->pdf_path);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $tenant->id,
            'type' => 'invoice',
            'notifiable_type' => Invoice::class,
            'notifiable_id' => $invoice->id,
        ]);

        Queue::assertPushed(SendInvoiceEmail::class);
    }

    public function test_agreement_expiry_job_notifies_stakeholders_before_expiration_without_duplicates(): void
    {
        config()->set('broadcasting.default', 'null');
        Carbon::setTestNow('2026-03-24 09:00:00');

        $owner = User::factory()->create();
        $tenant = User::factory()->create();
        $manager = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Kappa Residency');
        $property->manager_id = $manager->id;
        $property->save();

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'manager_id' => $manager->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);

        $job = new SendAgreementExpiryNotificationsJob;
        $job->handle();
        $job->handle();

        $this->assertSame(3, Notification::where('type', 'agreement')->count());
        $this->assertDatabaseHas('notifications', [
            'type' => 'agreement',
            'title' => 'Agreement Expiry Reminder: 7 Days Remaining',
            'notifiable_type' => PropertyTenant::class,
            'notifiable_id' => $tenancy->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_agreement_expiry_job_marks_subscription_inactive_and_notifies_after_expiration(): void
    {
        config()->set('broadcasting.default', 'null');
        Carbon::setTestNow('2026-03-24 09:00:00');

        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Lambda Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-23',
            'subscription_active' => 1,
            'stripe_subscription_id' => 'sub_test_active',
        ]);

        (new SendAgreementExpiryNotificationsJob)->handle();

        $tenancy->refresh();

        $this->assertSame(2, (int) $tenancy->subscription_active);
        $this->assertSame('sub_test_active', $tenancy->stripe_subscription_id);
        $this->assertSame(2, Notification::where('type', 'agreement')->count());
        $this->assertDatabaseHas('notifications', [
            'user_id' => $tenant->id,
            'type' => 'agreement',
            'title' => 'Tenancy Agreement Expired',
            'notifiable_type' => PropertyTenant::class,
            'notifiable_id' => $tenancy->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_agreement_expiry_job_clears_scheduled_cancellation_state_after_expiration(): void
    {
        config()->set('broadcasting.default', 'null');
        Carbon::setTestNow('2026-03-24 09:00:00');

        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Scheduled Expiry Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-23',
            'subscription_active' => 3,
            'stripe_subscription_id' => 'sub_scheduled_expiry',
            'subscription_cancel_at' => '2026-03-31 23:59:59',
        ]);

        (new SendAgreementExpiryNotificationsJob)->handle();

        $tenancy->refresh();

        $this->assertSame(2, (int) $tenancy->subscription_active);
        $this->assertNull($tenancy->subscription_cancel_at);
        $this->assertSame('sub_scheduled_expiry', $tenancy->stripe_subscription_id);

        Carbon::setTestNow();
    }

    public function test_manager_assignment_works_without_existing_tenancy_and_tracks_notifications(): void
    {
        config()->set('broadcasting.default', 'null');

        Role::findOrCreate('owner', 'web');
        Role::findOrCreate('property_manager', 'web');

        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $manager = User::factory()->create();
        $manager->assignRole('property_manager');

        $property = $this->createProperty($owner->id, 'Manager Ready Residency');

        $this
            ->actingAs($owner, 'api')
            ->postJson('/api/properties/'.$property->id.'/assign-manager', [
                'manager_id' => $manager->id,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Manager assigned successfully');

        $property->refresh();

        $this->assertSame($manager->id, $property->manager_id);
        $this->assertSame(2, Notification::where('type', 'property')->count());
        $this->assertDatabaseHas('notifications', [
            'user_id' => $manager->id,
            'type' => 'property',
            'title' => 'Property Assignment',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'property',
            'title' => 'Property Manager Assigned',
        ]);
    }

    public function test_manager_cannot_assign_manager_to_property(): void
    {
        Role::findOrCreate('owner', 'web');
        Role::findOrCreate('property_manager', 'web');

        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $manager = User::factory()->create();
        $manager->assignRole('property_manager');

        $otherManager = User::factory()->create();
        $otherManager->assignRole('property_manager');

        $property = $this->createProperty($owner->id, 'Locked Residency');
        $property->update(['manager_id' => $manager->id]);

        $this
            ->actingAs($manager, 'api')
            ->postJson('/api/properties/'.$property->id.'/assign-manager', [
                'manager_id' => $otherManager->id,
            ])
            ->assertForbidden();
    }

    public function test_manager_can_update_only_assigned_property(): void
    {
        Role::findOrCreate('owner', 'web');
        Role::findOrCreate('property_manager', 'web');

        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $manager = User::factory()->create();
        $manager->assignRole('property_manager');

        $property = $this->createProperty($owner->id, 'Managed Residency');
        $property->update(['manager_id' => $manager->id]);

        $this
            ->actingAs($manager, 'api')
            ->putJson('/api/properties/'.$property->id, [
                'propertyName' => 'Managed Residency Updated',
                'propertyType' => 'Residential',
                'state' => 'State',
                'city' => 'City',
                'address' => '123 Main Street',
                'monthlyRent' => 1400,
                'paymentMode' => 'UPI',
                'securityAmount' => 600,
                'electricityBillPaidBy' => 'tenant',
            ])
            ->assertOk()
            ->assertJsonPath('property.property_name', 'Managed Residency Updated');
    }

    public function test_manager_cannot_update_unassigned_property(): void
    {
        Role::findOrCreate('owner', 'web');
        Role::findOrCreate('property_manager', 'web');

        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $manager = User::factory()->create();
        $manager->assignRole('property_manager');

        $property = $this->createProperty($owner->id, 'Unmanaged Residency');

        $this
            ->actingAs($manager, 'api')
            ->putJson('/api/properties/'.$property->id, [
                'propertyName' => 'Blocked Update',
                'propertyType' => 'Residential',
                'state' => 'State',
                'city' => 'City',
                'address' => '123 Main Street',
                'monthlyRent' => 1400,
                'paymentMode' => 'UPI',
                'securityAmount' => 600,
                'electricityBillPaidBy' => 'tenant',
            ])
            ->assertForbidden();
    }

    public function test_tenant_assignment_creates_notification_and_surfaces_in_assigned_properties_without_rent_deed(): void
    {
        config()->set('broadcasting.default', 'null');

        Role::findOrCreate('owner', 'web');
        Role::findOrCreate('tenant', 'web');

        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $property = $this->createProperty($owner->id, 'Assigned Residency');

        $this
            ->actingAs($owner, 'api')
            ->postJson('/api/properties/'.$property->id.'/assign-tenants', [
                'tenants' => [[
                    'id' => $tenant->id,
                    'start_date' => '2026-03-24',
                    'end_date' => '2026-12-31',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Tenants assigned successfully');

        $this->assertDatabaseHas('property_tenant', [
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $tenant->id,
            'type' => 'property',
            'title' => 'Property Assigned to You',
        ]);

        $this
            ->actingAs($tenant, 'api')
            ->postJson('/api/users/assigned-properties', ['userId' => $tenant->id])
            ->assertOk()
            ->assertJsonCount(1, 'property')
            ->assertJsonPath('property.0.property_name', 'Assigned Residency')
            ->assertJsonPath('property.0.has_rent_deed', false);
    }

    public function test_rent_deed_update_resyncs_pending_schedule_due_date(): void
    {
        Mail::fake();
        Role::findOrCreate('owner', 'web');
        Role::findOrCreate('property_manager', 'web');

        $owner = User::factory()->create();
        $owner->assignRole('owner');
        $tenant = User::factory()->create(['email' => 'tenant-update@example.com']);
        $manager = User::factory()->create(['email' => 'manager-update@example.com']);
        $manager->assignRole('property_manager');

        $property = $this->createProperty($owner->id, 'Mu Residency');
        $property->update(['manager_id' => $manager->id]);

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-05-31',
        ]);

        $rentDeed = RentDeed::create([
            'agreement_number' => 'AGR-001',
            'agreement_date' => '2026-03-01',
            'owner_id' => $owner->id,
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'rent_due_date' => 5,
            'maintenance_charges' => 'Tenant',
        ]);

        RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-05',
            'amount' => 1200,
            'status' => 'pending',
        ]);

        $this
            ->actingAs($owner, 'api')
            ->putJson('/api/properties/rent-deeds/'.$rentDeed->id, [
                'agreementNumber' => 'AGR-001',
                'agreementDate' => '2026-03-01',
                'propertyId' => ['id' => $property->id],
                'size' => null,
                'usage' => null,
                'rentDueDate' => 10,
                'maintenanceCharges' => 'Tenant',
                'otherDetails' => null,
            ])
            ->assertOk();

        $schedule = RentSchedule::where('tenancy_id', $tenancy->id)
            ->where('month', '2026-03-01')
            ->first();

        $this->assertNotNull($schedule);
        $this->assertSame('2026-03-10', $schedule->due_date->format('Y-m-d'));
        Mail::assertQueued(RentDeedMail::class, 3);
        Mail::assertQueued(RentDeedMail::class, fn (RentDeedMail $mail) => $mail->hasTo('tenant-update@example.com'));
        Mail::assertQueued(RentDeedMail::class, fn (RentDeedMail $mail) => $mail->hasTo('manager-update@example.com'));
        $this->assertSame(3, Notification::where('type', 'agreement')->count());
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'agreement',
            'title' => 'Rent Deed Updated',
            'notifiable_type' => RentDeed::class,
            'notifiable_id' => $rentDeed->id,
        ]);
    }

    public function test_generate_rent_schedules_uses_tenancy_matching_rent_deed_not_latest_property_deed(): void
    {
        $owner = User::factory()->create();
        $firstTenant = User::factory()->create();
        $secondTenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Historical Deed Residency');

        $firstTenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $firstTenant->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);

        $secondTenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $secondTenant->id,
            'start_date' => '2026-04-01',
            'end_date' => '2026-06-30',
        ]);

        RentDeed::create([
            'agreement_number' => 'AGR-HISTORY-001',
            'agreement_date' => '2026-01-01',
            'owner_id' => $owner->id,
            'tenant_id' => $firstTenant->id,
            'property_id' => $property->id,
            'rent_due_date' => 5,
            'maintenance_charges' => 'Tenant',
        ]);

        RentDeed::create([
            'agreement_number' => 'AGR-HISTORY-002',
            'agreement_date' => '2026-04-01',
            'owner_id' => $owner->id,
            'tenant_id' => $secondTenant->id,
            'property_id' => $property->id,
            'rent_due_date' => 12,
            'maintenance_charges' => 'Tenant',
        ]);

        $this->artisan('rent:generate', ['tenancy_id' => $firstTenancy->id])->assertSuccessful();
        $this->artisan('rent:generate', ['tenancy_id' => $secondTenancy->id])->assertSuccessful();

        $firstSchedule = RentSchedule::where('tenancy_id', $firstTenancy->id)
            ->where('month', '2026-01-01')
            ->first();
        $secondSchedule = RentSchedule::where('tenancy_id', $secondTenancy->id)
            ->where('month', '2026-04-01')
            ->first();

        $this->assertNotNull($firstSchedule);
        $this->assertNotNull($secondSchedule);
        $this->assertSame('2026-01-05', $firstSchedule->due_date->format('Y-m-d'));
        $this->assertSame('2026-04-12', $secondSchedule->due_date->format('Y-m-d'));
    }

    public function test_generate_rent_schedules_uses_matching_historical_deed_for_same_tenant_renewal(): void
    {
        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Renewal Residency');

        $firstTenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);

        $secondTenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-04-01',
            'end_date' => '2026-06-30',
        ]);

        RentDeed::create([
            'agreement_number' => 'AGR-RENEW-001',
            'agreement_date' => '2026-01-01',
            'owner_id' => $owner->id,
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'rent_due_date' => 5,
            'maintenance_charges' => 'Tenant',
        ]);

        RentDeed::create([
            'agreement_number' => 'AGR-RENEW-002',
            'agreement_date' => '2026-04-01',
            'owner_id' => $owner->id,
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'rent_due_date' => 12,
            'maintenance_charges' => 'Tenant',
        ]);

        $this->artisan('rent:generate', ['tenancy_id' => $firstTenancy->id])->assertSuccessful();
        $this->artisan('rent:generate', ['tenancy_id' => $secondTenancy->id])->assertSuccessful();

        $firstSchedule = RentSchedule::where('tenancy_id', $firstTenancy->id)
            ->where('month', '2026-01-01')
            ->first();
        $secondSchedule = RentSchedule::where('tenancy_id', $secondTenancy->id)
            ->where('month', '2026-04-01')
            ->first();

        $this->assertNotNull($firstSchedule);
        $this->assertNotNull($secondSchedule);
        $this->assertSame('2026-01-05', $firstSchedule->due_date->format('Y-m-d'));
        $this->assertSame('2026-04-12', $secondSchedule->due_date->format('Y-m-d'));
    }

    public function test_manual_rent_approval_is_scoped_to_the_requested_tenancy(): void
    {
        config()->set('broadcasting.default', 'null');
        Role::findOrCreate('owner', 'web');

        $owner = User::factory()->create();
        $owner->assignRole('owner');
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Scoped Manual Residency');

        $firstTenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);

        $secondTenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-04-01',
            'end_date' => '2026-06-30',
        ]);

        $firstSchedule = RentSchedule::create([
            'tenancy_id' => $firstTenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-05',
            'amount' => 1200,
            'status' => 'manual_pending',
        ]);

        $secondSchedule = RentSchedule::create([
            'tenancy_id' => $secondTenancy->id,
            'month' => '2026-04-01',
            'due_date' => '2026-04-05',
            'amount' => 1200,
            'status' => 'manual_pending',
        ]);

        $firstPayment = Payment::create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'tenancy_id' => $firstTenancy->id,
            'rent_schedule_id' => $firstSchedule->id,
            'amount' => 1200,
            'status' => 'pending',
            'type' => 'rent_deposit',
            'payment_mode' => 'manual',
        ]);

        $secondPayment = Payment::create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'tenancy_id' => $secondTenancy->id,
            'rent_schedule_id' => $secondSchedule->id,
            'amount' => 1200,
            'status' => 'pending',
            'type' => 'rent_deposit',
            'payment_mode' => 'manual',
        ]);

        $this
            ->actingAs($owner, 'api')
            ->postJson('/api/users/owner/rent-approve', [
                'tenancy_id' => $firstTenancy->id,
                'rent_schedule_id' => $firstSchedule->id,
                'status' => 'approved',
            ])
            ->assertOk();

        $firstPayment->refresh();
        $secondPayment->refresh();
        $firstSchedule->refresh();
        $secondSchedule->refresh();

        $this->assertSame('succeeded', $firstPayment->status);
        $this->assertSame('pending', $secondPayment->status);
        $this->assertSame('paid', $firstSchedule->status);
        $this->assertSame('manual_pending', $secondSchedule->status);
    }

    public function test_assigning_a_new_tenant_does_not_detach_existing_tenancies(): void
    {
        config()->set('broadcasting.default', 'null');

        Role::findOrCreate('owner', 'web');
        Role::findOrCreate('tenant', 'web');

        $owner = User::factory()->create();
        $owner->assignRole('owner');
        $firstTenant = User::factory()->create();
        $firstTenant->assignRole('tenant');
        $secondTenant = User::factory()->create();
        $secondTenant->assignRole('tenant');

        $property = $this->createProperty($owner->id, 'Assignment Safe Residency');

        $this
            ->actingAs($owner, 'api')
            ->postJson('/api/properties/'.$property->id.'/assign-tenants', [
                'tenants' => [[
                    'id' => $firstTenant->id,
                    'start_date' => '2026-03-01',
                    'end_date' => '2026-06-30',
                ]],
            ])
            ->assertOk();

        $this
            ->actingAs($owner, 'api')
            ->postJson('/api/properties/'.$property->id.'/assign-tenants', [
                'tenants' => [[
                    'id' => $secondTenant->id,
                    'start_date' => '2026-07-01',
                    'end_date' => '2026-12-31',
                ]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('property_tenant', [
            'property_id' => $property->id,
            'tenant_id' => $firstTenant->id,
        ]);

        $this->assertDatabaseHas('property_tenant', [
            'property_id' => $property->id,
            'tenant_id' => $secondTenant->id,
        ]);
    }

    public function test_rent_reminder_job_does_not_duplicate_notifications_on_same_day(): void
    {
        config()->set('broadcasting.default', 'null');
        Carbon::setTestNow('2026-03-24 09:00:00');

        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Nu Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $schedule = RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-26',
            'amount' => 1200,
            'status' => 'pending',
        ]);

        $job = new SendRentReminderJob;
        $job->handle();
        $job->handle();

        $schedule->refresh();

        $this->assertNotNull($schedule->last_reminder_at);
        $this->assertSame(2, Notification::where('type', 'rent_due')->count());

        Carbon::setTestNow();
    }

    public function test_rent_reminder_job_does_not_touch_manual_pending_schedules(): void
    {
        config()->set('broadcasting.default', 'null');
        Carbon::setTestNow('2026-03-24 09:00:00');

        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Manual Review Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $schedule = RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-05',
            'amount' => 1200,
            'status' => 'manual_pending',
        ]);

        (new SendRentReminderJob)->handle();

        $schedule->refresh();

        $this->assertSame('manual_pending', $schedule->status);
        $this->assertNull($schedule->last_reminder_at);
        $this->assertSame(0, Notification::where('type', 'rent_due')->count());
        $this->assertSame(0, Notification::where('type', 'rent_overdue')->count());

        Carbon::setTestNow();
    }

    private function createProperty(int $ownerId, string $name): Property
    {
        return Property::create([
            'user_id' => $ownerId,
            'property_name' => $name,
            'property_type' => 'Residential',
            'state' => 'State',
            'city' => 'City',
            'address' => '123 Main Street',
            'monthly_rent' => 1200,
            'payment_mode' => 'UPI',
            'security_amount' => 500,
            'electricity_bill_paid_by' => 'tenant',
        ]);
    }

    private function stripeSignature(string $payload, string $secret): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return "t={$timestamp},v1={$signature}";
    }
}
