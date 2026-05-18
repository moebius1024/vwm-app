<?php

use Laravel\Fortify\Features;
use Illuminate\Support\Facades\DB;
use App\Models\User;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $medewerkerId = DB::table('medewerkers')->insertGetId([
        'user_id' => null,
        'medewerker_nummer' => 'M-1001',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('personen')->insert([
        'medewerker_id' => $medewerkerId,
        'naam' => 'Test Persoon',
        'identifier' => 'P-1001',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->post(route('register.store'), [
        'medewerker_nummer' => 'M-1001',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('cases.start', absolute: false));

    $user = User::where('email', 'test@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Test Persoon');

    $this->assertDatabaseHas('medewerkers', [
        'id' => $medewerkerId,
        'user_id' => $user->id,
    ]);
});

test('registration fails with unknown medewerker nummer', function () {
    $response = $this->from(route('register'))->post(route('register.store'), [
        'medewerker_nummer' => 'UNKNOWN-404',
        'email' => 'unknown@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('register'));
    $response->assertSessionHasErrors(['medewerker_nummer']);
    $this->assertGuest();
});

test('registration fails when medewerker nummer is already linked', function () {
    $existingUser = User::factory()->create();

    DB::table('medewerkers')->insert([
        'user_id' => $existingUser->id,
        'medewerker_nummer' => 'M-2001',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->from(route('register'))->post(route('register.store'), [
        'medewerker_nummer' => 'M-2001',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('register'));
    $response->assertSessionHasErrors(['medewerker_nummer']);
    $this->assertGuest();
});
