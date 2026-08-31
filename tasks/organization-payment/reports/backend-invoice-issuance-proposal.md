# Proposal P10b: claim dan penerbitan invoice asesmen

Status: **DRAFT untuk keputusan koordinator; belum izin implementasi.**
Tanggal: 2026-09-01. Baseline lane a1808f1; P10a diterima root ce9b3a0.
Scope increment ini hanya proposal dan laporan backend. P9 publik, writer P10b,
rekonsiliasi P10c, settlement P11, serta aktivasi endpoint tetap belum tersedia.

## Rekomendasi untuk direview

Gunakan bill existing dan satu record outbox khusus sebagai intent durable.
Pisahkan `reserved -> issuing` dari konsumsi izin POST. Hanya transaksi yang
mengubah outbox `pending/attempts=0 -> processing/attempts=1` boleh memperoleh
izin memanggil create sekali. Setelah konsumsi izin commit, semua retry hanya
boleh lookup/reconcile, sekalipun worker mati sebelum mengirim POST.

Rekomendasi tidak memerlukan schema baru: status bill, outbox payload, unique
deduplication_key dan counter attempts sudah tersedia. Ini membutuhkan kontrak
topic dan consumer khusus; jangan memakai retry consumer notifikasi existing.
Setujui secara eksplisit konservatisme ini: crash pada celah sebelum POST bisa
meninggalkan bill unknown yang tidak pernah mempunyai invoice. Tidak ada cara
aman menebak apakah POST belum terkirim dari umur claim atau hasil lookup kosong.

Reuse `PaymentProvider::createInvoice` dibatasi satu pemanggilan setelah izin
terpakai; hasilnya wajib dikonfirmasi `lookupInvoice` P10a sebelum persist.
Tidak reuse `CreateRegistrationInvoice` atau policy retry lima menitnya.

## Audit sumber existing

| Sumber | Fakta yang diperiksa | Implikasi |
| --- | --- | --- |
| migration 2026_08_31_000200, AssessmentBill | status CHECK reserved/issuing/unknown/pending/paid/expired/rejected; model masih string, enum AssessmentBillStatus belum ada | Tidak perlu menambah status SQL untuk P10b. Enum kelak opsional tanpa memperluas state. |
| assessment_bills | public_reference unik AB_ + ULID; gateway_ref nullable unik; amount BIGINT positif, IDR, item_count positif | Jangan generate reference per job; uniqueness gateway_ref bukan idempotensi POST provider. |
| assessment_bills | Tidak punya metadata/claim token/claimed_at/lease columns | Jangan mengarang field atau menggunakan rejection_reason sebagai ledger. |
| migration 2026_08_31_000300 | bill_items charge_id unik, FK komposit ke bill/charge/payer; self tepat satu item | Satu charge tidak dapat dilepas/dimasukkan bill baru saat retry; jumlah total tetap harus diperiksa aplikasi. |
| assessment_charges | price_snapshot/policy_snapshot, amount/base/consultation, currency dan free_settled_at | Harga dari snapshot reservasi, bukan menghitung ulang katalog ketika menerbitkan invoice. |
| outbox migrations 000200 dan 000900 tanggal 2026_08_25 | message_id unik; deduplication_key nullable unik; JSON payload; pending/processing/processed/failed; attempts, timestamps, expires_at | Intent/izin sekali pakai dapat disimpan tanpa migration, dengan validasi topic-specific. |
| rls_policies.sql dan migration secure_assessment_billing | outbox service-only FORCE RLS; write bill/items/charge service-only; role pengguna sebagian read-only | API internal harus menolak context pengguna sebelum melakukan query/write. |
| ReserveAssessmentBill P7b | mutex organisasi sebelum selection/charge; bill reserved + items + audit atomik; replay mempertahankan status/harga bahkan kanal OFF | Replay reservasi bukan otorisasi menerbitkan invoice. |
| ActivateSettledAssessment P8b | organization -> bill -> items -> attempt -> participant -> charge | Writer invoice/finalizer tidak boleh mengunci bill sebelum organisasi. |
| RlsContextRunner | run membuka transaksi, helper dapat berada dalam outer transaction; context dipulihkan sebelum commit callback | Return helper tidak membuktikan outer commit; HTTP wajib di luar context/transaksi. |
| config/queue.php | database/redis after_commit=false; default retry_after 90 detik dari config, bukan bukti runtime aktif | Gunakan afterCommit per job; jangan mengubah konfigurasi global untuk fitur ini. |
| dispatcher existing | topic participant.activation dan psychotest.assessment-event difilter eksplisit | Topic invoice harus tetap tidak dikonsumsi dispatcher lama. Tidak mengaktifkan consumer baru sekarang. |

