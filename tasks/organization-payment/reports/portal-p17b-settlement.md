# P17b-b — audit settlement dan recovery

Tanggal 2026-09-05. Audit dilakukan terhadap root immutable
`9c7aac8ef407f408bc9ecd30f0ded38f4e59db09`. Scope lane hanya bukti
settlement/manual/crash. Tidak ada perubahan pada application, schema, runner,
config, route, test existing, todo, atau dokumen kanonik.

## Pemetaan bukti existing

| Acceptance | Bukti existing yang diaudit | Postcondition yang benar-benar diperiksa |
|---|---|---|
| Webhook vs webhook | `tests/Postgres/AssessmentBillPaymentFinalizationTest::test_two_runtime_finalizers_commit_one_settlement_audit_and_activation_set` | Dua proses `pcntl_fork`, backend PID berbeda, parent barrier, dan kedua worker terlihat menunggu lock. Hasil tepat `settled` + `replayed`; bill paid, item settled, satu audit payment, satu audit activation, dan satu outbox. |
| Manual vs manual, keputusan sama | `tests/Postgres/AssessmentBillManualReviewTest::test_same_review_in_two_runtime_processes_has_one_settlement_audit_and_outbox` | Dua proses runtime dengan barrier/lock menghasilkan tepat `settled` + `replayed`; satu terminal audit dan satu activation outbox. |
| Manual vs manual, keputusan berlawanan | `tests/Postgres/AssessmentBillManualReviewTest::test_opposite_reviews_in_two_processes_commit_only_one_terminal_decision` | Approve dan reject overlap pada dua backend; hanya satu keputusan terminal tersimpan, satu operasi lain konflik, tepat satu audit terminal, dan maksimal satu activation outbox. |
| Revokasi saat manual review menunggu | `tests/Postgres/AssessmentBillManualReviewTest::test_committed_actor_revocation_wins_before_bill_lookup_and_mutation` | Perubahan role dikomit saat worker tertahan lock; recheck principal menolak sebelum lookup/mutasi bill. Bill tetap pending, audit dan outbox nol. |
| Aktivasi concurrent dan prerequisite berubah | `tests/Postgres/SettledAssessmentActivationTest::test_concurrent_retries_activate_and_enqueue_only_once` dan `::test_consent_withdrawal_committed_while_activation_waits_is_rechecked` | Retry concurrent menghasilkan satu activation/outbox; consent withdrawal yang menang lock membuat activation no-op dan entitlement tetap locked. |
| Crash activation/outbox | `tests/Postgres/SettledAssessmentActivationTest::test_outbox_crash_rolls_back_to_savepoint_even_if_caller_commits` | Crash sintetis saat insert outbox membatalkan entitlement/audit/outbox; retry kemudian menghasilkan satu activation lengkap. |
| Replay dan callback datang terlambat | `tests/Feature/Payments/AssessmentBillPaymentFinalizationTest::test_exact_replay_and_late_terminal_events_are_no_ops` | Replay paid exact tidak menggandakan side effect; expired/cancelled setelah paid diabaikan tanpa downgrade. |
| Callback terminal datang sebelum paid | `tests/Feature/Payments/AssessmentBillWebhookDispatchTest::test_terminal_bill_cannot_be_revived_or_changed_by_later_status` | Expired/cancelled lebih dulu tidak dapat dihidupkan kembali oleh paid atau diubah oleh terminal lain; allocation/outbox tetap kosong. `::test_exact_terminal_replay_is_idempotent` juga membuktikan claim terminal exact hanya satu. |
| Rollback dispatcher dan recovery | `tests/Feature/Payments/AssessmentBillWebhookDispatchTest::test_unexpected_finalizer_failure_rolls_back_event_claim_and_all_payment_effects` | Kegagalan finalizer membatalkan event claim, bill, allocation, audit, dan outbox; retry event yang sama kemudian applied satu kali. |
| Crash item kelima | `tests/Feature/Payments/AssessmentBillPaymentFinalizationTest::test_failure_while_saving_fifth_item_rolls_back_all_payment_effects` serta `tests/Feature/Payments/AssessmentBillManualReviewTest::test_fifth_item_failure_rolls_back_bill_verifier_allocations_audit_activation_and_outbox` | Kedua writer memiliki bukti rollback aplikasi, tetapi sebelumnya hanya pada SQLite feature runtime dan belum membuktikan retry setelah crash item kelima pada PostgreSQL. |

