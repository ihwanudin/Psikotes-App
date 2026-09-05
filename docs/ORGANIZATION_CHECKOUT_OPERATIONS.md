# Runbook operasi checkout organisasi

> **Status: DRAFT / PROVISIONAL (P18).** Dokumen ini belum menutup acceptance
> P18 dan belum merupakan izin aktivasi. Runbook harus dicocokkan kembali dengan
> bukti runtime browser P17c setelah P17c selesai. Seluruh switch checkout dan
> writer tetap **OFF** sampai cutover disetujui secara eksplisit.

## Tujuan dan batas

Runbook ini adalah panduan operator untuk checkout mandiri dan tagihan kolektif
organisasi pada kontrak `checkout-v2`. Kontrak produk dan bukti implementasi
tetap berada di:

- [SPEC organisasi](../SPEC-organization-billing.md);
- [SPEC checkout terintegrasi](../SPEC-integrated-checkout.md);
- [kontrak checkout organisasi](ORGANIZATION_CHECKOUT_CONTRACT.md);
- [kontrak reservasi tagihan](ASSESSMENT_BILL_RESERVATION.md); dan
- [catatan validasi](ORGANIZATION_CHECKOUT_VALIDATION.md).

Dokumen ini tidak mengizinkan edit langsung database, penghapusan histori,
reinvoice otomatis, deploy, migrasi database aktif, pengiriman notifikasi, atau
transaksi provider nyata. Jangan salin secret, token, bukti transfer, URL
sementara, atau data pribadi ke log maupun tiket insiden.

## Invarian yang tidak boleh dilanggar

1. Satu charge hanya boleh menjadi anggota satu bill. Claim tetap melekat pada
   bill yang sudah reserved, issuing, unknown, pending, expired, rejected, atau
   paid. Status terminal bukan izin membuat bill baru.
2. Timeout atau hasil provider yang tidak pasti berarti `unknown`, bukan gagal.
   Gunakan referensi bill dan intent yang sama untuk lookup; jangan melakukan
   POST create kedua.
3. `expired` dan `rejected` tidak melakukan auto-release maupun auto-reinvoice.
   Pelepasan atau penagihan ulang di masa depan hanya boleh melalui prosedur
   rekonsiliasi/manual baru yang ditinjau, bukan perubahan baris ad hoc.
4. Settlement satu bill bersifat atomik: bill, seluruh item, audit, dan efek
   aktivasi yang sah harus berhasil bersama atau seluruh transaksi di-rollback.
   Pembayaran parsial dan kelebihan bayar tidak dialokasikan otomatis.
5. `paid` tidak melewati consent. Attempt yang belum memenuhi consent tetap
   terkunci walaupun itemnya sudah settled.
6. Hanya SuperAdmin ONCAM aktif yang boleh melihat bukti dan memutuskan transfer
   manual. Cabang tidak boleh memverifikasi pembayarannya sendiri.
7. Marker sumber `checkout-v2` adalah cutover satu arah secara operasional.
   Switch OFF harus fail closed; tidak boleh jatuh kembali ke provisioning v1.

## Pemilik keputusan

| Peran | Tanggung jawab |
| --- | --- |
| Incident commander | Membuka insiden, membatasi perubahan, menentukan pause/resume, dan menyetujui eskalasi. |
| Operator pembayaran | Membaca status bill/intent/provider melalui alat yang disetujui dan menjalankan rekonsiliasi canonical. |
| SuperAdmin reviewer | Memeriksa bukti transfer melalui URL sementara dan memilih APPROVE atau REJECT beserta kode penolakan yang tersedia. |
| Engineer aplikasi | Menilai invariant, log/audit/outbox, kompatibilitas rollback, dan menyiapkan perbaikan melalui review kode. |
| Pemilik produk/operasi | Menyetujui sumber organisasi yang ikut cutover dan komunikasi kepada cabang/peserta. |

Tidak satu pun peran boleh memperbaiki state dengan `UPDATE` manual. Jika alat
operasional canonical belum mempunyai wiring yang ditinjau, eskalasikan; jangan
menggantikannya dengan console interaktif pada produksi.

