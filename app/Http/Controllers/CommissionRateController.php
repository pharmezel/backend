<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Support\ProductCommission;
use Illuminate\Http\Request;

class CommissionRateController extends Controller
{
    public function show(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'global_rate' => ProductCommission::globalRate(),
        ]);
    }

    public function update(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'rate' => 'required|numeric|min:0|max:100',
        ]);

        AppSetting::setValue(
            ProductCommission::GLOBAL_COMMISSION_KEY,
            (string) round((float) $validated['rate'], 2)
        );

        return response()->json([
            'message' => 'Global commission rate updated',
            'global_rate' => ProductCommission::globalRate(),
        ]);
    }
}
