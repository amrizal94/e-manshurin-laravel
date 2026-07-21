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
        Schema::create('jamaah_face_descriptors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jamaah_id')->constrained('jamaahs')->cascadeOnDelete();
            $table->foreignId('jamaah_photo_id')->nullable()->constrained('jamaah_photos')->nullOnDelete();
            // 512 float ArcFace embedding, terenkripsi at-rest (cast encrypted:array)
            $table->text('descriptor');
            $table->float('confidence')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jamaah_face_descriptors');
    }
};
