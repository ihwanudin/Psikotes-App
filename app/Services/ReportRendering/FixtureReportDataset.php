<?php

declare(strict_types=1);

namespace App\Services\ReportRendering;

use App\Domain\Report\DassScreeningSummary;
use App\Domain\Report\HppReportDraft;
use App\Domain\Report\InternalReportDraft;
use App\Domain\Report\ReportAspectGrid;
use App\Domain\Report\ReportIdentity;

/**
 * Deterministic, self-made fixture dataset for the F6 draft templates.
 * It intentionally has NO connection to real participant data or to the
 * F5 review/signature output (that contract is not final yet). Kraepelin
 * numbers mirror the verified SMA/SMK golden case in SPEC §4.3.
 */
final class FixtureReportDataset
{
    public static function hppDraft(?array $psychologist = null): HppReportDraft
    {
        return HppReportDraft::create(
            self::identity(),
            self::aspectGrid(),
            108,
            'Cukup Baik',
            self::clusterNarratives(),
            DassScreeningSummary::fromArray([
                'general_category' => 'Ringan',
                'narrative' => 'Skrining kesehatan mental menunjukkan kategori umum Ringan. Hasil skrining ini tidak memengaruhi penilaian kelayakan kerja.',
                // Bank Narasi has follow-up text only for Sedang and
                // Parah/Sangat Parah; Ringan carries none.
                'follow_up' => null,
            ]),
            'DIPERTIMBANGKAN',
            'Seluruh aspek kemampuan berada pada zona Terpenuhi, namun aspek Komunikasi & Tanggung Jawab berada pada Grey Area terhadap standar bidang perawatan (KAIGO).',
            'Pendampingan adaptasi komunikasi pada bulan pertama penempatan serta pemantauan berkala oleh supervisior lapangan.',
            $psychologist,
        );
    }

    public static function internalDraft(?array $psychologist = null): InternalReportDraft
    {
        return InternalReportDraft::create(
            self::identity(),
            self::ist(),
            self::kraepelin(),
            self::papi(),
            self::rmib(),
            self::aspectGrid()->toArray(),
            self::validity(),
            self::integrationSlots(),
            self::dassDetail(),
            [
                'Draf integrasi S1-S7 menunggu ringkasan psikolog sebelum tanda tangan.',
                'Seluruh angka pada lembar ini berasal dari data fixture; kontrak tinjauan F5 belum final.',
            ],
            $psychologist,
        );
    }

    /** @return array{name: string, sipp_number: string, signature_note: string|null, signed_at: string|null} */
    public static function psychologist(): array
    {
        return [
            'name' => 'Dewi Kartika, S.Psi.',
            'sipp_number' => 'SIPP-00000000',
            'signature_note' => 'Blok psikolog fixture; tanda tangan elektronik menyusul bersama kontrak F5.',
            'signed_at' => null,
        ];
    }

    private static function identity(): ReportIdentity
    {
        return ReportIdentity::fromArray([
            'report_number' => 'HPP-FIXTURE-2026-0001',
            'participant_name' => 'Siti Rahmawati',
            'test_number' => 'T26-09-0001',
            'birth_date' => '2003-04-17',
            'education' => 'SMA/SMK',
            'branch_name' => 'LPK Cahaya Sakura (fixture)',
            'target_field' => 'KAIGO',
            'standard_version' => 'GA-2026.08',
            'test_date' => '2026-09-14',
        ]);
    }