Audit ini membaca file, bukan memeriksa atau memigrasi DB aktif. ADR-004/005 tetap
berlaku: profil parsial bukan akses, snapshot funding awal tidak diubah writer
invoice. Uniqueness/FK tidak menegakkan seluruh invariant lifecycle aplikasi.

## Kontrak intent dan hasil internal

Usulan topic: `assessment.bill.invoice-issuance`, aggregate_type AssessmentBill,
aggregate_id bill ID. Dedup key tetap: SHA-256 atas purpose versi + organization
ID + bill ID. message_id ULID dibuat hanya sekali sebagai identitas intent,
bukan credential, merchant reference baru, atau izin POST dari browser.

Payload versi 1 berasal seluruhnya dari DB/server: organizationId, billId,
publicReference, paymentMethodId/providerCode, amount/currency, itemCount,
selectionHash/requestHash, daftar item/charge/attempt IDs terurut beserta nominal,
dan hash canonical snapshot tersebut. Simpan requestedExpiresAt UTC dan
description deterministik tanpa nama/email/telepon. Usulan durasi awal 24 jam
mengikuti existing registration; **durasi perlu keputusan review**, bukan nilai
yang sudah dikonfigurasi/diimplementasikan. requestedExpiresAt ditetapkan sekali
saat claim; bill.expires_at baru diisi expiry aktual dari hasil terverifikasi.

Snapshot immutable selama lifecycle. Reload dan cocokkan dengan DB sebelum
konsumsi izin dan sebelum persist hasil. Hash adalah pemeriksa konsistensi,
bukan pengganti authorization. Job hanya membawa message_id; tidak membawa model
Eloquent, principal browser, money, URL, secret, atau snapshot yang dipercaya dari
queue payload. Payload/schema/topic/key absent, invalid, atau berbeda: fail-closed,
tanpa backfill atau membuat intent pengganti.

Hasil action internal dibedakan jelas: claimed/queued, existing-pending,
in-flight, recovery-required, blocked/conflict/terminal. Tidak mengembalikan
"invoice ready" untuk issuing/unknown. DTO claim bukan hasil pembayaran.
Error/log memakai kode generik + ID internal minimum, tanpa provider payload,
credential atau profil peserta. Public response/projection bukan scope ini.

## State dan izin sekali pakai

| State bill + outbox | Aksi yang diizinkan | Yang dilarang |
| --- | --- | --- |
| reserved + belum ada intent | Guard sukses: atomik issuing + outbox pending/0 + audit claim | HTTP, hak tes, atau outbox notifikasi |
| issuing + pending/0, snapshot cocok | Satu worker memenangkan processing/1 dalam transaksi, kemudian commit | Menjalankan POST sebelum commit atau dari worker yang kalah |
| issuing + processing/1 | Pemegang izin dalam invocation yang menang boleh satu create; duplikat hanya in-flight | Mereset attempts atau memberi izin baru karena lease habis |
| issuing/unknown + izin sudah terpakai | Lookup reference tetap; hasil exact tunggal boleh pending melalui persist guard | Create ulang, generate reference baru, menghapus charge/items |
| pending + gateway_ref/URL lengkap konsisten | Replay hasil existing tanpa create | Mengganti gateway_ref, total atau expiry untuk memperpanjang tagihan |
| paid/expired/rejected | No-op terminal atau penolakan generik; accounting conflict dicatat | Reinvoice, rewind ke issuing/pending, mengubah settled_at/paid_at |
| state/intent/field yang tidak konsisten | Recovery-required/blocked, tidak ada POST | Menebak data hilang atau menganggapnya reserved baru |

Outbox `attempts=1` berarti **izin create telah dibelanjakan**, bukan bukti POST
atau pembayaran. Tidak ada transisi topic ini dari processing/failed kembali ke
pending/0. Unknown menyimpan outbox failed/1 dengan kode recovery-required;
pending valid menandai processed/1. Status outbox tidak menggantikan status bill.
State issuing tanpa intent sah tidak diperbaiki otomatis.

