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
        // One thing the payee owes under an agreement, and the file that was handed in for it.
        Schema::create('agreement_deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->cascadeOnDelete();
            $table->string('title', 1000);
            $table->string('status')->default('open');
            $table->foreignId('release_file_id')->nullable()->constrained()->nullOnDelete();
            $table->text('returned_note')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::table('agreements', function (Blueprint $table) {
            // A copy signed outside the application, on paper or with another service, as "disk:path".
            $table->string('signed_copy_path')->nullable();
            $table->string('signed_copy_name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agreements', function (Blueprint $table) {
            $table->dropColumn(['signed_copy_path', 'signed_copy_name']);
        });

        Schema::dropIfExists('agreement_deliverables');
    }
};
