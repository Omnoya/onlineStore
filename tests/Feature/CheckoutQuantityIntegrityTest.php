<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CheckoutQuantityIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @dataProvider invalidQuantities
     */
    public function test_checkout_rejects_an_invalid_quantity_stored_in_the_session(mixed $quantity): void
    {
        $initialBalance = 200;

        $user = new User();
        $user->setName('Quantity integrity test customer');
        $user->setEmail('quantity-integrity-customer@example.com');
        $user->setPassword(Hash::make('test-password'));
        $user->setRole('client');
        $user->setBalance($initialBalance);
        $user->save();

        $product = new Product();
        $product->setName('Quantity integrity test product');
        $product->setDescription('Product used to test checkout quantity validation.');
        $product->setImage('quantity-integrity-test-product.png');
        $product->setPrice(150);
        $product->save();

        $this
            ->actingAs($user)
            ->withSession([
                'products' => [$product->getId() => $quantity],
            ])
            ->get(route('cart.purchase'));

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Item::count());
        $this->assertSame($initialBalance, (int) $user->fresh()->getBalance());

        $productsInSession = session('products');
        $this->assertIsArray($productsInSession);
        $this->assertArrayHasKey($product->getId(), $productsInSession);
        $this->assertSame($quantity, $productsInSession[$product->getId()]);
    }

    public static function invalidQuantities(): array
    {
        return [
            'negative quantity' => [-2],
            'zero quantity' => [0],
            'decimal quantity' => [1.5],
            'non-numeric quantity' => ['abc'],
            'null quantity' => [null],
        ];
    }
}
