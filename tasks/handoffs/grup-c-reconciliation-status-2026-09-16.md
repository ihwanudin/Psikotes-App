# Grup C reconciliation status — 2026-09-16

## Ringkasan akhir audit

- **30 branch/ref diaudit** terhadap checkpoint `6350f35` dan checkpoint
  lanjutan `codex/reconcile-org-f2-2026-09-15`.
- **16 PATCH-EQUIVALENT/RECONCILED**; **13 PERLU MERGE historis terserap oleh
  rekonsiliasi `integration/psychotest-current`**; **0 KONFLIK** terbuka yang
  masih membutuhkan keputusan produk/teknis.
- Satu ref lama, `codex/f5-fixture-visual-repair`, dicatat sebagai
  **SUPERSEDED oleh v2** dan tidak dihitung sebagai audit mandiri.
- `integration/psychotest-current` sekarang **RECONCILED**: patch security
  `js-yaml`, callback keyring/config, lifecycle SQLite isolation, rollback
  preservation, DASS fixture centralization, direct-public fixture coverage,
  Xendit `ist+dass21`, dan residual manual-payment/direct-public entitlement
  coverage sudah tercermin secara patch-equivalent atau adaptif di checkpoint
  lanjutan.
- Sanity check `git cherry HEAD integration/psychotest-current` masih
  menampilkan sejumlah `+` karena beberapa patch diadaptasi dan tidak identik
  byte-for-byte, serta beberapa catatan handoff historis tidak dibawa. Audit
  file-level terakhir tidak menemukan sisa material signifikan di luar
  dokumentasi historis low-priority.
- Bukti akhir rekonsiliasi integration:
  - Focused direct-public/manual-payment tests: 28 tests / 199 assertions,
    hijau.
  - Full SQLite suite 2GB: 3,383 tests, 3,374 passed, 21,886 assertions,
    9 skipped, 0 error/failure.
  - Disposable PostgreSQL: 554 tests / 5,863 assertions, hijau.

This is a read-only audit ledger against checkpoint `6350f35`. A
`PATCH-EQUIVALENT` row means every candidate commit is already represented by
an equivalent patch in the checkpoint; it is not permission to delete the
source branch. `PERLU MERGE` means a material candidate patch is absent, and
`KONFLIK` means a material overlap needs a deliberate resolution.

