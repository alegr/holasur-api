<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_number')->unique();
            $table->string('beneficiary_type'); // owner, guest, supplier, employee, government, other
            $table->string('beneficiary_name');
            $table->string('expense_type')->nullable();
            $table->foreignId('cost_category_id')->nullable()->constrained('cost_categories')->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('owners')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('imputation_type'); // operation, property, owner, structure
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('ARS');
            $table->decimal('usd_rate', 12, 4)->nullable();
            $table->decimal('usd_amount', 12, 2)->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_frequency')->nullable();
            $table->date('scheduled_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('paid_date')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_account')->nullable();
            $table->string('payment_status')->default('pending'); // scheduled, pending, paid, cancelled
            $table->text('notes')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