## Triage berdasarkan state bill

| State | Arti operasional | Tindakan aman | Larangan |
| --- | --- | --- | --- |
| `reserved` | Bill dan item sudah mengklaim charge; provider belum dipanggil. | Pastikan metode pembayaran dan intent sesuai; jalankan issuer canonical hanya bila wiring/aktivasi telah disetujui. | Jangan buat bill pengganti atau melepas item. |
| `issuing` | Intent durable telah diklaim; hasil create belum dipersist. | Bekukan retry create dan telusuri `messageId`, public reference, audit, serta status outbox. Jika hasil tidak dapat dibuktikan, perlakukan sebagai unknown dan gunakan lookup intent yang sama. | Jangan POST create ulang dengan referensi baru. |
| `unknown` | Hasil create provider tidak pasti atau lookup belum menemukan hasil canonical. | Jalankan rekonsiliasi lookup yang bounded, leased, dan cooldown-aware pada intent yang sama setelah wiring disetujui. Ulangi hanya sesuai lease/cooldown. | Jangan menandai gagal, expired, atau reserved; jangan reinvoice. |
| `pending` | Invoice atau bukti manual menunggu settlement/review. | Cocokkan reference, metode, amount, currency IDR, dan expiry; tunggu event canonical atau keputusan reviewer. | Jangan menganggap screenshot/callback mentah sebagai paid. |
| `expired` | Invoice kedaluwarsa tanpa settlement yang sah. | Pertahankan bill, item, charge, intent, audit, dan reference. Buat kasus rekonsiliasi bila ada klaim pembayaran terlambat. | Jangan auto-release, auto-reinvoice, atau menurunkan pembayaran sah yang datang terlambat. |
| `rejected` | Reviewer manual menolak bukti secara terminal. | Pertahankan histori dan kode penolakan; arahkan kasus berikutnya ke prosedur bisnis/manual yang disetujui. | Jangan membebaskan charge atau membuat tagihan baru otomatis. |
| `paid` | Settlement canonical sudah diterapkan atomik. | Verifikasi semua item settled dan akses hanya aktif untuk attempt yang consent-nya lengkap. | Jangan downgrade oleh callback expired/rejected yang terlambat. |

Respons API/UI yang generik tidak cukup untuk menentukan state. Operator harus
menggunakan public reference bill sebagai korelasi aman dan mengecek state
persisted melalui permukaan read-only yang disetujui.

Bill `expired` atau `rejected` tetap terminal dan claimed. Implementasi sekarang
menolak event `paid` baru pada kedua state tersebut, kecuali replay terminal yang
persis sama. Klaim pembayaran terlambat hanya membuka kasus eskalasi; belum ada
recovery, release, atau reinvoice canonical dan operator tidak boleh menjanjikan
aktivasi sampai prosedur tersebut dirancang serta ditinjau.

## Claim dan penerbitan invoice

Implementasi canonical memisahkan tiga fase:

1. `ClaimAssessmentBillInvoice` mengunci organisasi, bill, item, attempt,
   participant, package, charge, dan metode; memvalidasi snapshot; menulis intent
   outbox; lalu mengubah `reserved` menjadi `issuing` dalam satu transaksi.
2. `IssueAssessmentBillInvoice` mengonsumsi permit di luar transaksi/RLS
   authority boundary, melakukan operasi provider satu kali, dan melakukan
   lookup dengan merchant reference yang sama bila hasil create perlu
   dikonfirmasi.
3. `PersistAssessmentInvoiceOutcome` melakukan late-state fence. Hasil pasti
   menjadi `pending`; hasil tidak pasti menjadi `unknown`. Konflik tidak boleh
   diperbaiki dengan menimpa state.

Metode `manual_transfer` tidak menerbitkan invoice provider dan tidak membuat
intent issuance. Jangan menjalankan issuer Xendit untuk bill manual.

Checklist sebelum issuer yang sudah disetujui dijalankan:

