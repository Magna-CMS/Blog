<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anonymous per-post reactions (like / love / …). One row per
 * (post, type, fingerprint) so a visitor can react once per type; the count is
 * the row count. The fingerprint is a non-reversible hash of IP + user agent,
 * never the raw IP, so no personal data is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_reactions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('post_id')
                ->constrained('blog_posts')
                ->cascadeOnDelete();

            $table->string('type', 32);
            $table->string('fingerprint', 64);

            $table->timestamps();

            $table->unique(['post_id', 'type', 'fingerprint']);
            $table->index(['post_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_reactions');
    }
};
