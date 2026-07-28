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
        Schema::create('avantio_payments', function (Blueprint $table) {
            $table->id();
            $table->string('avantio_id')->unique();
            $table->enum('payment_type', ['received', 'made', 'pending']);
            $table->date('date');
            $table->string('booking_reference')->nullable();
            $table->string('property_code')->nullable();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->string('description');
            $table->string('counterpart')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('USD');
            $table->string('state')->nullable();
            $table->string('portal')->nullable();
            $table->text('observations')->nullable();
            $table->jsonb('raw_data')->nullable();
            $table->timestamps();

            $table->index('payment_type');
            $table->index('property_code');
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('avantio_payments');
    }
};
