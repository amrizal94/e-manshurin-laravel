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
        Schema::create('kelompoks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('desa_id')->constrained('desas')->cascadeOnDelete();
            $table->string('nama');
            $table->timestamps();
            $table->unique(['desa_id', 'nama']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kelompoks');
    }
};
