<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyTenant;
use App\Models\User;
use App\Modules\Chat\Events\ChatUpdated;
use App\Modules\Chat\Events\MessageSent;
use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Models\ChatMessage;
use App\Modules\Chat\Models\ChatParticipant;
use App\Modules\Chat\Notifications\NewChatMessageNotification;
use App\Modules\Chat\Services\ChatPresenceService;
use App\Events\NotificationCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ChatModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('broadcasting.default', 'null');
        config()->set('chat.presence.store', 'array');

        Role::findOrCreate('owner', 'web');
        Role::findOrCreate('tenant', 'web');
        Role::findOrCreate('property_manager', 'web');
        Role::findOrCreate('super-admin', 'web');
    }

    public function test_owner_can_create_related_private_chat_and_duplicate_private_chat_is_reused(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $property = $this->createProperty($owner->id, 'Cedar Heights');
        $this->assignTenant($property->id, $tenant->id);

        $firstResponse = $this
            ->actingAs($owner, 'api')
            ->postJson('/api/v1/chat/chats/private', [
                'recipient_id' => $tenant->id,
            ])
            ->assertCreated();

        $chatId = (int) $firstResponse->json('chat.id');

        $this
            ->actingAs($owner, 'api')
            ->postJson('/api/v1/chat/chats/private', [
                'recipient_id' => $tenant->id,
            ])
            ->assertCreated()
            ->assertJsonPath('chat.id', $chatId);

        $this->assertDatabaseCount('chats', 1);
        $this->assertDatabaseHas('chats', [
            'id' => $chatId,
            'type' => Chat::TYPE_PRIVATE,
        ]);
        $this->assertDatabaseCount('chat_participants', 2);
    }

    public function test_owner_cannot_open_private_chat_with_unrelated_tenant(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $this
            ->actingAs($owner, 'api')
            ->postJson('/api/v1/chat/chats/private', [
                'recipient_id' => $tenant->id,
            ])
            ->assertForbidden();
    }

    public function test_tenant_cannot_create_group_chat_by_default(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $manager = User::factory()->create();
        $manager->assignRole('property_manager');

        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $property = $this->createProperty($owner->id, 'Palm Residency', $manager->id);
        $this->assignTenant($property->id, $tenant->id, $manager->id);

        $this
            ->actingAs($tenant, 'api')
            ->postJson('/api/v1/chat/chats/group', [
                'title' => 'Property Updates',
                'property_id' => $property->id,
                'participant_ids' => [$owner->id, $manager->id],
            ])
            ->assertForbidden();
    }

    public function test_message_sent_event_exposes_expected_channel_and_payload(): void
    {
        $owner = User::factory()->create(['name' => 'Owner User']);
        $owner->assignRole('owner');

        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $chat = $this->createPrivateChat($owner, $tenant);

        $message = ChatMessage::query()->create([
            'chat_id' => $chat->id,
            'sender_id' => $owner->id,
            'type' => ChatMessage::TYPE_TEXT,
            'body' => 'The lease draft is ready for review.',
        ])->load('sender', 'attachments', 'reads');

        $event = new MessageSent($message);

        $this->assertSame('private-chat.'.$chat->id, $event->broadcastOn()->name);
        $this->assertSame('message.sent', $event->broadcastAs());
        $this->assertSame($chat->id, $event->broadcastWith()['chat_id']);
        $this->assertSame($message->id, $event->broadcastWith()['message_id']);
        $this->assertSame($owner->id, $event->broadcastWith()['sender_id']);
        $this->assertSame('The lease draft is ready for review.', $event->broadcastWith()['message']);
        $this->assertSame('text', $event->broadcastWith()['type']);
    }

    public function test_new_message_creates_in_app_notification_and_never_sends_email(): void
    {
        Notification::fake();
        Event::fake([NotificationCreated::class]);

        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $property = $this->createProperty($owner->id, 'Harbor View');
        $this->assignTenant($property->id, $tenant->id);

        $chat = $this->createPrivateChat($owner, $tenant);

        app(ChatPresenceService::class)->markOnline($tenant->id);

        $this
            ->actingAs($owner, 'api')
            ->postJson("/api/v1/chat/chats/{$chat->id}/messages", [
                'type' => 'text',
                'message' => 'Checking in while you are online.',
            ])
            ->assertCreated();

        Notification::assertNotSentTo($tenant, NewChatMessageNotification::class);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $tenant->id,
            'type' => 'chat_message',
            'title' => 'New Chat Message',
            'notifiable_type' => Chat::class,
            'notifiable_id' => $chat->id,
        ]);

        Event::assertDispatched(NotificationCreated::class, fn (NotificationCreated $event) => $event->userId === $tenant->id
            && ($event->notification['type'] ?? null) === 'chat_message'
            && ($event->notification['notifiable_id'] ?? null) === $chat->id);

        app(ChatPresenceService::class)->markOffline($tenant->id);

        $this
            ->actingAs($owner, 'api')
            ->postJson("/api/v1/chat/chats/{$chat->id}/messages", [
                'type' => 'text',
                'message' => 'Please review the latest payment note.',
            ])
            ->assertCreated();

        Notification::assertNotSentTo($tenant, NewChatMessageNotification::class);
        $this->assertSame(2, \App\Models\Notification::query()
            ->where('user_id', $tenant->id)
            ->where('type', 'chat_message')
            ->count());
    }

    public function test_group_chat_creation_succeeds_when_redis_presence_store_is_unavailable(): void
    {
        config()->set('chat.presence.store', 'redis');
        config()->set('cache.default', 'array');

        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $manager = User::factory()->create();
        $manager->assignRole('property_manager');

        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $property = $this->createProperty($owner->id, 'Willow Court', $manager->id);
        $this->assignTenant($property->id, $tenant->id, $manager->id);

        $this
            ->actingAs($owner, 'api')
            ->postJson('/api/v1/chat/chats/group', [
                'title' => 'Move-in Coordination',
                'description' => 'Schedule, paperwork, and utility handoff.',
                'property_id' => $property->id,
                'participant_ids' => [$manager->id, $tenant->id],
            ])
            ->assertCreated()
            ->assertJsonPath('chat.title', 'Move-in Coordination');
    }

    public function test_owner_can_delete_group_chat_when_participating(): void
    {
        Event::fake([ChatUpdated::class]);

        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $manager = User::factory()->create();
        $manager->assignRole('property_manager');

        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $chat = $this->createGroupChat($owner, [$manager, $tenant]);

        $this
            ->actingAs($owner, 'api')
            ->deleteJson("/api/v1/chat/chats/{$chat->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Group chat deleted successfully.');

        $this->assertSoftDeleted('chats', ['id' => $chat->id]);
        $this->assertSoftDeleted('chat_participants', ['chat_id' => $chat->id, 'user_id' => $owner->id]);
        $this->assertSoftDeleted('chat_participants', ['chat_id' => $chat->id, 'user_id' => $manager->id]);
        $this->assertSoftDeleted('chat_participants', ['chat_id' => $chat->id, 'user_id' => $tenant->id]);
        $this->assertSame(0, ChatParticipant::query()->where('chat_id', $chat->id)->whereNull('deleted_at')->count());

        Event::assertDispatched(ChatUpdated::class, fn (ChatUpdated $event) => $event->chat->id === $chat->id
            && $event->action === 'deleted'
            && $event->chatDeleted === true
            && $event->participantCount === 0);
    }

    public function test_manager_can_remove_any_group_participant(): void
    {
        Event::fake([ChatUpdated::class]);

        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $manager = User::factory()->create();
        $manager->assignRole('property_manager');

        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $chat = $this->createGroupChat($owner, [$manager, $tenant]);

        $this
            ->actingAs($manager, 'api')
            ->deleteJson("/api/v1/chat/chats/{$chat->id}/participants/{$owner->id}")
            ->assertOk()
            ->assertJsonPath('data.chat_deleted', false)
            ->assertJsonPath('data.removed_user_id', $owner->id);

        $this->assertSoftDeleted('chat_participants', [
            'chat_id' => $chat->id,
            'user_id' => $owner->id,
        ]);

        $this->assertSame(
            [$manager->id, $tenant->id],
            ChatParticipant::query()
                ->where('chat_id', $chat->id)
                ->whereNull('deleted_at')
                ->orderBy('user_id')
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );

        Event::assertDispatched(ChatUpdated::class, fn (ChatUpdated $event) => $event->chat->id === $chat->id
            && $event->action === 'participant_removed'
            && $event->affectedUserId === $owner->id
            && $event->chatDeleted === false
            && $event->participantCount === 2);
    }

    public function test_manager_can_remove_self_and_group_is_deleted_when_no_participants_remain(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $manager = User::factory()->create();
        $manager->assignRole('property_manager');

        $chat = $this->createGroupChat($manager, [$owner]);

        $this
            ->actingAs($manager, 'api')
            ->deleteJson("/api/v1/chat/chats/{$chat->id}/participants/{$owner->id}")
            ->assertOk()
            ->assertJsonPath('data.chat_deleted', false);

        $this
            ->actingAs($manager, 'api')
            ->deleteJson("/api/v1/chat/chats/{$chat->id}/participants/{$manager->id}")
            ->assertOk()
            ->assertJsonPath('data.chat_deleted', true)
            ->assertJsonPath('data.removed_user_id', $manager->id);

        $this->assertSoftDeleted('chats', ['id' => $chat->id]);
        $this->assertSoftDeleted('chat_participants', ['chat_id' => $chat->id, 'user_id' => $owner->id]);
        $this->assertSoftDeleted('chat_participants', ['chat_id' => $chat->id, 'user_id' => $manager->id]);
    }

    public function test_tenant_cannot_delete_group_or_remove_participants(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $manager = User::factory()->create();
        $manager->assignRole('property_manager');

        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $chat = $this->createGroupChat($owner, [$manager, $tenant]);

        $this
            ->actingAs($tenant, 'api')
            ->deleteJson("/api/v1/chat/chats/{$chat->id}")
            ->assertForbidden();

        $this
            ->actingAs($tenant, 'api')
            ->deleteJson("/api/v1/chat/chats/{$chat->id}/participants/{$owner->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('chats', ['id' => $chat->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('chat_participants', [
            'chat_id' => $chat->id,
            'user_id' => $owner->id,
            'deleted_at' => null,
        ]);
    }

    public function test_chat_updated_event_exposes_expected_channel_and_payload(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $chat = $this->createGroupChat($owner, [$tenant]);
        $event = new ChatUpdated($chat, 'participant_removed', $owner->id, $tenant->id, false, 1);

        $this->assertSame('private-chat.'.$chat->id, $event->broadcastOn()->name);
        $this->assertSame('chat.updated', $event->broadcastAs());
        $this->assertSame($chat->id, $event->broadcastWith()['chat_id']);
        $this->assertSame('participant_removed', $event->broadcastWith()['action']);
        $this->assertSame($owner->id, $event->broadcastWith()['actor_id']);
        $this->assertSame($tenant->id, $event->broadcastWith()['affected_user_id']);
        $this->assertSame(false, $event->broadcastWith()['chat_deleted']);
        $this->assertSame(1, $event->broadcastWith()['participant_count']);
    }

    private function createProperty(int $ownerId, string $name, ?int $managerId = null): Property
    {
        return Property::create([
            'user_id' => $ownerId,
            'manager_id' => $managerId,
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

    private function assignTenant(int $propertyId, int $tenantId, ?int $managerId = null): PropertyTenant
    {
        return PropertyTenant::create([
            'property_id' => $propertyId,
            'tenant_id' => $tenantId,
            'manager_id' => $managerId,
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
        ]);
    }

    private function createGroupChat(User $creator, array $participants): Chat
    {
        $chat = Chat::query()->create([
            'type' => Chat::TYPE_GROUP,
            'created_by' => $creator->id,
            'title' => 'Property Coordination',
            'description' => 'Group for coordination',
        ]);

        $allParticipants = collect([$creator, ...$participants])
            ->unique(fn (User $user) => $user->id)
            ->values();

        foreach ($allParticipants as $participant) {
            ChatParticipant::query()->create([
                'chat_id' => $chat->id,
                'user_id' => $participant->id,
                'added_by' => $creator->id,
                'joined_at' => now(),
            ]);
        }

        return $chat;
    }

    private function createPrivateChat(User $firstUser, User $secondUser): Chat
    {
        $ids = [$firstUser->id, $secondUser->id];
        sort($ids);

        $chat = Chat::query()->create([
            'type' => Chat::TYPE_PRIVATE,
            'private_key' => implode(':', $ids),
            'created_by' => $firstUser->id,
        ]);

        ChatParticipant::query()->create([
            'chat_id' => $chat->id,
            'user_id' => $firstUser->id,
            'added_by' => $firstUser->id,
            'joined_at' => now(),
        ]);

        ChatParticipant::query()->create([
            'chat_id' => $chat->id,
            'user_id' => $secondUser->id,
            'added_by' => $firstUser->id,
            'joined_at' => now(),
        ]);

        return $chat;
    }
}
