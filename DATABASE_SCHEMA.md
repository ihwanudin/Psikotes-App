# DATABASE_SCHEMA.md (v2.0)

Skema penuh + RLS ada di SPEC.md §3 dan migrasi `db/`. Dokumen ini merangkum relasi, indeks, dan policy per tabel. ERD tekstual:

```
branches 1─n participants 1─n test_sessions 1─n answers / kraepelin_events
branches 1─n admins                    └─1 scores
participants 1─1 orders(aktif) ─n payment_webhook_events
participants 1─n entitlements
participants 1─1 reports ─? psychologists
branches 1─n commission_entries / withdrawal_requests / branch_fee_rules
(instrumen, read-only) ist_items/ist_keys/ist_ge_keys/ist_norms/ist_iq_norms/
  papi_items/papi_descriptions/rmib_jobs/rmib_category_texts/
  kraepelin_configs/kraepelin_category_bounds/aspect_formulas/aspect_narratives
```

Perubahan v4.0 (payment+referral): `branches`+`ref_code text unique`+`is_default bool`; `participants`+`referral_branch_id`+`referral_source enum(link|manual|default)`; `orders`+`gateway`+`gateway_ref`(=external_id Xendit)+`invoice_url`+`expires_at`; tabel baru `referral_visits(ref_code, branch_id, ip, ua, first_seen, participant_id null)` untuk audit atribusi first-touch. Indeks: `branches(ref_code)`, `orders(gateway_ref)` unique, `referral_visits(participant_id)`. Gating: `entitlements.status` locked→ready hanya via webhook terverifikasi / verifikasi manual / aktivasi super_admin. Perubahan v3.0: +`hpp_config`(sub_aspek, sumber[], bobot, tunduk_knockout bool), +`hpp_thresholds`(label, min_total, syarat), +`kraepelin_norms`(grup, faktor, lo, hi, skor, kategori), +`kraepelin_group_map`, +`papi_color_bands`(dimensi, lo, hi, zona, skor), +`aspect_narratives`(sub_aspek, band, teks), +`report_closings`(label, teks), +`norm_table_versions`(dipakai di tiap `reports`). `reports` +`status(draft|reviewed|final)`, +`integration_draft`, +`integration_final`, +`closing_final`, +`norm_version`. Perubahan v2.0: `ist_norms` tanpa kolom usia (norma tunggal; kolom usia dipertahankan nullable untuk fallback GE); +`aspect_formulas` (aspek, sumber, bobot, arah/zona), +`aspect_narratives` (aspek × band → teks), +`papi_dimensions` (7 grup), `kraepelin_category_bounds(+education_level null)`.

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
