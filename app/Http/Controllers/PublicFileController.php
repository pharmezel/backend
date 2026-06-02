<?php

namespace App\Http\Controllers;

use App\Support\PublicStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicFileController extends Controller
{
    public function show(string $path): BinaryFileResponse
    {
        $safe = ltrim(str_replace(['..', '\\'], ['', '/'], $path), '/');
        if ($safe === '' || ! PublicStorage::isReadableFile($safe)) {
            abort(404);
        }

        $full = PublicStorage::absolutePath($safe);

        return response()->file($full, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