Record pending/0 yang belum pernah mengonsumsi izin dapat didispatch ulang bila
broker gagal; ini berbeda dari mengulangi POST. Pending/0 yang guard-nya kini
gagal tidak boleh mengonsumsi izin: tetap blocked tanpa HTTP dan audit generik,
tanpa reset bill atau invoice lain. Dispatcher harus bounded/backoff, bukan
busy loop; jadwal dan kebijakan unblock operasional direview terpisah.

## Urutan transaksi dan lock

1. **Claim P10b-a**, dipanggil hanya dalam service context yang dibentuk caller
   terautentikasi. Ambil organisasi berdasarkan scope tepercaya; lock organisasi
   dahulu, reload bill dengan organization_id yang sama, lalu items terurut ID.
   Bila memeriksa registry, lock client/source terurut sesudah organisasi sebelum
   bill; semua writer satu organisasi tetap diserialisasi mutex yang sama.
2. Lock attempt dan participant terurut sesuai P8b; package terurut ID untuk
   guard aktif/scope sebagaimana P7, lalu charge. Metode pembayaran sesudah
   child locks, kemudian record outbox. Tidak mengambil admin
   lock setelah organisasi. Tidak memanggil P7b dari keadaan bill sudah terkunci.
   Lock package tidak berarti repricing: nominal/test types tetap dari snapshot
   charge existing, bukan menghitung harga katalog ulang.
3. Guard semua item dahulu; set issuing, insert intent dan audit secara atomik.
   Konflik unique memicu reload+compare seluruh intent, bukan insertOrIgnore yang
   diartikan sebagai sukses. Gagal satu item/audit/insert membatalkan semuanya.
4. **Dispatch sesudah outer commit** memakai afterCommit per job pada koneksi
   yang sama. Outbox menjadi sumber durable bila commit berhasil tetapi callback
   atau broker mati. Duplicate dispatch boleh; duplicate POST tidak boleh.
   P10b-a sendiri belum memasang dispatch/hook/caller produksi.
5. **Job P10b-b** mulai hanya jika RLS context null dan DB transactionLevel=0.
   Dalam transaksi service baru, gunakan urutan lock yang sama, cocokkan intent,
   reload guard lalu consume pending/0 -> processing/1. Hanya return invocation
   yang berhasil mengubah row memperoleh izin in-memory. Jangan serialisasikan
   izin itu ke job retry; invocation berikutnya selalu membaca DB lagi.
6. Setelah run service selesai, verifikasi transaksi fisik selesai dan context
   pulih sebelum HTTP. Jangan menaruh HTTP di callback DB::transaction yang dapat
   di-retry saat deadlock. Outer rollback atau nested-savepoint release tidak
   mengizinkan POST. Worker dengan ambient transaction/context ditolak.
7. Satu create, lalu lookup ketat (rincian di bawah), tanpa lock DB selama HTTP.
   Persist hasil dalam transaksi service ketiga: lock organisasi -> bill/items
   -> referensi terkait -> outbox, cocokkan identitas/snapshot dan reload status.
   Attach gateway_ref/URL/expiry + pending + processed outbox + audit atomik.
   Duplicate hasil identik no-op; unique gateway_ref milik bill lain/conflict atau
   perubahan total/scope/state membatalkan attach, tidak mengulang create.

Urutan ini mempertahankan mutex P7/P8b; bukan klaim bebas deadlock untuk semua
writer masa depan. Policy writer dan payment-method toggle harus masuk matriks
concurrency. Retry DB transaction hanya aman pada fase tanpa network.

## Guard sebelum claim dan konsumsi izin

- Service context wajib. Participant, BranchAdmin, SuperAdmin, staff,
  psychologist dan context kosong tidak boleh langsung memanggil action; job
  tepercaya membuka service hanya dari entrypoint queue tanpa ambient context.
  Tidak memakai runAsService sebagai elevasi otomatis dari input pengguna.
- Bill persisted dan organization scope cocok; organization/client/source aktif,
  contract checkout-v2/opt-in sah, paket/scope dan payer masih diizinkan memakai
  resolver existing. Self tepat satu peserta pembayar sendiri; organization
  tidak punya payer_participant_id. Bukan mengubah initial funding ADR-005.
