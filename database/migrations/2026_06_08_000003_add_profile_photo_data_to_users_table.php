<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'profile_photo_filename')) {
                $table->string('profile_photo_filename')->nullable()->after('profile_photo_url');
            }

            if (! Schema::hasColumn('users', 'profile_photo_mime')) {
                $table->string('profile_photo_mime', 100)->nullable()->after('profile_photo_filename');
            }

            if (! Schema::hasColumn('users', 'profile_photo_data')) {
                $table->mediumText('profile_photo_data')->nullable()->after('profile_photo_mime');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['profile_photo_data', 'profile_photo_mime', 'profile_photo_filename'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
