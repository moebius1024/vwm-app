<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class VehicleMakeController extends Controller
{
    public function index(): JsonResponse
    {
        $makes = Cache::remember('nhtsa.makes', 60 * 60 * 24, function () {
            $response = Http::timeout(10)->get('https://vpic.nhtsa.dot.gov/api/vehicles/getallmakes', [
                'format' => 'json',
            ]);

            if ($response->failed()) {
                return null;
            }

            $data = $response->json();

            return collect($data['Results'] ?? [])
                ->map(function ($row) {
                    $id = $row['Make_ID'] ?? null;
                    $name = $row['Make_Name'] ?? null;

                    if (!$id || !$name) {
                        return null;
                    }

                    $uri = "https://vpic.nhtsa.dot.gov/api/vehicles/getmanufacturerdetails/{$id}?format=json";

                    return [
                        'id' => $id,
                        'name' => $name,
                        'uri' => $uri,
                    ];
                })
                ->filter()
                ->values()
                ->all();
        });

        if ($makes === null) {
            return response()->json(['error' => 'Kon merken niet ophalen.'], 502);
        }

        return response()->json(['makes' => $makes]);
    }
}