- Semua item positif/IDR, unik, cocok ke charge/attempt/participant/organisasi/
  payer; jumlah item dan sum dengan overflow guard harus sama dengan bill.
  Snapshot charge harus valid melalui parser existing, tidak tertimpa perubahan
  harga katalog. Tolak items kosong/hilang/tambahan, jumlah corrupt, settlement
  parsial, free_settled_at, hak sudah ready/berjalan/selesai atau status terminal/tidak sesuai
  invoice awal. Attempt awal PROVISIONED, tidak revoked/finalized/deleted.
- payment_method persisted aktif dengan code tepat xendit. Manual_transfer
  mengembalikan not-applicable tanpa HTTP/claim invoice; tidak fallback otomatis.
  Total nol/free ditolak sebelum provider: flow free tersendiri, bukan invoice
  nol. Bila reserved sudah mempunyai gateway_ref/URL/paid/proof/verification
  yang tidak sesuai, fail-closed, jangan menimpa.
- Snapshot requested expiry masih valid sebelum consume; tidak memperpanjangnya
  ketika job terlambat. No provider secret dalam payload atau log.

**Titik keputusan guard adalah commit konsumsi izin**, bukan saat reservasi.
Jika policy/metode OFF atau revoke menang lock sebelum titik itu: tidak ada POST.
Setelah titik itu worker sudah in-flight; OFF/lease expiry tidak menjamin request
remote dapat dibatalkan. Preflight tambahan dapat memperkecil celah tetapi tidak
memberi atomicity lintas DB/provider. Hasil remote tetap perlu dicatat secara
akuntansi lewat korelasi exact; jangan membuang hasil karena policy berubah
setelah izin commit, dan jangan mengaktifkan akses dari hasil invoice. Status
terminal tidak diturunkan; konflik menuju reconciliation/operator P10c/P11.

## Reuse provider tanpa mewarisi retry legacy

Audit XenditProvider::createInvoice: satu POST, tanpa HTTP retry; ketika timeout
atau non-success, satu GET fallback findInvoiceByExternalId. Helper itu memilih
kandidat pertama dan tidak membuktikan uniqueness. Return PaymentInvoice saja
juga tidak menyatakan apakah hasil berasal dari POST atau fallback. Karena itu
return legacy **tidak cukup** untuk attach langsung pada bill baru.

Pilihan A yang direkomendasikan: wrapper internal bill memanggil createInvoice
sekali untuk permit yang baru dimenangkan. Apa pun return/exception provider,
pakai lookupInvoice P10a exact reference/amount/currency sebagai verifikasi
terpisah. Bila create mengembalikan invoice, providerReference hasil lookup harus
sama; perbedaan tetap unknown. Empty/duplicate/invalid lookup tetap unknown,
termasuk bila POST sebelumnya tampak sukses. Bila create melempar dan lookup
menemukan satu invoice sah, boleh attach pending. Tidak menandai paid.

Biaya pilihan A: paling banyak satu POST + GET fallback legacy + GET ketat,
lebih konservatif saat read-after-write belum tersedia. Timeout/transport error
tidak dibawa sebagai previous exception ke error publik/log. Jika ada error
programming/storage di luar kontrak provider, jangan mencoba create lagi;
laporkan melalui framework dan tinggalkan izin terpakai untuk recovery.

Alternatif B: boundary create-once khusus yang tidak punya fallback dan memakai
parser ketat. Ini mengurangi request/ambiguitas provenance, tetapi memperluas
kontrak/adapter/fake dan butuh review+regresi terpisah sebelum writer. Tidak
diimplementasikan dalam proposal ini. Tidak mengubah create/status/webhook v1.

CreateRegistrationInvoice existing mengizinkan claim baru setelah lima menit;
policy itu **tidak boleh disalin**. Retry job/lease hanya menjalankan P10a lookup
setelah permit consumed. ShouldBeUnique/cache lock hanya optimasi dispatch,
bukan pengaman uang. Reference AB_ tidak dianggap provider idempotency key;
tidak ada klaim provider mendukung repeated POST yang aman.

## Crash, lease, dan late response

