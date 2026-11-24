<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The notices this person has acknowledged and hidden, by key.
            $table->json('hidden_notices')->nullable();
        });

        Schema::table('agreements', function (Blueprint $table) {
            // A signed copy can be filed first and matched to its payee afterwards.
            $table->unsignedBigInteger('payee_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('hidden_notices');
        });
    }
};
