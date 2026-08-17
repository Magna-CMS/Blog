<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Localisation: each post is a single-locale document; translations are separate
 * posts (own slug/content) sharing a `translation_group`. Existing posts default
 * to the configured default locale and stand alone (null group), so nothing
 * changes until translations are linked.
 */
return new class extends Migration
{
    public function up(): void
    {
        $default = (string) config('blog.locales.default', 'en');

        Schema::table('blog_posts', function (Blueprint $table) use ($default): void {
            $table->string('locale', 12)->default($default)->after('slug');
            $table->string('translation_group', 40)->nullable()->after('locale');

            $table->index('locale');
            $table->index('translation_group');
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->dropColumn(['locale', 'translation_group']);
        });
    }
};
