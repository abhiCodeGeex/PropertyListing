<?php

namespace App\Modules\Chat\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Models\ChatMessage;
use App\Modules\Chat\Models\MessageAttachment;
use App\Modules\Chat\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService $service
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $chats = $this->service->paginateChats($request->user(), [
            'search' => $request->input('search'),
            'per_page' => $request->integer('per_page', 20),
        ]);

        $currentUserId = (int) $request->user()->id;
        $chats->through(fn (Chat $chat) => $this->chatPayload($chat, $currentUserId));

        return response()->json($chats);
    }

    public function show(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $loaded = $this->service->getVisibleChat($request->user(), $chat->id);

        return response()->json([
            'chat' => $this->chatPayload($loaded, (int) $request->user()->id, true),
        ]);
    }

    public function messages(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);
        $this->service->markMessagesDelivered($request->user(), $chat);

        $data = $this->service->getMessages($chat, [
            'before_id' => $request->input('before_id'),
            'per_page' => $request->integer('per_page', 30),
        ]);

        return response()->json([
            'data' => collect($data['messages'])
                ->map(fn (ChatMessage $message) => $this->messagePayload($message))
                ->values()
                ->all(),
            'has_more' => $data['has_more'],
            'next_before_id' => $data['next_before_id'],
        ]);
    }

    public function createPrivate(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'recipient_id' => 'required|exists:users,id',
        ]);

        $chat = $this->service->createPrivateChat(
            actor: $request->user(),
            recipientId: (int) $payload['recipient_id']
        );

        return response()->json([
            'message' => 'Direct chat is ready.',
            'chat' => $this->chatPayload($chat, (int) $request->user()->id, true),
        ], 201);
    }

    public function createGroup(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'title' => 'required|string|max:120',
            'description' => 'nullable|string|max:1000',
            'property_id' => 'nullable|exists:properties,id',
            'participant_ids' => 'required|array|min:1',
            'participant_ids.*' => 'required|integer|exists:users,id|distinct',
        ]);

        $chat = $this->service->createGroupChat(
            actor: $request->user(),
            participantIds: $payload['participant_ids'],
            payload: $payload
        );

        return response()->json([
            'message' => 'Group chat created successfully.',
            'chat' => $this->chatPayload($chat, (int) $request->user()->id, true),
        ], 201);
    }

    public function deleteGroup(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('delete', $chat);

        $this->service->deleteGroupChat($request->user(), $chat);

        return response()->json([
            'message' => 'Group chat deleted successfully.',
        ]);
    }

    public function removeParticipant(Request $request, Chat $chat, User $user): JsonResponse
    {
        $this->authorize('manageParticipants', $chat);

        $data = $this->service->removeGroupParticipant(
            actor: $request->user(),
            chat: $chat,
            participantUserId: (int) $user->id
        );

        return response()->json([
            'message' => 'Group participant removed successfully.',
            'data' => $data,
        ]);
    }

    public function sendMessage(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('sendMessage', $chat);

        $payload = $request->validate([
            'message' => 'nullable|string|max:5000',
            'type' => ['required', Rule::in(ChatMessage::TYPES)],
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv',
        ]);

        $message = $this->service->sendMessage(
            actor: $request->user(),
            chat: $chat,
            payload: $payload,
            files: $request->file('attachments', [])
        );

        return response()->json([
            'message' => 'Message sent successfully.',
            'data' => $this->messagePayload($message),
        ], 201);
    }

    public function markRead(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('markRead', $chat);

        $payload = $request->validate([
            'message_id' => 'nullable|integer',
        ]);

        $data = $this->service->markChatRead(
            actor: $request->user(),
            chat: $chat,
            messageId: isset($payload['message_id']) ? (int) $payload['message_id'] : null
        );

        return response()->json([
            'message' => 'Chat marked as read.',
            'data' => $data,
        ]);
    }

    public function typing(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('typing', $chat);

        $payload = $request->validate([
            'typing' => 'required|boolean',
        ]);

        $this->service->dispatchTyping($request->user(), $chat, (bool) $payload['typing']);

        return response()->json([
            'message' => 'Typing event dispatched.',
        ]);
    }

    public function context(Request $request): JsonResponse
    {
        return response()->json(
            $this->service->relatedContext($request->user(), [
                'search' => $request->input('search'),
                'property_id' => $request->input('property_id'),
            ])
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'count' => $this->service->unreadCount($request->user()),
        ]);
    }

    public function presenceOnline(Request $request): JsonResponse
    {
        $this->service->markPresenceOnline($request->user());

        return response()->json([
            'message' => 'Presence marked online.',
        ]);
    }

    public function presenceOffline(Request $request): JsonResponse
    {
        $this->service->markPresenceOffline($request->user());

        return response()->json([
            'message' => 'Presence marked offline.',
        ]);
    }

    public function downloadAttachment(Chat $chat, MessageAttachment $attachment)
    {
        $this->authorize('view', $chat);

        return $this->service->downloadAttachment($chat, $attachment);
    }

    private function chatPayload(Chat $chat, int $currentUserId, bool $detailed = false): array
    {
        $chat->loadMissing([
            'participants.user.roles',
            'property.owner',
            'property.manager',
            'lastMessage.sender',
            'lastMessage.attachments',
        ]);

        $participants = $chat->participants
            ->map(fn ($participant) => [
                'id' => $participant->user?->id,
                'name' => $participant->user?->name,
                'email' => $participant->user?->email,
                'roles' => $participant->user?->roles?->pluck('name')->values()->all() ?? [],
                'unread_count' => (int) $participant->unread_count,
                'last_read_message_id' => $participant->last_read_message_id ? (int) $participant->last_read_message_id : null,
                'last_read_at' => optional($participant->last_read_at)->toIso8601String(),
            ])
            ->values();

        $currentParticipant = $chat->participants->firstWhere('user_id', $currentUserId);
        $counterpart = $chat->isPrivate()
            ? $participants->first(fn ($participant) => (int) ($participant['id'] ?? 0) !== $currentUserId)
            : null;

        $payload = [
            'id' => $chat->id,
            'type' => $chat->type,
            'title' => $chat->title,
            'description' => $chat->description,
            'property_id' => $chat->property_id,
            'created_by' => $chat->created_by,
            'last_message_at' => optional($chat->last_message_at)->toIso8601String(),
            'created_at' => optional($chat->created_at)->toIso8601String(),
            'updated_at' => optional($chat->updated_at)->toIso8601String(),
            'unread_count' => (int) ($currentParticipant?->unread_count ?? 0),
            'participants' => $participants->all(),
            'counterpart' => $counterpart,
            'property' => $chat->property ? [
                'id' => $chat->property->id,
                'property_name' => $chat->property->property_name,
                'city' => $chat->property->city,
                'state' => $chat->property->state,
                'owner' => $chat->property->owner ? [
                    'id' => $chat->property->owner->id,
                    'name' => $chat->property->owner->name,
                    'email' => $chat->property->owner->email,
                ] : null,
                'manager' => $chat->property->manager ? [
                    'id' => $chat->property->manager->id,
                    'name' => $chat->property->manager->name,
                    'email' => $chat->property->manager->email,
                ] : null,
            ] : null,
            'last_message' => $chat->lastMessage ? $this->messagePayload($chat->lastMessage) : null,
        ];

        if (! $detailed) {
            return $payload;
        }

        $payload['meta'] = $chat->meta ?? [];

        return $payload;
    }

    private function messagePayload(ChatMessage $message): array
    {
        $message->loadMissing([
            'sender',
            'attachments',
            'reads.user',
        ]);

        return [
            'id' => $message->id,
            'chat_id' => $message->chat_id,
            'sender_id' => $message->sender_id,
            'sender' => $message->sender ? [
                'id' => $message->sender->id,
                'name' => $message->sender->name,
                'email' => $message->sender->email,
            ] : null,
            'message' => $message->body,
            'type' => $message->type,
            'timestamp' => optional($message->created_at)->toIso8601String(),
            'updated_at' => optional($message->updated_at)->toIso8601String(),
            'delivered_at' => optional($message->delivered_at)->toIso8601String(),
            'read_at' => optional($message->read_at)->toIso8601String(),
            'read_by_ids' => $message->reads
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
            'attachments' => $message->attachments
                ->map(fn (MessageAttachment $attachment) => [
                    'id' => $attachment->id,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'size' => $attachment->size,
                    'download_endpoint' => "/api/v1/chat/chats/{$message->chat_id}/attachments/{$attachment->id}",
                ])
                ->values()
                ->all(),
        ];
    }
}
