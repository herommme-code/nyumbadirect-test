<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversations') && ! Schema::hasColumn('conversations', 'recipient_id')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->integer('recipient_id')->nullable()->index()->after('user_id');
            });
        }

        if (Schema::hasTable('chat_messages') && ! Schema::hasColumn('chat_messages', 'sender_id')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->integer('sender_id')->nullable()->index()->after('conversation_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('chat_messages') && Schema::hasColumn('chat_messages', 'sender_id')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropColumn('sender_id');
            });
        }

        if (Schema::hasTable('conversations') && Schema::hasColumn('conversations', 'recipient_id')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropColumn('recipient_id');
            });
        }
    }
};
