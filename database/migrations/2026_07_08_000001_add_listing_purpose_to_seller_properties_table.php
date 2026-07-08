<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_properties', function (Blueprint $table) {
            if (! Schema::hasColumn('seller_properties', 'listing_purpose')) {
                $table->string('listing_purpose')->default('Rent')->after('price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seller_properties', function (Blueprint $table) {
            if (Schema::hasColumn('seller_properties', 'listing_purpose')) {
                $table->dropColumn('listing_purpose');
            }
        });
    }
};