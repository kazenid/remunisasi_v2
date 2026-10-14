<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Slip Insentif {{ $periode }}</title>
    <style>
        @page { margin: 28px 36px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 16px; margin: 0; }
        h2 { font-size: 12px; margin: 18px 0 6px; text-transform: uppercase; letter-spacing: .04em; color: #475569; }
        .muted { color: #64748b; }
        .head { width: 100%; border-bottom: 2px solid #0f172a; padding-bottom: 8px; }
        .head td { vertical-align: middle; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-weight: bold; font-size: 10px; }
        .final { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .estimasi { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th { background: #f1f5f9; text-align: left; padding: 6px; border: 1px solid #cbd5e1; font-size: 10px; }
        table.grid td { padding: 6px; border: 1px solid #e2e8f0; vertical-align: top; }
        .r { text-align: right; }
        .total td { font-weight: bold; background: #f8fafc; }
        .box { border: 1px solid #cbd5e1; padding: 10px 12px; margin-top: 10px; }
        .big { font-size: 20px; font-weight: bold; }
        .note { margin-top: 12px; padding: 8px 10px; background: #fffbeb; border: 1px solid #fde68a; font-size: 10px; }
        .note ul { margin: 4px 0 0 14px; padding: 0; }
        .watermark { position: fixed; top: 38%; left: 8%; font-size: 86px; color: rgba(148, 163, 184, .22); transform: rotate(-28deg); font-weight: bold; }
        .foot { margin-top: 22px; font-size: 9px; color: #94a3b8; }
    </style>
</head>
<body>
@if ($d['status'] !== 'final')
    <div class="watermark">ESTIMASI</div>
@endif

<table class="head">
    <tr>
        <td style="width:60px">
            @if ($logo)<img src="{{ $logo }}" style="height:46px" alt="">@endif
        </td>
        <td>
            <h1>Slip Insentif Pegawai</h1>
            <div class="muted">RSUD Bangil · TUKIMAN (Tugas dan Kinerja Manajemen)</div>
        </td>
        <td style="text-align:right">
            @if ($d['status'] === 'final')
                <span class="badge final">FINAL</span>
            @else
                <span class="badge estimasi">ESTIMASI · BELUM FINAL</span>
            @endif
        </td>
    </tr>
</table>

<table style="width:100%; margin-top:10px">
    <tr>
        <td style="width:50%">
            <div class="muted">Nama</div>
            <div><b>{{ $nama }}</b></div>
        </td>
        <td>
            <div class="muted">Periode</div>
            <div><b>{{ $periode }}</b></div>
        </td>
        <td>
            <div class="muted">Status</div>
            <div>
                @if ($d['status'] === 'final')
                    Final · difinalkan {{ $difinalkan }}
                @else
                    Estimasi (belum difinalkan Admin)
                @endif
            </div>
        </td>
    </tr>
</table>

<div class="box">
    <span class="muted">{{ $d['status'] === 'final' ? 'Insentif yang ditetapkan' : 'Perkiraan insentif' }}</span><br>
    <span class="big">{{ $rp($d['nominal']) }}</span>
    <span class="muted"> · pencapaian {{ $pct($d['persen_total']) }} dari plafon {{ $rp($d['plafon']) }}</span>
</div>

<h2>Rincian Perolehan</h2>
<table class="grid">
    <thead>
    <tr>
        <th>Komponen</th>
        <th class="r">Bobot</th>
        <th>Capaian</th>
        <th class="r">Pencapaian</th>
        <th class="r">Maksimal</th>
        <th class="r">Diperoleh</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($d['komponen'] as $k)
        <tr>
            <td>{{ $k['label'] }}</td>
            <td class="r">{{ $pct($k['bobot']) }}</td>
            <td>
                @if ($k['kode'] === 'kehadiran')
                    Persentase kehadiran
                @elseif ($k['target_menit'])
                    {{ $durasi($k['menit']) }} dari target {{ $durasi($k['target_menit']) }}
                @else
                    {{ $durasi($k['menit']) }} · target belum diatur
                @endif
            </td>
            <td class="r">{{ $pct($k['persen']) }}</td>
            <td class="r">{{ $rp($k['maksimal']) }}</td>
            <td class="r">{{ $rp($k['diperoleh']) }}</td>
        </tr>
    @endforeach
    <tr class="total">
        <td colspan="3">Total</td>
        <td class="r">{{ $pct($d['persen_total']) }}</td>
        <td class="r">{{ $rp($d['plafon']) }}</td>
        <td class="r">{{ $rp($d['nominal']) }}</td>
    </tr>
    </tbody>
</table>

<div style="margin-top:8px" class="muted">
    Diperoleh = plafon × bobot × pencapaian. Pencapaian tugas dibatasi maksimal 100% dari target jam yang disetujui Kabid.
</div>

@if (count($d['catatan']))
    <div class="note">
        <b>Catatan</b>
        <ul>
            @foreach ($d['catatan'] as $c)
                <li>{{ $c }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="foot">
    Dicetak {{ $dicetak }} · Dokumen ini dihasilkan sistem TUKIMAN
    @if ($d['status'] !== 'final')
        dan bukan dasar pembayaran selama berstatus estimasi.
    @else
        .
    @endif
</div>
</body>
</html>