    private static function aspectGrid(): ReportAspectGrid
    {
        return ReportAspectGrid::fromArray([
            ['code' => 'A1', 'label_id' => 'Inteligensi Umum', 'label_jp' => '一般知性', 'level' => 5, 'standard' => 3],
            ['code' => 'A2', 'label_id' => 'Daya Analisis–Sintesis', 'label_jp' => '問題解決のための分析力', 'level' => 4, 'standard' => 3],
            ['code' => 'B1', 'label_id' => 'Konsentrasi & Daya Ingat', 'label_jp' => '集中力と記憶力', 'level' => 3, 'standard' => 3],
            ['code' => 'B2', 'label_id' => 'Kecepatan & Ketelitian', 'label_jp' => '仕事のスピードと正確さ', 'level' => 4, 'standard' => 3],
            ['code' => 'B3', 'label_id' => 'Daya Tangkap', 'label_jp' => '理解力', 'level' => 3, 'standard' => 3],
            ['code' => 'B4', 'label_id' => 'Sistematika Kerja', 'label_jp' => '仕事の計画性', 'level' => 4, 'standard' => 3],
            ['code' => 'C1', 'label_id' => 'Kematangan & Kepercayaan Diri', 'label_jp' => '成熟度と自信', 'level' => 4, 'standard' => 3],
            ['code' => 'C2', 'label_id' => 'Komunikasi & Tanggung Jawab', 'label_jp' => 'コミュニケーションと責任感', 'level' => 3, 'standard' => 4],
            ['code' => 'C3', 'label_id' => 'Inisiatif & Penyesuaian Sosial', 'label_jp' => '自発性と社会適応力', 'level' => 4, 'standard' => 4],
            ['code' => 'C4', 'label_id' => 'Daya Tahan Stres & Stabilitas', 'label_jp' => 'ストレス耐性と情緒的安定性', 'level' => 4, 'standard' => 4],
            ['code' => 'C5', 'label_id' => 'Ketahanan Kerja', 'label_jp' => '持久力', 'level' => 5, 'standard' => 3],
            ['code' => 'C6', 'label_id' => 'Keuletan', 'label_jp' => '持続力', 'level' => 4, 'standard' => 3],
            ['code' => 'C7', 'label_id' => 'Arah & Gaya Kerja', 'label_jp' => '志向性と業務遂行', 'level' => 4, 'standard' => 3],
            ['code' => 'D1', 'label_id' => 'Outdoor — Pertanian & Perkebunan', 'label_jp' => '屋外・農業', 'level' => 4, 'standard' => null],
            ['code' => 'D2', 'label_id' => 'Mechanical — Mekanik & Kelistrikan', 'label_jp' => '機械・電気', 'level' => 3, 'standard' => null],
            ['code' => 'D3', 'label_id' => 'Practical — Konstruksi & Produksi', 'label_jp' => '建設・製造', 'level' => 4, 'standard' => null],
            ['code' => 'D4', 'label_id' => 'Medical — Perawatan & Kesehatan', 'label_jp' => '介護・医療', 'level' => 4, 'standard' => 3],
            ['code' => 'D5', 'label_id' => 'Social Service — Restoran & Perhotelan', 'label_jp' => '接客・サービス', 'level' => 3, 'standard' => null],
        ]);
    }

    /** @return array<string, string> */
    private static function clusterNarratives(): array
    {
        return [
            'A' => 'Kemampuan intelektual berada pada kisaran Cukup Baik dengan pola penalaran yang terarah; peserta menyerap instruksi baru dengan cepat pada materi yang konkret.',
            'B' => 'Cara kerja peserta cukup cepat dan teliti dengan sistematika yang baik; konsentrasi terjaga pada tugas berulang meski memerlukan variasi aktivitas secara berkala.',
            'C' => 'Kepribadian kerja menunjukkan kematangan dan kepatuhan aturan yang menonjol; aspek komunikasi masih berkembang dan memerlukan lingkungan yang mendukung praktik bahasa.',
            'D' => 'Minat kerja paling kuat pada bidang medis/perawatan dan layanan sosial, selaras dengan bidang tujuan KAIGO.',
        ];
    }

    /** @return array<mixed> */
    private static function ist(): array
    {
        return [
            'iq' => 108,
            'iq_category' => 'Cukup Baik',
            'subtests' => [
                'SE' => ['sw' => 112, 'level' => 4, 'label' => 'Cukup Baik'],
                'WA' => ['sw' => 105, 'level' => 4, 'label' => 'Cukup Baik'],
                'AN' => ['sw' => 118, 'level' => 4, 'label' => 'Cukup Baik'],
                'GE' => ['sw' => 96, 'level' => 3, 'label' => 'Sedang'],
                'RA' => ['sw' => 121, 'level' => 5, 'label' => 'Baik'],
                'ZR' => ['sw' => 108, 'level' => 4, 'label' => 'Cukup Baik'],
                'FA' => ['sw' => 99, 'level' => 3, 'label' => 'Sedang'],
                'WU' => ['sw' => 115, 'level' => 4, 'label' => 'Cukup Baik'],
                'ME' => ['sw' => 102, 'level' => 3, 'label' => 'Sedang'],
            ],
        ];
    }

    /** @return array<mixed> */
    private static function kraepelin(): array
    {
        return [
            'norm_group' => 'SMA/SMK',
            'factors' => [
                'panker' => ['value' => '13,120', 'level' => 4, 'band' => 'Baik'],
                'tianker' => ['value' => '5', 'level' => 4, 'band' => 'Baik'],
                'hanker' => ['value' => '5,032', 'level' => 5, 'band' => 'Baik Sekali'],
                'janker' => ['value' => '6', 'level' => 4, 'band' => 'Baik'],
            ],
        ];
    }

