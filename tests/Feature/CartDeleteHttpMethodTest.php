<?php

namespace Tests\Feature;

use Tests\TestCase;

class CartDeleteHttpMethodTest extends TestCase
{
    public function test_cart_delete_route_uses_delete_and_does_not_allow_get(): void
    {
        $route = app('router')->getRoutes()->getByName('cart.delete');

        $this->assertNotNull($route);
        $this->assertContains('DELETE', $route->methods());
        $this->assertNotContains('GET', $route->methods());
    }

    public function test_get_request_to_cart_delete_is_rejected_without_modifying_the_cart(): void
    {
        $products = [123 => 2, 456 => 1];

        $response = $this
            ->withSession(['products' => $products])
            ->get('/cart/delete');

        $response->assertStatus(405);
        $this->assertSame($products, session('products'));
    }

    public function test_delete_request_clears_the_cart_and_redirects_back(): void
    {
        $response = $this
            ->from(route('cart.index'))
            ->withSession([
                'products' => [123 => 2, 456 => 1],
            ])
            ->delete(route('cart.delete'));

        $response->assertRedirect(route('cart.index'));
        $response->assertSessionMissing('products');
    }
}
