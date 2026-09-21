<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CheckoutHttpMethodTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_route_uses_post_and_does_not_allow_get(): void
    {
        $route = app('router')->getRoutes()->getByName('cart.purchase');

        $this->assertNotNull($route);
        $this->assertContains('POST', $route->methods());
        $this->assertNotContains('GET', $route->methods());
    }

    public function test_get_request_to_purchase_is_rejected_without_creating_order_data(): void
    {
        $user = new User();
        $user->setName('HTTP method test customer');
        $user->setEmail('http-method-customer@example.com');
        $user->setPassword(Hash::make('test-password'));
        $user->setRole('client');
        $user->setBalance(200);
        $user->save();

        $product = new Product();
        $product->setName('HTTP method test product');
        $product->setDescription('Product used to test the checkout HTTP method.');
        $product->setImage('http-method-test-product.png');
        $product->setPrice(150);
        $product->save();

        $response = $this
            ->actingAs($user)
            ->withSession([
                'products' => [$product->getId() => 1],
            ])
            ->get('/cart/purchase');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Item::count());
        $response->assertStatus(405);
    }
}
