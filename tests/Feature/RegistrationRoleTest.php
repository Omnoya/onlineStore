<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_cannot_assign_the_administrator_role(): void
    {
        $email = 'new-customer@example.com';

        $this->post(route('register'), [
            'name' => 'New Customer',
            'email' => $email,
            'password' => 'strong-test-password',
            'password_confirmation' => 'strong-test-password',
            'role' => 'admin',
        ]);

        $this->assertSame(1, User::count());

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('client', $user->getRole());
        $this->assertNotSame('admin', $user->getRole());
        $this->get(route('admin.home.index'))->assertRedirect(route('home.index'));
    }
}
