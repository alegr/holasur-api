<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_catalogs', function (Blueprint $table) {
            $table->boolean('is_from_avantio')->default(false)->after('is_active');
        });

        Schema::table('booking_services', function (Blueprint $table) {
            $table->boolean('is_from_avantio')->default(false)->after('cost_override');
        });
    }

    public function down(): void
    {
        Schema::table('service_catalogs', function (Blueprint $table) {
            $table->dropColumn('is_from_avantio');
        });
        Schema::table('booking_services', function (Blueprint $table) {
            $table->dropColumn('is_from_avantio');
        });
    }
};
