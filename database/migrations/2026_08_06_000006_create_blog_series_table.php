<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Series (multi-part posts): a named, ordered collection a post can belong to.
 * A post carries at most one series plus its position within it; the delivery
 * payload uses these to render "Part N of M" navigation with prev/next links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_series', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->foreignId('series_id')
                ->nullable()
                ->after('category_id')
                ->constrained('blog_series')
                ->nullOnDelete();

            $table->unsignedInteger('series_position')->nullable()->after('series_id');

            $table->index(['series_id', 'series_position']);
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('series_id');
            $table->dropColumn('series_position');
        });

        Schema::dropIfExists('blog_series');
    }
};
