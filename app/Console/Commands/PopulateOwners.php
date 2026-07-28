<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Owner;
use App\Models\Property;

class PopulateOwners extends Command
{
    protected $signature = 'holasur:populate-owners';
    protected $description = 'Extrae propietarios únicos de las propiedades y crea registros de Owner';

    public function handle(): int
    {
        $this->info('Extrayendo propietarios de propiedades...');

        $properties = Property::whereNotNull('raw_data')->get();

        // Collect unique owner names
        $ownerNames = [];
        $propertyOwnerMap = []; // property_id => owner_name

        foreach ($properties as $property) {
            $rawData = $property->raw_data;
            if (!is_array($rawData)) {
                continue;
            }

            $ownerName = $rawData['_csv']['Owner']
                ?? $rawData['_csv']['owner']
                ?? $rawData['Owner']
                ?? $rawData['owner']
                ?? null;

            if ($ownerName && is_string($ownerName)) {
                $trimmed = trim($ownerName);
                if ($trimmed !== '') {
                    $ownerNames[$trimmed] = true;
                    $propertyOwnerMap[$property->id] = $trimmed;
                }
            }
        }

        if (empty($ownerNames)) {
            $this->warn('No se encontraron nombres de propietarios en los datos.');
            return self::SUCCESS;
        }

        // Create Owner records for each unique name
        $createdCount = 0;
        $ownerIdMap = []; // owner_name => owner_id

        foreach (array_keys($ownerNames) as $name) {
            $owner = Owner::firstOrCreate(
                ['name' => $name],
                ['name' => $name, 'avantio_id' => 'owner-' . md5($name)],
            );

            if ($owner->wasRecentlyCreated) {
                $createdCount++;
            }

            $ownerIdMap[$name] = $owner->id;
        }

        // Link properties to owners
        $linkedCount = 0;

        foreach ($propertyOwnerMap as $propertyId => $ownerName) {
            if (isset($ownerIdMap[$ownerName])) {
                $property = Property::find($propertyId);
                if ($property && $property->owner_id !== $ownerIdMap[$ownerName]) {
                    $property->owner_id = $ownerIdMap[$ownerName];
                    $property->save();
                    $linkedCount++;
                }
            }
        }

        $this->info("Created {$createdCount} owners, linked {$linkedCount} properties");

        return self::SUCCESS;
    }
}