    /** @return array<mixed> */
    private static function papi(): array
    {
        $raws = [
            'A' => 5, 'B' => 6, 'C' => 4, 'D' => 5, 'E' => 3, 'F' => 6, 'G' => 2, 'I' => 4,
            'K' => 5, 'L' => 6, 'N' => 4, 'O' => 3, 'P' => 5, 'R' => 4, 'S' => 6, 'T' => 5,
            'V' => 4, 'W' => 5, 'X' => 3, 'Z' => 4,
        ];

        $scales = [];
        foreach ($raws as $code => $raw) {
            $qualitative = in_array($code, ['G', 'I', 'X', 'Z'], true);
            $scales[$code] = [
                'raw' => $raw,
                'level' => $qualitative ? null : 4,
                'qualitative' => $qualitative,
            ];
        }

        return ['scales' => $scales];
    }

    /** @return array<mixed> */
    private static function rmib(): array
    {
        return [
            'categories' => [
                'out' => ['label' => 'Luas/Out Door', 'rank' => 9],
                'mech' => ['label' => 'Mekanik', 'rank' => 6],
                'prac' => ['label' => 'Praktik/Technical', 'rank' => 4],
                'med' => ['label' => 'Medis', 'rank' => 2],
                'socsvc' => ['label' => 'Layanan Sosial', 'rank' => 3],
                'aesth' => ['label' => 'Estetik', 'rank' => 11],
                'sci' => ['label' => 'Sains', 'rank' => 7],
                'bus' => ['label' => 'Bisnis', 'rank' => 8],
                'cler' => ['label' => 'Klerikal', 'rank' => 5],
                'comm' => ['label' => 'Komunikasi', 'rank' => 10],
                'lit' => ['label' => 'Sastra', 'rank' => 12],
                'mus' => ['label' => 'Musik', 'rank' => 1],
            ],
        ];
    }

    /** @return array<mixed> */
    private static function validity(): array
    {
        $keys = [
            'identity_verified', 'camera_active', 'no_tab_switch', 'no_second_person',
            'responses_complete', 'subtest_time_plausible', 'no_straight_lining',
            'connection_stable', 'papi_social_desirability', 'kraepelin_human_tempo',
        ];

        $items = [];
        foreach ($keys as $key) {
            $items[$key] = true;
        }

        return ['status' => 'V1', 'items' => $items, 'procedure_note' => null];
    }

    /** @return array<mixed> */
    private static function integrationSlots(): array
    {
        return [
            'S1' => ['text_id' => 'Peserta menunjukkan kapasitas intelektual Cukup Baik dengan kematangan emosi yang stabil di atas rata-rata kelompok normanya.', 'text_jp' => '知的能力は平均よりやや高く、情緒の安定性が認められる。'],
            'S2' => ['text_id' => 'Tempo kerja cepat dan teliti dengan keajegan lajur yang baik; kelelahan kerja belum tampak pada sesi pemeriksaan.', 'text_jp' => '作業テンポは速く正確で、持続性も確認された。'],
            'S3' => ['text_id' => 'Kepribadian hangat dan patuh aturan; kecenderungan mencari dukungan sosial saat menghadapi tugas baru.', 'text_jp' => '規則を重んじる温厚な性格で、新しい課題では支援を求める傾向がある。'],
            'S4' => ['text_id' => 'Kekuatan utama: ketahanan stres, kepatuhan keselamatan kerja, dan minat layanan perawatan yang tinggi.', 'text_jp' => '強みはストレス耐性・安全遵守・介護への高い関心。'],
            'S5' => ['text_id' => 'Area pengembangan: kepercayaan diri berkomunikasi dalam bahasa asing; perlu latihan terstruktur dan umpan balik rutin.', 'text_jp' => '課題は外国語でのコミュニケーション自信であり、段階的な訓練を推奨する。'],
            'S6' => ['text_id' => 'Kesesuaian dengan bidang perawatan (KAIGO) baik; minat medis/perawatan berada pada peringkat atas hasil RMIB.', 'text_jp' => '介護分野との適合性は高く、RMIBでも医療・介護関心が上位にある。'],
            'S7' => ['text_id' => 'Disarankan penempatan pada unit perawatan lansia dengan supervisior yang komunikatif dan jadwal pelatihan bahasa pada enam bulan pertama.', 'text_jp' => '入職後半年間は言葉の訓練を伴う高齢者ケアユニットへの配置を推奨する。'],
        ];
    }

    /** @return array<mixed> */
    private static function dassDetail(): array
    {
        return [
            'subscales' => [
                'd' => ['raw' => 5, 'doubled' => 10, 'category' => 'Ringan'],
                'a' => ['raw' => 3, 'doubled' => 6, 'category' => 'Normal'],
                's' => ['raw' => 8, 'doubled' => 16, 'category' => 'Ringan'],
            ],
            'general_category' => 'Ringan',
            'follow_up' => 'Tidak diperlukan rujukan; kategori umum berada di bawah taraf pemantauan.',
            'flags' => [],
        ];
    }
}
