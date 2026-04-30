import { Injectable, NgZone } from '@angular/core';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { NotificationStore } from '../services/notification.store';
import { environment } from '../../environments/environment';

(window as any).Pusher = Pusher;

@Injectable({ providedIn: 'root' })
export class WebsocketService {
    private echo?: Echo<any>;
    private connectionAttempted = false;
    private callbacks: Record<string, Set<() => void>> = {};
    private privateChannels = new Map<string, any>();

    constructor(private notificationStore: NotificationStore, private ngZone: NgZone) { }

    on(title: string, callback: () => void): () => void {
        this.callbacks[title] ??= new Set();
        this.callbacks[title].add(callback);

        return () => {
            const callbacks = this.callbacks[title];
            if (!callbacks) {
                return;
            }

            callbacks.delete(callback);

            if (callbacks.size === 0) {
                delete this.callbacks[title];
            }
        };
    }

    connect(userId: number): void {
        if (this.echo || this.connectionAttempted) return;
        this.connectionAttempted = true;

        try {
            this.echo = new Echo({
                broadcaster: 'reverb',
                key: environment.reverbKey,
                wsHost: environment.reverbHost,
                wsPort: environment.reverbPort,
                forceTLS: environment.reverbUseTls,
                enabledTransports: [environment.reverbUseTls ? 'wss' : 'ws'],
                authEndpoint: `${environment.apiUrl}/broadcasting/auth`,
                auth: {
                    headers: {
                        Authorization: `Bearer ${localStorage.getItem('api_token')}`,
                    },
                },
            });
            const userChannel = this.getPrivateChannel(`user.${userId}`);

            userChannel
                .listen('.notification.created', (e: any) => {
                    this.ngZone.run(() => {
                        const context = e.context ?? {};
                        const notification = {
                            id: Number(e.notification.id),
                            title: e.notification.title,
                            type: e.notification.type,
                            message: e.notification.message,
                            read_at: e.notification.read_at ?? null,
                            created_at: e.notification.created_at ?? new Date().toISOString(),
                            notifiable_id: e.notification.notifiable_id ?? null,
                            notifiable_type: e.notification.notifiable_type ?? null,
                        };

                        this.notificationStore.prepend(notification);
                        this.emitNotificationEvents(notification.type, context);
                    });
                })
                .listen('.manual.security.deposit', () => {
                    this.ngZone.run(() => {
                        this.emit('security_deposit');
                    });
                })
                .listen('.manual.rent.deposit', () => {
                    this.ngZone.run(() => {
                        this.emit('rent_deposit');
                    });
                });
        } catch (error) {
            console.warn('Realtime connection unavailable. Continuing without websocket updates.', error);
            this.echo = undefined;
        }
    }

    disconnect(): void {
        this.echo?.disconnect();
        this.echo = undefined;
        this.connectionAttempted = false;
        this.callbacks = {};
        this.privateChannels.clear();
    }

    listen(channelName: string, eventName: string, callback: (payload: any) => void): () => void {
        return this.listenToPrivateChannel(channelName, eventName, callback);
    }

    listenToPrivateChannel(channelName: string, eventName: string, callback: (payload: any) => void): () => void {
        const channel = this.getPrivateChannel(channelName);

        if (!channel) {
            return () => undefined;
        }

        channel.listen(eventName, (payload: any) => {
            this.ngZone.run(() => callback(payload));
        });

        return () => {
            channel.stopListening(eventName);
        };
    }

    leavePrivateChannel(channelName: string): void {
        if (!this.echo) {
            return;
        }

        this.privateChannels.delete(channelName);
        this.echo.leave(channelName);
    }

    emit(eventName: string): void;
    emit(channelName: string, eventName: string, payload: any): void;
    emit(first: string, second?: string, third?: any): void {
        if (typeof second === 'string') {
            const channel = this.getPrivateChannel(first);

            if (typeof channel?.whisper === 'function') {
                channel.whisper(second, third);
            }

            return;
        }

        const eventName = first;
        for (const callback of this.callbacks[eventName] ?? []) {
            callback();
        }
    }

    private getPrivateChannel(channelName: string): any {
        if (!this.echo) {
            return undefined;
        }

        if (!this.privateChannels.has(channelName)) {
            this.privateChannels.set(channelName, this.echo.private(channelName));
        }

        return this.privateChannels.get(channelName);
    }

    private emitNotificationEvents(type: string, context: any): void {
        const event = context?.event ?? null;
        const paymentType = context?.payment_type ?? null;

        this.emit(type);

        if (type === 'rent_deposit' && event === 'paid') {
            this.emit('rent_paid');
            this.emit('stripe_payment_succeeded');
            return;
        }

        if (type === 'security_deposit' && event === 'paid') {
            this.emit('security_deposit_paid');
            this.emit('stripe_payment_succeeded');
            return;
        }

        if (type === 'overdue' && event === 'paid') {
            this.emit('overdue_paid');
            this.emit('stripe_payment_succeeded');
            return;
        }

        if (type === 'subscription') {
            if (event === 'activated') this.emit('subscription_activated');
            if (event === 'scheduled') this.emit('subscription_scheduled');
            if (event === 'past_due') this.emit('subscription_past_due');
            if (event === 'cancelled') this.emit('subscription_cancelled');
            return;
        }

        if (type === 'payment_failed') {
            this.emit('payment_failed');
            if (paymentType) {
                this.emit(`${paymentType}_failed`);
            }
        }
    }
}
