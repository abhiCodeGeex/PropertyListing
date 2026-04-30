import { HttpClient } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { BaseApiService } from '../../services/base-api.service';
import {
  ChatContextResponse,
  ChatListResponse,
  ChatMessagesResponse,
  ChatSummary
} from './chat.models';

@Injectable({ providedIn: 'root' })
export class ChatService extends BaseApiService {
  private readonly endpoint = '/v1/chat';

  constructor(http: HttpClient) {
    super(http);
  }

  getContext(params?: { search?: string; property_id?: number | null }): Observable<ChatContextResponse> {
    return this.get(`${this.endpoint}/context`, params, { skipLoader: true });
  }

  getChats(params?: { search?: string; per_page?: number }): Observable<ChatListResponse> {
    return this.get(`${this.endpoint}/chats`, params, { skipLoader: true });
  }

  getChat(chatId: number): Observable<{ chat: ChatSummary }> {
    return this.get(`${this.endpoint}/chats/${chatId}`, undefined, { skipLoader: true });
  }

  getMessages(
    chatId: number,
    params?: { before_id?: number | null; per_page?: number }
  ): Observable<ChatMessagesResponse> {
    return this.get(`${this.endpoint}/chats/${chatId}/messages`, params, { skipLoader: true });
  }

  createPrivateChat(recipientId: number): Observable<{ message: string; chat: ChatSummary }> {
    return this.post(`${this.endpoint}/chats/private`, { recipient_id: recipientId });
  }

  createGroupChat(payload: {
    title: string;
    description?: string | null;
    property_id?: number | null;
    participant_ids: number[];
  }): Observable<{ message: string; chat: ChatSummary }> {
    return this.post(`${this.endpoint}/chats/group`, payload);
  }

  deleteGroupChat(chatId: number): Observable<{ message: string }> {
    return this.delete(`${this.endpoint}/chats/${chatId}`);
  }

  removeGroupParticipant(
    chatId: number,
    userId: number
  ): Observable<{ message: string; data: { chat_id: number; removed_user_id: number; chat_deleted: boolean } }> {
    return this.delete(`${this.endpoint}/chats/${chatId}/participants/${userId}`);
  }

  sendMessage(
    chatId: number,
    payload: { message: string; attachments: File[] }
  ): Observable<{ message: string; data: any }> {
    return this.post(`${this.endpoint}/chats/${chatId}/messages`, this.messageFormData(payload));
  }

  markRead(chatId: number, messageId?: number | null): Observable<{ message: string; data: any }> {
    return this.post(`${this.endpoint}/chats/${chatId}/read`, messageId ? { message_id: messageId } : {});
  }

  sendTyping(chatId: number, typing: boolean): Observable<{ message: string }> {
    return this.post(`${this.endpoint}/chats/${chatId}/typing`, { typing }, { skipLoader: true });
  }

  markPresenceOnline(): Observable<{ message: string }> {
    return this.post(`${this.endpoint}/presence/online`, {}, { skipLoader: true });
  }

  markPresenceOffline(): Observable<{ message: string }> {
    return this.post(`${this.endpoint}/presence/offline`, {}, { skipLoader: true });
  }

  downloadAttachment(chatId: number, attachmentId: number): Observable<Blob> {
    return this.http.get(
      `${environment.apiUrl}${this.endpoint}/chats/${chatId}/attachments/${attachmentId}`,
      { responseType: 'blob' }
    );
  }

  private messageFormData(payload: { message: string; attachments: File[] }): FormData {
    const formData = new FormData();
    const trimmed = payload.message.trim();
    const attachments = payload.attachments ?? [];
    const allImages = attachments.length > 0 && attachments.every(file => file.type.startsWith('image/'));
    const type = attachments.length === 0 ? 'text' : allImages ? 'image' : 'file';

    if (trimmed) {
      formData.append('message', trimmed);
    }

    formData.append('type', type);

    for (const file of attachments) {
      formData.append('attachments[]', file);
    }

    return formData;
  }
}
