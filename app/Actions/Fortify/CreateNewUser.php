<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'medewerker_nummer' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input) {
            $medewerker = DB::table('medewerkers')
                ->where('medewerker_nummer', $input['medewerker_nummer'])
                ->first(['id', 'user_id']);

            if (! $medewerker) {
                throw ValidationException::withMessages([
                    'medewerker_nummer' => 'Onbekend medewerkernummer.',
                ]);
            }

            if (! empty($medewerker->user_id)) {
                throw ValidationException::withMessages([
                    'medewerker_nummer' => 'Dit medewerkernummer is al gekoppeld aan een account.',
                ]);
            }

            $persoonNaam = DB::table('personen')
                ->where('medewerker_id', $medewerker->id)
                ->value('naam');

            if (! $persoonNaam) {
                throw ValidationException::withMessages([
                    'medewerker_nummer' => 'Geen persoon gekoppeld aan dit medewerkernummer.',
                ]);
            }

            $user = User::create([
                'name' => $persoonNaam,
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $updated = DB::table('medewerkers')
                ->where('id', $medewerker->id)
                ->whereNull('user_id')
                ->update([
                    'user_id' => $user->id,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                throw ValidationException::withMessages([
                    'medewerker_nummer' => 'Dit medewerkernummer is net gekoppeld door een andere registratie.',
                ]);
            }

            return $user;
        });
    }
}
