# DATABASE_SCHEMA.md (v4.1)

Skema penuh + RLS ada di SPEC.md §3 dan migrasi `db/`. Dokumen ini merangkum relasi, indeks, dan policy per tabel. ERD tekstual:

```
branches 1─n participants 1─n test_sessions 1─n answers / kraepelin_events
branches 1─n admins                    └─1 scores
packages 1─n package_items
packages 1─n participants
participants 1─n identity_evidence
participants 1─1 identity_verifications
participants 1─1 orders(aktif) ─n payment_webhook_events
participants 1─n entitlements
participants 1─1 reports ─? psychologists
branches 1─n commission_entries / withdrawal_requests / branch_fee_rules
(instrumen, read-only) ist_items/ist_keys/ist_ge_keys/ist_norms/ist_iq_norms/
  papi_items/papi_descriptions/rmib_jobs/rmib_category_texts/
  kraepelin_configs/kraepelin_category_bounds/aspect_formulas/aspect_narratives
```

Perubahan v4.0 (payment+referral): `branches`+`ref_code text unique`+`is_default bool`; `participants`+`referral_branch_id`+`referral_source enum(link|manual|default)`; `orders.gateway_ref` menyimpan ID invoice Xendit sedangkan `external_id` Xendit adalah `orders.public_id`; order juga menyimpan `invoice_url`+`expires_at`. `payment_webhook_events` menyimpan provider, ID event logis, kedua reference, status ternormalisasi, snapshot uang, intent hash, outcome/error code, dan waktu proses—tanpa payload mentah/PII—dengan unique `(provider,event_id)`. Tabel baru `referral_visits(ref_code, branch_id, ip, ua, first_seen, participant_id null)` untuk audit atribusi first-touch. Indeks: `branches(ref_code)`, `orders(gateway_ref)` unique, `payment_webhook_events(provider,event_id)` unique, `referral_visits(participant_id)`. Gating: `entitlements.status` locked→ready hanya via webhook terverifikasi / verifikasi manual / aktivasi super_admin. Perubahan v3.0: +`hpp_config`(sub_aspek, sumber[], bobot, tunduk_knockout bool), +`hpp_thresholds`(label, min_total, syarat), +`kraepelin_norms`(grup, faktor, lo, hi, skor, kategori), +`kraepelin_group_map`, +`papi_color_bands`(dimensi, lo, hi, zona, skor), +`aspect_narratives`(sub_aspek, band, teks), +`report_closings`(label, teks), +`norm_table_versions`(dipakai di tiap `reports`). `reports` +`status(draft|reviewed|final)`, +`integration_draft`, +`integration_final`, +`closing_final`, +`norm_version`. Perubahan v2.0: `ist_norms` tanpa kolom usia (norma tunggal; kolom usia dipertahankan nullable untuk fallback GE); +`aspect_formulas` (aspek, sumber, bobot, arah/zona), +`aspect_narratives` (aspek × band → teks), +`papi_dimensions` (7 grup), `kraepelin_category_bounds(+education_level null)`.

## Indeks minimum
- `answers(session_id, item_no)` unique; `kraepelin_events(session_id, seq)` unique.
- `test_sessions(participant_id, test_type)` partial unique where status in ('created','in_progress').
- `participants(branch_id)`, `participants(test_number)` unique, `orders(status)`, `commission_entries(branch_id, period_month)`, `proctor_logs(session_id, occurred_at)`.

## RLS per kelompok tabel
- Instrumen: SELECT untuk authenticated; tulis hanya service_role.
- Data peserta (participants, sessions, answers, events, scores, reports, proctor_*): peserta → hanya barisnya (claim `participant_id`); branch_admin → join branch_id; super_admin → semua; service_role bypass.
- Keuangan (orders, commission_*, withdrawal_*): branch_admin read-only cabangnya; mutasi via API service_role saja (jangan beri UPDATE langsung).
- `payment_webhook_events`, `audit_logs`: service_role only; super_admin SELECT.

Backup: PITR (Point-In-Time Recovery) Postgres via `pg_basebackup`/WAL archiving + dump harian terenkripsi ke object storage S3-compatible (DEPLOYMENT.md).

## Fondasi schema F1

- Identitas tenant: `branches`, `admins`, `participants`, `referral_visits`, dan `consent_records`.
- Katalog tes global: `packages` menyimpan kode, nama, harga integer, mata uang `IDR`, dan sakelar `is_active`; `package_items` memetakan satu paket ke satu atau lebih jenis tes kanonis (`ist`, `papi`, `rmib`, `kraepelin`, `dass21`). Template seed tidak memiliki harga dan default nonaktif. `participants.package_id` menunjuk paket yang dipilih; hanya paket aktif, berharga positif, dan memiliki item yang boleh dipakai registrasi.
- Pembayaran/operasional: `payment_methods`, `orders`, `entitlements`, `audit_logs`, dan `outbox_messages`. Kanal `xendit` dan `manual_transfer` dibuat nonaktif; menonaktifkan kanal tidak menghapus order historis.
- DASS memakai schema PostgreSQL `dass` dengan tabel `assessments`, `responses`, dan `results`. Seluruh tabel memiliki `expires_at` untuk retensi dua tahun. SQLite testing memakai nama ekuivalen `dass_*` karena tidak mendukung schema PostgreSQL.
- Bukti verifikasi privat: `identity_evidence` menyimpan dua baris per peserta (`identity_document`, `initial_selfie`) berisi ULID publik, disk/key acak, MIME hasil inspeksi isi, byte, dimensi, dan checksum SHA-256; tidak ada nama file asli. `identity_verifications` menyimpan nama matcher, outcome/ confidence/marker, serta status tinjauan manual. Outcome matcher tidak mengubah kelayakan peserta.
- Migrasi dijalankan oleh owner terpisah. Aplikasi memakai role `psikotes_runtime` yang `NOSUPERUSER`, `NOCREATEDB`, `NOCREATEROLE`, dan `NOBYPASSRLS`.
- Policy RLS fail-closed didefinisikan di `database/schema/rls_policies.sql`: konteks kosong tidak memperoleh baris, akses cabang/peserta dibatasi oleh ID sesi, mutasi keuangan dibatasi, dan schema `dass` tidak dapat dibaca admin non-psikolog. Verifikasi negatif lintas tenant tetap harus dijalankan pada PostgreSQL nyata sebelum Task 6 dinyatakan selesai.
- Tabel bukti identitas dibuat setelah policy fondasi dan langsung mengaktifkan `FORCE ROW LEVEL SECURITY`: service boleh menulis; peserta hanya barisnya; admin cabang/staf hanya peserta cabangnya; super_admin/psikolog dapat membaca semua. Object storage tetap menjadi kontrol akses kedua dan tidak memiliki URL permanen.
- PII: identitas/kontak peserta serta IP/user-agent referral. Data sensitif: response dan hasil DASS. Data finansial: order. `audit_logs.context` hanya boleh memuat identifier dan metadata allowlist, bukan PII mentah.
