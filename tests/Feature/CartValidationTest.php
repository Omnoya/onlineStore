<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @dataProvider invalidQuantities
     */
    public function test_an_invalid_quantity_cannot_be_added_to_the_cart(array $payload): void
    {
        $product = $this->createProduct();

        $response = $this->post(
            route('cart.add', ['id' => $product->getId()]),
            $payload
        );

        $response->assertSessionHasErrors('quantity');

        $productsInSession = session('products', []);

        $this->assertArrayNotHasKey(
            $product->getId(),
            $productsInSession,
            'A product submitted with an invalid quantity must not be stored in the cart.'
        );
    }

    public static function invalidQuantities(): array
    {
        return [
            'negative quantity' => [['quantity' => -2]],
            'zero quantity' => [['quantity' => 0]],
            'decimal quantity' => [['quantity' => 1.5]],
            'non-numeric quantity' => [['quantity' => 'abc']],
            'missing quantity' => [[]],
        ];
    }

    public function test_a_positive_integer_quantity_is_added_to_the_cart(): void
    {
        $product = $this->createProduct();

        $response = $this->post(
            route('cart.add', ['id' => $product->getId()]),
            ['quantity' => 2]
        );

        $response->assertSessionDoesntHaveErrors(['quantity']);
        $this->assertSame(2, session('products', [])[$product->getId()] ?? null);
    }

    private function createProduct(): Product
    {
        $product = new Product();
        $product->setName('Test product');
        $product->setDescription('Product created for cart validation testing.');
        $product->setImage('test-product.png');
        $product->setPrice(100);
        $product->save();

        return $product;
    }
}
