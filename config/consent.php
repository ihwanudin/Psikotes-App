<?php

declare(strict_types=1);

return [
    'legal_review_pending' => true,

    'documents' => [
        'psychotest' => [
            'version' => 'draft-2026-08-25.2',
            'title' => 'Persetujuan psikotes dan pemrosesan data',
            'text' => <<<'TEXT'
Saya menyetujui pengumpulan dan pemrosesan data identitas, foto dokumen identitas, selfie awal, kontak, jawaban tes, skor, laporan, bukti pembayaran, serta log teknis untuk penyelenggaraan psikotes, verifikasi identitas dan integritas sesi, administrasi pembayaran, dan penerbitan laporan kepada pihak yang berwenang. Saya memahami bahwa sesi dapat menggunakan foto kamera berkala dan pencatatan perpindahan layar sebagai bahan tinjauan, bukan sebagai jaminan pencegahan kecurangan. Saya memahami bahwa kebijakan privasi mencakup tujuan pemrosesan, pembagian terbatas, masa retensi, keamanan data, serta hak akses, koreksi, penghapusan, dan penarikan persetujuan.
TEXT,
        ],
        'dass' => [
            'version' => 'draft-2026-08-25',
            'title' => 'Pilihan mengikuti skrining DASS-21',
            'text' => <<<'TEXT'
Saya memahami bahwa DASS-21 adalah skrining kesehatan mental yang terpisah dari penilaian kelayakan psikotes, bukan diagnosis klinis, dan hasilnya tidak pernah menentukan zona atau label kelayakan. Saya dapat menolak bagian ini tanpa menghentikan psikotes utama. Bila saya memilih ikut, data dan hasil rinci DASS-21 hanya dapat diakses oleh peserta dan psikolog sesuai kebijakan privasi.
TEXT,
        ],
    ],
];
