<?php

namespace App\Models;

use App\Models\Concerns\BerTargetStruktur;
use App\Support\WaktuSholat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Jadwal kegiatan yang berulang tiap pekan pada hari-hari tertentu.
 *
 * Yang berulang cuma templatnya. Kegiatannya sendiri tetap dibuat sebagai baris
 * kegiatans oleh perintah kegiatan:generate — absensi menunjuk kegiatan_id, jadi
 * kegiatan yang cuma "dihitung waktu ditampilkan" tidak bisa diabsen.
 */
#[Fillable([
    'nama', 'jenis_pengajian', 'daerah_id', 'desa_id', 'kelompok_id',
    'hari', 'jam_mulai', 'jam_selesai', 'aktif', 'created_by',
    'patokan_sholat', 'offset_menit', 'durasi_menit',
])]
class JadwalRutin extends Model
{
    use BerTargetStruktur, LogsActivity;

    protected $table = 'jadwal_rutins';

    /** Sejauh mana ke depan kegiatannya dibuatkan. Sebulan cukup untuk direncanakan, */
    /** dan pendek untuk dibetulkan kalau jadwalnya ternyata salah. */
    public const HORIZON_HARI = 30;

    public const NAMA_HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty()->logExcept(['updated_at']);
    }

    protected function casts(): array
    {
        return ['hari' => 'array', 'aktif' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function kegiatans(): HasMany
    {
        return $this->hasMany(Kegiatan::class);
    }

    /**
     * Tanggal-tanggal yang seharusnya punya kegiatan dari jadwal ini, mulai hari ini.
     *
     * @return list<Carbon>
     */
    public function tanggalDalamHorizon(?Carbon $mulai = null, ?int $hari = null): array
    {
        $mulai = ($mulai ?? Kegiatan::sekarangLokal())->copy()->startOfDay();
        $hariDipilih = array_map(intval(...), $this->hari ?? []);
        $tanggal = [];

        for ($i = 0; $i < ($hari ?? self::HORIZON_HARI); $i++) {
            $calon = $mulai->copy()->addDays($i);
            if (in_array($calon->dayOfWeek, $hariDipilih, true)) {
                $tanggal[] = $calon;
            }
        }

        return $tanggal;
    }

    /** Bentuk kegiatan untuk satu tanggal — belum disimpan, jadi bisa diuji bentroknya dulu. */
    /**
     * Jam mulai pada satu tanggal.
     *
     * Yang berpatokan sholat dihitung di sini, bukan dibaca dari kolom jam_mulai: waktu
     * sholat bergeser tiap hari, jadi satu jadwal menghasilkan jam yang berbeda-beda.
     * Hasilnya tetap disimpan sebagai jam konkret di kegiatannya, sehingga kiosk,
     * pemeriksa bentrok, dan rekap tidak perlu tahu apa-apa soal ini.
     *
     * @return array{0: string, 1: string} jam mulai & selesai, format H:i
     */
    public function jamUntuk(Carbon $tanggal): array
    {
        if (! $this->patokan_sholat) {
            return [
                Carbon::parse($this->jam_mulai)->format('H:i'),
                Carbon::parse($this->jam_selesai)->format('H:i'),
            ];
        }

        $mulai = WaktuSholat::untuk($tanggal)[$this->patokan_sholat]
            ->copy()
            ->addMinutes((int) ($this->offset_menit ?? 0));

        return [
            $mulai->format('H:i'),
            $mulai->copy()->addMinutes((int) ($this->durasi_menit ?? 60))->format('H:i'),
        ];
    }

    public function kegiatanUntuk(Carbon $tanggal): Kegiatan
    {
        [$jamMulai, $jamSelesai] = $this->jamUntuk($tanggal);

        return new Kegiatan([
            'nama' => $this->nama,
            'jenis_pengajian' => $this->jenis_pengajian,
            'daerah_id' => $this->daerah_id,
            'desa_id' => $this->desa_id,
            'kelompok_id' => $this->kelompok_id,
            'tanggal' => $tanggal->toDateString(),
            'jam_mulai' => $jamMulai,
            'jam_selesai' => $jamSelesai,
            'created_by' => $this->created_by,
            'jadwal_rutin_id' => $this->id,
        ]);
    }

    /** "Senin, Rabu" — dipakai pesan bentrok dan log. */
    public function namaHari(): string
    {
        $hari = array_map(intval(...), $this->hari ?? []);
        sort($hari);

        return implode(', ', array_map(fn (int $h) => self::NAMA_HARI[$h] ?? '?', $hari));
    }
}
