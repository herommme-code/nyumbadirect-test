<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_properties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('listing_id');
            $table->string('title');
            $table->text('description');
            $table->unsignedInteger('price');
            $table->string('type');
            $table->unsignedInteger('bedrooms');
            $table->unsignedInteger('bathrooms');
            $table->string('region');
            $table->string('district');
            $table->string('ward');
            $table->string('landmark');
            $table->decimal('latitude', 11, 8)->default(0);
            $table->decimal('longitude', 11, 8)->default(0);
            $table->json('amenities')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->string('image_url', 4000)->nullable();
            $table->json('local_image_paths')->nullable();
            $table->string('local_video_path', 4000)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'listing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_properties');
    }
};
