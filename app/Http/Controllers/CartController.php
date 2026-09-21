<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Order;
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CartController extends Controller
{
    public function index(Request $request)
    {
        $total = 0;
        $productsInCart = [];

        $productsInSession = $request->session()->get("products");
        if ($productsInSession) {
            if (!$request->session()->has('checkout_token')) {
                $request->session()->put('checkout_token', (string) Str::uuid());
            }

            $productsInCart = Product::findMany(array_keys($productsInSession));
            $total = Product::sumPricesByQuantities($productsInCart, $productsInSession);
        } else {
            $request->session()->forget('checkout_token');
        }

        $viewData = [];
        $viewData["title"] = "Cart - Online Store";
        $viewData["subtitle"] =  "Shopping Cart";
        $viewData["total"] = $total;
        $viewData["products"] = $productsInCart;
        return view('cart.index')->with("viewData", $viewData);
    }

    public function add(Request $request, $id)
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $product = Product::findOrFail($id);
        $stock = (int) $product->getStock();

        if ($stock === 0) {
            return back()->withErrors([
                'quantity' => 'This product is out of stock.',
            ])->withInput();
        }

        if ($validated['quantity'] > $stock) {
            return back()->withErrors([
                'quantity' => 'The requested quantity exceeds the available stock.',
            ])->withInput();
        }

        $products = $request->session()->get("products", []);
        $cartChanged = !array_key_exists($id, $products)
            || (int) $products[$id] !== (int) $validated['quantity'];

        $products[$id] = $validated['quantity'];
        $request->session()->put('products', $products);

        if ($cartChanged) {
            $request->session()->forget('checkout_token');
        }

        return redirect()->route('cart.index');
    }

    public function delete(Request $request)
    {
        $request->session()->forget(['products', 'checkout_token']);
        return back();
    }

    public function purchase(Request $request)
    {
        $tokenValidator = Validator::make(
            $request->only('checkout_token'),
            ['checkout_token' => ['required', 'uuid']]
        );

        if ($tokenValidator->fails()) {
            return redirect()
                ->route('cart.index')
                ->with('error', 'The checkout token is invalid.');
        }

        $checkoutToken = $tokenValidator->validated()['checkout_token'];
        $userId = Auth::id();

        $existingOrder = Order::query()
            ->where('user_id', $userId)
            ->where('checkout_token', $checkoutToken)
            ->first();

        if ($existingOrder) {
            if ($request->session()->get('checkout_token') === $checkoutToken) {
                $request->session()->forget(['products', 'checkout_token']);
            }

            return $this->purchaseConfirmation($existingOrder);
        }

        $sessionCheckoutToken = $request->session()->get('checkout_token');
        if (!is_string($sessionCheckoutToken) || !hash_equals($sessionCheckoutToken, $checkoutToken)) {
            return redirect()
                ->route('cart.index')
                ->with('error', 'The checkout token does not match the current cart.');
        }

        $productsInSession = $request->session()->get("products");

        $validator = Validator::make(
            ['products' => $productsInSession],
            [
                'products' => ['required', 'array', 'min:1'],
                'products.*' => ['required', 'integer', 'min:1'],
            ]
        );

        if ($validator->fails()) {
            return redirect()
                ->route('cart.index')
                ->with('error', 'The cart contains invalid quantities.');
        }

        if ($productsInSession) {
            $productIds = array_keys($productsInSession);

            $checkout = DB::transaction(function () use (
                $productIds,
                $productsInSession,
                $userId,
                $checkoutToken
            ) {
                $user = User::query()
                    ->whereKey($userId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $existingOrder = Order::query()
                    ->where('user_id', $user->getId())
                    ->where('checkout_token', $checkoutToken)
                    ->lockForUpdate()
                    ->first();

                if ($existingOrder) {
                    return [
                        'status' => 'replay',
                        'order' => $existingOrder,
                    ];
                }

                $productsInCart = Product::query()
                    ->whereIn('id', $productIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if (count(array_unique($productIds)) !== $productsInCart->count()) {
                    return ['status' => 'missing_product'];
                }

                $total = 0;
                foreach ($productsInCart as $product) {
                    $quantity = $productsInSession[$product->getId()];

                    if ($quantity > $product->getStock()) {
                        return ['status' => 'insufficient_stock'];
                    }

                    $total = $total + ($product->getPrice() * $quantity);
                }

                if ($total > $user->getBalance()) {
                    return ['status' => 'insufficient_balance'];
                }

                $order = new Order();
                $order->setUserId($user->getId());
                $order->setCheckoutToken($checkoutToken);
                $order->setTotal(0);
                $order->save();

                foreach ($productsInCart as $product) {
                    $quantity = $productsInSession[$product->getId()];
                    $product->setStock($product->getStock() - $quantity);
                    $product->save();
                }

                $newBalance = $user->getBalance() - $total;
                $user->setBalance($newBalance);
                $user->save();

                foreach ($productsInCart as $product) {
                    $quantity = $productsInSession[$product->getId()];
                    $item = new Item();
                    $item->setQuantity($quantity);
                    $item->setPrice($product->getPrice());
                    $item->setProductId($product->getId());
                    $item->setOrderId($order->getId());
                    $item->save();
                }
                $order->setTotal($total);
                $order->save();

                return [
                    'status' => 'success',
                    'order' => $order,
                ];
            });

            if ($checkout['status'] === 'missing_product') {
                return redirect()
                    ->route('cart.index')
                    ->with('error', 'One or more products are no longer available.');
            }

            if ($checkout['status'] === 'insufficient_stock') {
                return redirect()
                    ->route('cart.index')
                    ->with('error', 'One or more products do not have sufficient stock.');
            }

            if ($checkout['status'] === 'insufficient_balance') {
                return redirect()
                    ->route('cart.index')
                    ->with('error', 'Insufficient balance.');
            }

            $order = $checkout['order'];
            $request->session()->forget(['products', 'checkout_token']);

            return $this->purchaseConfirmation($order);
        } else {
            return redirect()->route('cart.index');
        }
    }

    private function purchaseConfirmation(Order $order)
    {
        $viewData = [];
        $viewData["title"] = "Purchase - Online Store";
        $viewData["subtitle"] =  "Purchase Status";
        $viewData["order"] =  $order;
        return view('cart.purchase')->with("viewData", $viewData);
    }
}
