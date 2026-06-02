<?php

use App\Http\Controllers\PublicFileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'status' => 'ok',
        'app' => 'Pharmezel API',
    ]);
});

// Serve uploaded files. Uses direct filesystem paths (not route:cache friendly wildcard closures).
Route::get('/storage/{path}', [PublicFileController::class, 'show'])
    ->where('path', '.+');
