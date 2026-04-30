<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyTenant;
use App\Models\User;
use App\Modules\Maintenance\Events\MaintenanceRequestCreated;
use App\Modules\Maintenance\Models\MaintenanceRequest;
use App\Modules\Maintenance\Notifications\MaintenanceCommentAddedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MaintenanceRealtimeAndNotificationTest extends TestCase
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

    public function test_comment_addition_notifies_other_stakeholders_but_not_author(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $property = $this->createProperty($owner->id, 'Cedar Heights');

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
            'title' => 'Hallway light flickering',
            'description' => 'The hallway light outside the flat keeps flickering.',
            'priority' => 'medium',
            'status' => 'approved',
            'last_activity_at' => now(),
        ]);

        $this
            ->actingAs($owner, 'api')
            ->postJson("/api/v1/maintenance/requests/{$maintenanceRequest->id}/comment", [
                'body' => 'Electrician visit is scheduled for tomorrow morning.',
            ])
            ->assertCreated()
            ->assertJsonPath('comment.user.id', $owner->id);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $tenant->id,
            'type' => 'maintenance_comment',
            'title' => 'Maintenance Comment Added',
        ]);

        Notification::assertSentTo($tenant, MaintenanceCommentAddedNotification::class);
        Notification::assertNotSentTo($owner, MaintenanceCommentAddedNotification::class);
    }

    public function test_request_created_event_exposes_expected_broadcast_channels_and_payload(): void
    {
        $owner = User::factory()->create();
        $tenant = User::factory()->create();
        $manager = User::factory()->create();

        $property = $this->createProperty($owner->id, 'Palm Residency');
        $property->update(['manager_id' => $manager->id]);

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
            'title' => 'Lobby intercom issue',
            'description' => 'The intercom speaker is not audible.',
            'priority' => 'high',
            'status' => 'pending',
            'last_activity_at' => now(),
        ])->load([
            'property.owner',
            'property.manager',
        ]);

        $event = new MaintenanceRequestCreated($maintenanceRequest);

        $channelNames = collect($event->broadcastOn())
            ->map(fn ($channel) => $channel->name)
            ->all();

        $this->assertContains('private-user.'.$tenant->id, $channelNames);
        $this->assertContains('private-user.'.$owner->id, $channelNames);
        $this->assertContains('private-user.'.$manager->id, $channelNames);
        $this->assertContains('private-property.'.$property->id, $channelNames);

        $this->assertSame('request.created', $event->broadcastAs());
        $this->assertSame($maintenanceRequest->id, $event->broadcastWith()['request_id']);
        $this->assertSame('pending', $event->broadcastWith()['status']);
        $this->assertSame($property->id, $event->broadcastWith()['property_id']);
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
