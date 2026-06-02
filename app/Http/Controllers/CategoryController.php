<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

/**
 * Product category master data.
 *
 * All authenticated users may list categories. Create, update, and delete require superadmin.
 */
class CategoryController extends Controller
{
    public function index()
    {
        return response()->json([
            'categories' => Category::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:categories,name',
            'description' => 'nullable|string',
        ]);

        $category = Category::create($validated);

        return response()->json([
            'message' => 'Category created',
            'category' => $category,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $category = Category::find($id);
        if (! $category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:categories,name,'.$id,
            'description' => 'nullable|string',
        ]);

        $category->update($validated);

        return response()->json([
            'message' => 'Category updated',
            'category' => $category->fresh(),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $category = Category::find($id);
        if (! $category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        $category->delete();

        return response()->json([
            'message' => 'Category deleted',
        ]);
    }
}
