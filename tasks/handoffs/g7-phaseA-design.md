# G7 Phase A — Design Proposal: Sealed*Result for PAPI, Kraepelin, RMIB

**Status:** DRAFT — menunggu review Lead + persetujuan user sebelum Fase B
**Branch:** `deepseek/g7-sealed-results`
**Tanggal:** 2026-09-19

---

## 1. Ringkasan Pola IST (Referensi)

IST adalah instrumen pertama yang memiliki kontrak `SealedIstResult`. Pola yang sudah mapan:

```
SealedIstResult (value object, immutable)
    → PersistSealedIstResult (action, writes to sealed_results ledger)
        → LoadPersistedIstResult (action, reads from sealed_results ledger)
            → PersistedIstResult (read model, returned to consumers)
```

### 1.1 Skema Ledger (`sealed_results`)

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | auto-increment |
| `report_id` | bigint FK | ke `reports.id` |
| `contract` | text NOT NULL | `'ist-result:v1'` |
| `participant_id` | bigint FK | denormalized untuk query cepat |
| `session_id` | bigint FK | sesi IST |
| `engine_version` | text NOT NULL | versi scoring engine |
| `scored_at` | timestamptz NOT NULL | kapan skoring selesai |
| `standard_score` | numeric NOT NULL | IST: IQ (77–132) |
| `source_score` | numeric NOT NULL | IST: SW (Σ RW → SW) |
| `category` | text | IST: kategori SW (Baik/Cukup Baik/Sedang/Agak Kurang/Kurang) |
| `checksum` | text NOT NULL | SHA-256 dari payload JSON canonical |
| `payload` | jsonb NOT NULL | seluruh hasil skoring dalam JSON canonical |

### 1.2 Invariants IST

- `contract` = `'ist-result:v1'` — rigid, tidak bisa berubah tanpa migrasi eksplisit
- `standard_score` (IQ) dan `source_score` (SW) **NOT NULL** — IST selalu menghasilkan keduanya
- `category` adalah kategori SW dari tabel `sw_score_bands`
- `checksum` = SHA-256(canonical_json(payload)) — verifiable, idempotent
- Satu `(report_id, contract)` unique — satu laporan hanya punya satu IST result
- Upsert via `ON CONFLICT (report_id, contract) DO UPDATE` dengan guard `checksum = EXCLUDED.checksum` — idempotent, tidak menimpa hasil berbeda
- `payload` berisi: 9 subtest RW, SW, level 1–5, IQ, kategori IQ, engine_version, norma_version

### 1.3 Idempotency & Checksum

```
IF EXISTS (SELECT 1 FROM sealed_results WHERE report_id=$1 AND contract=$2 AND checksum=$3)
    → return existing (NO-OP)
IF EXISTS (SELECT 1 FROM sealed_results WHERE report_id=$1 AND contract=$2 AND checksum<>$3)
    → THROW "checksum mismatch — result already sealed with different payload"
ELSE
    → INSERT
```

---

## 2. Output Skoring Aktual (F2)

### 2.1 PAPI Kostick

**File:** `tools/extract/extract_papi.py`, `database/seeders/data/papi.json`
**Spesifikasi:** SPEC.md §4.2

- **20 dimensi:** N, G, A, L, P, I, T, V, O, B, S, X, C, D, R, Z, E, K, F, W
  - 16 dipakai HPP (A,B,C,D,E,F,K,L,N,O,P,R,S,T,V,W)
  - 4 tidak dipakai HPP (G,I,X,Z) — tetap diskor & tampil ke psikolog
- **90 mapping:** ROLE=45, NEED=45 (verified: `test_papi_invariants` di `tools/extract/tests/test_f0.py:85`)
- **Metode skoring:** distance-from-white-zone (OPTIMAL)
  ```
  jarak = skor < lo ? lo−skor : (skor > hi ? skor−hi : 0)
  level = max(1, 5 − min(jarak, 4))
  ```
