<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CheckoutStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_checkout_decrements_product_stock(): void
    {
        $checkoutToken = '550e8400-e29b-41d4-a716-446655440000';
        $user = $this->createUser(500);
        $product = $this->createProduct('Stocked product', 100, 5);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'products' => [$product->getId() => 2],
                'checkout_token' => $checkoutToken,
            ])
            ->post(route('cart.purchase'), ['checkout_token' => $checkoutToken]);

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Item::count());

        $item = Item::firstOrFail();
        $this->assertSame($product->getId(), $item->getProductId());
        $this->assertSame(2, (int) $item->getQuantity());
        $this->assertSame(3, (int) $product->fresh()->getStock());
        $this->assertSame(300, (int) $user->fresh()->getBalance());
        $response->assertSessionMissing('products');
        $response->assertSessionMissing('checkout_token');
    }

    public function test_checkout_rejects_stock_that_became_insufficient_after_cart_creation(): void
    {
        $initialBalance = 500;
        $checkoutToken = '550e8400-e29b-41d4-a716-446655440000';
        $user = $this->createUser($initialBalance);
        $product = $this->createProduct('Low stock product', 100, 1);
        $productsInSession = [$product->getId() => 2];

        $this
            ->actingAs($user)
            ->withSession([
                'products' => $productsInSession,
                'checkout_token' => $checkoutToken,
            ])
            ->post(route('cart.purchase'), ['checkout_token' => $checkoutToken]);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Item::count());
        $this->assertSame(1, (int) $product->fresh()->getStock());
        $this->assertSame($initialBalance, (int) $user->fresh()->getBalance());
        $this->assertSame($productsInSession, session('products'));
        $this->assertSame($checkoutToken, session('checkout_token'));
    }

    public function test_checkout_rejects_an_entire_multi_product_cart_when_one_stock_is_insufficient(): void
    {
        $initialBalance = 1000;
        $checkoutToken = '550e8400-e29b-41d4-a716-446655440000';
        $user = $this->createUser($initialBalance);
        $availableProduct = $this->createProduct('Available product', 100, 5);
        $insufficientProduct = $this->createProduct('Insufficient product', 200, 1);
        $productsInSession = [
            $availableProduct->getId() => 2,
            $insufficientProduct->getId() => 2,
        ];

        $this
            ->actingAs($user)
            ->withSession([
                'products' => $productsInSession,
                'checkout_token' => $checkoutToken,
            ])
            ->post(route('cart.purchase'), ['checkout_token' => $checkoutToken]);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Item::count());
        $this->assertSame(5, (int) $availableProduct->fresh()->getStock());
        $this->assertSame(1, (int) $insufficientProduct->fresh()->getStock());
        $this->assertSame($initialBalance, (int) $user->fresh()->getBalance());
        $this->assertSame($productsInSession, session('products'));
        $this->assertSame($checkoutToken, session('checkout_token'));
    }

    private function createUser(int $balance): User
    {
        $user = new User();
        $user->setName('Checkout stock test customer');
        $user->setEmail('checkout-stock-customer@example.com');
        $user->setPassword(Hash::make('test-password'));
        $user->setRole('client');
        $user->setBalance($balance);
        $user->save();

        return $user;
    }

    private function createProduct(string $name, int $price, int $stock): Product
    {
        $product = new Product();
        $product->setName($name);
        $product->setDescription('Product created for checkout stock testing.');
        $product->setImage('checkout-stock-test-product.png');
        $product->setPrice($price);
        $product->setStock($stock);
        $product->save();

        return $product;
    }
}