| Titik gagal | State durable | Pemulihan yang aman |
| --- | --- | --- |
| Sebelum claim outer commit | reserved, tidak ada intent committed | Transaksi/dispatch rollback; retry claim normal, nol HTTP |
| Sesudah claim commit, sebelum enqueue | issuing + pending/0 | Redrive intent yang sama, lalu satu worker consume; bukan claim baru |
| Sesudah consume commit, sebelum POST | issuing + processing/1 | Stale -> unknown; lookup saja, sekalipun POST sebenarnya belum terkirim |
| Provider accept, response timeout | processing/1, hasil remote tidak pasti | Unknown/lookup exact; tidak POST ulang |
| Response sah, crash sebelum persist | processing/1, gateway_ref lokal kosong | Lookup yang sama memulihkan korelasi; tidak create |
| Attach/audit/outbox update gagal | Attach rollback seluruhnya; permit tetap consumed dari transaksi sebelumnya | Unknown atau issuing stale; lookup/retry persist terverifikasi |
| Persist pending commit, ACK broker hilang | pending + gateway_ref, processed/1 | Duplicate job no-op, nol create |
| Worker lama bangun setelah dianggap stale | unknown atau pending/terminal oleh recovery | Tidak dapat memperoleh permit baru; late result harus lolos lock/snapshot/status guard |
| Intent hilang/expired/corrupt | Bill masih issuing/unknown/pending, tidak reset | Fail-closed; kehilangan ledger bukan izin membuat ulang |

Lease hanya menandai pekerjaan perlu recovery; tidak memberi fencing terhadap
request yang sudah berjalan di jaringan. Worker lama yang sebelumnya memenangkan
permit mungkin baru mengirim POST setelah penanda stale dibuat. Tetap maksimum
satu invocation create: worker pengganti tidak pernah create. Late response dapat
resolve unknown hanya lewat lookup exact; tidak overwrite gateway berbeda atau
paid/expired/rejected. Jika dibutuhkan jaminan cancel setelah OFF, diperlukan
kontrak/provider boundary tambahan, bukan memperpanjang lock DB saat HTTP.

Timeout job harus lebih besar dari total maksimum tiga HTTP call ditambah budget
lock/persist, tetapi lebih kecil dari retry_after. Usulan awal 45 detik hanya
berlaku bila timeout HTTP 10 detik dan lock budget dibatasi; validasi konfigurasi
runtime sebelum wiring. Lease/stale threshold tidak memicu retry POST. Tidak
mengubah config aktif, menjalankan worker, atau menentukan scheduler sekarang.

Retensi outbox topic ini wajib mempertahankan intent unresolved; expiry bukan
izin purge/recreate. Dalam source aplikasi yang diaudit belum ditemukan purger
outbox umum, tetapi prosedur operasional belum diverifikasi. Jika operator tidak
dapat menjamin retensi/counter monotonic topic-specific, pilih ledger issuance
terpisah melalui ADR/migration tersendiri sebelum implementasi. Jangan memakai
audit_logs sebagai satu-satunya koordinasi atau FK fiktif dari JSON.

## Pembagian implementasi setelah keputusan

**P10b-a, claim saja:** action service-only untuk guard/lock/claim + snapshot
intent + audit. Hasilnya message ID/decision, bukan network. Tes feature dan PG
dua proses untuk uniqueness/rollback/denial; status enum/DTO hanya jika membantu
kontrak, tetap 3–5 file per commit. Jangan memasang dispatcher/job produksi.

**P10b-b, issuance/job:** konsumsi permit atomik, job/runner di luar outer
transaction, wrapper provider pilihan A, persist hasil guarded dan recovery
classification. Tambahkan afterCommit hook internal serta redrive bounded yang
hanya dipanggil tes; scheduler/route/source tetap belum aktif. Pisahkan commit
action/job dan tes pendukung jika melebihi lima file; jangan memasukkan consumer
notifikasi atau settlement P11. P10c kelak mengoperasionalkan rekonsiliasi lookup
terjadwal; acceptance akhir P10b harus membuktikan crash tidak memicu POST kedua.

Schema baru bukan prasyarat rekomendasi A. Jika dipilih ledger terpisah atau
epoch fencing tambahan, ajukan ADR + migration baru berikut uji rollback/RLS
lebih dahulu. Tidak mengedit migration historis atau menyelipkan schema di writer.

## Matriks tes yang wajib dibuat, belum dijalankan

