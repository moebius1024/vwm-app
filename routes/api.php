<?php

use App\Http\Controllers\BestandController;
use App\Http\Controllers\MutatieController;
use App\Http\Controllers\SjabloonController;
use App\Http\Controllers\VehicleMakeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['web', 'auth']);

Route::middleware(['web', 'auth'])->group(function () {
    // De 'motor' route
    Route::get('/sjabloon/uri', [SjabloonController::class, 'getSjabloonByUri']);
    Route::get('/sjabloon/{id}', [SjabloonController::class, 'getSjabloonVoorTransactie']);
    Route::get('/sjablonen', [SjabloonController::class, 'listSjablonen']);
    Route::get('/roltypes', [SjabloonController::class, 'listRolTypes']);
    Route::get('/labels', [SjabloonController::class, 'listAllLabels']);
    Route::post('/labels', [SjabloonController::class, 'listLabels']);
    Route::get('/identifiers', [SjabloonController::class, 'listIdentifiers']);
    Route::get('/shacl/validate', [SjabloonController::class, 'validateShacl']);
    Route::get('/voertuig/kenteken', [VehicleMakeController::class, 'lookupKenteken']);
    Route::post('/bestand/upload', [BestandController::class, 'upload']);
    Route::post('/mutatie', [MutatieController::class, 'storeMutatie']);
    Route::post('/goic/volg', [MutatieController::class, 'volgGoic']);
    Route::post('/goic/volg-incident', [MutatieController::class, 'volgGoic']);
    Route::post('/goic/displays', [MutatieController::class, 'resolveGoicDisplays']);
});
