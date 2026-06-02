<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Schema\Blueprint;

/**
 * Application service provider for boot-time setup.
 *
 * Applies lightweight schema patches when columns or tables are missing (products.image,
 * users.profile_image, feedback table) and ensures public storage directories exist.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $appUrl = config('app.url');
        if (is_string($appUrl) && $appUrl !== '' && ! preg_match('#^https?://(localhost|127\.0\.0\.1)#i', $appUrl)) {
            URL::forceRootUrl(rtrim($appUrl, '/'));
        }
        if (! app()->environment('local')) {
            URL::forceScheme('https');
        }

        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'image')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('image')->nullable()->after('description');
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'profile_image')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('profile_image')->nullable()->after('contact_number');
            });
        }

        if (! Schema::hasTable('feedback')) {
            Schema::create('feedback', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('subject');
                $table->text('message');
                $table->string('category')->nullable();
                $table->text('admin_reply')->nullable();
                $table->string('status')->default('open');
                $table->timestamps();
                $table->index(['status', 'created_at']);
            });
        }

        Storage::disk('public')->makeDirectory('drugs');
        Storage::disk('public')->makeDirectory('profiles');
    }
}
