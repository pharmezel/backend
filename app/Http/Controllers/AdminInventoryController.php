<?php

namespace App\Http\Controllers;

use App\Models\AdminInventory;
use Illuminate\Http\Request;

class AdminInventoryController extends Controller
{
    public function toggle(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $inv = AdminInventory::where('id', $id)->where('admin_id', $user->user_id)->first();
        if (! $inv) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $inv->update(['is_active' => ! $inv->is_active]);
        $inv->refresh();

        return response()->json([
            'message' => 'Updated',
            'is_active' => $inv->is_active,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $inv = AdminInventory::where('id', $id)->where('admin_id', $user->user_id)->first();
        if (! $inv) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $inv->delete();

        return response()->json(['message' => 'Removed']);
    }
}
