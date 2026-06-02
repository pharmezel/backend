<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Writes to the public disk with post-save verification on the real filesystem path.
 */
class PublicStorage
{
    public static function disk()
    {
        return Storage::disk('public');
    }

    public static function root(): string
    {
        $root = (string) config('filesystems.disks.public.root', '');

        return $root !== '' ? $root : storage_path('app/public');
    }

    public static function absolutePath(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        return rtrim(self::root(), '/\\').DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public static function put(string $path, string $contents, string $field = 'image'): string
    {
        self::ensureDirectoryForPath($path);

        $written = self::disk()->put($path, $contents);
        self::assertStored($written ? $path : null, $field);

        return $path;
    }

    public static function storeUploadedFile(UploadedFile $file, string $directory, string $field = 'image'): string
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                $field => ['The uploaded image could not be processed. Try another photo.'],
            ]);
        }

        self::ensureDirectory($directory);

        $path = $file->store($directory, 'public');
        self::assertStored($path ?: null, $field);

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path && self::disk()->exists($path)) {
            self::disk()->delete($path);
        }
    }

    public static function isReadableFile(string $relative): bool
    {
        $full = self::absolutePath($relative);
        if (! is_file($full) || ! is_readable($full)) {
            return false;
        }

        $root = realpath(self::root());
        $real = realpath($full);

        return $root !== false && $real !== false && str_starts_with($real, $root);
    }

    private static function ensureDirectoryForPath(string $path): void
    {
        $dir = dirname(str_replace('\\', '/', $path));
        if ($dir !== '.' && $dir !== '') {
            self::ensureDirectory($dir);
        }
    }

    private static function ensureDirectory(string $directory): void
    {
        if (! self::disk()->exists($directory)) {
            if (! self::disk()->makeDirectory($directory)) {
                throw ValidationException::withMessages([
                    'image' => ['Upload folder could not be created on the server.'],
                ]);
            }
        }
    }

    private static function assertStored(?string $path, string $field): void
    {
        if ($path === null || $path === '' || ! self::isReadableFile($path)) {
            throw ValidationException::withMessages([
                $field => ['Image could not be saved on the server. Storage is not writable — contact support or redeploy.'],
            ]);
        }
    }
}
