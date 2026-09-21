<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class CheckoutTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_rolls_back_all_writes_when_item_creation_fails(): void
    {
        $initialBalance = 200;
        $initialStock = 5;

        $user = new User();
        $user->setName('Transaction test customer');
        $user->setEmail('transaction-customer@example.com');
        $user->setPassword(Hash::make('test-password'));
        $user->setRole('client');
        $user->setBalance($initialBalance);
        $user->save();

        $product = new Product();
        $product->setName('Transaction test product');
        $product->setDescription('Product used to test checkout atomicity.');
        $product->setImage('transaction-test-product.png');
        $product->setPrice(150);
        $product->setStock($initialStock);
        $product->save();

        $originalDispatcher = Item::getEventDispatcher();
        Item::setEventDispatcher(clone $originalDispatcher);
        Item::creating(static function (Item $item): void {
            throw new RuntimeException('Forced item creation failure.');
        });

        $this->withoutExceptionHandling();

        try {
            $this
                ->actingAs($user)
                ->withSession([
                    'products' => [$product->getId() => 1],
                ])
                ->post(route('cart.purchase'));

            $this->fail('The forced item creation failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced item creation failure.', $exception->getMessage());
        } finally {
            Item::setEventDispatcher($originalDispatcher);
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Item::count());
        $this->assertSame($initialBalance, (int) $user->fresh()->getBalance());
        $this->assertSame($initialStock, (int) $product->fresh()->getStock());
        $this->assertSame(1, session("products.{$product->getId()}"));
    }
}
