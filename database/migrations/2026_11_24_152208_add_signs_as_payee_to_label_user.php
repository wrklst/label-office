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
        Schema::table('label_user', function (Blueprint $table) {
            // The payee an admin signs their own agreements as. A payee member has payee_id for that.
            $table->unsignedBigInteger('signs_as_payee_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('label_user', function (Blueprint $table) {
            $table->dropColumn('signs_as_payee_id');
        });
    }
};
