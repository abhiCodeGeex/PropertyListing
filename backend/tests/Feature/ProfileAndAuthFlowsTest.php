<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\Property;
use App\Models\PropertyTenant;
use App\Models\RentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileAndAuthFlowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_returns_generic_success_for_unknown_email(): void
    {
        $this->postJson('/api/forgot-password', [
            'email' => 'missing@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'If the email is registered, a password reset link will be sent shortly.');
    }

    public function test_profile_update_persists_user_and_profile_fields(): void
    {
        $user = User::factory()->create([
            'username' => 'before-update',
            'password' => Hash::make('old-password-123'),
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/profile', [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'username' => 'jane-doe',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
                'dob' => '1995-05-10',
                'current_address' => '123 Main Street',
                'native_address' => '456 Native Street',
                'aadhar' => '123456789012',
                'aadhar_text' => 'Jane Doe',
                'pan' => 'ABCDE1234F',
                'marital_status' => 'single',
                'gender' => 'female',
                'phone' => '9876543210',
            ])
            ->assertOk()
            ->assertJsonPath('user.username', 'jane-doe')
            ->assertJsonPath('profile.first_name', 'Jane')
            ->assertJsonPath('profile.aadhar_text', 'Jane Doe');

        $user->refresh();
        $profile = Profile::where('user_id', $user->id)->first();

        $this->assertSame('jane-doe', $user->username);
        $this->assertSame('Jane Doe', $user->name);
        $this->assertTrue(Hash::check('new-password-123', $user->password));
        $this->assertNotNull($profile);
        $this->assertSame('123 Main Street', $profile->current_address);
        $this->assertSame('9876543210', $profile->phone);
    }

    public function test_rent_payment_is_blocked_until_rent_deed_exists(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $tenant = User::factory()->create();

        $property = Property::create([
            'user_id' => $owner->id,
            'property_name' => 'No Deed Residency',
            'property_type' => 'Residential',
            'state' => 'State',
            'city' => 'City',
            'address' => '123 Main Street',
            'monthly_rent' => 1200,
            'payment_mode' => 'Credit/Debit Cards',
            'security_amount' => 500,
            'electricity_bill_paid_by' => 'tenant',
        ]);

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-03-01',
        ]);

        RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'month' => '2026-03-01',
            'due_date' => '2026-03-07',
            'amount' => 1200,
            'status' => 'pending',
        ]);

        $this->actingAs($tenant, 'api')
            ->postJson('/api/rent/subscribe', [
                'tenancy_id' => $tenancy->id,
                'payment_method' => 'pm_test',
                'rent_amount' => 1200,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tenancy_id');
    }
}
