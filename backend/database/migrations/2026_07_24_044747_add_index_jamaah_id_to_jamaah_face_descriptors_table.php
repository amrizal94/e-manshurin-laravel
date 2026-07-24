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
        Schema::table('jamaah_face_descriptors', function (Blueprint $table) {
            $table->index('jamaah_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jamaah_face_descriptors', function (Blueprint $table) {
            $table->dropIndex(['jamaah_id']);
        });
    }
};
