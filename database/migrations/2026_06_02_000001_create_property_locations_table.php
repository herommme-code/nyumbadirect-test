<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_locations')->create('property_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('user_email')->index();
            $table->string('listing_id');
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->string('source')->default('gps');
            $table->timestampTz('registered_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'listing_id']);
            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql_locations')->dropIfExists('property_locations');
    }
};
