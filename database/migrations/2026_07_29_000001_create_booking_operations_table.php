<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->string('operation_id')->unique();
            $table->string('status')->default('pre_reserva')->comment('pre_reserva, confirmada, en_curso, cerrada, cancelada');
            $table->string('responsible')->nullable();
            $table->text('commercial_notes')->nullable();
            $table->text('operational_notes')->nullable();
            $table->jsonb('checklist')->default('{}');
            $table->string('incident_type')->nullable()->comment('limpieza, mantenimiento, huesped, cobro, etc');
            $table->string('incident_level')->nullable()->comment('bajo, medio, alto');
            $table->boolean('cleaning_coordinated')->default(false);
            $table->boolean('requires_maintenance')->default(false);
            $table->boolean('pending_followup')->default(false);
            $table->jsonb('documentation')->default('{}');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('operation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_operations');
    }
};
