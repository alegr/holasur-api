<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\ImportLog;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ImportController extends Controller
{
    /**
     * Known database columns per entity (excluding avantio_id, raw_data, timestamps).
     * Incoming fields matching these keys get mapped to columns; everything else goes to raw_data.
     */
    private const COLUMN_MAP = [
        'owners' => [
            'name', 'email', 'phone', 'country', 'intranet_access',
        ],
        'properties' => [
            'avantio_reference', 'owner_id', 'name', 'type', 'location',
            'address', 'size_m2', 'bedrooms', 'bathrooms', 'beds',
            'max_guests', 'status',
        ],
        'customers' => [
            'name', 'email', 'phone', 'country',
        ],
        'bookings' => [
            'avantio_reference', 'property_id', 'customer_id',
            'check_in', 'check_out', 'nights', 'adults', 'children',
            'status', 'channel', 'total_amount', 'currency', 'is_revenue',
        ],
        'tasks' => [
            'booking_id', 'property_id', 'type', 'responsible',
            'supplier', 'status', 'scheduled_date',
        ],
    ];

    /**
     * Aliases: scraper field name => database column name.
     * Allows the scraper to send slightly different field names.
     */
    private const FIELD_ALIASES = [
        'properties' => [
            'type_location' => null, // split handler below
            'reference' => 'avantio_reference',
            '_rawText' => null, // stored in raw_data
        ],
        'bookings' => [
            'dates' => null, // split handler below
            'amount' => 'total_amount',
            'reference' => 'avantio_reference',
            '_rawText' => null,
        ],
        'owners' => [
            '_rawText' => null,
        ],
        'customers' => [
            '_rawText' => null,
        ],
        'tasks' => [
            '_rawText' => null,
        ],
    ];

    private const MODEL_MAP = [
        'owners' => Owner::class,
        'properties' => Property::class,
        'customers' => Customer::class,
        'bookings' => Booking::class,
        'tasks' => Task::class,
    ];

    /**
     * POST /api/import/{entity}
     */
    public function import(Request $request, string $entity): JsonResponse
    {
        if (!isset(self::MODEL_MAP[$entity])) {
            return response()->json([
                'error' => "Unknown entity type: {$entity}",
                'allowed' => array_keys(self::MODEL_MAP),
            ], 422);
        }

        $data = $request->input('data', []);

        if (!is_array($data) || empty($data)) {
            return response()->json([
                'error' => 'The "data" field must be a non-empty array.',
            ], 422);
        }

        $importLog = ImportLog::create([
            'started_at' => now(),
            'status' => 'running',
            'entity_type' => $entity,
        ]);

        $imported = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];

        $modelClass = self::MODEL_MAP[$entity];
        $knownColumns = self::COLUMN_MAP[$entity];

        foreach ($data as $index => $row) {
            try {
                if (!is_array($row)) {
                    throw new \InvalidArgumentException("Row {$index} is not an array.");
                }

                $avantioId = $row['avantio_id'] ?? null;
                if (empty($avantioId)) {
                    throw new \InvalidArgumentException("Row {$index} missing avantio_id.");
                }

                $mapped = $this->mapFields($entity, $row, $knownColumns);

                $existing = $modelClass::where('avantio_id', $avantioId)->first();

                $modelClass::updateOrCreate(
                    ['avantio_id' => $avantioId],
                    $mapped
                );

                if ($existing) {
                    $updated++;
                } else {
                    $imported++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Row {$index}: {$e->getMessage()}";
                Log::warning("Import {$entity} row {$index} failed", [
                    'error' => $e->getMessage(),
                    'row' => $row ?? null,
                ]);
            }
        }

        $status = $failed === count($data) ? 'failed' : 'completed';

        $importLog->update([
            'completed_at' => now(),
            'status' => $status,
            'records_imported' => $imported,
            'records_updated' => $updated,
            'records_failed' => $failed,
            'error_log' => !empty($errors) ? implode("\n", $errors) : null,
        ]);

        return response()->json([
            'entity' => $entity,
            'status' => $status,
            'imported' => $imported,
            'updated' => $updated,
            'failed' => $failed,
            'errors' => $errors,
            'import_log_id' => $importLog->id,
        ]);
    }

    /**
     * GET /api/import/logs
     */
    public function logs(Request $request): JsonResponse
    {
        $logs = ImportLog::orderByDesc('started_at')
            ->limit($request->integer('limit', 50))
            ->get();

        return response()->json(['data' => $logs]);
    }

    /**
     * Map incoming scraper fields to database columns.
     * Known columns get set directly; everything else goes into raw_data.
     */
    private function mapFields(string $entity, array $row, array $knownColumns): array
    {
        $attributes = [];
        $rawData = [];
        $aliases = self::FIELD_ALIASES[$entity] ?? [];

        // Apply special field transformations first
        $row = $this->applyTransformations($entity, $row);

        foreach ($row as $field => $value) {
            // Skip avantio_id (used as match key, not updated)
            if ($field === 'avantio_id') {
                continue;
            }

            // Check aliases
            if (isset($aliases[$field])) {
                $mappedField = $aliases[$field];
                if ($mappedField === null) {
                    // Explicitly sent to raw_data
                    $rawData[$field] = $value;
                    continue;
                }
                $field = $mappedField;
            }

            // Check if it's a known column
            if (in_array($field, $knownColumns, true)) {
                $attributes[$field] = $value;
            } else {
                $rawData[$field] = $value;
            }
        }

        // Merge new raw_data with any existing raw_data already set
        if (!empty($rawData)) {
            $attributes['raw_data'] = $rawData;
        }

        return $attributes;
    }

    /**
     * Apply entity-specific field transformations for scraped data.
     */
    private function applyTransformations(string $entity, array $row): array
    {
        // Properties: split type_location into type + location
        if ($entity === 'properties' && isset($row['type_location'])) {
            $parts = array_map('trim', explode(' - ', $row['type_location'], 2));
            if (!isset($row['type']) && isset($parts[0])) {
                $row['type'] = $parts[0];
            }
            if (!isset($row['location']) && isset($parts[1])) {
                $row['location'] = $parts[1];
            }
            // type_location itself will be stored in raw_data via alias mapping
        }

        // Bookings: split dates into check_in + check_out
        if ($entity === 'bookings' && isset($row['dates'])) {
            $parts = array_map('trim', explode(' - ', $row['dates'], 2));
            if (!isset($row['check_in']) && isset($parts[0])) {
                $row['check_in'] = $parts[0];
            }
            if (!isset($row['check_out']) && isset($parts[1])) {
                $row['check_out'] = $parts[1];
            }
            // dates itself will be stored in raw_data via alias mapping
        }

        return $row;
    }
}
