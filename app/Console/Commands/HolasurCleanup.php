<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Booking;
use App\Models\Property;
use App\Models\AvantioPayment;

class HolasurCleanup extends Command
{
    protected $signature = 'holasur:cleanup';
    protected $description = 'Limpia reservas con prefijos de importación, vincula reservas y pagos huérfanos a propiedades';

    public function handle(): int
    {
        $this->info('Iniciando limpieza de datos...');

        // 1. Delete bookings with bad avantio_id prefixes
        $prefixes = ['detail-', 'import-', 'booking-', 'csv-'];
        $cleanedCount = 0;

        foreach ($prefixes as $prefix) {
            $count = Booking::where('avantio_id', 'like', $prefix . '%')->count();
            if ($count > 0) {
                Booking::where('avantio_id', 'like', $prefix . '%')->delete();
                $cleanedCount += $count;
                $this->line("  Eliminadas {$count} reservas con prefijo '{$prefix}'");
            }
        }

        // 2. Link orphan bookings to properties by matching property_name in raw_data
        $linkedBookings = 0;
        $orphanBookings = Booking::whereNull('property_id')->get();

        if ($orphanBookings->isNotEmpty()) {
            // Build a lookup map: property name (lowercase) => property id
            $properties = Property::all();
            $propertyMap = [];
            foreach ($properties as $property) {
                if ($property->name) {
                    $propertyMap[mb_strtolower(trim($property->name))] = $property->id;
                }
            }

            foreach ($orphanBookings as $booking) {
                $rawData = $booking->raw_data;
                if (!is_array($rawData)) {
                    continue;
                }

                // Try common keys where property name might be stored
                $propertyName = $rawData['property_name']
                    ?? $rawData['Property']
                    ?? $rawData['property']
                    ?? $rawData['Accommodation']
                    ?? $rawData['accommodation']
                    ?? $rawData['_csv']['Property']
                    ?? $rawData['_csv']['property_name']
                    ?? $rawData['_csv']['Accommodation']
                    ?? null;

                if ($propertyName) {
                    $key = mb_strtolower(trim($propertyName));
                    if (isset($propertyMap[$key])) {
                        $booking->property_id = $propertyMap[$key];
                        $booking->save();
                        $linkedBookings++;
                    }
                }
            }
        }

        // 3. Link orphan payments to properties by matching property_code
        $linkedPayments = 0;
        $orphanPayments = AvantioPayment::whereNull('property_id')
            ->whereNotNull('property_code')
            ->where('property_code', '!=', '')
            ->get();

        if ($orphanPayments->isNotEmpty()) {
            // Build lookup: avantio_id => property id, and name (lowercase) => property id
            $properties = Property::all();
            $avantioMap = [];
            $nameMap = [];
            foreach ($properties as $property) {
                if ($property->avantio_id) {
                    $avantioMap[$property->avantio_id] = $property->id;
                }
                if ($property->name) {
                    $nameMap[mb_strtolower(trim($property->name))] = $property->id;
                }
            }

            foreach ($orphanPayments as $payment) {
                $code = trim($payment->property_code);
                $propertyId = $avantioMap[$code]
                    ?? $nameMap[mb_strtolower($code)]
                    ?? null;

                if ($propertyId) {
                    $payment->property_id = $propertyId;
                    $payment->save();
                    $linkedPayments++;
                }
            }
        }

        $this->info("Cleaned {$cleanedCount} bookings, linked {$linkedBookings} bookings, linked {$linkedPayments} payments");

        return self::SUCCESS;
    }
}
