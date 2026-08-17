<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalised search column: the post's title, excerpt and flattened block text
 * concatenated, kept in sync by PostObserver::saving(). The delivery API searches
 * this with a portable LIKE; a FULLTEXT index or Scout can be layered on later
 * without changing the public API shape. `blog:reindex` rebuilds it after bulk
 * writes that bypass the model events (imports, revision restores).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->longText('search_text')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->dropColumn('search_text');
        });
    }
};
