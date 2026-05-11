<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\ProductCommission;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index()
    {
        $products = Product::with(['brand', 'category'])
            ->orderBy('product_name')
            ->get();

        $payload = $products->map(fn (Product $product) => $this->transformProduct($product));

        return response()->json([
            'products' => $payload,
        ]);
    }

    public function show(Request $request, $id)
    {
        $product = Product::with(['brand', 'category'])
            ->where('product_id', $id)
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return response()->json([
            'product' => $this->transformProduct($product),
        ]);
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
            'product' => $this->transformProduct($product),
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
            $product->update(['stock_quantity' => $validated['stock_quantity']]);
            $product = $product->fresh(['brand', 'category']);

            return response()->json([
                'message' => 'Updated successfully',
                'product' => $this->transformProduct($product),
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
            'product' => $this->transformProduct($product),
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
            'commission_rate' => 'required|numeric|min:0|max:100',
        ]);

        $product = Product::where('product_id', $id)->first();
        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $product->commission_rate = $validated['commission_rate'];
        $product->save();
        $product->load(['brand', 'category']);

        return response()->json([
            'message' => 'Commission updated',
            'product' => $this->transformProduct($product),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformProduct(Product $product): array
    {
        $base = $product->toArray();
        $resolved = ProductCommission::resolvedRate($product);

        return array_merge($base, [
            'product_commission_rate' => $product->commission_rate,
            'commission_rate' => $resolved,
            'effective_price' => ProductCommission::effectivePrice($product),
        ]);
    }
}