- checkout source, organization, payer, package, harga snapshot, total, jumlah
  item, currency IDR, dan payment method cocok;
- bill masih memiliki seluruh charge yang sama dan tidak ada item settled;
- tidak ada intent lain untuk aggregate bill yang sama;
- provider client, worker, dan observability telah divalidasi di lingkungan
  nonaktif/sintetis; dan
- incident commander belum memasang pause.

## Rekonsiliasi state `issuing` atau `unknown`

`CoordinateAssessmentInvoiceReconciliation` saat ini merupakan coordinator
internal bounded dengan reservation lease, validasi ulang setelah lease, batas
lookup, dan cooldown. Kode secara eksplisit belum mendaftarkan command, job,
route, atau scheduler. Karena itu, **jangan** memakai command
`payments:reconcile-xendit` sebagai pengganti: command tersebut melayani alur
pending Xendit lain, bukan pemulihan intent invoice organisasi ini.

Setelah wiring khusus direview dan diaktifkan melalui perubahan terpisah:

1. Pause ingress/issuer untuk scope terdampak bila ada risiko create bersamaan.
2. Catat hanya ID aman: public reference bill, hashed provider reference,
   `messageId`, organization ID, state, dan waktu UTC. Jangan catat invoice URL.
3. Pastikan hanya satu outbox `assessment.bill.invoice-issuance` yang cocok,
   attempts/processed state sesuai, dan snapshot hash tetap sama.
4. Reserve lease melalui coordinator canonical. Jangan melewati validasi lease
   atau mengulang lookup sebelum cooldown.
5. Lookup provider memakai merchant reference, amount, dan currency dari permit
   yang sama. Bila tidak ada hasil yang cocok, state tetap `unknown`.
6. Bila ditemukan invoice yang cocok, late-state fence memindahkan bill ke
   `pending` dan menyimpan reference/URL/expiry secara atomik.
7. Hasil `validationRejected` atau `recoveryRequired` adalah eskalasi engineer;
   jangan retry tanpa diagnosis invariant.
8. Setelah selesai, verifikasi audit `invoice_unknown` atau `invoice_issued`,
   lease sudah dilepas, dan tidak ada create kedua.

## Rekonsiliasi transfer manual

1. Reviewer membuka bill melalui resource review yang disetujui dan memastikan
   dirinya SuperAdmin ONCAM aktif.
2. Bukti hanya dibuka melalui temporary URL yang tercatat audit. URL harus masih
   berlaku dan fingerprint bukti harus cocok dengan akses terbaru.
3. Cocokkan beneficiary, nominal penuh, currency IDR, identitas bill, dan indikasi
   duplikasi. Jangan mengunduh atau menyalin bukti ke tiket.
4. Pilih `APPROVE` bila seluruh pemeriksaan lolos. Pilih `REJECT` hanya dengan
   salah satu kode canonical: `AMOUNT_MISMATCH`, `UNREADABLE_PROOF`,
   `WRONG_BENEFICIARY`, `DUPLICATE_PROOF`, atau `OTHER_UNVERIFIABLE`.
5. Fingerprint stale/ambigu, URL kedaluwarsa, actor tidak sah, atau state berubah
   harus fail closed. Buka ulang bill dan mulai pemeriksaan baru; jangan menimpa
   fingerprint maupun status.
6. APPROVE harus menghasilkan `paid` dan settlement seluruh item dalam satu
   transaksi. REJECT menghasilkan `rejected` tanpa item settled. Replay keputusan
   yang identik boleh idempotent; keputusan berbeda harus ditolak.

## Verifikasi konsistensi allocation

Jalankan pemeriksaan read-only yang ditinjau setelah setiap settlement atau
insiden. Satu bill konsisten hanya bila seluruh syarat berikut benar:

- `item_count` sama dengan jumlah item aktual, semua item tetap menunjuk charge
  yang benar, dan jumlah amount item sama persis dengan amount bill;
- bill `paid` mempunyai `paid_at`, dan setiap item mempunyai `settled_at` yang
  sama dengan waktu pembayaran canonical;
