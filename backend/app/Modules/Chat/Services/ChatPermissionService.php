<?php

namespace App\Modules\Chat\Services;

use App\Models\Property;
use App\Models\PropertyTenant;
use App\Models\User;
use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Models\ChatParticipant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ChatPermissionService
{
    public function canCreateGroup(User $actor): bool
    {
        if ($actor->hasRole('super-admin')) {
            return true;
        }

        if ($actor->hasRole('owner') || $actor->hasRole('property_manager')) {
            return true;
        }

        return $actor->hasRole('tenant') && (bool) config('chat.tenant_group_creation', false);
    }

    public function assertCanCreatePrivateChat(User $actor, User $recipient): void
    {
        if ((int) $actor->id === (int) $recipient->id) {
            throw ValidationException::withMessages([
                'recipient_id' => ['You cannot create a direct chat with yourself.'],
            ]);
        }

        if (! $this->canCreatePrivateChat($actor, $recipient)) {
            throw new AuthorizationException('You are not allowed to create this direct chat.');
        }
    }

    public function assertCanCreateGroupChat(User $actor, Collection $participants, ?Property $property): void
    {
        if (! $this->canCreateGroup($actor)) {
            throw new AuthorizationException('You are not allowed to create group chats.');
        }

        if ($participants->isEmpty()) {
            throw ValidationException::withMessages([
                'participant_ids' => ['At least one participant must be selected.'],
            ]);
        }

        if ($actor->hasRole('super-admin')) {
            return;
        }

        if (! $property) {
            throw ValidationException::withMessages([
                'property_id' => ['A related property is required for this group chat.'],
            ]);
        }

        if (! $this->canScopeProperty($actor, $property)) {
            throw new AuthorizationException('You are not allowed to create a group for this property.');
        }

        foreach ($participants as $participant) {
            if (! $participant instanceof User) {
                continue;
            }

            if (! $this->canIncludeInPropertyGroup($actor, $participant, $property)) {
                throw ValidationException::withMessages([
                    'participant_ids' => ["User #{$participant->id} is not eligible for this property chat group."],
                ]);
            }
        }
    }

    public function canManageGroup(User $actor, Chat $chat): bool
    {
        if ($chat->type !== Chat::TYPE_GROUP) {
            return false;
        }

        if (! $this->isActiveParticipant($chat->id, (int) $actor->id)) {
            return false;
        }

        return $actor->hasRole('super-admin')
            || $actor->hasRole('owner')
            || $actor->hasRole('property_manager');
    }

    public function assertCanManageGroup(User $actor, Chat $chat, string $action = 'manage this group chat'): void
    {
        if (! $this->canManageGroup($actor, $chat)) {
            throw new AuthorizationException("You are not allowed to {$action}.");
        }
    }

    public function canCreatePrivateChat(User $actor, User $recipient): bool
    {
        if ((int) $actor->id === (int) $recipient->id) {
            return false;
        }

        if ($actor->hasRole('super-admin')) {
            return true;
        }

        if ($actor->hasRole('owner')) {
            if ($recipient->hasRole('tenant') && $this->ownerSharesTenant($actor->id, $recipient->id)) {
                return true;
            }

            if ($recipient->hasRole('property_manager') && $this->ownerSharesManager($actor->id, $recipient->id)) {
                return true;
            }
        }

        if ($actor->hasRole('property_manager')) {
            if ($recipient->hasRole('super-admin')) {
                return true;
            }

            if ($recipient->hasRole('owner') && $this->managerSharesOwner($actor->id, $recipient->id)) {
                return true;
            }

            if ($recipient->hasRole('tenant') && $this->managerSharesTenant($actor->id, $recipient->id)) {
                return true;
            }
        }

        if ($actor->hasRole('tenant')) {
            if ($recipient->hasRole('owner') && $this->tenantSharesOwner($actor->id, $recipient->id)) {
                return true;
            }

            if ($recipient->hasRole('property_manager') && $this->tenantSharesManager($actor->id, $recipient->id)) {
                return true;
            }
        }

        return false;
    }

    public function canIncludeInPropertyGroup(User $actor, User $participant, Property $property): bool
    {
        if ((int) $actor->id === (int) $participant->id) {
            return false;
        }

        if ($actor->hasRole('super-admin')) {
            return true;
        }

        if ($actor->hasRole('owner') && (int) $property->user_id === (int) $actor->id) {
            if ($participant->hasRole('property_manager') && (int) $property->manager_id === (int) $participant->id) {
                return true;
            }

            if ($participant->hasRole('tenant')) {
                return PropertyTenant::query()
                    ->where('property_id', $property->id)
                    ->where('tenant_id', $participant->id)
                    ->exists();
            }
        }

        if ($actor->hasRole('property_manager') && (int) $property->manager_id === (int) $actor->id) {
            if ($participant->hasRole('owner') && (int) $property->user_id === (int) $participant->id) {
                return true;
            }

            if ($participant->hasRole('tenant')) {
                return PropertyTenant::query()
                    ->where('property_id', $property->id)
                    ->where('tenant_id', $participant->id)
                    ->exists();
            }
        }

        if (
            $actor->hasRole('tenant')
            && (bool) config('chat.tenant_group_creation', false)
            && PropertyTenant::query()->where('property_id', $property->id)->where('tenant_id', $actor->id)->exists()
        ) {
            if ($participant->hasRole('owner') && (int) $property->user_id === (int) $participant->id) {
                return true;
            }

            if ($participant->hasRole('property_manager') && (int) $property->manager_id === (int) $participant->id) {
                return true;
            }
        }

        return false;
    }

    public function canScopeProperty(User $actor, Property $property): bool
    {
        if ($actor->hasRole('super-admin')) {
            return true;
        }

        if ($actor->hasRole('owner') && (int) $property->user_id === (int) $actor->id) {
            return true;
        }

        if ($actor->hasRole('property_manager') && (int) $property->manager_id === (int) $actor->id) {
            return true;
        }

        return $actor->hasRole('tenant')
            && (bool) config('chat.tenant_group_creation', false)
            && PropertyTenant::query()->where('property_id', $property->id)->where('tenant_id', $actor->id)->exists();
    }

    private function ownerSharesTenant(int $ownerId, int $tenantId): bool
    {
        return PropertyTenant::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('property', fn ($query) => $query->where('user_id', $ownerId))
            ->exists();
    }

    private function ownerSharesManager(int $ownerId, int $managerId): bool
    {
        return Property::query()
            ->where('user_id', $ownerId)
            ->where('manager_id', $managerId)
            ->exists();
    }

    private function managerSharesOwner(int $managerId, int $ownerId): bool
    {
        return Property::query()
            ->where('manager_id', $managerId)
            ->where('user_id', $ownerId)
            ->exists();
    }

    private function managerSharesTenant(int $managerId, int $tenantId): bool
    {
        return PropertyTenant::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('property', fn ($query) => $query->where('manager_id', $managerId))
            ->exists();
    }

    private function tenantSharesOwner(int $tenantId, int $ownerId): bool
    {
        return PropertyTenant::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('property', fn ($query) => $query->where('user_id', $ownerId))
            ->exists();
    }

    private function tenantSharesManager(int $tenantId, int $managerId): bool
    {
        return PropertyTenant::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('property', fn ($query) => $query->where('manager_id', $managerId))
            ->exists();
    }

    private function isActiveParticipant(int $chatId, int $userId): bool
    {
        return ChatParticipant::query()
            ->where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->exists();
    }
}