- **Output per dimensi:** raw_score (0–9), zone (white/blue_low/blue_high/yellow_low/yellow_high), level (1–5)
- **Band scores:** white=8, blue_low=7, blue_high=7, yellow_low=3, yellow_high=5
- **White zones:** berbeda tiap dimensi (contoh: A=[5,8], W=[4,7], E=[3,6])

### 2.2 Kraepelin

**File:** `tools/extract/extract_kraepelin.py`, `database/seeders/data/kraepelin.json`
**Spesifikasi:** SPEC.md §4.3, SCORING_ALGORITHM.md §6

- **4 faktor:**
  - Panker = ΣY / 50 (rata-rata capaian per lajur, besar=baik)
  - Tianker = Σsalah + Σterlewat (kecil=baik)
  - Hanker = b × 50 (besar=baik, **DIKONFIRMASI**: 2 golden test verified)
  - Janker = max(Y) − min(Y) (kecil=baik)
- **Factor rounding:** before band lookup, precision=3, mode=half_up
- **Golden tests verified:**
  - Golden #1 (S1/S2): Panker 15.86, Tianker 7, Hanker −0.622, Janker 7 → skor 7/6/4/6
  - Golden #2 (SMA/SMK): Panker 13.12, Tianker 5, Janker 6, slope b 0.100648, Hanker 5.032 → 4/4/4/5
- **Output per faktor:** raw_value, score (1–10), category, level (1–5)
- **Norma:** per grup (6 grup; CPMI SLTA → SMA/SMK), cutoff berbeda

### 2.3 RMIB

**File:** `tools/extract/extract_rmib.py`, `database/seeders/data/rmib.json`
**Spesifikasi:** SPEC.md §4.4

- **12 kategori:** Out, Mech, Prac, Med, SocSvc, ... (12 total)
- **9 kelompok × 12 pekerjaan:** 108 sel rotasi
- **Rotasi:** posisi p kelompok j = `MOD(p+j−2,12)+1`
- **Skor kategori:** Σ rank pada 9 sel (makin kecil makin diminati)
- **Validasi invariant:** Σ rank = 702, tiap kelompok = 78
- **Rank → skor HPP 1–10:** dari tabel lookup (`11 RMIB Rank ke Skor`)
- **Rank → level:** `ceil((skor+1)/2)` → `1–2→5, 3–4→4, 5–6→3, 7–8→2, 9–12→1`
- **5 kategori dipakai HPP:** Out/Mech/Prac/Med/SocSvc → D1–D5
- **7 kategori lain:** tersimpan untuk psikolog (tidak di HPP)
- **Tie policy:** competition ranking

---

## 3. TEMUAN: Ketidakcocokan Layout Ledger

### 3.1 Masalah

Skema `sealed_results` dirancang dengan asumsi IST:
- `standard_score` NOT NULL → IST: IQ (77–132)
- `source_score` NOT NULL → IST: SW (Σ RW dikonversi)
- `category` → IST: kategori SW (Baik/Cukup Baik/Sedang/Agak Kurang/Kurang)

Ketiga instrumen G7 **tidak memiliki padanan alami** untuk kolom-kolom tersebut:

| Instrumen | `standard_score` | `source_score` | `category` |
|---|---|---|---|
| **IST** | IQ (77–132) ✓ | SW (total RW→SW) ✓ | Kategori SW ✓ |
| **PAPI** | ❌ tidak ada | ❌ tidak ada (20 dimensi, bukan 1 skor) | ❌ 20 zone per dimensi, bukan 1 kategori |
| **Kraepelin** | ❌ tidak ada | ❌ tidak ada (4 faktor, bukan 1 skor) | ❌ 4 kategori per faktor, bukan 1 |
| **RMIB** | ❌ tidak ada | ❌ tidak ada (12 kategori, bukan 1 skor) | ❌ 12 rank+level, bukan 1 kategori |

### 3.2 Proposal: Sentinel Values

Untuk memenuhi constraint NOT NULL tanpa mengubah skema (Fase B), gunakan **sentinel values**:

| Kolom | PAPI sentinel | Kraepelin sentinel | RMIB sentinel |
|---|---|---|---|
| `standard_score` | `-1` | `-1` | `-1` |
| `source_score` | `-1` | `-1` | `-1` |
| `category` | `'N/A'` | `'N/A'` | `'N/A'` |