- bill selain `paid` tidak mempunyai settlement item (kecuali sedang dianalisis
  sebagai invariant breach);
- setiap charge tetap terhubung ke paling banyak satu bill dan payer,
  participant, organization, package, amount, currency, price snapshot, serta
  policy snapshot cocok sepanjang rantai;
- audit pembayaran/manual-review tepat satu secara semantik, dan outbox/event
  duplikat tidak menggandakan efek; serta
- attempt berbayar hanya aktif bila consent psikotes dan DASS-21 lengkap;
  otherwise tetap terkunci tanpa membatalkan status paid.

Jika satu syarat gagal, klasifikasikan sebagai **allocation invariant breach**:
pause writer untuk scope terdampak, simpan bukti read-only, dan eskalasi ke
engineer. Jangan mengesahkan sebagian item, mengubah `item_count`, atau
menjalankan ulang finalizer secara manual.

## Observability dan eskalasi

Dashboard/alert minimal harus menampilkan agregat tanpa PII:

- jumlah dan usia bill per state, khususnya `issuing` dan `unknown`;
- jumlah lease reserved/validated/rejected, lookup, hasil issued/unknown,
  `recoveryRequired`, dan cooldown;
- kegagalan provider menurut kelas operasi tanpa payload sensitif;
- mismatch amount/currency/reference, callback conflict/rejected/duplicate, dan
  callback terlambat setelah paid;
- bill paid dengan item tidak settled, bill non-paid dengan item settled,
  mismatch item count/total, dan attempt paid yang masih menunggu consent; serta
- backlog/failure audit dan outbox.

Audit `checkout.confirmed` dapat memiliki beberapa generation yang sah setelah
withdrawal/re-consent atau rotasi dokumen. Jangan menghapus atau menyatukannya
sebagai duplikat. Audit tersebut dan `checkout.consent_reaccepted` hanya dapat
dibaca role service; dashboard dan eskalasi SuperAdmin harus memakai metrik
agregat/redacted dari service tanpa tipe, versi, timestamp consent, atau metadata
DASS pada tiket maupun respons operator.

| Tingkat | Contoh | Respons |
| --- | --- | --- |
| SEV-1 | Cross-tenant exposure, double settlement, pembayaran sah hilang, atau banyak bill paid tidak konsisten. | Pause seluruh writer checkout, pertahankan marker/history, panggil incident commander, security bila relevan, payment owner, dan engineer segera. |
| SEV-2 | Satu scope mengalami issuing/unknown berkepanjangan, provider conflict, recoveryRequired, atau allocation invariant breach tanpa paparan lintas tenant. | Pause scope/sumber terkait, hentikan create baru, rekonsiliasi bounded, eskalasi engineer dan operasi pembayaran. |
| SEV-3 | Invoice expired/rejected normal, consent tertunda, atau masalah UI tanpa perubahan state. | Tangani melalui antrean operasi; jangan reinvoice/release otomatis. |

Tiket insiden mencatat waktu UTC, environment, public reference, organization ID,
hashed provider reference, `messageId`, state sebelum/sesudah, decision/result,
dan correlation ID. Redact data peserta, signature, token, URL bukti/invoice, dan
payload provider.

## Urutan aktivasi yang aman

Aktivasi adalah pekerjaan terpisah yang memerlukan change approval. Urutan
berikut bersifat fail-closed:

1. Selesaikan P17c, cocokkan bukti browser ke
   [catatan validasi](ORGANIZATION_CHECKOUT_VALIDATION.md), lalu review ulang
   runbook ini. P18 baru boleh ditandai selesai sesudahnya.
2. Pastikan backup/restore, compatibility aplikasi-skema, RLS, audit/outbox,
   payment method, provider fake/sandbox, dashboard, alert, dan on-call telah
   dibuktikan. Semua switch produksi masih OFF.
3. Terapkan versi aplikasi dan perubahan skema kompatibel melalui prosedur
   deployment yang disetujui; jangan membuat marker sumber dulu.
