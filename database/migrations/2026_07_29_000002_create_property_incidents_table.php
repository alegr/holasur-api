<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('type')->comment('permanent, transitory');
            $table->string('title');
            $table->text('description');
            $table->string('reported_by')->nullable();
            $table->string('status')->default('open')->comment('open, in_progress, resolved');
            $table->string('priority')->default('medium')->comment('low, medium, high');
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index('property_id');
            $table->index('status');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_incidents');
    }
};
