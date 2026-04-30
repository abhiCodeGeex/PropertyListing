# Real-Time Chat Module Setup

This implementation uses the existing Laravel Reverb stack already present in the production codebase. Reverb satisfies the "native WebSockets only" requirement without introducing Pusher SaaS or a second realtime server into an existing system.

## Backend runtime

Run the backend migration and websocket services:

```powershell
cd backend
php artisan migrate
php artisan config:clear
php artisan queue:work redis --queue=default,broadcasts --tries=3
php artisan reverb:start --host=0.0.0.0 --port=8080
```

## Required environment

Set the Laravel environment for Redis-backed broadcasting, queues, cache, and Reverb scaling:

```dotenv
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis
CACHE_STORE=redis

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_DB=0
REDIS_CACHE_DB=1

REVERB_APP_ID=property-listing
REVERB_APP_KEY=property-listing-key
REVERB_APP_SECRET=property-listing-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_SCALING_ENABLED=true
REVERB_SCALING_CHANNEL=reverb

FRONTEND_URL=http://localhost:4200
CHAT_TENANT_GROUP_CREATION=false
CHAT_PRESENCE_STORE=redis
CHAT_PRESENCE_TTL=120
```

## Frontend runtime

The Angular app already includes `laravel-echo` and `pusher-js` for Reverb protocol compatibility. Keep the environment aligned with the backend:

```ts
export const environment = {
  apiUrl: 'http://localhost:8000/api',
  backendUrl: 'http://localhost:8000',
  frontendUrl: 'http://localhost:4200',
  reverbKey: 'property-listing-key',
  reverbHost: 'localhost',
  reverbPort: 8080,
  reverbUseTls: false
};
```

Run the Angular app:

```powershell
cd front
npm run build
npm start
```

## API endpoints

All chat endpoints are protected by `auth:api`.

```text
GET    /api/v1/chat/context
POST   /api/v1/chat/presence/online
POST   /api/v1/chat/presence/offline
GET    /api/v1/chat/chats
POST   /api/v1/chat/chats/private
POST   /api/v1/chat/chats/group
GET    /api/v1/chat/chats/{chat}
GET    /api/v1/chat/chats/{chat}/messages
POST   /api/v1/chat/chats/{chat}/messages
POST   /api/v1/chat/chats/{chat}/read
POST   /api/v1/chat/chats/{chat}/typing
GET    /api/v1/chat/chats/{chat}/attachments/{attachment}
```

## Broadcast events

`chat.{chat_id}` private channel:

- `.message.sent`
- `.message.read`
- `.user.typing`

`user.{user_id}` private channel:

- `.chat.created`

## Database tables

- `chats`
- `chat_participants`
- `messages`
- `message_reads`
- `message_attachments`

## Sample flow

1. User opens `/chat` in Angular.
2. Angular connects to Reverb through Echo and subscribes to `user.{id}` plus active `chat.{chat_id}` channels.
3. User sends a message to `/api/v1/chat/chats/{chat}/messages`.
4. Laravel stores the message, attachments, read state, unread counters, and chat summary pointers.
5. Laravel dispatches `MessageSent`.
6. Reverb broadcasts the payload instantly over `private-chat.{chat_id}`.
7. Angular updates the message window and chat list in place.
8. When the recipient opens the chat, Angular posts `/read`, Laravel stores `message_reads`, updates unread counters, and broadcasts `MessageRead`.
9. If a recipient is offline according to Redis-backed presence TTL, Laravel queues an email notification.
