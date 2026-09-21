<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminProductStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_create_a_product_with_stock(): void
    {
        $admin = $this->createAdmin();

        $response = $this
            ->actingAs($admin)
            ->from(route('admin.product.index'))
            ->post(route('admin.product.store'), [
                'name' => 'Stocked product',
                'description' => 'Product created with an explicit stock.',
                'price' => 150,
                'stock' => 8,
            ]);

        $response->assertRedirect(route('admin.product.index'));

        $product = Product::query()->where('name', 'Stocked product')->firstOrFail();
        $this->assertSame(8, (int) $product->getStock());
    }

    public function test_an_admin_can_update_a_product_stock(): void
    {
        $admin = $this->createAdmin();
        $product = $this->createProduct(3);

        $response = $this
            ->actingAs($admin)
            ->put(route('admin.product.update', ['id' => $product->getId()]), [
                'name' => 'Updated product',
                'description' => 'Product with updated stock.',
                'price' => 175,
                'stock' => 12,
            ]);

        $response->assertRedirect(route('admin.product.index'));
        $this->assertSame(12, (int) $product->fresh()->getStock());
    }

    /**
     * @dataProvider invalidStocks
     */
    public function test_an_admin_cannot_create_a_product_with_invalid_stock(array $stockPayload): void
    {
        $admin = $this->createAdmin();

        $response = $this
            ->actingAs($admin)
            ->post(route('admin.product.store'), array_merge([
                'name' => 'Invalid stock product',
                'description' => 'Product submitted with invalid stock.',
                'price' => 150,
            ], $stockPayload));

        $response->assertSessionHasErrors('stock');
        $this->assertSame(0, Product::count());
    }

    public static function invalidStocks(): array
    {
        return [
            'negative stock' => [['stock' => -1]],
            'decimal stock' => [['stock' => 1.5]],
            'missing stock' => [[]],
        ];
    }

    private function createAdmin(): User
    {
        $admin = new User();
        $admin->setName('Stock test administrator');
        $admin->setEmail('stock-admin@example.com');
        $admin->setPassword(Hash::make('test-password'));
        $admin->setRole('admin');
        $admin->setBalance(0);
        $admin->save();

        return $admin;
    }

    private function createProduct(int $stock): Product
    {
        $product = new Product();
        $product->setName('Product to update');
        $product->setDescription('Product created for a stock update test.');
        $product->setImage('stock-update-test-product.png');
        $product->setPrice(150);
        $product->setStock($stock);
        $product->save();

        return $product;
    }
}
