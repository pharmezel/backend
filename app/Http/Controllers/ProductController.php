<?php

namespace App\Http\Controllers;

use App\Models\AdminInventory;
use App\Models\Product;
use App\Models\ReferralLink;
use App\Models\User;
use App\Support\ProductApiTransform;
use App\Support\PublicStorage;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Product catalog with role-scoped visibility.
 *
 * Superadmin: full master catalog CRUD and per-product commission overrides.
 * Admin: `own` flag returns personal admin_inventory rows; may add products to own inventory.
 * Buyer: sees active stock from their direct referrer — superadmin master catalog or admin inventory.
 * Product images are stored on the public disk under `drugs/`.
 */
class ProductController extends Controller
{
    private function storeUploadedFile($file): string
    {
        return PublicStorage::storeUploadedFile($file, 'drugs', 'image');
    }

    private function storeBase64Image(string $data): string
    {
        $ext = 'jpg';
        if (preg_match('/^data:image\/(\w+);base64,/', $data, $matches)) {
            $data = substr($data, strpos($data, ',') + 1);
            $ext = strtolower($matches[1]);
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }
        }

        $binary = base64_decode($data, true);
        if ($binary === false || strlen($binary) < 10) {
            throw ValidationException::withMessages([
                'image_base64' => ['Invalid image data.'],
            ]);
        }

        if (strlen($binary) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'image_base64' => ['Image must be smaller than 5MB.'],
            ]);
        }

        $path = 'drugs/'.uniqid('drug_', true).'.'.$ext;

        return PublicStorage::put($path, $binary, 'image_base64');
    }

    private function resolveImageFromRequest(Request $request, ?string $existingPath = null): ?string
    {
        if ($request->boolean('remove_image')) {
            $this->deleteProductImage($existingPath);

            return null;
        }

        if ($request->hasFile('image')) {
            $this->deleteProductImage($existingPath);

            return $this->storeUploadedFile($request->file('image'));
        }

        $base64 = $request->input('image_base64');
        if (is_string($base64) && $base64 !== '') {
            $this->deleteProductImage($existingPath);

            return $this->storeBase64Image($base64);
        }

        return $existingPath;
    }

    private function deleteProductImage(?string $path): void
    {
        PublicStorage::delete($path);
    }

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
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:5120'],
            'image_base64' => 'nullable|string',
        ]);

        $imagePath = $this->resolveImageFromRequest($request);

        $product = Product::create([
            'product_name' => $validated['product_name'],
            'description' => $validated['description'] ?? null,
            'image' => $imagePath,
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
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:5120'],
            'image_base64' => 'nullable|string',
            'remove_image' => 'nullable|boolean',
        ]);

        $validated['image'] = $this->resolveImageFromRequest($request, $product->image);
        unset($validated['remove_image'], $validated['image_base64']);

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

        $this->deleteProductImage($product->image);
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
            'commission_rate' => ['present', 'nullable', 'numeric', 'min:0', 'max:100'],
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
            'product' => ProductApiTransform::toArray($product),
        ]);
    }
}
