<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A release has no contract of its own any more; contracts belong to
     * tracks. The contract a release had becomes the contract of each of its
     * tracks that has none. A track on two releases gets the contract of the
     * release with the lower id.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('releases', 'contract_id')) {
            return;
        }

        foreach (DB::table('releases')->whereNotNull('contract_id')->orderBy('id')->get(['id', 'name', 'contract_id']) as $release) {
            $trackIds = DB::table('release_track')->where('release_id', $release->id)->pluck('track_id');

            if ($trackIds->isEmpty()) {
                Log::warning("Release {$release->id} \"{$release->name}\" has a contract but no tracks; its contract {$release->contract_id} cannot be kept.");

                continue;
            }

            DB::table('tracks')->whereIn('id', $trackIds)->whereNull('contract_id')->update(['contract_id' => $release->contract_id]);
        }

        if (collect(Schema::getForeignKeys('releases'))->contains(fn (array $key): bool => $key['columns'] === ['contract_id'])) {
            Schema::table('releases', function (Blueprint $table) {
                $table->dropForeign(['contract_id']);
            });
        }

        Schema::table('releases', function (Blueprint $table) {
            $table->dropColumn('contract_id');
        });
    }

    /**
     * The column comes back empty; the contracts stay on the tracks.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('releases', 'contract_id')) {
            Schema::table('releases', function (Blueprint $table) {
                $table->foreignId('contract_id')->nullable()->after('artist_id')->constrained()->nullOnDelete();
            });
        }
    }
};
