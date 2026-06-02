<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\Request;

/**
 * Brand master data and default commission rates.
 *
 * All authenticated users may list brands. Create, update, delete, and commission-rate
 * changes require superadmin.
 */
class BrandController extends Controller
{
    public function index()
    {
        return response()->json([
            'brands' => Brand::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:brands,name',
            'contact_number' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
        ]);

        $brand = Brand::create($validated);

        return response()->json([
            'message' => 'Brand created',
            'brand' => $brand,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $brand = Brand::find($id);
        if (! $brand) {
            return response()->json(['message' => 'Brand not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:brands,name,'.$id,
            'contact_number' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
        ]);

        $brand->update($validated);

        return response()->json([
            'message' => 'Brand updated',
            'brand' => $brand->fresh(),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $brand = Brand::find($id);
        if (! $brand) {
            return response()->json(['message' => 'Brand not found'], 404);
        }

        $brand->delete();

        return response()->json([
            'message' => 'Brand deleted',
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

        $brand = Brand::find($id);
        if (! $brand) {
            return response()->json(['message' => 'Brand not found'], 404);
        }

        $brand->commission_rate = $validated['commission_rate'];
        $brand->save();

        return response()->json([
            'message' => 'Brand commission updated',
            'brand' => $brand->fresh(),
        ]);
    }
}
