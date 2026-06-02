<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'status' => 'ok',
        'app' => 'Pharmezel API',
    ]);
});

// Serve uploaded files when public/storage symlink is missing (e.g. local dev on Windows).
Route::get('/storage/{path}', function (string $path) {
    $safe = str_replace(['..', '\\'], ['', '/'], $path);
    $full = storage_path('app/public/'.$safe);
    if (! is_file($full)) {
        abort(404);
    }

    return response()->file($full);
})->where('path', '.*');
