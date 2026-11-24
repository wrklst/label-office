<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // An agreement can be about several releases, and about some of the tracks of a release only.
        Schema::create('agreement_release', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->unique(['agreement_id', 'release_id']);
        });

        Schema::create('agreement_track', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->unique(['agreement_id', 'track_id']);
        });

        foreach (DB::table('agreements')->whereNotNull('track_id')->orWhereNotNull('release_id')->get(['id', 'release_id', 'track_id']) as $agreement) {
            // A track brings its release along: the tracks on offer are always those of the chosen releases.
            $releaseId = $agreement->release_id ?? DB::table('tracks')->where('id', $agreement->track_id)->value('release_id');

            if ($releaseId !== null) {
                DB::table('agreement_release')->insert(['agreement_id' => $agreement->id, 'release_id' => $releaseId]);
            }

            if ($agreement->track_id !== null && $releaseId !== null) {
                DB::table('agreement_track')->insert(['agreement_id' => $agreement->id, 'track_id' => $agreement->track_id]);
            }
        }

        Schema::table('agreements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('release_id');
            $table->dropConstrainedForeignId('track_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agreements', function (Blueprint $table) {
            $table->foreignId('release_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::dropIfExists('agreement_track');
        Schema::dropIfExists('agreement_release');
    }
};
