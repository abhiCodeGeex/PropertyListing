<?php

use App\Models\Property;
use App\Modules\Chat\Models\ChatParticipant;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{id}', function ($user, $id) {
    logger('Broadcast auth', [
        'auth_user' => $user?->id,
        'channel_id' => $id,
    ]);

    return (int) $user->id === (int) $id;
});

Broadcast::channel('property.{id}', function ($user, $id) {
    $property = Property::query()->find($id);

    if (! $property) {
        return false;
    }

    if ($user->hasRole('super-admin')) {
        return true;
    }

    if ((int) $property->user_id === (int) $user->id) {
        return true;
    }

    if ((int) $property->manager_id === (int) $user->id) {
        return true;
    }

    return $property->tenants()->where('users.id', $user->id)->exists();
});

Broadcast::channel('chat.{chatId}', function ($user, $chatId) {
    return ChatParticipant::query()
        ->where('chat_id', $chatId)
        ->where('user_id', $user->id)
        ->whereNull('deleted_at')
        ->exists();
});
