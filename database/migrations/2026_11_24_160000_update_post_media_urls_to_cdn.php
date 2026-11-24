<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('post_media')) {
            return;
        }

        $cdnUrl = rtrim(config('filesystems.disks.r2.url') ?: env('CLOUDFLARE_R2_PUBLIC_URL') ?: 'https://cdn.bangoapp.com', '/');

        // Remplacement de https://api.bangoapp.com/storage/ et http://api.bangoapp.com/storage/ par le CDN
        DB::table('post_media')
            ->where('media_url', 'like', '%api.bangoapp.com/storage/%')
            ->update([
                'media_url' => DB::raw("REPLACE(REPLACE(media_url, 'https://api.bangoapp.com/storage/', '{$cdnUrl}/'), 'http://api.bangoapp.com/storage/', '{$cdnUrl}/')"),
            ]);

        DB::table('post_media')
            ->where('poster_url', 'like', '%api.bangoapp.com/storage/%')
            ->update([
                'poster_url' => DB::raw("REPLACE(REPLACE(poster_url, 'https://api.bangoapp.com/storage/', '{$cdnUrl}/'), 'http://api.bangoapp.com/storage/', '{$cdnUrl}/')"),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('post_media')) {
            return;
        }

        $cdnUrl = rtrim(config('filesystems.disks.r2.url') ?: env('CLOUDFLARE_R2_PUBLIC_URL') ?: 'https://cdn.bangoapp.com', '/');

        DB::table('post_media')
            ->where('media_url', 'like', "{$cdnUrl}/%")
            ->update([
                'media_url' => DB::raw("REPLACE(media_url, '{$cdnUrl}/', 'https://api.bangoapp.com/storage/')"),
            ]);

        DB::table('post_media')
            ->where('poster_url', 'like', "{$cdnUrl}/%")
            ->update([
                'poster_url' => DB::raw("REPLACE(poster_url, '{$cdnUrl}/', 'https://api.bangoapp.com/storage/')"),
            ]);
    }
};