| Kelompok | Skenario minimum | Bukti yang diminta |
| --- | --- | --- |
| PG claim race | Dua proses bill sama; transaksi pertama commit/rollback; replay berbeda metadata/scope | Tepat satu intent+audit; pemenang tunggal; rollback tidak meninggalkan orphan |
| PG permit race | Dua job/dua proses message sama dan sepuluh items | Satu processing/1 commit dan satu POST tercatat; bukan satu POST per peserta |
| Outer commit | Nested action success lalu outer rollback/commit; callback broker gagal | Tidak dispatch/HTTP sebelum commit fisik; intent tetap durable setelah callback gagal |
| Sync queue | Callback afterCommit dengan queue sync dan RlsContextRunner nyata | Context null + transactionLevel=0 pada Http fake; bukan hanya Queue::fake assertion |
| PG afterCommit | Non-owner connection kedua mengamati sebelum/sesudah outer commit | Row/permit visible dan lock released sebelum fake provider dipanggil |
| Atomic attach | Exception setelah gateway_ref save, audit insert, outbox update | Bill/outbox/audit rollback sebagai unit; izin sebelumnya tetap 1; retry nol POST |
| Crash barriers | Stop sebelum POST, accept-lalu-timeout, sebelum persist, sesudah persist sebelum ACK | Retry tetap total maksimal satu POST; empty/HTTP error/duplicate lookup unknown |
| Stale issuer | Worker A ditahan, B recovery, A late result; B sudah pending/terminal atau ref berbeda | Tidak rearm permit; late response tidak overwrite state/ref |
| Policy races | OFF/revoke/client disable/method toggle sebelum consume vs sesudah consume | Sebelum: nol POST. Sesudah: tidak klaim cancel remote, tidak akses, korelasi diaudit |
| Scope/RLS | Runtime PostgreSQL non-owner NOBYPASSRLS; role peserta/admin/staff/no-context; forged org/message | Direct action/write ditolak; tidak read bill tenant lain/outbox atau elevasi browser |
| Data corrupt | Sum/count/overflow/currency/item linkage/snapshot/status/gateway conflict | Fail-closed tanpa perbaikan data, repricing atau provider call |
| Metode/free | Manual transfer; metode nonaktif; total0; mixed free+paid reservasi | Tidak kirim manual/free ke Xendit; hanya paid items snapshot masuk total |
| Snapshot replay | Harga katalog berubah; job lama; expiry habis; claim payload diubah | Nominal/reference tetap, tidak extend expiry/backfill atau overwrite ADR-005 |
| Provider boundary | Legacy create success/fallback/error, strict lookup kosong/duplikat/mismatch/float money | Maksimal satu create; tidak attach hasil fallback ambigu; tidak paid event |
| Outbox isolation | Jalankan selector consumer notifikasi/integrasi terhadap topic invoice sintetis | Tidak ada notifier/webhook dispatch, no participant.activation/assessment.activation |
| Ledger retention | Intent missing/corrupt/expired dan pending dengan attempts1 | Tidak create pengganti atau reset claim; recovery-required |
| Regression | Create/status/webhook legacy, P7 reservation, P8 gate/activation | Semantik legacy tidak berubah; invoice bukan hak tes/paid/consent |

Gunakan phpunit.organization-payment.xml dan Http::fake + preventStrayRequests.
PG melalui runner disposable existing, dua proses dengan barrier deterministik
dan fake provider request recorder terisolasi; tidak menilai race hanya dari
jumlah row setelah loop sequential. Jangan memakai RefreshDatabase test manager
sebagai satu-satunya bukti outer commit fisik. PG test membuktikan lock/RLS, bukan
request provider nyata; semua failure injection/payload/data sintetis.

## Rujukan dan keputusan yang ditunggu

[Laravel 13 jobs dan transaksi](https://laravel.com/docs/13.x/queues#jobs-and-database-transactions)
mendokumentasikan dispatch setelah parent transaction commit. Source terpasang
DatabaseTransactionsManager::afterCommitCallbacksShouldBeExecuted memakai level
0; callback in-memory tetap tidak menutup crash sesudah commit sebelum enqueue.
[PostgreSQL 17 locking](https://www.postgresql.org/docs/17/explicit-locking.html#LOCKING-DEADLOCKS)
menjelaskan pentingnya urutan lock konsisten. Analisis crash/permit dalam proposal
ini adalah rancangan aplikasi, bukan jaminan exactly-once dari kedua platform.

Review perlu memutuskan: (1) outbox topic-specific monotonic permit tanpa schema
baru atau ledger terpisah; (2) reuse create legacy + strict lookup pilihan A atau
create-once boundary pilihan B; (3) menerima fail-closed celah pre-POST dan
requested expiry awal 24 jam; (4) pemisahan scope P10b-a/P10b-b di atas.
Tidak ada acceptance implementasi yang ditandai selesai oleh dokumen ini.
