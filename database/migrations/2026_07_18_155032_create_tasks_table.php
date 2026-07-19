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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('avantio_id')->unique();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('type')->comment('cleaning, heating, parking, bed_linen, maintenance, other');
            $table->string('responsible')->nullable();
            $table->string('supplier')->nullable();
            $table->string('status')->nullable();
            $table->date('scheduled_date')->nullable();
            $table->jsonb('raw_data')->nullable();
            $table->timestamps();

            $table->index('property_id');
            $table->index('booking_id');
            $table->index('type');
            $table->index('scheduled_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
