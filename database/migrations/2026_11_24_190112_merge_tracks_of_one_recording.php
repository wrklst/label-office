<?php

use App\Services\Catalog\RecordingMerge;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A recording becomes one track that is on several releases, instead of
     * one track per release with the same ISRC. Other spellings of its title
     * are kept on it, so a statement that writes it differently still matches.
     */
    public function up(): void
    {
        // Each step can be run again: MySQL keeps what was built when a later step fails.
        if (! Schema::hasTable('release_track')) {
            Schema::create('release_track', function (Blueprint $table) {
                $table->foreignId('release_id')->constrained()->cascadeOnDelete();
                $table->foreignId('track_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('track_number')->nullable();
                $table->unsignedSmallInteger('disc_number')->nullable();
                $table->primary(['release_id', 'track_id']);
                $table->index('track_id');
            });
        }

        if (! Schema::hasColumn('tracks', 'aliases')) {
            Schema::table('tracks', function (Blueprint $table) {
                $table->json('aliases')->nullable()->after('name');
            });
        }

        DB::table('release_track')->insertOrIgnoreUsing(
            ['release_id', 'track_id', 'track_number', 'disc_number'],
            DB::table('tracks')->select(['release_id', 'id', 'track_number', 'disc_number']),
        );

        foreach (RecordingMerge::run() as $line) {
            Log::info("Merged tracks: {$line}");
        }
    }

    /**
     * Merged tracks stay merged; only the list of releases is dropped.
     */
    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn('aliases');
        });

        Schema::dropIfExists('release_track');
    }
};
