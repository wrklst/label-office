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
        // Who the label is as a party to an agreement.
        Schema::table('labels', function (Blueprint $table) {
            $table->string('legal_name')->nullable();
            $table->string('legal_form')->nullable();
            $table->text('legal_address')->nullable();
            $table->string('legal_register')->nullable();
        });

        // Who has to sign for the label, and who may also sign the other side of the same agreement.
        Schema::table('label_user', function (Blueprint $table) {
            $table->boolean('signs_for_label')->default(false);
            $table->boolean('self_dealing_exempt')->default(false);
        });

        // Who the payee is as a party to an agreement.
        Schema::table('payees', function (Blueprint $table) {
            $table->string('legal_name')->nullable();
            $table->text('address')->nullable();
            $table->char('country', 2)->nullable();
            $table->string('vat_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('labels', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'legal_form', 'legal_address', 'legal_register']);
        });

        Schema::table('label_user', function (Blueprint $table) {
            $table->dropColumn(['signs_for_label', 'self_dealing_exempt']);
        });

        Schema::table('payees', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'address', 'country', 'vat_id']);
        });
    }
};
