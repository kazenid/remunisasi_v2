<?php

namespace App\Services;

use App\Models\IncentiveResult;
use App\Models\Period;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Laporan insentif per periode: ringkasan untuk halaman laporan dan berkas Excel.
 * Angka mengikuti hasil hitung yang sama dengan halaman "Hitung Insentif".
 */
class IncentiveReportService
{
    public const BULAN = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public function __construct(private readonly IncentiveCalculatorService $calc) {}

    /**
     * @return array{tahun:int,bulan:int,label:string,status:string,difinalkan_pada:?string,cut_off:string,target_realisasi:string,baris:array,total:array}
     */
    public function susun(int $tahun, int $bulan): array
    {
        $baris = $this->calc->hitung($tahun, $bulan)->all();
        $periode = Period::info($tahun, $bulan);

        $final = IncentiveResult::query()->where('tahun', $tahun)->where('bulan', $bulan)->max('difinalkan_pada');

        $jumlah = count($baris);

        return [
            'tahun' => $tahun,
            'bulan' => $bulan,
            'label' => self::BULAN[$bulan].' '.$tahun,
            'status' => $final ? 'Final' : 'Simulasi',
            'difinalkan_pada' => $final ? (string) $final : null,
            'cut_off' => $periode['cut_off'],
            'target_realisasi' => $periode['target_realisasi'],
            'baris' => $baris,
            'total' => [
                'jumlah_pegawai' => $jumlah,
                'utama_menit' => array_sum(array_column($baris, 'utama_menit')),
                'tambahan_menit' => array_sum(array_column($baris, 'tambahan_menit')),
                'nominal_plafon' => array_sum(array_column($baris, 'nominal_plafon')),
                'nominal_insentif' => array_sum(array_column($baris, 'nominal_insentif')),
                'rata_persen_total' => $jumlah ? round(array_sum(array_column($baris, 'persen_total')) / $jumlah, 2) : 0,
            ],
        ];
    }

    /** Tulis laporan ke berkas XLSX sementara dan kembalikan path-nya. */
    public function buatExcel(int $tahun, int $bulan): string
    {
        $data = $this->susun($tahun, $bulan);
        $path = tempnam(sys_get_temp_dir(), 'insentif_').'.xlsx';

        $tebal = new Style(fontBold: true);
        $judul = new Style(fontBold: true, fontSize: 14);

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Laporan Insentif');

        $writer->addRow(Row::fromValuesWithStyle(['Laporan Insentif Pegawai'], $judul));
        $writer->addRow(Row::fromValues(['Periode', $data['label']]));
        $writer->addRow(Row::fromValues(['Status', $data['status'].($data['difinalkan_pada'] ? ' ('.$data['difinalkan_pada'].')' : '')]));
        $writer->addRow(Row::fromValues(['Target realisasi', $data['target_realisasi']]));
        $writer->addRow(Row::fromValues(['Dicetak', now(Period::TZ)->format('d/m/Y H:i')]));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValuesWithStyle([
            'No', 'Nama', 'Kehadiran (%)',
            'Tugas Utama (menit)', 'Target Utama (menit)', 'Utama (%)',
            'Tugas Tambahan (menit)', 'Target Tambahan (menit)', 'Tambahan (%)',
            'Total (%)', 'Plafon (Rp)', 'Insentif (Rp)', 'Catatan',
        ], $tebal));

        foreach ($data['baris'] as $i => $r) {
            $catatan = array_filter([
                $r['kehadiran_tersedia'] ? null : 'Kehadiran belum tersedia',
                $r['target_utama_belum_diset'] ? 'Target utama belum diset' : null,
                $r['target_tambahan_belum_diset'] ? 'Target tambahan belum diset' : null,
            ]);

            $writer->addRow(Row::fromValues([
                $i + 1, $r['nama'], (float) $r['persen_kehadiran'],
                (int) $r['utama_menit'], $r['target_utama_menit'], (float) $r['persen_utama'],
                (int) $r['tambahan_menit'], $r['target_tambahan_menit'], (float) $r['persen_tambahan'],
                (float) $r['persen_total'], (int) $r['nominal_plafon'], (int) $r['nominal_insentif'],
                implode('; ', $catatan),
            ]));
        }

        $t = $data['total'];
        $writer->addRow(Row::fromValuesWithStyle([
            '', 'TOTAL ('.$t['jumlah_pegawai'].' pegawai)', '',
            $t['utama_menit'], '', '',
            $t['tambahan_menit'], '', '',
            $t['rata_persen_total'], $t['nominal_plafon'], $t['nominal_insentif'], 'Total % = rata-rata',
        ], $tebal));

        $writer->close();

        return $path;
    }
}