### 3.3 Proposal Migrasi (untuk keputusan Lead)

**Opsi A (rekomendasi):** Buat kolom `standard_score` dan `source_score` menjadi **nullable** via migrasi. Ubah constraint dari `NOT NULL` ke `DEFAULT NULL`. IST tetap mengisi keduanya; instrumen lain mengisi NULL. Kolom `category` tetap NOT NULL tapi instrumen non-IST mengisi string deskriptif (contoh: PAPI = ringkasan zona dominan, Kraepelin = kategori terendah/tertinggi, RMIB = minat utama).

**Opsi B (alternatif):** Gunakan sentinel values seperti di atas. Tidak perlu migrasi skema.

**Keputusan ditunda ke Lead.** Fase B akan mengikuti keputusan ini.

---

## 4. Usulan Desain Sealed*Result per Instrumen

### 4.1 SealedPapiResult

**Contract:** `'papi-result:v1'`

**Fields di `payload` (jsonb):**
```json
{
  "contract": "papi-result:v1",
  "engine_version": "PAPI-OPTIMAL-2026.09",
  "scored_at": "2026-09-19T10:00:00+07:00",
  "dimensions": {
    "N": {"raw_score": 6, "zone": "white", "level": 5},
    "G": {"raw_score": 4, "zone": "blue_low", "level": 4},
    "...": "..."
  },
  "hpp_excluded": ["G", "I", "X", "Z"],
  "invariants_verified": {
    "role_count": 45,
    "need_count": 45,
    "dimensions_scored": 20,
    "all_scores_0_to_9": true,
    "white_zone_coverage": "verified"
  }
}
```

**Invariants:**
- Tepat 20 dimensi terskor
- ROLE=45, NEED=45 (dari 90 mapping)
- Setiap dimensi memiliki raw_score ∈ [0,9]
- Setiap dimensi memiliki zone ∈ {white, blue_low, blue_high, yellow_low, yellow_high}
- Setiap dimensi memiliki level ∈ [1,5]
- 4 dimensi excluded (G,I,X,Z) diverifikasi eksplisit

**Ledger mapping:**
- `standard_score` → sentinel `-1` (atau NULL bila migrasi disetujui)
- `source_score` → sentinel `-1` (atau NULL)
- `category` → `'PAPI-20D'` (string deskriptif; atau `'N/A'` bila sentinel)
- `checksum` → SHA-256(canonical JSON payload)

### 4.2 SealedKraepelinResult

**Contract:** `'kraepelin-result:v1'`

**Fields di `payload` (jsonb):**
```json
{
  "contract": "kraepelin-result:v1",
  "engine_version": "KRAEPELIN-2026.09",
  "scored_at": "2026-09-19T10:00:00+07:00",
  "factors": {
    "panker": {"raw": 15.86, "score": 7, "category": "Baik", "level": 4},
    "tianker": {"raw": 7.0, "score": 6, "category": "Cukup", "level": 3},
    "hanker": {"raw": -0.622, "score": 4, "category": "Kurang", "level": 2},
    "janker": {"raw": 7.0, "score": 6, "category": "Cukup", "level": 3}
  },
  "norm_group": "SMA/SMK",
  "column_count": 50,
  "hanker_formula": "b_x_50",
  "factor_rounding": {"precision": 3, "mode": "half_up"},
  "golden_verified": true
}
```

**Invariants:**
- Tepat 4 faktor (panker, tianker, hanker, janker)
- Hanker = slope_b × 50 (formula terverifikasi)
- Panker = rata-rata capaian 50 lajur
- Setiap faktor memiliki raw, score ∈ [1,10], category, level ∈ [1,5]
- Golden test harus lolos sebelum sealing (validasi internal)
- Column count = 50
- Factor rounding precision = 3, half_up

**Ledger mapping:**
- `standard_score` → sentinel `-1` (atau NULL)
- `source_score` → sentinel `-1` (atau NULL)
- `category` → kategori Panker (faktor utama) atau `'KRAEPELIN-4F'`
- `checksum` → SHA-256(canonical JSON payload)

