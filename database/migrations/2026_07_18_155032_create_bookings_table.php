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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('avantio_id')->unique();
            $table->string('avantio_reference')->nullable();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedSmallInteger('nights');
            $table->unsignedSmallInteger('adults')->default(1);
            $table->unsignedSmallInteger('children')->default(0);
            $table->string('status')->default('confirmed')->comment('pre_booking, confirmed, owner_booking, not_available');
            $table->string('channel')->nullable()->comment('booking_com, airbnb, direct, owner, other');
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->char('currency', 3)->default('EUR');
            $table->boolean('is_revenue')->default(true)->comment('false for owner blocks / not-available');
            $table->jsonb('raw_data')->nullable();
            $table->timestamps();

            $table->index('property_id');
            $table->index('customer_id');
            $table->index('check_in');
            $table->index('check_out');
            $table->index('status');
            $table->index('channel');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
