<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MagnaCms\Blog\Support\ExcerptGenerator;

/**
 * A summary for every post, written or not: `generated_excerpt` holds the
 * first paragraph of the body (ExcerptGenerator), kept current by
 * PostObserver on every save. Post::summary() prefers the author's excerpt.
 *
 * Existing posts are backfilled here, in id-ordered chunks through the query
 * builder — no model events, no revisions, no webhooks for a column nobody
 * edited — and with plain column values, so it runs the same on SQLite,
 * MySQL and Postgres.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('blog_posts', 'generated_excerpt')) {
            Schema::table('blog_posts', function (Blueprint $table): void {
                $table->string('generated_excerpt', 255)->nullable()->after('excerpt');
            });
        }

        DB::table('blog_posts')
            ->select(['id', 'content'])
            ->orderBy('id')
            ->chunkById(200, function ($posts): void {
                foreach ($posts as $post) {
                    $content = is_string($post->content) ? json_decode($post->content, true) : null;

                    DB::table('blog_posts')
                        ->where('id', $post->id)
                        ->update(['generated_excerpt' => ExcerptGenerator::fromContent($content)]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('blog_posts', 'generated_excerpt')) {
            Schema::table('blog_posts', function (Blueprint $table): void {
                $table->dropColumn('generated_excerpt');
            });
        }
    }
};
