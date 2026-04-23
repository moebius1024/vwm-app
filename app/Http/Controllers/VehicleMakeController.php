<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class VehicleMakeController extends Controller
{
    public function lookupKenteken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kenteken' => 'required|string',
        ]);

        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $validated['kenteken']) ?? '');
        if ($normalized === '') {
            return response()->json(['error' => 'Ongeldig kenteken.'], 422);
        }

        $cacheKey = "rdw.kenteken.{$normalized}";
        $payload = Cache::remember($cacheKey, 60 * 60 * 24, function () use ($normalized) {
            try {
                $response = Http::timeout(10)->get('https://opendata.rdw.nl/resource/m9d7-ebf2.json', [
                    'kenteken' => $normalized,
                ]);
            } catch (Throwable $e) {
                return [
                    'ok' => false,
                    'error' => 'RDW service niet bereikbaar.',
                ];
            }

            if ($response->failed()) {
                return [
                    'ok' => false,
                    'error' => 'RDW response niet succesvol.',
                ];
            }

            $rows = $response->json();
            $first = is_array($rows) && !empty($rows) && is_array($rows[0]) ? $rows[0] : null;

            return [
                'ok' => true,
                'record' => $first,
            ];
        });

        if (($payload['ok'] ?? false) !== true) {
            return response()->json([
                'kenteken' => $normalized,
                'error' => $payload['error'] ?? 'RDW lookup mislukt.',
            ], 502);
        }

        return response()->json([
            'kenteken' => $normalized,
            'found' => !empty($payload['record']),
            'record' => $payload['record'] ?? null,
        ]);
    }
}
