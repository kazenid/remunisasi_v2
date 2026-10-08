<?php

namespace App\Http\Requests;

use App\Models\TaskReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi pembuatan/pembaruan tugas (draf).
 * Catatan: foto selfie WAJIB saat pengajuan (Tugas 7), bukan saat draf —
 * sehingga draf boleh disimpan tanpa bukti lengkap.
 */
class TaskDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // otorisasi ditangani Policy di controller
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'jenis' => ['required', Rule::in([TaskReport::JENIS_UTAMA, TaskReport::JENIS_TAMBAHAN])],
            'judul' => ['required', 'string', 'max:255'],
            'uraian' => ['nullable', 'string'],
            'durasi_jam' => ['required', 'integer', 'min:0', 'max:24'],
            'durasi_menit' => ['required', 'integer', 'min:0', 'max:59'],

            // Koordinat opsional.
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'akurasi' => ['nullable', 'numeric', 'min:0'],

            // Berkas bukti (opsional pada tahap draf).
            'foto_selfie' => ['nullable', 'array'],
            'foto_selfie.*' => ['file', 'image', 'mimes:jpeg,jpg,png', 'max:5120'], // 5 MB
            'dokumen' => ['nullable', 'array'],
            'dokumen.*' => ['file', 'mimes:pdf,doc,docx,xls,xlsx', 'max:10240'], // 10 MB

            // Bukti campuran (gambar + dokumen) dalam satu unggahan.
            'bukti' => ['nullable', 'array'],
            'bukti.*' => ['file', 'mimes:jpeg,jpg,png,pdf,doc,docx,xls,xlsx', 'max:10240'], // 10 MB
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ((int) $this->input('durasi_jam', 0) * 60 + (int) $this->input('durasi_menit', 0) <= 0) {
                $v->errors()->add('durasi_menit', 'Durasi harus lebih dari 0.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'judul.required' => 'Judul tugas wajib diisi.',
            'tanggal.required' => 'Tanggal kegiatan wajib diisi.',
            'jenis.in' => 'Jenis tugas tidak valid.',
            'foto_selfie.*.image' => 'Foto selfie harus berupa gambar (JPEG/PNG).',
            'foto_selfie.*.max' => 'Ukuran foto maksimal 5 MB.',
            'dokumen.*.mimes' => 'Dokumen harus berformat pdf, doc, docx, xls, atau xlsx.',
            'dokumen.*.max' => 'Ukuran dokumen maksimal 10 MB.',
            'bukti.*.file' => 'Berkas bukti tidak valid.',
            'bukti.*.mimes' => 'Tipe file tidak didukung (hanya jpg, png, pdf, doc, docx, xls, xlsx).',
            'bukti.*.max' => 'Ukuran file maksimal 10 MB.',
        ];
    }
}
