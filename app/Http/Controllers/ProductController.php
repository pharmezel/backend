<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;

class ProductController extends Controller
{
    // get products
    public function index()
    {
        $products = Product::all();

        return response()->json([
            'products' => $products
        ]);
    }
    //delete product
    public function destroy($id)
    {
        try {

            $product = Product::where('product_id', $id)->first();

            if (!$product) {
                return response()->json([
                    'message' => 'Product not found'
                ], 404);
            }

            $product->delete();

            return response()->json([
                'message' => 'Deleted successfully'
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Delete failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    // edit product
    public function update(Request $request, $id)
    {
        try {

            $product = Product::where('product_id', $id)->first();

            if (!$product) {
                return response()->json([
                    'message' => 'Product not found'
                ], 404);
            }

            $product->update($request->all());

            return response()->json([
                'message' => 'Updated successfully',
                'product' => $product
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Update failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    //add product
    public function store(Request $request)
    {
        try {

            $validated = $request->validate([
                'product_name' => 'required',
                'description' => 'nullable',
                'category_name' => 'nullable',
                'unit_price' => 'required|numeric',
                'expiry_date' => 'nullable',
                'stock_quantity' => 'required|integer',
                'date_added' => 'nullable',
                'commission_rate' => 'nullable',
            ]);

            $product = Product::create($validated);

            return response()->json([
                'message' => 'Product created successfully',
                'product' => $product
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Create failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}