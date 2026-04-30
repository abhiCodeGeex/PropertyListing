<?php

namespace App\Modules\Chat\Policies;

use App\Models\User;
use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Models\ChatParticipant;

class ChatPolicy
{
    public function view(User $user, Chat $chat): bool
    {
        return $this->isParticipant($user, $chat);
    }

    public function sendMessage(User $user, Chat $chat): bool
    {
        return $this->isParticipant($user, $chat);
    }

    public function markRead(User $user, Chat $chat): bool
    {
        return $this->isParticipant($user, $chat);
    }

    public function typing(User $user, Chat $chat): bool
    {
        return $this->isParticipant($user, $chat);
    }

    public function delete(User $user, Chat $chat): bool
    {
        return $this->canManageGroup($user, $chat);
    }

    public function manageParticipants(User $user, Chat $chat): bool
    {
        return $this->canManageGroup($user, $chat);
    }

    private function canManageGroup(User $user, Chat $chat): bool
    {
        if ($chat->type !== Chat::TYPE_GROUP || ! $this->isParticipant($user, $chat)) {
            return false;
        }

        return $user->hasRole('super-admin')
            || $user->hasRole('owner')
            || $user->hasRole('property_manager');
    }

    private function isParticipant(User $user, Chat $chat): bool
    {
        return ChatParticipant::query()
            ->where('chat_id', $chat->id)
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->exists();
    }
}
