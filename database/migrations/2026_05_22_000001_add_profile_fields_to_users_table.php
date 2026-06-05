<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'name' => fn (Blueprint $table) => $table->string('name')->default('Nyumbadirect Guest')->after('id'),
            'phone' => fn (Blueprint $table) => $table->string('phone')->nullable()->after('email'),
            'whatsapp_number' => fn (Blueprint $table) => $table->string('whatsapp_number')->nullable()->after('phone'),
            'location' => fn (Blueprint $table) => $table->string('location')->nullable()->after('whatsapp_number'),
            'bio' => fn (Blueprint $table) => $table->text('bio')->nullable()->after('location'),
        ] as $column => $addColumn) {
            if (! Schema::hasColumn('users', $column)) {
                Schema::table('users', $addColumn);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'whatsapp_number',
                'location',
                'bio',
            ]);
        });
    }
};
