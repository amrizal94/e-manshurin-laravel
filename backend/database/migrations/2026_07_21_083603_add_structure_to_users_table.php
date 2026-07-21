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
            // Akun di-scope ke satu tingkat struktur: isi tepat satu kolom sesuai tingkatnya.
            // Super Admin: ketiganya null (akses semua).
            $table->foreignId('daerah_id')->nullable()->constrained('daerahs')->nullOnDelete();
            $table->foreignId('desa_id')->nullable()->constrained('desas')->nullOnDelete();
            $table->foreignId('kelompok_id')->nullable()->constrained('kelompoks')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('daerah_id');
            $table->dropConstrainedForeignId('desa_id');
            $table->dropConstrainedForeignId('kelompok_id');
        });
    }
};
