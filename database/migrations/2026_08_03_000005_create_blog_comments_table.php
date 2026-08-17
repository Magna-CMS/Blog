<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comments table. Posts accumulate threaded (one level) comments moderated
 * through the admin CommentResource and served, once approved, via the delivery
 * API. Guests supply a name/email; registered users link via author_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_comments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('post_id')
                ->constrained('blog_posts')
                ->cascadeOnDelete();

            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('blog_comments')
                ->nullOnDelete();

            // Registered author (nullable) or guest details.
            $table->foreignUlid('author_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('author_name')->nullable();
            $table->string('author_email')->nullable();

            $table->text('body');
            $table->string('status')->default('pending');

            $table->timestamps();

            $table->index(['post_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_comments');
    }
};
