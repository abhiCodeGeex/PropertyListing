<?php

return [
    'tenant_group_creation' => env('CHAT_TENANT_GROUP_CREATION', false),
    'presence' => [
        'store' => env('CHAT_PRESENCE_STORE', env('CACHE_STORE', 'file')),
        'ttl_seconds' => (int) env('CHAT_PRESENCE_TTL', 120),
    ],
    'messages' => [
        'max_text_length' => (int) env('CHAT_MESSAGE_MAX_LENGTH', 5000),
        'max_attachments' => (int) env('CHAT_MESSAGE_MAX_ATTACHMENTS', 5),
        'max_attachment_size_kb' => (int) env('CHAT_MESSAGE_MAX_ATTACHMENT_SIZE_KB', 10240),
    ],
];
