<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversations') && ! Schema::hasColumn('conversations', 'user_deleted_at')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->timestamp('user_deleted_at')->nullable()->after('listing_id');
            });
        }

        if (Schema::hasTable('conversations') && ! Schema::hasColumn('conversations', 'recipient_deleted_at')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->timestamp('recipient_deleted_at')->nullable()->after('user_deleted_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('conversations') && Schema::hasColumn('conversations', 'recipient_deleted_at')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropColumn('recipient_deleted_at');
            });
        }

        if (Schema::hasTable('conversations') && Schema::hasColumn('conversations', 'user_deleted_at')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropColumn('user_deleted_at');
            });
        }
    }
};
