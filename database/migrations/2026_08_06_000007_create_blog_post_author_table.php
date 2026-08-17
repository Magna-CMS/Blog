<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Co-authors: additional contributors on a post. The post's own `author_id`
 * stays the primary author (and the impersonation guard is unchanged); this
 * pivot only adds extra names, so existing single-author behaviour is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_post_author', function (Blueprint $table): void {
            $table->foreignId('post_id')
                ->constrained('blog_posts')
                ->cascadeOnDelete();

            $table->foreignUlid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->primary(['post_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_author');
    }
};
