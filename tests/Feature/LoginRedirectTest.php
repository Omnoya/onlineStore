<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'strong-test-password';

    public function test_admin_is_redirected_to_admin_home_after_login(): void
    {
        $admin = $this->createUser('login-admin@example.com', 'admin');

        $response = $this->post(route('login'), [
            'email' => $admin->getEmail(),
            'password' => self::PASSWORD,
        ]);

        $this->assertAuthenticatedAs($admin);
        $response->assertRedirect(route('admin.home.index'));
    }

    public function test_client_is_redirected_to_home_after_login(): void
    {
        $client = $this->createUser('login-client@example.com', 'client');

        $response = $this->post(route('login'), [
            'email' => $client->getEmail(),
            'password' => self::PASSWORD,
        ]);

        $this->assertAuthenticatedAs($client);
        $response->assertRedirect(route('home.index'));
    }

    public function test_login_preserves_an_intended_url(): void
    {
        $admin = $this->createUser('intended-admin@example.com', 'admin');
        $intendedUrl = route('admin.product.index');

        $response = $this
            ->withSession(['url.intended' => $intendedUrl])
            ->post(route('login'), [
                'email' => $admin->getEmail(),
                'password' => self::PASSWORD,
            ]);

        $this->assertAuthenticatedAs($admin);
        $response->assertRedirect($intendedUrl);
    }

    private function createUser(string $email, string $role): User
    {
        $user = new User();
        $user->setName('Login redirect test user');
        $user->setEmail($email);
        $user->setPassword(Hash::make(self::PASSWORD));
        $user->setRole($role);
        $user->setBalance(0);
        $user->save();

        return $user;
    }
}