### 4.3 SealedRmibResult

**Contract:** `'rmib-result:v1'`

**Fields di `payload` (jsonb):**
```json
{
  "contract": "rmib-result:v1",
  "engine_version": "RMIB-2026.09",
  "scored_at": "2026-09-19T10:00:00+07:00",
  "categories": {
    "Out": {"rank_sum": 15, "hpp_score": 7, "level": 4},
    "Mech": {"rank_sum": 22, "hpp_score": 5, "level": 3},
    "...": "..."
  },
  "hpp_categories": ["Out", "Mech", "Prac", "Med", "SocSvc"],
  "non_hpp_categories": ["...", "..."],
  "invariants_verified": {
    "total_rank_sum": 702,
    "group_sums_all_78": true,
    "categories_count": 12,
    "rotation_cells": 108
  }
}
```

**Invariants:**
- Tepat 12 kategori
- Σ rank = 702
- Setiap kelompok = 78
- 108 sel rotasi (9 kelompok × 12 posisi)
- 5 kategori HPP (D1–D5), 7 non-HPP
- Setiap kategori memiliki rank_sum, hpp_score ∈ [1,10], level ∈ [1,5]
- Rank → score via tabel lookup `11 RMIB Rank ke Skor`
- Tie policy: competition ranking

**Ledger mapping:**
- `standard_score` → sentinel `-1` (atau NULL)
- `source_score` → sentinel `-1` (atau NULL)
- `category` → minat utama (kategori dengan rank_sum terendah) atau `'RMIB-12C'`
- `checksum` → SHA-256(canonical JSON payload)

---

## 5. Sketsa Wiring G7 (Fase C)

### 5.1 Target: AggregateInstrumentLevels

Setelah ketiga Sealed*Result tersimpan di ledger, komponen `AggregateInstrumentLevels` menggantikan client-supplied sources di `ReportSigningService` Step 4.

**Kondisi saat ini (sebelum G7):**
```
Client → POST /reports/{id}/sign
  body: { sources: { ist: {...}, papi: {...}, kraepelin: {...}, rmib: {...} } }
  → ReportSigningService.validateAndSign(sources)
    → Step 4: aggregate levels from client-supplied sources
```

**Kondisi target (setelah G7):**
```
Client → POST /reports/{id}/sign
  body: {}  // sources TIDAK LAGI dikirim client
  → ReportSigningService.validateAndSign()
    → Step 4: AggregateInstrumentLevels.loadFromLedger(report_id)
      → LoadPersistedIstResult(report_id)
      → LoadPersistedPapiResult(report_id)
      → LoadPersistedKraepelinResult(report_id)
      → LoadPersistedRmibResult(report_id)
      → aggregate into 18 sub-aspect levels
```

### 5.2 Breaking API Change

- **Sebelum:** Client mengirim `sources` di request body
- **Setelah:** Server membaca dari `sealed_results` ledger
- **Dampak:** Semua client (Filament admin panel, internal tools) harus diupdate
- **Keamanan:** Client tidak bisa lagi mengirim skor palsu — semua skor diverifikasi dari ledger yang sealed + checksummed
- **Ini adalah BREAKING CHANGE yang disengaja** — tujuan utama G7 adalah server-authoritative scoring

### 5.3 DASS Tetap Terpisah

- DASS-21 **tidak masuk** ke Sealed*Result manapun
- DASS tetap di skema terpisah (`dass_responses`, `dass_results`)
- DASS tidak memengaruhi kelayakan (G4, T-07)
- Load DASS tetap dari tabel sendiri, bukan dari sealed_results ledger

---

## 6. Rencana Fase B

### 6.1 Commit Order

1. **PAPI first** — paling sederhana (20 dimensi independen, tidak ada dependensi antar dimensi)
2. **Kraepelin second** — 4 faktor dengan formula regresi (Hanker = b×50), golden test verification
3. **RMIB third** — 12 kategori dengan rotasi, paling banyak invariant checks

### 6.2 Size Estimates

