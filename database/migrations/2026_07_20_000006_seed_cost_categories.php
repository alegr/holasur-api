<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $categories = [
            // Direct
            ['name' => 'limpieza', 'type' => 'direct'],
            ['name' => 'valet', 'type' => 'direct'],
            ['name' => 'amenities', 'type' => 'direct'],
            ['name' => 'late_checkin', 'type' => 'direct'],
            ['name' => 'late_checkout', 'type' => 'direct'],
            ['name' => 'mascota', 'type' => 'direct'],
            ['name' => 'cuna', 'type' => 'direct'],
            ['name' => 'extras', 'type' => 'direct'],
            ['name' => 'garantía', 'type' => 'direct'],
            // Indirect
            ['name' => 'reparaciones', 'type' => 'indirect'],
            ['name' => 'mantenimiento', 'type' => 'indirect'],
            ['name' => 'fotografía', 'type' => 'indirect'],
            ['name' => 'diseño', 'type' => 'indirect'],
            ['name' => 'seguros', 'type' => 'indirect'],
            // Structural
            ['name' => 'publicidad', 'type' => 'structural'],
            ['name' => 'sueldos', 'type' => 'structural'],
            ['name' => 'honorarios', 'type' => 'structural'],
            ['name' => 'licencias', 'type' => 'structural'],
            ['name' => 'servicios', 'type' => 'structural'],
            ['name' => 'comunicación', 'type' => 'structural'],
            ['name' => 'asesoramiento', 'type' => 'structural'],
            ['name' => 'gastos_administrativos', 'type' => 'structural'],
            ['name' => 'alquiler', 'type' => 'structural'],
            ['name' => 'monotributo', 'type' => 'structural'],
            ['name' => 'impuestos', 'type' => 'structural'],
        ];

        foreach ($categories as $cat) {
            DB::table('cost_categories')->insert(array_merge($cat, [
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        DB::table('cost_categories')->whereIn('name', [
            'limpieza', 'valet', 'amenities', 'late_checkin', 'late_checkout',
            'mascota', 'cuna', 'extras', 'garantía',
            'reparaciones', 'mantenimiento', 'fotografía', 'diseño', 'seguros',
            'publicidad', 'sueldos', 'honorarios', 'licencias', 'servicios',
            'comunicación', 'asesoramiento', 'gastos_administrativos', 'alquiler',
            'monotributo', 'impuestos',
        ])->delete();
    }
};
