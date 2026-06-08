<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_online')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_online')->default(false)->after('profile_photo_url');
            });
        }

        if (! Schema::hasColumn('users', 'last_seen_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('last_seen_at')->nullable()->after('is_online');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'is_online')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_online');
            });
        }

        if (Schema::hasColumn('users', 'last_seen_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('last_seen_at');
            });
        }
    }
};
