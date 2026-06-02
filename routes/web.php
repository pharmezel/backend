<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return response()->json([
        'status' => 'ok',
        'app' => 'Pharmezel API',
    ]);
});

// Serve uploaded files (works even when public/storage symlink is missing).
Route::get('/storage/{path}', function (string $path) {
    $safe = ltrim(str_replace(['..', '\\'], ['', '/'], $path), '/');
    if ($safe === '' || ! Storage::disk('public')->exists($safe)) {
        abort(404);
    }

    return Storage::disk('public')->response($safe);
})->where('path', '.*');
