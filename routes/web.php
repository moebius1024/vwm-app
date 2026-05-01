<?php

use App\Http\Controllers\CaseController;
use App\Http\Controllers\BeheerController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/start', [CaseController::class, 'index'])->name('cases.start');
    Route::post('/cases', [CaseController::class, 'store'])->name('cases.store');
    Route::get('/raadplegen', [CaseController::class, 'consult'])->name('cases.consult');
    Route::get('/raadplegen/go', [CaseController::class, 'consultGo'])->name('cases.consult.go');

    Route::get('/bewerken', [CaseController::class, 'edit'])->name('cases.edit');
    Route::get('/beheer', [BeheerController::class, 'index'])->name('beheer.index');
    Route::post('/beheer/teams', [BeheerController::class, 'storeTeam'])->name('beheer.teams.store');
    Route::patch('/beheer/teams/{team}', [BeheerController::class, 'updateTeam'])->name('beheer.teams.update');
    Route::post('/beheer/medewerkers', [BeheerController::class, 'storeMedewerker'])->name('beheer.medewerkers.store');
    Route::patch('/beheer/medewerkers/{medewerker}', [BeheerController::class, 'updateMedewerker'])->name('beheer.medewerkers.update');
    Route::post('/beheer/personen', [BeheerController::class, 'storePersoon'])->name('beheer.personen.store');
    Route::patch('/beheer/personen/{persoon}', [BeheerController::class, 'updatePersoon'])->name('beheer.personen.update');

    Route::get('dashboard', fn () => redirect('/start'))->name('dashboard');
});

require __DIR__.'/settings.php';