Webhook dan manual review terhadap **bill yang sama** bukan pasangan dua writer
valid. Kontrak manual mengharuskan payment method `manual_transfer`,
`gateway_ref === null`, `invoice_url === null`, dan fingerprint proof tepat.
Kontrak webhook mengharuskan `gateway_ref` non-null yang cocok dengan provider
event. Karena identitas channel saling eksklusif, membuat keduanya sama-sama
valid pada satu bill akan mengarang state yang ditolak production. Race pada dua
bill berbeda tidak memperebutkan terminal state yang sama; serialisasi organisasi
sudah diamati oleh masing-masing race writer di atas.

## Bukti baru PostgreSQL

File baru `tests/Postgres/OrganizationBillingSettlementRecoveryTest.php`
menutup gap item-kelima pada runtime PostgreSQL non-owner/NOBYPASSRLS:

- `test_webhook_fifth_allocation_crash_rolls_back_and_retry_settles_everything`
  membangun satu bill webhook dengan 10 attempt. Listener Eloquent melempar tepat
  pada save allocation kelima. Sesudah exception, bill masih pending; `paid_at`,
  verifier, dan rejection null; seluruh 10 item belum settled; seluruh attempt
  PROVISIONED dan entitlement locked; audit/outbox nol. Setelah listener dilepas,
  event identik di-retry dan menghasilkan `settled`, 10 allocation, 10 activation,
  10 attempt READY, 10 entitlement ready, 10 outbox, satu payment audit, dan 10
  activation audit. Seluruh timestamp allocation tepat sama dengan `paid_at`.
- `test_manual_fifth_allocation_crash_rolls_back_and_retry_settles_everything`
  mengulang postcondition yang sama untuk manual approve dengan admin dan proof
  sintetis persisted. Retry review yang sama menyelesaikan 10 allocation dan 10
  activation, dengan tepat satu payment audit beraktor admin.

Run final menggunakan archive root tersebut, vendor fisik dengan SHA256
`composer.lock` identik
`44AA7EA181ECF0ACCDD18A05AE5DA39BC8D9016C88431BFE9AEBFCB536720E16`,
tanpa `.env`, dan konfigurasi test copy yang hanya menunjuk file baru. Runner
membuat PostgreSQL/network internal disposable tanpa published port dan
membersihkan container/network sendiri. Hasil final: **2 tes / 66 assertions**,
tanpa error/failure/skip. Pint, PHP lint, PHPStan level 7 terfokus, dan diff-check
lulus. PHPStan memakai environment testing sintetis dengan SQLite memory; tidak
membaca `.env`. SHA256 test final:
`92AA482E1C4347524419B9FAB8D6C33FBAB4549984AF62617F8BD1A898F7779E`.

## Batas dan gap tersisa

- Dua test baru menyuntik exception application setelah empat save sukses dan
  saat save kelima. Ini membuktikan transaction rollback dan retry canonical pada
  PostgreSQL, bukan simulasi hard-kill OS tepat di tengah commit/WAL.
- Race dua proses tidak diduplikasi dalam file baru. Bukti overlap berasal dari
  tiga test PostgreSQL existing yang mengamati backend PID dan lock wait; run
  focused lane ini hanya menjalankan dua recovery test baru.
- Reorder dan event-claim recovery telah dibuktikan pada feature dispatcher,
  bukan diulang sebagai callback HTTP/provider atau dua proses PostgreSQL.
- Crash claim issuance merupakan ownership lane reservation/issuance, bukan
  settlement lane ini. Tidak ada provider, invoice, notifier, atau outbound nyata.

Kesimpulan audit: concurrency writer settlement yang valid sudah memiliki bukti
dua proses; gap PostgreSQL item-kelima/retry telah ditutup. Hard process death dan
callback-dispatch concurrency lintas proses tetap batas eksplisit, bukan klaim
yang dianggap lulus oleh increment ini.
