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
        Schema::create('agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('label_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agreement_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('status')->default('draft');
            // Who grants the rights: the label, or an artist of the label as a party of their own.
            $table->string('licensor')->default('label');
            $table->foreignId('payee_id')->constrained()->restrictOnDelete();
            $table->foreignId('artist_payee_id')->nullable()->constrained('payees')->restrictOnDelete();
            $table->foreignId('release_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            $table->json('terms')->nullable();
            // Set when the agreement is sent, and never changed after: the text every signature refers to.
            $table->longText('body')->nullable();
            $table->char('body_hash', 64)->nullable();
            $table->json('parties')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->index(['label_id', 'status']);
        });

        Schema::create('agreement_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('capacity');
            // What the signer typed and who they were, kept apart from the account, which can change or go.
            $table->string('name');
            $table->string('email');
            $table->char('body_hash', 64);
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();
            $table->unique(['agreement_id', 'user_id', 'capacity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agreement_signatures');
        Schema::dropIfExists('agreements');
    }
};
