<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('avantio_id')->unique()->nullable();
            $table->string('invoice_number')->nullable();
            $table->date('date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('type')->nullable(); // proforma, invoice, credit_note
            $table->string('status')->nullable(); // draft, sent, paid, cancelled
            $table->string('booking_reference')->nullable();
            $table->string('property_code')->nullable();
            $table->string('customer_name')->nullable();
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->text('description')->nullable();
            $table->json('raw_data')->nullable();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
