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
        Schema::create('agreement_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('label_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // The text of the agreement with {{ fields }} for what differs per deal.
            $table->longText('body');
            $table->timestamps();
            $table->index(['label_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agreement_templates');
    }
};
