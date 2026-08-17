<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engagement columns: a sticky/featured flag (indexed for the ?featured filter)
 * and an aggregate view counter. Views are buffered in the cache and flushed here
 * periodically (blog:flush-views) rather than written on every read, so a viral
 * post never hammers this row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->boolean('is_featured')->default(false)->after('visibility');
            $table->unsignedBigInteger('views')->default(0)->after('is_featured');

            $table->index('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->dropIndex(['is_featured']);
            $table->dropColumn(['is_featured', 'views']);
        });
    }
};