4. Jalankan smoke/read-only dan tes sintetis. Pastikan legacy source yang belum
   opt-in tetap tidak berubah.
5. Route HTTP sudah terdaftar tetapi inert selama gate/writer OFF. Aktifkan
   komponen internal paling dalam secara bertahap: audit dan reconciliation yang
   sudah mempunyai wiring resmi, kemudian confirmation transport. Jangan
   menyalakan payment writer sebelum fake/sandbox provider, outbound control,
   credential isolation, dan hold point operator terbukti; writer payment dapat
   memanggil provider secara sinkron. Nyalakan `writer_enabled` terakhir dan
   hentikan bila observability tidak sehat.
6. Aktifkan `checkout_handoff` dan `checkout_session` hanya setelah consumer
   hilir siap; aktifkan gate checkout utama setelah seluruh jalur tertutup telah
   diverifikasi.
7. Drain request dan worker sebelum perubahan registry. Set
   `IntegrationSource.contract_version = checkout-v2` hanya untuk sumber yang
   disetujui secara eksplisit, satu canary terlebih dahulu.
8. Jalankan canary sintetis/read-only dan pantau issuing, unknown, allocation,
   audit, outbox, consent, dan isolasi tenant sebelum menambah sumber berikutnya.

Tidak ada aktivasi global implisit. Organization/client/source lain tetap di
kontraknya sampai opt-in terpisah disetujui.

## Rollback dan containment

Rollback yang aman adalah rollback **aplikasi/traffic**, bukan penghapusan state:

1. Pause ingress dan kedua payment/confirmation writer untuk scope terdampak;
   hentikan claim/create baru, lalu drain request dan worker yang sedang berjalan.
2. Simpan marker `checkout-v2`, bill, item, charge, intent, lease, audit, outbox,
   consent, dan histori. Jangan delete marker, jangan ubah kembali ke v1, jangan
   menjalankan migration down yang menghapus data.
3. Inventarisasi bill in-flight. Selesaikan atau karantina `issuing`/`unknown`
   melalui rekonsiliasi canonical sebelum mengganti versi aplikasi.
4. Rollback hanya ke versi aplikasi yang terbukti kompatibel dengan skema dan
   mampu fail closed terhadap marker v2. Jika tidak ada versi kompatibel, tetap
   dalam containment OFF dan perbaiki maju.
   Jangan menyalakan confirmation writer pada versi sebelum dukungan generation
   audit v2, dan jangan rollback migration/policy privacy audit consent.
5. Verifikasi source ber-marker v2 mengembalikan unavailable/tertutup, bukan
   memasuki endpoint v1. Sumber yang belum opt-in tidak boleh terpengaruh.
6. Setelah rollback, ulangi pemeriksaan allocation, audit/outbox, provider lookup,
   consent lock, dan isolasi tenant. Resume memerlukan change approval baru.

Menonaktifkan checkout setelah cutover dapat membuat layanan sementara tidak
tersedia; itu perilaku yang benar. Availability tidak boleh dipulihkan dengan
fallback legacy yang dapat menggandakan attempt atau tagihan.

## Gate serah-terima P18

Dokumen ini tetap provisional sampai seluruh kondisi berikut dipenuhi:

- P17c browser desktop/mobile/keyboard selesai pada database disposable dan
  origin test-only, dengan fake `PaymentProvider`, outbound-deny, tanpa credential
  nyata, serta bukti sintetis dicatat tanpa token;
- hasil P17c cocok dengan state matrix, consent lock, expiry, reload, IDOR, dan
  settlement kolektif dalam runbook ini;
- pemilik operasi, pembayaran, aplikasi, dan produk menyetujui jalur eskalasi,
  aktivasi, containment, dan rollback;
- tidak ada command/job/scheduler yang diasumsikan aktif tanpa wiring dan review;
  serta
- verifikasi link dan `git diff --check` lulus.

Sampai gate tersebut ditutup secara eksplisit, jangan mencentang acceptance P18
dan jangan mengaktifkan source, feature gate, writer, provider, atau outbound.
