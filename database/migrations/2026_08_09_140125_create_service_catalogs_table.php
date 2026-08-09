<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_catalogs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('category')->default('service'); // service, extra
            $table->decimal('default_unit_cost', 12, 2)->nullable();
            $table->string('cost_unit')->default('per_booking'); // per_booking, per_night, per_guest, fixed
            $table->decimal('default_unit_price', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Add cost columns to booking_services
        Schema::table('booking_services', function (Blueprint $table) {
            $table->foreignId('service_catalog_id')->nullable()->after('charge_moment')->constrained('service_catalogs')->nullOnDelete();
            $table->decimal('unit_cost', 12, 2)->nullable()->after('service_catalog_id');
            $table->decimal('cost_units', 8, 2)->nullable()->after('unit_cost');
            $table->decimal('total_cost', 12, 2)->nullable()->after('cost_units');
            $table->boolean('cost_override')->default(false)->after('total_cost');
        });
    }

    public function down(): void
    {
        Schema::table('booking_services', function (Blueprint $table) {
            $table->dropForeign(['service_catalog_id']);
            $table->dropColumn(['service_catalog_id', 'unit_cost', 'cost_units', 'total_cost', 'cost_override']);
        });
        Schema::dropIfExists('service_catalogs');
    }
};
