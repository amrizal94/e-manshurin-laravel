<?php

namespace App\Models;

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
])]
class Kegiatan extends Model
{
    use LogsActivity;

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
        'umum' => ['praremaja', 'remaja', 'usman', 'menikah'],
        'caberawit' => ['paud_tk', 'caberawit'],
        'praremaja' => ['praremaja'],
        'remaja' => ['remaja'],
        'usman' => ['usman'],
    ];

    protected function casts(): array
    {
        return ['tanggal' => 'date'];
    }

    public function daerah(): BelongsTo
    {
        return $this->belongsTo(Daerah::class);
    }

    public function desa(): BelongsTo
    {
        return $this->belongsTo(Desa::class);
    }

    public function kelompok(): BelongsTo
    {
        return $this->belongsTo(Kelompok::class);
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
            ->orderByRaw('jam_mulai is null, jam_mulai')
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
            ->whereNotNull('jam_mulai')
            ->orderBy('jam_mulai')
            ->get()
            ->first(fn (self $k) => self::menitJam($k->jam_mulai) > self::menitHari($waktu));
    }

    /** Jam kegiatan kosong dianggap berlaku sepanjang hari. */
    public function jendelaAbsenTerbuka(Carbon $waktu): bool
    {
        if (! $this->jam_mulai || ! $this->jam_selesai) {
            return true;
        }

        $menit = self::menitHari($waktu);

        return $menit >= self::menitJam($this->jam_mulai) - self::TOLERANSI_MENIT
            && $menit <= self::menitJam($this->jam_selesai) + self::TOLERANSI_MENIT;
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
            ->whereIn('kategori_usia', self::KATEGORI_MAP[$this->jenis_pengajian]);

        if ($this->kelompok_id) {
            return $query->where('kelompok_id', $this->kelompok_id);
        }
        if ($this->desa_id) {
            return $query->whereHas('kelompok', fn ($q) => $q->where('desa_id', $this->desa_id));
        }

        return $query->whereHas('kelompok.desa', fn ($q) => $q->where('daerah_id', $this->daerah_id));
    }

    /** Kegiatan yang menyentuh wilayah user: target di bawah scope user, atau target level atas yang mencakup user. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->kelompok_id) {
            $kelompok = $user->kelompok()->with('desa')->first();

            return $query->where(fn ($q) => $q
                ->where('kelompok_id', $kelompok->id)
                ->orWhere('desa_id', $kelompok->desa_id)
                ->orWhere('daerah_id', $kelompok->desa->daerah_id));
        }

        if ($user->desa_id) {
            $desa = $user->desa;

            return $query->where(fn ($q) => $q
                ->whereIn('kelompok_id', Kelompok::where('desa_id', $desa->id)->select('id'))
                ->orWhere('desa_id', $desa->id)
                ->orWhere('daerah_id', $desa->daerah_id));
        }

        if ($user->daerah_id) {
            $desaIds = Desa::where('daerah_id', $user->daerah_id)->select('id');

            return $query->where(fn ($q) => $q
                ->whereIn('kelompok_id', Kelompok::whereIn('desa_id', $desaIds)->select('id'))
                ->orWhereIn('desa_id', $desaIds)
                ->orWhere('daerah_id', $user->daerah_id));
        }

        return $query;
    }
}
