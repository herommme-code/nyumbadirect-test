<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_properties', function (Blueprint $table) {
            if (! Schema::hasColumn('seller_properties', 'plot_size')) {
                $table->string('plot_size')->default('')->after('bathrooms');
            }

            if (! Schema::hasColumn('seller_properties', 'plot_size_unit')) {
                $table->string('plot_size_unit')->default('')->after('plot_size');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seller_properties', function (Blueprint $table) {
            if (Schema::hasColumn('seller_properties', 'plot_size_unit')) {
                $table->dropColumn('plot_size_unit');
            }

            if (Schema::hasColumn('seller_properties', 'plot_size')) {
                $table->dropColumn('plot_size');
            }
        });
    }
};