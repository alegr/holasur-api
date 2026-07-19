<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('avantio_id')->unique();
            $table->string('avantio_reference')->nullable();
            $table->foreignId('owner_id')->constrained('owners')->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->nullable()->comment('house, apartment, etc.');
            $table->string('location')->nullable();
            $table->text('address')->nullable();
            $table->decimal('size_m2', 8, 2)->nullable();
            $table->unsignedSmallInteger('bedrooms')->nullable();
            $table->unsignedSmallInteger('bathrooms')->nullable();
            $table->unsignedSmallInteger('beds')->nullable();
            $table->unsignedSmallInteger('max_guests')->nullable();
            $table->string('status')->default('active')->comment('active, inactive, deactivated');
            $table->jsonb('raw_data')->nullable();
            $table->timestamps();

            $table->index('owner_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
