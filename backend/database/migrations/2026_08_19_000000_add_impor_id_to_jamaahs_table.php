<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda satu kali impor. Tanpa ini tidak ada cara membatalkan impor yang ternyata
     * salah file: 600 baris baru bercampur dengan data lama, dan memilahnya lewat
     * created_at berarti menebak — apalagi kalau ada yang menambah jamaah manual di
     * menit yang sama.
     */
    public function up(): void
    {
        Schema::table('jamaahs', function (Blueprint $table) {
            $table->uuid('impor_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('jamaahs', function (Blueprint $table) {
            $table->dropColumn('impor_id');
        });
    }
};
