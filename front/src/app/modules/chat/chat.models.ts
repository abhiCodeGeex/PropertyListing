export interface ChatUserSummary {
  id: number;
  name: string;
  email: string;
  roles: string[];
  can_message?: boolean;
  group_eligible?: boolean;
}

export interface ChatPropertySummary {
  id: number;
  property_name: string;
  city?: string | null;
  state?: string | null;
  owner?: ChatUserSummary | null;
  manager?: ChatUserSummary | null;
}

export interface ChatAttachment {
  id: number;
  original_name: string;
  mime_type: string;
  size: number;
  download_endpoint: string;
}

export interface ChatMessage {
  id: number;
  chat_id: number;
  sender_id: number;
  sender: ChatUserSummary | null;
  message: string | null;
  type: 'text' | 'file' | 'image';
  timestamp: string;
  updated_at?: string | null;
  read_by_ids: number[];
  attachments: ChatAttachment[];
}

export interface ChatParticipantSummary extends ChatUserSummary {
  unread_count: number;
  last_read_message_id: number | null;
  last_read_at: string | null;
}

export interface ChatSummary {
  id: number;
  type: 'private' | 'group';
  title: string | null;
  description: string | null;
  property_id: number | null;
  created_by: number;
  last_message_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  unread_count: number;
  participants: ChatParticipantSummary[];
  counterpart: ChatParticipantSummary | null;
  property: ChatPropertySummary | null;
  last_message: ChatMessage | null;
  meta?: Record<string, unknown>;
}

export interface ChatContextResponse {
  can_create_group: boolean;
  properties: ChatPropertySummary[];
  users: ChatUserSummary[];
}

export interface ChatListResponse {
  data: ChatSummary[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface ChatMessagesResponse {
  data: ChatMessage[];
  has_more: boolean;
  next_before_id: number | null;
}

export interface ChatRealtimeEvent<T = any> {
  type: 'chat.created' | 'chat.updated' | 'message.sent' | 'message.read' | 'user.typing';
  chatId?: number;
  payload: T;
}

export interface ChatMessageComposerPayload {
  message: string;
  attachments: File[];
}

export function chatDisplayName(chat: ChatSummary): string {
  if (chat.type === 'group') {
    return chat.title?.trim() || 'Untitled Group';
  }

  return chat.counterpart?.name || 'Direct Chat';
}

export function chatPreview(chat: ChatSummary): string {
  const lastMessage = chat.last_message;

  if (!lastMessage) {
    return chat.type === 'group'
      ? 'No messages yet.'
      : 'Start the conversation.';
  }

  if (lastMessage.attachments.length > 0 && !lastMessage.message) {
    return lastMessage.type === 'image'
      ? 'Shared an image'
      : 'Shared an attachment';
  }

  return lastMessage.message || 'New activity';
}
