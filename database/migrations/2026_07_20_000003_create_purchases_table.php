<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('purchase_number')->unique();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('receipt_type')->nullable();
            $table->string('receipt_number')->nullable();
            $table->date('receipt_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('paid_date')->nullable();
            $table->string('accounting_month')->nullable();
            $table->string('economic_responsible'); // holasur, owner
            $table->foreignId('owner_id')->nullable()->constrained('owners')->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('imputation_type'); // operation, property, owner, structure
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->char('currency', 3)->default('ARS');
            $table->decimal('usd_rate', 12, 4)->nullable();
            $table->decimal('usd_total', 12, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->default('pending'); // pending, approved, paid, cancelled
            $table->text('notes')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
