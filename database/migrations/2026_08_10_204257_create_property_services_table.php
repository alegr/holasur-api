<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_catalog_id')->nullable()->constrained('service_catalogs')->nullOnDelete();
            $table->string('name'); // service name from Avantio
            $table->boolean('is_applicable')->default(false); // from Extras tab
            $table->boolean('is_personalized')->default(false); // from Extras tab
            $table->decimal('owner_return_percent', 5, 2)->nullable(); // from contract (e.g., 100% for Heating)
            $table->boolean('is_from_avantio')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_services');
    }
};
