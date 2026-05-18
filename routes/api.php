<?php

use App\Http\Controllers\BestandController;
use App\Http\Controllers\MutatieController;
use App\Http\Controllers\SjabloonController;
use App\Http\Controllers\VehicleMakeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['web', 'auth'])->name('api.user.show');

Route::middleware(['web', 'auth'])->group(function () {
    // De 'motor' route
    Route::get('/sjabloon/uri', [SjabloonController::class, 'getSjabloonByUri'])->name('api.sjabloon.uri.show');
    Route::get('/sjabloon/{id}', [SjabloonController::class, 'getSjabloonVoorTransactie'])->name('api.sjabloon.transactie.show');
    Route::get('/sjablonen', [SjabloonController::class, 'listSjablonen'])->name('api.sjablonen.index');
    Route::get('/roltypes', [SjabloonController::class, 'listRolTypes'])->name('api.roltypes.index');
    Route::get('/labels', [SjabloonController::class, 'listAllLabels'])->name('api.labels.index');
    Route::post('/labels', [SjabloonController::class, 'listLabels'])->name('api.labels.resolve');
    Route::get('/identifiers', [SjabloonController::class, 'listIdentifiers'])->name('api.identifiers.index');
    Route::get('/shacl/validate', [SjabloonController::class, 'validateShacl'])->name('api.shacl.validate');
    Route::get('/voertuig/kenteken', [VehicleMakeController::class, 'lookupKenteken'])->name('api.voertuig.kenteken.lookup');
    Route::post('/bestand/upload', [BestandController::class, 'upload'])->name('api.bestand.upload');
    Route::get('/bestand/view', [BestandController::class, 'view'])->name('api.bestand.view');
    Route::post('/mutatie', [MutatieController::class, 'storeMutatie'])->name('api.mutatie.store');
    Route::post('/goic/volg', [MutatieController::class, 'volgGoic'])->name('api.goic.volg');
    Route::post('/goic/volg-incident', [MutatieController::class, 'volgGoic'])->name('api.goic.volg_incident');
    Route::post('/goic/displays', [MutatieController::class, 'resolveGoicDisplays'])->name('api.goic.displays.resolve');
});
