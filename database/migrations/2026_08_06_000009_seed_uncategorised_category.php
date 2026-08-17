<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the "Uncategorised" fallback category (WordPress-style) so it exists in
 * the category list from the start. Idempotent: skipped if the slug is present.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('blog_categories')->where('slug', 'uncategorised')->exists();

        if (! $exists) {
            DB::table('blog_categories')->insert([
                'name' => 'Uncategorised',
                'slug' => 'uncategorised',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('blog_categories')->where('slug', 'uncategorised')->delete();
    }
};
