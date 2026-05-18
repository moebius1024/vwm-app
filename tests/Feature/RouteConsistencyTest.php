<?php

use Illuminate\Support\Facades\Route;

it('has named api routes for core endpoints', function () {
    expect(Route::has('api.user.show'))->toBeTrue();
    expect(Route::has('api.sjabloon.uri.show'))->toBeTrue();
    expect(Route::has('api.sjabloon.transactie.show'))->toBeTrue();
    expect(Route::has('api.mutatie.store'))->toBeTrue();
    expect(Route::has('api.goic.displays.resolve'))->toBeTrue();
});