Setiap instrumen diperkirakan ~1400 lines (total ~4200 lines untuk ketiganya):

| Instrumen | Value Object | Persist Action | Load Action | Read Model | Test | Total |
|---|---|---|---|---|---|---|
| PAPI | ~200 | ~250 | ~150 | ~100 | ~700 | ~1400 |
| Kraepelin | ~200 | ~250 | ~150 | ~100 | ~700 | ~1400 |
| RMIB | ~250 | ~250 | ~150 | ~100 | ~700 | ~1450 |

### 6.3 Per-Instrument Deliverables

Setiap instrumen menghasilkan:
- `app/Results/Sealed{Instrument}Result.php` — immutable value object
- `app/Actions/Results/PersistSealed{Instrument}Result.php` — write to ledger
- `app/Actions/Results/LoadPersisted{Instrument}Result.php` — read from ledger
- `app/Results/Persisted{Instrument}Result.php` — read model / DTO
- `tests/Unit/Results/Sealed{Instrument}ResultTest.php` — unit tests
- `tests/Feature/Results/PersistSealed{Instrument}ResultTest.php` — integration tests

---

## 7. Konflik dengan Spec

**Tidak ditemukan konflik.** SPEC.md v4:

- §4.2 (PAPI), §4.3 (Kraepelin), §4.4 (RMIB) — mendefinisikan output skoring yang akan menjadi payload Sealed*Result
- §9 (Tinjau, Tanda Tangan) — menyebut "penanda G7 menonjol" di layar tinjauan; ini konsisten dengan G7 sebagai guardrail yang memerlukan sealed results sebagai sumber kebenaran
- §5 (Agregasi Sub-Aspek) — formula agregasi (rata-rata setara, jangkar) tidak berubah; G7 hanya mengubah **dari mana** skor sumber dibaca (dari ledger, bukan dari client)
- §11 (Model Data) — tidak menyebutkan `sealed_results` secara eksplisit; tabel ini adalah tambahan infra yang tidak bertentangan dengan model data yang ada

---

## 8. Pertanyaan Terbuka

1. **Migrasi `standard_score` / `source_score` ke nullable?** Opsi A (nullable via migrasi, direkomendasikan) vs Opsi B (sentinel -1). Keputusan Lead diperlukan sebelum Fase B dimulai.

2. **Nilai `category` untuk non-IST?** Bila tetap NOT NULL: PAPI pakai `'PAPI-20D'`, Kraepelin pakai kategori Panker, RMIB pakai minat utama — apakah ini cukup deskriptif? Atau perlu format yang lebih terstruktur?

3. **Apakah `engine_version` di payload cukup, atau perlu juga di kolom ledger tersendiri?** Saat ini `engine_version` hanya ada di dalam `payload` jsonb. Bila query perlu filter by version tanpa membuka jsonb, perlu kolom baru.

4. **Apakah Sealed*Result perlu menyimpan `norm_group` / `norm_version` di level kolom?** Kraepelin dan IST bergantung pada grup norma; bila norma berubah, sealed result harus bisa diidentifikasi versi normanya tanpa unpack jsonb.

5. **Urutan sealing: apakah harus sequential (IST → PAPI → Kraepelin → RMIB) atau boleh parallel?** Sequential lebih aman untuk idempotency; parallel butuh locking per `(report_id, contract)`.

---

## 9. Checklist Persetujuan

- [ ] Lead menyetujui desain SealedPapiResult (20 dimensi, invariants)
- [ ] Lead menyetujui desain SealedKraepelinResult (4 faktor, golden test)
- [ ] Lead menyetujui desain SealedRmibResult (12 kategori, invariants)
- [ ] Lead memutuskan Opsi A (nullable) vs Opsi B (sentinel) untuk `standard_score`/`source_score`
- [ ] Lead menyetujui nilai `category` untuk masing-masing instrumen
- [ ] Lead menyetujui commit order: PAPI → Kraepelin → RMIB
- [ ] Lead menyetujui breaking API change: client no longer sends sources
- [ ] User (LSI) menyetujui tidak ada DASS di Sealed*Result manapun
- [ ] Fase B bisa dimulai
