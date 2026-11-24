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
        Schema::table('labels', function (Blueprint $table) {
            // Whether the label is bound by all of its signatories together or by any one of them.
            $table->string('signing_rule')->default('all');
        });

        // An admin can now be a payee of the label like anyone else, so the link kept apart for signing joins payee_id.
        DB::table('label_user')->whereNotNull('signs_as_payee_id')->whereNull('payee_id')->update(['payee_id' => DB::raw('signs_as_payee_id')]);

        Schema::table('label_user', function (Blueprint $table) {
            $table->dropIndex(['signs_as_payee_id']);
            $table->dropColumn('signs_as_payee_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('label_user', function (Blueprint $table) {
            $table->unsignedBigInteger('signs_as_payee_id')->nullable()->index();
        });

        Schema::table('labels', function (Blueprint $table) {
            $table->dropColumn('signing_rule');
        });
    }
};
