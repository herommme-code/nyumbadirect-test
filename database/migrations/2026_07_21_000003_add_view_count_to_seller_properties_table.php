<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_properties', function (Blueprint $table) {
            if (! Schema::hasColumn('seller_properties', 'view_count')) {
                $table->unsignedBigInteger('view_count')->default(0)->after('local_video_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seller_properties', function (Blueprint $table) {
            if (Schema::hasColumn('seller_properties', 'view_count')) {
                $table->dropColumn('view_count');
            }
        });
    }
};
