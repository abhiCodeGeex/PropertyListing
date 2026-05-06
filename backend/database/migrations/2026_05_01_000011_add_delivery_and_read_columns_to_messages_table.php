<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (! Schema::hasColumn('messages', 'receiver_id')) {
                $table->foreignId('receiver_id')->nullable()->after('sender_id')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('messages', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('meta');
            }

            if (! Schema::hasColumn('messages', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('delivered_at');
            }
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->index(['receiver_id', 'read_at'], 'messages_receiver_read_idx');
            $table->index(['chat_id', 'receiver_id', 'read_at'], 'messages_chat_receiver_read_idx');
            $table->index(['chat_id', 'sender_id', 'read_at'], 'messages_chat_sender_read_idx');
            $table->index(['chat_id', 'sender_id', 'delivered_at'], 'messages_chat_sender_delivered_idx');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_receiver_read_idx');
            $table->dropIndex('messages_chat_receiver_read_idx');
            $table->dropIndex('messages_chat_sender_read_idx');
            $table->dropIndex('messages_chat_sender_delivered_idx');
        });

        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'receiver_id')) {
                $table->dropConstrainedForeignId('receiver_id');
            }
            if (Schema::hasColumn('messages', 'read_at')) {
                $table->dropColumn('read_at');
            }
            if (Schema::hasColumn('messages', 'delivered_at')) {
                $table->dropColumn('delivered_at');
            }
        });
    }
};
