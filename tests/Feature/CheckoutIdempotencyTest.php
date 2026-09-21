<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CheckoutIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const FIRST_CHECKOUT_TOKEN = '550e8400-e29b-41d4-a716-446655440000';
    private const SECOND_CHECKOUT_TOKEN = '123e4567-e89b-42d3-a456-426614174000';

    public function test_retrying_the_same_checkout_intention_does_not_process_the_order_twice(): void
    {
        $initialBalance = 500;
        $initialStock = 5;
        $price = 100;
        $quantity = 1;
        $user = $this->createUser($initialBalance);
        $product = $this->createProduct($price, $initialStock);

        $productsInSession = [$product->getId() => $quantity];

        $this->postCheckout($user, $productsInSession, self::FIRST_CHECKOUT_TOKEN);

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Item::count());

        $this->postCheckout($user, $productsInSession, self::FIRST_CHECKOUT_TOKEN);

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Item::count());

        $order = Order::firstOrFail();
        $item = Item::firstOrFail();

        $this->assertSame($order->getId(), $item->getOrderId());
        $this->assertSame($product->getId(), $item->getProductId());
        $this->assertSame($quantity, (int) $item->getQuantity());
        $this->assertSame(self::FIRST_CHECKOUT_TOKEN, $order->getCheckoutToken());
        $this->assertSame($initialBalance - ($price * $quantity), (int) $user->fresh()->getBalance());
        $this->assertSame($initialStock - $quantity, (int) $product->fresh()->getStock());
    }

    public function test_two_distinct_checkout_intentions_can_create_two_orders(): void
    {
        $user = $this->createUser(500);
        $product = $this->createProduct(100, 5);
        $productsInSession = [$product->getId() => 1];

        $this->postCheckout($user, $productsInSession, self::FIRST_CHECKOUT_TOKEN);
        $this->postCheckout($user, $productsInSession, self::SECOND_CHECKOUT_TOKEN);

        $this->assertSame(2, Order::count());
        $this->assertSame(2, Item::count());
        $this->assertSame(
            [self::FIRST_CHECKOUT_TOKEN, self::SECOND_CHECKOUT_TOKEN],
            Order::query()->orderBy('id')->pluck('checkout_token')->all()
        );
        $this->assertSame(300, (int) $user->fresh()->getBalance());
        $this->assertSame(3, (int) $product->fresh()->getStock());
    }

    public function test_checkout_rejects_a_token_that_does_not_match_the_session(): void
    {
        $initialBalance = 500;
        $initialStock = 5;
        $user = $this->createUser($initialBalance);
        $product = $this->createProduct(100, $initialStock);
        $productsInSession = [$product->getId() => 1];

        $response = $this
            ->actingAs($user)
            ->withSession([
                'products' => $productsInSession,
                'checkout_token' => self::FIRST_CHECKOUT_TOKEN,
            ])
            ->post(route('cart.purchase'), [
                'checkout_token' => self::SECOND_CHECKOUT_TOKEN,
            ]);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Item::count());
        $this->assertSame($initialBalance, (int) $user->fresh()->getBalance());
        $this->assertSame($initialStock, (int) $product->fresh()->getStock());
        $response->assertSessionHas('products', $productsInSession);
        $response->assertSessionHas('checkout_token', self::FIRST_CHECKOUT_TOKEN);
    }

    private function postCheckout(User $user, array $products, string $checkoutToken)
    {
        return $this
            ->actingAs($user)
            ->withSession([
                'products' => $products,
                'checkout_token' => $checkoutToken,
            ])
            ->post(route('cart.purchase'), ['checkout_token' => $checkoutToken]);
    }

    private function createUser(int $balance): User
    {
        $user = new User();
        $user->setName('Checkout idempotency test customer');
        $user->setEmail('checkout-idempotency-customer@example.com');
        $user->setPassword(Hash::make('test-password'));
        $user->setRole('client');
        $user->setBalance($balance);
        $user->save();

        return $user;
    }

    private function createProduct(int $price, int $stock): Product
    {
        $product = new Product();
        $product->setName('Checkout idempotency test product');
        $product->setDescription('Product used to test checkout idempotency.');
        $product->setImage('checkout-idempotency-test-product.png');
        $product->setPrice($price);
        $product->setStock($stock);
        $product->save();

        return $product;
    }
}
