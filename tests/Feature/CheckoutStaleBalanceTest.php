<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CheckoutStaleBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_rejects_a_purchase_based_on_a_stale_user_balance(): void
    {
        $initialBalance = 200;
        $databaseBalance = 100;

        $user = new User();
        $user->setName('Stale balance test customer');
        $user->setEmail('stale-balance-customer@example.com');
        $user->setPassword(Hash::make('test-password'));
        $user->setRole('client');
        $user->setBalance($initialBalance);
        $user->save();

        $product = new Product();
        $product->setName('Stale balance test product');
        $product->setDescription('Product used to test stale balance protection.');
        $product->setImage('stale-balance-test-product.png');
        $product->setPrice(150);
        $product->setStock(10);
        $product->save();

        $this->actingAs($user);

        User::query()
            ->whereKey($user->getId())
            ->update(['balance' => $databaseBalance]);

        $persistedUser = User::query()->findOrFail($user->getId());
        $this->assertSame($databaseBalance, (int) $persistedUser->getBalance());
        $this->assertSame($initialBalance, (int) $user->getBalance());

        $response = $this
            ->withSession([
                'products' => [$product->getId() => 1],
            ])
            ->post(route('cart.purchase'));

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Item::count());
        $this->assertSame(
            $databaseBalance,
            (int) User::query()->findOrFail($user->getId())->getBalance()
        );
        $response->assertSessionHas("products.{$product->getId()}", 1);
    }
}
