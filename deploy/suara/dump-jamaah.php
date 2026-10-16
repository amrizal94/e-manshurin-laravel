<?php
use App\Models\Jamaah;
use App\Models\Setting;

function sapaanKiosk(string $jk, ?int $usia, ?string $kat): string {
    if ($usia !== null) {
        $kel = $usia <= 15 ? 'anak' : ($usia <= 40 ? 'muda' : 'tua');
    } else {
        $kel = in_array($kat, ['paud_tk', 'caberawit', 'praremaja'], true) ? 'anak' : 'muda';
    }
    if ($kel === 'anak') return 'Dek';
    if ($kel === 'tua') return $jk === 'L' ? 'Pak' : 'Bu';

    return $jk === 'L' ? 'Mas' : 'Mbak';
}

$semua = Setting::semua();
$out = [
    'templat' => [
        'suara_absen' => $semua[Setting::SUARA_ABSEN] ?? null,
        'suara_idle' => $semua[Setting::SUARA_IDLE] ?? null,
    ],
    'jamaah' => [],
];

foreach (Jamaah::where('aktif', true)->get() as $j) {
    $usia = $j->tanggal_lahir ? \Carbon\Carbon::parse($j->tanggal_lahir)->age : null;
    $out['jamaah'][] = [
        'id' => $j->id,
        'nama' => $j->nama_panggilan ?: $j->nama_lengkap,
        'jk' => $j->jenis_kelamin,
        'sapaan' => sapaanKiosk($j->jenis_kelamin, $usia, $j->kategori_usia),
    ];
}

file_put_contents('/tmp/suara-jamaah.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "OK " . count($out['jamaah']) . PHP_EOL;