| Branch | Status | Catatan singkat |
|---|---|---|
| `integration/psychotest-current` | RECONCILED | Common ancestor `8889095`; 33 commit eksklusif diaudit ulang. Satu patch security `js-yaml` patch-equivalent; callback keyring, SQLite lifecycle/rollback, DASS fixture, direct-public fixture, Xendit, dan residual manual-payment entitlement coverage direkonsiliasi adaptif ke checkpoint lanjutan. Sisa `git cherry +` adalah patch yang sengaja disupersede/adaptasi atau dokumentasi historis low-priority, bukan pekerjaan material yang terlewat. Evidence: focused direct-public/manual-payment 28/199 hijau, full SQLite 3,383 tests / 21,886 assertions hijau, PostgreSQL 554 tests / 5,863 assertions hijau. |
| `codex/f9-backup-restore-rehearsal` | PATCH-EQUIVALENT | Common ancestor `8bde313`; checkpoint/branch divergence `50/4`. Kedua script dan contract test byte-identical. Handoff checkpoint lebih baru: canonical integration verification dan status acceptance menggantikan status kandidat yang masih pending review. |
| `codex/f9-load-performance-baseline` | PATCH-EQUIVALENT | Common ancestor `99b557f`; divergence `45/11`. Empat file kandidat sudah terserap; tiga script byte-identical. Handoff checkpoint menambahkan canonical integration smoke/acceptance sehingga lebih baru dari candidate handoff. |
| `codex/test-harness-reliability` | PATCH-EQUIVALENT | Common ancestor `fff2feb`; divergence `69/4`. Seluruh empat patch kandidat terdeteksi setara. Dua puluh tiga path byte-identical; perbedaan tiga path adalah kelanjutan checkpoint untuk isolasi generic-result-ledger dan portability PCNTL/Unix-socket, bukan patch kandidat yang hilang. |
| `codex/test-harness-reliability-qa` | PATCH-EQUIVALENT | Common ancestor `16fa5b9`; divergence `76/2`. Kedua patch QA sudah setara. Perbedaan pada helper/result tests dan historical migration test adalah kelanjutan checkpoint (ledger isolation serta test portability), bukan perubahan material yang perlu diambil dari branch QA. |
| `codex/security-binary-content-gate` | PATCH-EQUIVALENT | Common ancestor `83f7c54`; divergence `77/3`. Tiga tool scanner path byte-identical dan seluruh patch kandidat setara. Handoff checkpoint menambahkan canonical QA/PM acceptance dan hasil scanner terbaru. |
| `codex/test-fixture-ledger-compat` | PATCH-EQUIVALENT | Common ancestor `06bb245`; divergence `53/1`. Sembilan path fixture/helper byte-identical; tidak ada perbedaan tree pada path kandidat. |
| `codex/release-gate-verification` | PATCH-EQUIVALENT | Common ancestor `06bb245`; divergence `53/1`. Patch handoff kandidat sudah terserap. Handoff checkpoint menambahkan acceptance fixture-repair, PostgreSQL `554/5,863`, dan batas klaim release yang lebih mutakhir. |
| `codex/f2-r0-persistence-seam` | PATCH-EQUIVALENT | Common ancestor `3c92c5c`; divergence `96/3`. Audit blob ketat: keenam path (empat `app/` sealed-result/answer-set dan dua test) byte-identical; tidak ada patch material kandidat yang belum ada. |
| `codex/f2-r2-ist-result-ledger` | PATCH-EQUIVALENT | Common ancestor `8efbbcc`; divergence `85/4`. Keempat commit kandidat patch-equivalent. Service dan PostgreSQL ledger test identik; checkpoint melanjutkan dua path: fallback FK **SQLite-only** (jalur PostgreSQL tetap sama) serta reset `RefreshDatabaseState` test-only setelah `migrate:fresh`. |
| `codex/f2-r2c-ist-result-reader` | PATCH-EQUIVALENT | Common ancestor `dabe77e`; divergence `66/2`. Reader/DTO/unit test byte-identical. Satu Feature test checkpoint menambahkan reset `RefreshDatabaseState` test-harness saja; tidak ada perubahan reader kandidat yang hilang. |
| `codex/f2-s3-start-integration` | PATCH-EQUIVALENT | Common ancestor `24af4e7`; divergence `94/1`. Keempat path start-session action/test byte-identical setelah audit blob. |
| `codex/f2-s4-runtime-role-repair` | PATCH-EQUIVALENT | Common ancestor `88cf854`; divergence `170/7`. Seluruh tujuh commit kandidat patch-equivalent; 14 dari 26 path (termasuk action/model dan tiga migration entitlement) byte-identical. Dua belas path test checkpoint adalah kelanjutan fixture case-identity, ledger isolation, dan rollback/runtime-role coverage; tidak ada kontrak aplikasi atau migration kandidat yang absent. |
| `codex/f5-fixture-contract-repair` | PATCH-EQUIVALENT | Common ancestor `4bb3464`; divergence `91/3`. Page class dan Feature test identik. Checkpoint memperketat scope selector warning dengan `[aria-labelledby]` dan memperluas browser assertion untuk border yang benar-benar terlihat; ini superseding test/visual safety, bukan fitur kandidat yang hilang. |
| `codex/f5-fixture-visual-repair` | SUPERSEDED oleh v2 | Tidak diaudit terpisah sesuai instruksi; ancestor dari `codex/f5-fixture-visual-repair-v2`. |
| `codex/f5-fixture-visual-repair-v2` | PATCH-EQUIVALENT | Common ancestor `4de3fb4`; divergence `32/1`. Kedua path (fixture Blade dan browser test) byte-identical; audit blob dan patch menunjukkan tidak ada perbedaan material. |
| `codex/psychotest-result-contract` | PATCH-EQUIVALENT | Common ancestor `fb1c120`; divergence `713/2`. Kedua commit candidate patch-equivalent; checkpoint kemudian mengembangkan projector/test/handoff sehingga blob akhir berbeda, tetapi tidak ada patch candidate yang hilang. |
| `fix/assessment-case-schema-preservation-test` | PERLU MERGE | Common ancestor `8889095`; divergence `184/30`. Tip `2725740` menambah 151 baris rollback-preservation coverage pada `AssessmentCaseSchemaTest`; branch membawa 28 patch material dan 69/71 path akhir tidak identik. Perlu rekonsiliasi test/schema manual. |
| `fix/integration-billing-fixtures-wave1` | PERLU MERGE | Common ancestor `8889095`; divergence `184/13`. Sebelas patch material, 17 path berbeda; cakupan fixture billing/DASS dan callback integration. |
| `fix/integration-case-fixtures-wave1` | PERLU MERGE | Common ancestor `8889095`; divergence `184/13`. Sebelas patch material, 18 path berbeda; fixture checkout/integrated-case dan billing helper. |
| `fix/integration-case-fixtures-wave2` | PERLU MERGE | Common ancestor `8889095`; divergence `184/15`. Tiga belas patch material, 27 path berbeda; checkout HTTP/session dan result dispatch/persistence fixtures. |
| `fix/integration-case-fixtures-wave3` | PERLU MERGE | Common ancestor `8889095`; divergence `184/18`. Enam belas patch material, 36 path berbeda; residual integration/result-poll fixtures dan lifecycle isolation. |
| `fix/integration-case-fixtures-wave4` | PERLU MERGE | Common ancestor `8889095`; divergence `184/23`. Dua puluh satu patch material, 60/61 path berbeda; payment/direct-case fixtures serta profile-migration context. |
| `fix/integration-checkout-fixtures-wave3` | PERLU MERGE | Common ancestor `8889095`; divergence `184/18`. Enam belas patch material, 36 path berbeda; checkout handoff/recovery/session/confirmation fixture scope. |
| `fix/integration-database-trait-isolation` | PERLU MERGE | Common ancestor `8889095`; divergence `184/15`. Tiga belas patch material, 25 path berbeda; mixed database lifecycle trait/helper isolation. |
| `fix/integration-direct-public-fixture-wave4` | PERLU MERGE | Common ancestor `8889095`; divergence `184/28`. Kandidat tip `571c0c9` menambah shared direct-public fixture dan merapikan empat payment tests; 66/68 path akhir berbeda. |
| `fix/integration-payment-fixtures-wave2` | PERLU MERGE | Common ancestor `8889095`; divergence `184/18`. Delapan belas commit kandidat, 36 path berbeda; direct-case payment fixture setup. |
| `fix/integration-payment-fixtures-wave3` | PERLU MERGE | Common ancestor `8889095`; divergence `184/23`. Dua puluh tiga commit kandidat, 60/61 path berbeda; residual billing/payment/seed fixture cases. |
| `fix/integration-preview-component` | PERLU MERGE | Common ancestor `8889095`; divergence `184/15`. Lima belas commit kandidat, 23 path berbeda; tip `8da24ab` memperbarui collective preview case identity. |
| `fix/integration-schema-fixtures-wave4` | PERLU MERGE | Common ancestor `8889095`; divergence `184/23`. Dua puluh tiga commit kandidat, 59 path berbeda; termasuk migration assessment-case, schema rollback tests, dan payment-operations fixtures. |
| `fix/js-yaml-advisory` | PERLU MERGE | Common ancestor `8889095`; divergence `184/30`. Tip `4229cd4` mengubah `package-lock.json` untuk advisory transitive `js-yaml`; lockfile checkpoint tidak identik dan perlu review dependency tersendiri. |

No branch was merged, deleted, pushed, or promoted while producing this
ledger.
