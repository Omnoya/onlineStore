<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CheckoutBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_cannot_purchase_a_cart_that_exceeds_their_balance(): void
    {
        $initialBalance = 100;

        $user = new User();
        $user->setName('Test customer');
        $user->setEmail('customer@example.com');
        $user->setPassword(Hash::make('test-password'));
        $user->setRole('client');
        $user->setBalance($initialBalance);
        $user->save();

        $product = new Product();
        $product->setName('Expensive test product');
        $product->setDescription('Product priced above the customer balance.');
        $product->setImage('expensive-test-product.png');
        $product->setPrice(150);
        $product->save();

        $orderCountBeforeCheckout = Order::count();
        $itemCountBeforeCheckout = Item::count();

        $response = $this
            ->actingAs($user)
            ->withSession([
                'products' => [$product->getId() => 1],
            ])
            ->post(route('cart.purchase'));

        $this->assertSame($orderCountBeforeCheckout, Order::count());
        $this->assertSame($itemCountBeforeCheckout, Item::count());
        $this->assertSame($initialBalance, (int) $user->fresh()->getBalance());
        $response->assertSessionHas("products.{$product->getId()}", 1);
    }

    public function test_a_user_can_purchase_a_cart_within_their_balance(): void
    {
        $user = new User();
        $user->setName('Test customer with sufficient balance');
        $user->setEmail('funded-customer@example.com');
        $user->setPassword(Hash::make('test-password'));
        $user->setRole('client');
        $user->setBalance(200);
        $user->save();

        $product = new Product();
        $product->setName('Affordable test product');
        $product->setDescription('Product priced within the customer balance.');
        $product->setImage('affordable-test-product.png');
        $product->setPrice(150);
        $product->save();

        $response = $this
            ->actingAs($user)
            ->withSession([
                'products' => [$product->getId() => 1],
            ])
            ->post(route('cart.purchase'));

        $this->assertSame(1, Order::where('user_id', $user->getId())->count());

        $order = Order::where('user_id', $user->getId())->firstOrFail();
        $this->assertSame(150, (int) $order->getTotal());

        $this->assertSame(1, Item::count());

        $item = Item::firstOrFail();
        $this->assertSame($order->getId(), $item->getOrderId());
        $this->assertSame($product->getId(), $item->getProductId());
        $this->assertSame(1, (int) $item->getQuantity());

        $this->assertSame(50, (int) $user->fresh()->getBalance());
        $response->assertSessionMissing('products');
    }
}
