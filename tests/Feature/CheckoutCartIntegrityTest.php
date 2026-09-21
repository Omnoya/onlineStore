<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CheckoutCartIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_rejects_a_cart_containing_a_missing_product(): void
    {
        $initialBalance = 200;
        $checkoutToken = '550e8400-e29b-41d4-a716-446655440000';

        $user = new User();
        $user->setName('Cart integrity test customer');
        $user->setEmail('cart-integrity-customer@example.com');
        $user->setPassword(Hash::make('test-password'));
        $user->setRole('client');
        $user->setBalance($initialBalance);
        $user->save();

        $product = new Product();
        $product->setName('Available test product');
        $product->setDescription('Product used to test cart integrity.');
        $product->setImage('available-test-product.png');
        $product->setPrice(150);
        $product->setStock(10);
        $product->save();

        $missingProductId = $product->getId() + 1000;
        $this->assertNull(Product::find($missingProductId));

        $response = $this
            ->actingAs($user)
            ->withSession([
                'products' => [
                    $product->getId() => 1,
                    $missingProductId => 1,
                ],
                'checkout_token' => $checkoutToken,
            ])
            ->post(route('cart.purchase'), ['checkout_token' => $checkoutToken]);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Item::count());
        $this->assertSame($initialBalance, (int) $user->fresh()->getBalance());
        $response->assertSessionHas('products');
        $response->assertSessionHas("products.{$product->getId()}", 1);
        $response->assertSessionHas("products.{$missingProductId}", 1);
        $response->assertSessionHas('checkout_token', $checkoutToken);
    }
}
