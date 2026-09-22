<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_first_administrator(): void
    {
        $password = 'strong-test-password';

        $exitCode = $this->artisan('app:create-admin')
            ->expectsQuestion('Name', 'First Administrator')
            ->expectsQuestion('Email', 'first-admin@example.com')
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $password)
            ->run();

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, User::count());

        $admin = User::query()->firstOrFail();
        $this->assertSame('First Administrator', $admin->getName());
        $this->assertSame('first-admin@example.com', $admin->getEmail());
        $this->assertSame('admin', $admin->getRole());
        $this->assertSame(0, (int) $admin->getBalance());
        $this->assertNotSame($password, $admin->getPassword());
        $this->assertTrue(Hash::check($password, $admin->getPassword()));
    }

    public function test_it_does_not_promote_a_client_with_an_existing_email(): void
    {
        $client = $this->createUser('existing@example.com', 'client');
        $originalPasswordHash = $client->getPassword();

        $exitCode = $this->artisan('app:create-admin')
            ->expectsQuestion('Name', 'New Administrator')
            ->expectsQuestion('Email', 'existing@example.com')
            ->expectsQuestion('Password', 'another-strong-password')
            ->expectsQuestion('Confirm password', 'another-strong-password')
            ->run();

        $this->assertNotSame(0, $exitCode);
        $this->assertSame(1, User::count());
        $this->assertSame('client', $client->fresh()->getRole());
        $this->assertSame($originalPasswordHash, $client->fresh()->getPassword());
    }

    public function test_it_refuses_to_create_another_administrator(): void
    {
        $admin = $this->createUser('existing-admin@example.com', 'admin');

        $exitCode = $this->artisan('app:create-admin')->run();

        $this->assertNotSame(0, $exitCode);
        $this->assertSame(1, User::count());
        $this->assertSame('admin', $admin->fresh()->getRole());
    }

    /**
     * @dataProvider invalidAnswers
     */
    public function test_it_rejects_invalid_answers(
        string $email,
        string $password,
        string $confirmation
    ): void {
        $exitCode = $this->artisan('app:create-admin')
            ->expectsQuestion('Name', 'Proposed Administrator')
            ->expectsQuestion('Email', $email)
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $confirmation)
            ->run();

        $this->assertNotSame(0, $exitCode);
        $this->assertSame(0, User::count());
    }

    public static function invalidAnswers(): array
    {
        return [
            'invalid email' => ['not-an-email', 'strong-test-password', 'strong-test-password'],
            'short password' => ['new-admin@example.com', 'short', 'short'],
            'different confirmation' => ['new-admin@example.com', 'strong-test-password', 'different-password'],
        ];
    }

    private function createUser(string $email, string $role): User
    {
        $user = new User();
        $user->setName('Existing user');
        $user->setEmail($email);
        $user->setPassword(Hash::make('existing-password'));
        $user->setRole($role);
        $user->setBalance(0);
        $user->save();

        return $user;
    }
}
