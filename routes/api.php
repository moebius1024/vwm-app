<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SjabloonController;
use App\Http\Controllers\VehicleMakeController;
use App\Http\Controllers\BestandController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// De 'motor' route
Route::get('/sjabloon/uri', [SjabloonController::class, 'getSjabloonByUri']);
Route::get('/sjabloon/{id}', [SjabloonController::class, 'getSjabloonVoorTransactie']);
Route::get('/sjablonen', [SjabloonController::class, 'listSjablonen']);
Route::get('/roltypes', [SjabloonController::class, 'listRolTypes']);
Route::get('/labels', [SjabloonController::class, 'listLabels']);
Route::post('/labels', [SjabloonController::class, 'listLabels']);
Route::get('/identifiers', [SjabloonController::class, 'listIdentifiers']);
Route::get('/shacl/validate', [SjabloonController::class, 'validateShacl']);
Route::get('/voertuig/kenteken', [VehicleMakeController::class, 'lookupKenteken']);
Route::post('/bestand/upload', [BestandController::class, 'upload']);
Route::post('/mutatie', [App\Http\Controllers\SjabloonController::class, 'storeMutatie']);
