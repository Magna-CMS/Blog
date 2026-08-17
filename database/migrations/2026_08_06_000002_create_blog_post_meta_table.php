<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arbitrary typed key/value metadata per post (WordPress-style custom fields).
 * The `value` is stored JSON-encoded so any scalar or structured value round-trips
 * under its declared `type`. A unique (post_id, key) keeps one row per key so meta
 * behaves like a map, not a log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_post_meta', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('post_id')
                ->constrained('blog_posts')
                ->cascadeOnDelete();

            $table->string('key');
            $table->json('value')->nullable();
            $table->string('type')->default('string');

            $table->timestamps();

            $table->unique(['post_id', 'key']);
            $table->index('key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_meta');
    }
};
