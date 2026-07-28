<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standard_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->onDelete('cascade');
            $table->foreignId('cost_category_id')->constrained()->onDelete('cascade');
            $table->decimal('standard_amount', 12, 2);
            $table->timestamps();

            $table->unique(['property_id', 'cost_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standard_costs');
    }
};
