<?php

namespace App\Http\Controllers;

use App\Models\AdminInventory;
use App\Models\Product;
use App\Models\ReferralLink;
use App\Models\User;
use App\Support\ProductApiTransform;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $ownInventory = $request->boolean('own');

        if ($user->role === 'superadmin') {
            $products = Product::with(['brand', 'category'])->orderBy('product_name')->get();

            return response()->json([
                'products' => $products->map(fn (Product $p) => ProductApiTransform::toArray($p)),
            ]);
        }

        if ($user->role === 'admin' && $ownInventory) {
            $items = AdminInventory::with(['product.brand', 'product.category'])
                ->where('admin_id', $user->user_id)
                ->get();

            $products = $items->map(function (AdminInventory $inv) {
                $p = ProductApiTransform::toArray($inv->product);
                $p['stock_quantity'] = $inv->stock_quantity;
                $p['admin_inventory_id'] = $inv->id;
                $p['is_active'] = $inv->is_active;

                return $p;
            });

            return response()->json(['products' => $products]);
        }

        $referral = ReferralLink::where('referred_id', $user->user_id)->first();
        if (! $referral) {
            return response()->json(['products' => []]);
        }

        $referrer = User::find($referral->referrer_id);
        if (! $referrer) {
            return response()->json(['products' => []]);
        }

        if ($referrer->role === 'superadmin') {
            $products = Product::with(['brand', 'category'])
                ->where('stock_quantity', '>', 0)
                ->orderBy('product_name')
                ->get();

            return response()->json([
                'products' => $products->map(fn (Product $p) => ProductApiTransform::toArray($p)),
            ]);
        }

        $items = AdminInventory::with(['product.brand', 'product.category'])
            ->where('admin_id', $referrer->user_id)
            ->where('is_active', true)
            ->where('stock_quantity', '>', 0)
            ->get();

        $products = $items->map(function (AdminInventory $inv) {
            $p = ProductApiTransform::toArray($inv->product);
            $p['stock_quantity'] = $inv->stock_quantity;

            return $p;
        });

        return response()->json(['products' => $products]);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        $ownInventory = $request->boolean('own');

        if ($user->role === 'superadmin') {
            $product = Product::with(['brand', 'category'])->where('product_id', $id)->first();
            if (! $product) {
                return response()->json(['message' => 'Product not found'], 404);
            }

            return response()->json([
                'product' => ProductApiTransform::toArray($product),
            ]);
        }

        if ($user->role === 'admin' && $ownInventory) {
            $inv = AdminInventory::with(['product.brand', 'product.category'])
                ->where('admin_id', $user->user_id)
                ->where('product_id', $id)
                ->first();
            if (! $inv || ! $inv->product) {
                return response()->json(['message' => 'Product not found'], 404);
            }

            $p = ProductApiTransform::toArray($inv->product);
            $p['stock_quantity'] = $inv->stock_quantity;
            $p['admin_inventory_id'] = $inv->id;
            $p['is_active'] = $inv->is_active;

            return response()->json(['product' => $p]);
        }

        $referral = ReferralLink::where('referred_id', $user->user_id)->first();
        if (! $referral) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $referrer = User::find($referral->referrer_id);
        if (! $referrer) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        if ($referrer->role === 'superadmin') {
            $product = Product::with(['brand', 'category'])->where('product_id', $id)->first();
            if (! $product || $product->stock_quantity <= 0) {
                return response()->json(['message' => 'Product not found'], 404);
            }

            return response()->json([
                'product' => ProductApiTransform::toArray($product),
            ]);
        }

        $inv = AdminInventory::with(['product.brand', 'product.category'])
            ->where('admin_id', $referrer->user_id)
            ->where('product_id', $id)
            ->where('is_active', true)
            ->where('stock_quantity', '>', 0)
            ->first();

        if (! $inv || ! $inv->product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $p = ProductApiTransform::toArray($inv->product);
        $p['stock_quantity'] = $inv->stock_quantity;

        return response()->json(['product' => $p]);
    }

    public function store(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'product_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'brand_id' => 'nullable|exists:brands,id',
            'category_id' => 'nullable|exists:categories,id',
            'unit_price' => 'required|numeric|min:0',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'stock_quantity' => 'required|integer|min:0',
            'expiry_date' => 'nullable|date',
        ]);

        $product = Product::create([
            'product_name' => $validated['product_name'],
            'description' => $validated['description'] ?? null,
            'category_name' => null,
            'brand_id' => $validated['brand_id'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'unit_price' => $validated['unit_price'],
            'commission_rate' => $validated['commission_rate'] ?? null,
            'stock_quantity' => $validated['stock_quantity'],
            'expiry_date' => $validated['expiry_date'] ?? null,
            'date_added' => now(),
        ]);

        $product->load(['brand', 'category']);

        return response()->json([
            'message' => 'Product created successfully',
            'product' => ProductApiTransform::toArray($product),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        $product = Product::where('product_id', $id)->first();
        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        if ($user->role === 'admin') {
            $validated = $request->validate([
                'stock_quantity' => 'required|integer|min:0',
            ]);

            $inv = AdminInventory::where('admin_id', $user->user_id)
                ->where('product_id', $id)
                ->first();

            if (! $inv) {
                return response()->json(['message' => 'Product not found in your inventory'], 404);
            }

            $inv->update(['stock_quantity' => $validated['stock_quantity']]);
            $product = $product->fresh(['brand', 'category']);
            $p = ProductApiTransform::toArray($product);
            $p['stock_quantity'] = $inv->fresh()->stock_quantity;
            $p['admin_inventory_id'] = $inv->id;
            $p['is_active'] = $inv->is_active;

            return response()->json([
                'message' => 'Updated successfully',
                'product' => $p,
            ]);
        }

        if ($user->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'product_name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'brand_id' => 'nullable|exists:brands,id',
            'category_id' => 'nullable|exists:categories,id',
            'unit_price' => 'sometimes|numeric|min:0',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'stock_quantity' => 'sometimes|integer|min:0',
            'expiry_date' => 'nullable|date',
        ]);

        $product->update($validated);
        $product = $product->fresh(['brand', 'category']);

        return response()->json([
            'message' => 'Updated successfully',
            'product' => ProductApiTransform::toArray($product),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $product = Product::where('product_id', $id)->first();

        if (! $product) {
            return response()->json([
                'message' => 'Product not found',
            ], 404);
        }

        $product->delete();

        return response()->json([
            'message' => 'Deleted successfully',
        ]);
    }

    public function updateCommission(Request $request, $id)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'commission_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $product = Product::where('product_id', $id)->first();
        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $product->commission_rate = $validated['commission_rate'] ?? null;
        $product->save();
        $product->load(['brand', 'category']);

        return response()->json([
            'message' => 'Commission updated',
            'product' => ProductApiTransform::toArray($product),
        ]);
    }
}
