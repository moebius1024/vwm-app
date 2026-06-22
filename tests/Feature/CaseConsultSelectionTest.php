<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the dedicated case selection page when consulting without a case', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('cases.consult'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('cases/Start')
            ->where('mode', 'consult')
            ->has('cases')
            ->has('teamNaam'),
        );
});

it('keeps the start page in start mode', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('cases.start'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('cases/Start')
            ->where('mode', 'start')
            ->has('caseSoorten'),
        );
});
