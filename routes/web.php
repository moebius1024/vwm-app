<?php

use App\Http\Controllers\CaseController;
use App\Services\GraphService;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/start', [CaseController::class, 'index'])->name('cases.start');
    Route::post('/cases', [CaseController::class, 'store'])->name('cases.store');
    Route::get('/raadplegen', [CaseController::class, 'consult'])->name('cases.consult');

    Route::get('/bewerken', [CaseController::class, 'edit'])->name('cases.edit');

    Route::get('dashboard', fn () => redirect('/start'))->name('dashboard');
});

Route::get('/graph-test', function (GraphService $graphService) {
    try {
        $results = $graphService->testConnection();

        return 'Verbinding met GraphDB ('.env('GRAPHDB_REPO').') is gelukt! Aantal triples gevonden: '.$results->numRows();
    } catch (Exception $e) {
        return 'Fout bij verbinden met GraphDB: '.$e->getMessage();
    }
});

require __DIR__.'/settings.php';
