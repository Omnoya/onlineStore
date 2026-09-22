<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Create the first administrator account';

    public function handle(): int
    {
        if (User::query()->where('role', 'admin')->exists()) {
            $this->error('An administrator already exists.');
            return self::FAILURE;
        }

        $name = $this->ask('Name');
        $email = $this->ask('Email');
        $password = $this->secret('Password');
        $passwordConfirmation = $this->secret('Confirm password');

        $validator = Validator::make(
            [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
            ],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]
        );

        if ($validator->fails()) {
            if ($validator->errors()->has('name')) {
                $this->error('Name must be provided and contain at most 255 characters.');
            }
            if ($validator->errors()->has('email')) {
                $this->error('Email must be a valid, unused address of at most 255 characters.');
            }
            if ($validator->errors()->has('password')) {
                $this->error('Password must contain at least 8 characters and match its confirmation.');
            }

            return self::FAILURE;
        }

        if (User::query()->where('role', 'admin')->exists()) {
            $this->error('An administrator already exists.');
            return self::FAILURE;
        }

        $validated = $validator->validated();
        $user = new User();
        $user->setName($validated['name']);
        $user->setEmail($validated['email']);
        $user->setPassword(Hash::make($validated['password']));
        $user->setRole('admin');
        $user->setBalance(0);

        try {
            if (!$user->save()) {
                $this->error('Unable to create the administrator account.');
                return self::FAILURE;
            }
        } catch (QueryException) {
            $this->error('Unable to create the administrator account. Check the database and try again.');
            return self::FAILURE;
        }

        $this->info('Administrator account created.');
        return self::SUCCESS;
    }
}
