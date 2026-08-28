<?php

namespace App\Models;

use App\Models\Concerns\BerTargetStruktur;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'nama', 'jenis_pengajian', 'daerah_id', 'desa_id', 'kelompok_id',
    'tanggal', 'jam_mulai', 'jam_selesai', 'created_by',
    'jadwal_rutin_id', 'libur', 'keterangan_libur',
])]
class Kegiatan extends Model
{
    use BerTargetStruktur, LogsActivity;

    protected $table = 'kegiatans';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty()->logExcept(['updated_at']);
    }

    /**
     * Toleransi absen wajah, dalam menit, sebelum jam mulai dan sesudah jam selesai.
     * Jamaah biasa datang lebih awal dan ada yang absen susulan setelah acara bubar.
     */
    public const TOLERANSI_MENIT = 30;

    /** Jenis pengajian -> kategori usia jamaah yang boleh absen. */
    public const KATEGORI_MAP = [
        // Janda dan duda tetap ikut umum seperti waktu pasangannya masih ada — tanpa ini,
        // mengubah status seorang jamaah membuatnya lenyap dari daftar peserta.
        'umum' => ['praremaja', 'remaja', 'usman', 'menikah', 'janda', 'duda'],
        'caberawit' => ['paud_tk', 'caberawit'],
        'praremaja' => ['praremaja'],
        'remaja' => ['remaja'],
        'usman' => ['usman'],
        'ibu' => ['menikah', 'janda'],
        'bapak' => ['menikah', 'duda'],
    ];

    /**
     * Jenis pengajian yang dibatasi satu jenis kelamin.
     * Kategori usia saja tidak cukup: "menikah" berisi suami dan istri sekaligus.
     */
    public const GENDER_MAP = ['ibu' => 'P', 'bapak' => 'L'];

    protected function casts(): array
    {
        return ['tanggal' => 'date', 'libur' => 'boolean'];
    }

    public function jadwalRutin(): BelongsTo
    {
        return $this->belongsTo(JadwalRutin::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function absensis(): HasMany
    {
        return $this->hasMany(Absensi::class);
    }

    /** Waktu sekarang menurut zona setempat — dasar semua perbandingan jadwal. */
    public static function sekarangLokal(): Carbon
    {
        return now(config('app.zona_lokal'));
    }

    /**
     * Kegiatan dalam wilayah user yang jendela absennya sedang terbuka.
     * Dipakai kiosk standby untuk menentukan sendiri kegiatan mana yang sedang jalan.
     */
    public static function sedangBerlangsung(User $user, ?Carbon $waktu = null): Collection
    {
        $waktu ??= self::sekarangLokal();

        return self::visibleTo($user)
            ->whereDate('tanggal', $waktu->toDateString())
            // Target paling spesifik menang: kiosk di satu masjid harus mengutamakan
            // kegiatan kelompoknya sendiri, bukan kegiatan desa/daerah yang jamnya berhimpit.
            ->orderByRaw('kelompok_id is null, desa_id is null, jam_mulai is null, jam_mulai')
            ->get()
            ->filter(fn (self $k) => $k->jendelaAbsenTerbuka($waktu))
            ->values();
    }

    /** Kegiatan terdekat yang belum mulai pada hari yang sama — buat pesan layar kiosk saat idle. */
    public static function berikutnya(User $user, ?Carbon $waktu = null): ?self
    {
        $waktu ??= self::sekarangLokal();

        return self::visibleTo($user)
            ->whereDate('tanggal', $waktu->toDateString())
            ->where('libur', false)
            ->whereNotNull('jam_mulai')
            ->orderBy('jam_mulai')
            ->get()
            ->first(fn (self $k) => self::menitJam($k->jam_mulai) > self::menitHari($waktu));
    }

    /** Jam kegiatan kosong dianggap berlaku sepanjang hari. */
    public function jendelaAbsenTerbuka(Carbon $waktu): bool
    {
        // Satu-satunya tempat kiosk memutuskan kamera menyala atau tidak, jadi di sinilah
        // libur harus menutupnya — bukan di tiap pemanggil yang bisa terlewat satu.
        if ($this->libur) {
            return false;
        }

        if (! $this->jam_mulai || ! $this->jam_selesai) {
            return true;
        }

        $menit = self::menitHari($waktu);

        return $menit >= self::menitJam($this->jam_mulai) - self::TOLERANSI_MENIT
            && $menit <= self::menitJam($this->jam_selesai) + self::TOLERANSI_MENIT;
    }

    /**
     * Kegiatan lain di hari yang sama yang jendelanya bertumpuk DAN pesertanya beririsan.
     *
     * Dua kegiatan boleh berbarengan selama pesertanya tidak beririsan — caberawit dan
     * umum di jam yang sama itu wajar. Yang ditolak: jamaah yang sama bisa masuk keduanya,
     * karena kiosk lalu harus menebak dia sedang duduk di pengajian yang mana.
     *
     * Yang libur tidak menghalangi apa pun — memang tidak ada acaranya.
     */
    public function bentrok(?int $kecuali = null): ?self
    {
        return self::whereDate('tanggal', $this->tanggal)
            ->where('libur', false)
            ->when($kecuali, fn (Builder $q, int $id) => $q->whereKeyNot($id))
            ->get()
            ->first(fn (self $lain) => $this->jendelaBertumpuk($lain) && $this->pesertaBeririsan($lain));
    }

    /** Jam kegiatan sebagai "18:30-20:00" — dipakai pesan bentrok. */
    public function rentangJam(): string
    {
        return substr((string) $this->jam_mulai, 0, 5).'-'.substr((string) $this->jam_selesai, 0, 5);
    }

    /**
     * Jendela absen dua kegiatan saling menimpa. Toleransi ikut dihitung karena itulah
     * rentang yang benar-benar dipakai kiosk — 19.00-20.00 dan 20.15-21.30 tidak
     * bertumpuk di atas kertas, tapi jendela absennya iya.
     */
    public function jendelaBertumpuk(self $lain): bool
    {
        if (! $this->jam_mulai || ! $this->jam_selesai || ! $lain->jam_mulai || ! $lain->jam_selesai) {
            return true; // salah satu berlaku sepanjang hari
        }

        $t = self::TOLERANSI_MENIT;

        return self::menitJam($this->jam_mulai) - $t <= self::menitJam($lain->jam_selesai) + $t
            && self::menitJam($lain->jam_mulai) - $t <= self::menitJam($this->jam_selesai) + $t;
    }

    /**
     * Ada jamaah yang jadi peserta di kedua kegiatan. pesertaQuery() sudah menggabungkan
     * kategori usia dan wilayah, jadi irisan ini sekaligus menangkap "Umum vs Remaja"
     * maupun "kegiatan desa vs kegiatan kelompok di dalamnya".
     */
    public function pesertaBeririsan(self $lain): bool
    {
        return $this->pesertaQuery()->whereIn('id', $lain->pesertaQuery()->select('jamaahs.id'))->exists();
    }

    /** "19:30" maupun "19:30:00" -> menit sejak tengah malam. */
    private static function menitJam(string $jam): int
    {
        [$h, $m] = array_pad(explode(':', $jam), 2, '0');

        return (int) $h * 60 + (int) $m;
    }

    private static function menitHari(Carbon $waktu): int
    {
        return $waktu->hour * 60 + $waktu->minute;
    }

    /** Jamaah aktif yang berhak absen di kegiatan ini (sesuai target struktur + kategori usia). */
    public function pesertaQuery(): Builder
    {
        $query = Jamaah::where('aktif', true)
            ->whereIn('kategori_usia', self::KATEGORI_MAP[$this->jenis_pengajian])
            ->when(
                self::GENDER_MAP[$this->jenis_pengajian] ?? null,
                fn (Builder $q, string $jk) => $q->where('jenis_kelamin', $jk)
            );

        if ($this->kelompok_id) {
            return $query->where('kelompok_id', $this->kelompok_id);
        }
        if ($this->desa_id) {
            return $query->whereHas('kelompok', fn ($q) => $q->where('desa_id', $this->desa_id));
        }

        return $query->whereHas('kelompok.desa', fn ($q) => $q->where('daerah_id', $this->daerah_id));
    }
}
