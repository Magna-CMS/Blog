<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_posts', function (Blueprint $table): void {
            $table->id();

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->json('content')->nullable(); // Editor.js block document.

            // Featured image stored as a Magna media-library path (e.g. media/…),
            // matching the core media picker / IngestsMedia convention used by the
            // Docs plugin. Resolve to a URL via the core media URL resolver.
            $table->string('featured_image')->nullable();

            // Primary category (nullable — matches the /blog/{category}/{slug} permalink).
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('blog_categories')
                ->nullOnDelete();

            // Author references the core user table (users, ULID PK). Nullable so a
            // deleted user does not cascade-delete their posts.
            $table->foreignUlid('author_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('status')->default('draft');
            $table->string('visibility')->default('public');
            $table->string('password')->nullable(); // Hashed; only set when visibility = password.
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes(); // Trash status.

            $table->index(['status', 'published_at']);
            $table->index('category_id');
            $table->index('author_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
