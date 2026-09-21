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

class CartController extends Controller
{
    public function index(Request $request)
    {
        $total = 0;
        $productsInCart = [];

        $productsInSession = $request->session()->get("products");
        if ($productsInSession) {
            $productsInCart = Product::findMany(array_keys($productsInSession));
            $total = Product::sumPricesByQuantities($productsInCart, $productsInSession);
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

        $products = $request->session()->get("products");
        $products[$id] = $validated['quantity'];
        $request->session()->put('products', $products);

        return redirect()->route('cart.index');
    }

    public function delete(Request $request)
    {
        $request->session()->forget('products');
        return back();
    }

    public function purchase(Request $request)
    {
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
            $productsInCart = Product::findMany($productIds);

            if (count(array_unique($productIds)) !== $productsInCart->count()) {
                return redirect()
                    ->route('cart.index')
                    ->with('error', 'One or more products are no longer available.');
            }

            $total = Product::sumPricesByQuantities($productsInCart, $productsInSession);
            $userId = Auth::id();

            $order = DB::transaction(function () use ($productsInCart, $productsInSession, $total, $userId) {
                $user = User::query()
                    ->whereKey($userId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($total > $user->getBalance()) {
                    return null;
                }

                $order = new Order();
                $order->setUserId($user->getId());
                $order->setTotal(0);
                $order->save();

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

                $newBalance = $user->getBalance() - $total;
                $user->setBalance($newBalance);
                $user->save();

                return $order;
            });

            if ($order === null) {
                return redirect()
                    ->route('cart.index')
                    ->with('error', 'Insufficient balance.');
            }

            $request->session()->forget('products');

            $viewData = [];
            $viewData["title"] = "Purchase - Online Store";
            $viewData["subtitle"] =  "Purchase Status";
            $viewData["order"] =  $order;
            return view('cart.purchase')->with("viewData", $viewData);
        } else {
            return redirect()->route('cart.index');
        }
    }
}
