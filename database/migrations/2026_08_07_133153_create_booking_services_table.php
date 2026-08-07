<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('category')->default('service'); // property, service, extra
            $table->string('concept');
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->string('price_label')->nullable(); // "US$ 112.84", "Included", "US$ 65.00/booking"
            $table->string('quantity')->nullable(); // "4 nights", "1", etc.
            $table->string('tax')->nullable(); // "0 %", "21 %"
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('charge_moment')->nullable(); // "when making a booking", etc.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_services');
    }
};
