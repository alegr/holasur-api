<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ExchangeRateController extends Controller
{
    /**
     * GET /api/exchange-rate
     * Returns current USD/ARS exchange rates from dolarapi.com
     * Cached for 1 hour.
     */
    public function current(): JsonResponse
    {
        $rates = Cache::remember('exchange_rates', 3600, function () {
            try {
                $response = Http::timeout(5)->get('https://dolarapi.com/v1/dolares');
                if ($response->successful()) {
                    $data = $response->json();
                    $rates = [];
                    foreach ($data as $rate) {
                        $rates[$rate['casa']] = [
                            'name' => $rate['nombre'],
                            'buy' => $rate['compra'],
                            'sell' => $rate['venta'],
                            'updated' => $rate['fechaActualizacion'] ?? now()->toISOString(),
                        ];
                    }
                    return $rates;
                }
            } catch (\Exception $e) {
                // Fallback to a default rate if API fails
            }
            return ['blue' => ['name' => 'Blue', 'buy' => 1300, 'sell' => 1350, 'updated' => now()->toISOString()]];
        });

        // Return the "blue" rate as default for business analysis
        $blue = $rates['blue'] ?? $rates[array_key_first($rates)];

        return response()->json([
            'default_rate' => $blue['sell'],
            'rates' => $rates,
            'cached_until' => now()->addHour()->toISOString(),
        ]);
    }
}
