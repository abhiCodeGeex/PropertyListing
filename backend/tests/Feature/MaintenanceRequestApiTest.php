<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyTenant;
use App\Models\User;
use App\Modules\Maintenance\Models\MaintenanceRequest;
use App\Modules\Maintenance\Notifications\MaintenanceRequestCreatedNotification;
use App\Modules\Maintenance\Notifications\MaintenanceStatusUpdatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MaintenanceRequestApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config()->set('broadcasting.default', 'null');

        Role::findOrCreate('owner', 'web');
        Role::findOrCreate('tenant', 'web');
        Role::findOrCreate('property_manager', 'web');
        Role::findOrCreate('super-admin', 'web');
    }

    public function test_tenant_can_create_request_and_owner_receives_notification(): void
    {
        Notification::fake();

        [$owner, $tenant, $property, $tenancy] = $this->workspaceFixture();

        $response = $this
            ->actingAs($tenant, 'api')
            ->postJson('/api/v1/maintenance/requests', [
                'property_id' => $property->id,
                'tenancy_id' => $tenancy->id,
                'title' => 'Water leakage in kitchen ceiling',
                'description' => 'Water is dripping near the exhaust vent every evening.',
                'category' => 'Plumbing',
                'priority' => 'high',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('request.status', 'pending')
            ->assertJsonPath('request.property_id', $property->id)
            ->assertJsonPath('request.tenant_id', $tenant->id);

        $this->assertDatabaseHas('maintenance_requests', [
            'property_id' => $property->id,
            'tenancy_id' => $tenancy->id,
            'tenant_id' => $tenant->id,
            'status' => 'pending',
            'priority' => 'high',
        ]);

        $this->assertDatabaseHas('maintenance_status_history', [
            'action' => 'created',
            'to_status' => 'pending',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'maintenance_request',
            'title' => 'Maintenance Request Created',
            'notifiable_type' => MaintenanceRequest::class,
        ]);

        Notification::assertSentTo($owner, MaintenanceRequestCreatedNotification::class);
    }

    public function test_pending_request_cannot_jump_directly_to_completed_status(): void
    {
        [$owner, $tenant, $property, $tenancy] = $this->workspaceFixture();

        $maintenanceRequest = MaintenanceRequest::create([
            'property_id' => $property->id,
            'tenancy_id' => $tenancy->id,
            'tenant_id' => $tenant->id,
            'title' => 'AC not cooling',
            'description' => 'The AC unit is running but not cooling the room.',
            'priority' => 'medium',
            'status' => 'pending',
            'last_activity_at' => now(),
        ]);

        $this
            ->actingAs($owner, 'api')
            ->postJson("/api/v1/maintenance/requests/{$maintenanceRequest->id}/status", [
                'status' => 'completed',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_owner_can_approve_request_and_tenant_receives_notification(): void
    {
        Notification::fake();

        [$owner, $tenant, $property, $tenancy] = $this->workspaceFixture();

        $maintenanceRequest = MaintenanceRequest::create([
            'property_id' => $property->id,
            'tenancy_id' => $tenancy->id,
            'tenant_id' => $tenant->id,
            'title' => 'Main door lock jammed',
            'description' => 'The lock is difficult to turn from outside.',
            'priority' => 'urgent',
            'status' => 'pending',
            'last_activity_at' => now(),
        ]);

        $response = $this
            ->actingAs($owner, 'api')
            ->postJson("/api/v1/maintenance/requests/{$maintenanceRequest->id}/approve", [
                'reason' => 'Verified with building support and approved for repair.',
                'message' => 'The repair has been approved and will be scheduled.',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('request.status', 'approved');

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $maintenanceRequest->id,
            'status' => 'approved',
        ]);

        $this->assertDatabaseHas('maintenance_status_history', [
            'maintenance_request_id' => $maintenanceRequest->id,
            'action' => 'approved',
            'from_status' => 'pending',
            'to_status' => 'approved',
            'reason' => 'Verified with building support and approved for repair.',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $tenant->id,
            'type' => 'maintenance_status',
            'title' => 'Maintenance Request Approved',
        ]);

        Notification::assertSentTo($tenant, MaintenanceStatusUpdatedNotification::class);
    }

    public function test_tenant_cannot_view_another_tenants_request(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $otherTenant = User::factory()->create();
        $otherTenant->assignRole('tenant');

        $property = $this->createProperty($owner->id, 'Maple Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
        ]);

        $maintenanceRequest = MaintenanceRequest::create([
            'property_id' => $property->id,
            'tenancy_id' => $tenancy->id,
            'tenant_id' => $tenant->id,
            'title' => 'Bathroom exhaust issue',
            'description' => 'The exhaust fan stopped working this morning.',
            'priority' => 'medium',
            'status' => 'pending',
            'last_activity_at' => now(),
        ]);

        $this
            ->actingAs($otherTenant, 'api')
            ->getJson("/api/v1/maintenance/requests/{$maintenanceRequest->id}")
            ->assertForbidden();
    }

    public function test_workspace_summary_returns_scoped_counts_for_owner(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $manager = User::factory()->create();
        $manager->assignRole('property_manager');

        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $otherOwner = User::factory()->create();
        $otherOwner->assignRole('owner');

        $otherTenant = User::factory()->create();
        $otherTenant->assignRole('tenant');

        $property = $this->createProperty($owner->id, 'Skyline Residency');
        $managedProperty = $this->createProperty($owner->id, 'Clover Heights');
        $managedProperty->update(['manager_id' => $manager->id]);
        $otherProperty = $this->createProperty($otherOwner->id, 'Outside Scope');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
        ]);

        $managedTenancy = PropertyTenant::create([
            'property_id' => $managedProperty->id,
            'tenant_id' => $tenant->id,
            'manager_id' => $manager->id,
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
        ]);

        $otherTenancy = PropertyTenant::create([
            'property_id' => $otherProperty->id,
            'tenant_id' => $otherTenant->id,
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
        ]);

        MaintenanceRequest::create([
            'property_id' => $property->id,
            'tenancy_id' => $tenancy->id,
            'tenant_id' => $tenant->id,
            'title' => 'Pending faucet repair',
            'description' => 'Faucet is leaking under the sink.',
            'priority' => 'urgent',
            'status' => 'pending',
            'last_activity_at' => now(),
        ]);

        MaintenanceRequest::create([
            'property_id' => $managedProperty->id,
            'tenancy_id' => $managedTenancy->id,
            'tenant_id' => $tenant->id,
            'title' => 'Elevator panel issue',
            'description' => 'Panel buttons stop responding.',
            'priority' => 'high',
            'status' => 'in_progress',
            'last_activity_at' => now(),
        ]);

        MaintenanceRequest::create([
            'property_id' => $otherProperty->id,
            'tenancy_id' => $otherTenancy->id,
            'tenant_id' => $otherTenant->id,
            'title' => 'Outside owner request',
            'description' => 'This request should not be counted.',
            'priority' => 'urgent',
            'status' => 'pending',
            'last_activity_at' => now(),
        ]);

        $this
            ->actingAs($owner, 'api')
            ->getJson('/api/v1/maintenance/summary')
            ->assertOk()
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.pending', 1)
            ->assertJsonPath('summary.in_progress', 1)
            ->assertJsonPath('summary.urgent', 1);
    }

    private function workspaceFixture(): array
    {
        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $property = $this->createProperty($owner->id, 'River View Residency');

        $tenancy = PropertyTenant::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
        ]);

        return [$owner, $tenant, $property, $tenancy];
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
            'monthly_rent' => 1500,
            'payment_mode' => 'UPI',
            'security_amount' => 750,
            'electricity_bill_paid_by' => 'tenant',
        ]);
    }
}
