<?php

namespace App\Http\Controllers;

use App\Models\BookingOperation;
use App\Models\PropertyIncident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationalController extends Controller
{
    /**
     * GET /api/booking-operations/{bookingId}
     */
    public function getOperation(int $bookingId): JsonResponse
    {
        $operation = BookingOperation::where('booking_id', $bookingId)->first();

        if (!$operation) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $operation]);
    }

    /**
     * POST /api/booking-operations/{bookingId}
     */
    public function createOperation(Request $request, int $bookingId): JsonResponse
    {
        $existing = BookingOperation::where('booking_id', $bookingId)->first();
        if ($existing) {
            return response()->json(['error' => 'Operation already exists for this booking'], 409);
        }

        // Generate operation_id: HS-YYYY-NNNN
        $year = date('Y');
        $lastOp = BookingOperation::where('operation_id', 'like', "HS-{$year}-%")
            ->orderByDesc('operation_id')
            ->first();

        if ($lastOp) {
            $lastNum = (int) substr($lastOp->operation_id, -4);
            $nextNum = $lastNum + 1;
        } else {
            $nextNum = 1;
        }

        $operationId = sprintf('HS-%s-%04d', $year, $nextNum);

        $operation = BookingOperation::create([
            'booking_id' => $bookingId,
            'operation_id' => $operationId,
            'status' => $request->input('status', 'pre_reserva'),
            'responsible' => $request->input('responsible'),
            'commercial_notes' => $request->input('commercial_notes'),
            'operational_notes' => $request->input('operational_notes'),
            'checklist' => $request->input('checklist', []),
            'incident_type' => $request->input('incident_type'),
            'incident_level' => $request->input('incident_level'),
            'cleaning_coordinated' => $request->boolean('cleaning_coordinated'),
            'requires_maintenance' => $request->boolean('requires_maintenance'),
            'pending_followup' => $request->boolean('pending_followup'),
            'documentation' => $request->input('documentation', []),
        ]);

        return response()->json(['data' => $operation], 201);
    }

    /**
     * PUT /api/booking-operations/{bookingId}
     */
    public function updateOperation(Request $request, int $bookingId): JsonResponse
    {
        $operation = BookingOperation::where('booking_id', $bookingId)->first();

        if (!$operation) {
            return response()->json(['error' => 'No operation found for this booking'], 404);
        }

        $fields = [
            'status',
            'responsible',
            'commercial_notes',
            'operational_notes',
            'checklist',
            'incident_type',
            'incident_level',
            'cleaning_coordinated',
            'requires_maintenance',
            'pending_followup',
            'documentation',
        ];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $operation->$field = $request->input($field);
            }
        }

        // Auto-set closed_at when status changes to cerrada
        if ($request->input('status') === 'cerrada' && !$operation->closed_at) {
            $operation->closed_at = now();
        } elseif ($request->input('status') && $request->input('status') !== 'cerrada') {
            $operation->closed_at = null;
        }

        $operation->save();

        return response()->json(['data' => $operation]);
    }

    /**
     * GET /api/property-incidents
     */
    public function listIncidents(Request $request): JsonResponse
    {
        $query = PropertyIncident::query()->orderByDesc('created_at');

        if ($propertyId = $request->input('property_id')) {
            $query->where('property_id', $propertyId);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * POST /api/property-incidents
     */
    public function createIncident(Request $request): JsonResponse
    {
        $request->validate([
            'property_id' => 'required|exists:properties,id',
            'type' => 'required|in:permanent,transitory',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'priority' => 'in:low,medium,high',
        ]);

        $incident = PropertyIncident::create([
            'property_id' => $request->input('property_id'),
            'type' => $request->input('type'),
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'reported_by' => $request->input('reported_by'),
            'status' => 'open',
            'priority' => $request->input('priority', 'medium'),
        ]);

        return response()->json(['data' => $incident], 201);
    }

    /**
     * PUT /api/property-incidents/{id}
     */
    public function updateIncident(Request $request, int $id): JsonResponse
    {
        $incident = PropertyIncident::findOrFail($id);

        $fields = ['type', 'title', 'description', 'reported_by', 'status', 'priority', 'resolution_notes'];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $incident->$field = $request->input($field);
            }
        }

        // Auto-set resolved_at when status changes to resolved
        if ($request->input('status') === 'resolved' && !$incident->resolved_at) {
            $incident->resolved_at = now();
        } elseif ($request->input('status') && $request->input('status') !== 'resolved') {
            $incident->resolved_at = null;
        }

        $incident->save();

        return response()->json(['data' => $incident]);
    }
}
