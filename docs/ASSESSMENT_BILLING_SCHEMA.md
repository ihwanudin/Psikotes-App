# Penyimpanan tagihan asesmen — P6

## Lingkup

assessment_bills adalah tagihan induk; assessment_charges adalah biaya satu
assessment_participant (attempt). Tidak menggunakan orders legacy sebagai
wadah batch. assessment_bill_items mengikat biaya ke tagihan;
assessment_entitlements menyimpan hak terkunci per attempt dan jenis tes. Tidak ada
invoice gateway, API, atau entitlement otomatis dari penambahan tabel/model.

## Kontrak data

Charge mengikat assessment_participant_id unik ke organization_id,
participant_id, dan package_id lewat FK komposit. Participant harus tetap milik
organisasi tersebut; package_id adalah paket attempt, bukan paket profil legacy.
Indeks unik pendukung ditambah ke parent tanpa mengubah isi/hubungan baris lama.

Komponen uang: base_amount, consultation_amount, amount, currency; seluruhnya
integer Rupiah dan wajib eksplisit, tidak mengambil harga default dari kode.
consultation_requested false mengharuskan consultation_amount nol; true harus
positif. amount = base_amount + consultation_amount, semua nonnegatif.
price_snapshot menyimpan keterangan katalog saat reservasi; policy_snapshot
menyimpan keputusan policy. JSON wajib object, bukan sumber otoritatif nominal
terpisah. P7 memvalidasi isi/sumber snapshot dan menjaga immutabilitas reservasi.
free_settled_at hanya boleh diisi ketika amount nol; bukan bukti consent/akses.

Bill memuat organization_id, payer_type, payer_participant_id nullable,
public_reference AB_ + ULID, amount positif, currency IDR, item_count positif,
selection_hash, idempotency_key, request_hash, payment_method_id, status,
gateway_ref, invoice_url, proof_object_key, expires_at, paid_at, verified_at,
verified_by_admin_id, dan rejection_reason. Tidak ada default harga/mata uang.

- self: payer_participant_id wajib, FK ke peserta organisasi itu, item_count=1.
- organization: payer_participant_id harus NULL; organization_id adalah pembayar.
- Unique idempotensi dipisah: organisasi+payer key untuk kolektif, dan
  organisasi+peserta+payer key untuk mandiri. Partial unique index menghindari
  NULL yang membolehkan duplikasi key kolektif. Hash payload wajib disimpan;
  pembandingan retry dilakukan action P7, bukan dianggap selesai oleh UNIQUE.
- Status awal reserved. Nilai: reserved, issuing, unknown, pending, paid,
  expired, rejected. paid_at hanya bersama paid dan wajib saat paid. Pasangan
  verifier/waktu wajib konsisten. CHECK bukan validasi transisi status; P10/P11
  wajib menjaga idempotensi, keaslian event, dan larangan menurunkan paid.
- Total nol tidak masuk bill. UNIQUE charge_id pada item menjaga satu claim.
  Konsistensi jumlah/total seluruh item dan reservasi atomik tetap tugas P7.

## Proteksi dan rollback

FK menggunakan RESTRICT untuk histori, tanpa cascade hapus pembayaran. Empat
tabel menggunakan ENABLE/FORCE RLS PostgreSQL. Service memiliki akses tulis;
izin baca mengikuti matriks P6c di bawah. Model bukan
otorisasi: semua input HTTP harus melalui action/policy terpisah nanti.

Constraint scalar/JSON PostgreSQL melengkapi FK/unique portable. SQLite dipakai
untuk hydration, relasi, FK/unique, dan lifecycle up/down; bukan pengganti bukti
constraint CHECK maupun RLS PostgreSQL. Acuan:
[FK dan CHECK](https://www.postgresql.org/docs/17/ddl-constraints.html),
[row security](https://www.postgresql.org/docs/17/ddl-rowsecurity.html).

down() menghapus tabel milik tiap migrasi lalu indeks pendukungnya. Ini
rollback DDL destruktif bagi data billing baru, bukan prosedur rollback produksi.
Tes up/down populated hanya pada database disposable dan membuktikan orders,
peserta, attempt serta konfigurasi legacy tetap utuh. Produksi harus menjaga
histori billing, tidak menjalankan down setelah ada transaksi.

## Kontrak P6b: item dan entitlement

Item menyimpan bill_id, charge_id UNIQUE, organization_id, participant_id,
payer_type, payer_participant_id nullable, amount, currency, settled_at nullable.
FK komposit mengikat organisasi/pembayar/mata uang ke bill dan
organisasi/peserta/pembayar/nominal/mata uang ke charge. FK pembayar peserta
tambahan dan CHECK self mewajibkan payer_participant_id = participant_id;
organization mewajibkan NULL. Partial UNIQUE bill_id untuk self membatasi satu
charge per bill mandiri, termasuk dua attempt orang yang sama. Nominal item
harus positif, IDR, dan persis snapshot charge, sehingga charge gratis tidak
masuk bill berbayar. Jumlah/total seluruh item dan transisi settled tetap P7/P11.

Entitlement menyimpan charge_id, assessment_participant_id, organization_id,
participant_id, test_type, status default locked, ready_at, started_at,
completed_at. FK komposit mengikat charge ke attempt/organisasi/peserta yang
sama. UNIQUE attempt + test_type membedakan hak tes antar-attempt peserta yang
sama. Jenis tes dan status mengikuti kosakata existing: ist/papi/rmib/kraepelin/
dass21; locked/ready/in_progress/done. Timestamp wajib konsisten dan berurutan.
Tidak ada pembukaan otomatis dari paid atau fallback ke entitlement legacy;
verifikasi pembayaran, consent, identitas, dan paket tetap tugas action P8/P11.

Duplikasi scope pada child disengaja untuk FK database, bukan metadata bebas.
FK dipilih agar perubahan parent pun ditolak bila merusak kaitan child; bukan
CHECK yang membaca tabel lain. CHECK PostgreSQL menutup celah NULL pada FK
pembayar mandiri. SQLite menguji FK/unique/lifecycle, PostgreSQL menguji CHECK
dan FORCE RLS; keduanya memakai fixture sintetis. P6c menambah izin baca terpilih
tanpa mengubah service-only write. Semua FK RESTRICT; rollback dilakukan terbalik:
entitlement, item, lalu bill/charge. Tidak memakai CASCADE untuk histori.

## Matriks akses P6c

| Peran | Bill/item | Charge/entitlement | INSERT/UPDATE/DELETE |
| --- | --- | --- | --- |
| service | Semua | Semua | Melalui layanan internal |
| super_admin | Semua | Semua | Ditolak oleh RLS langsung |
| branch_admin | Organisasi sendiri | Organisasi sendiri | Ditolak |
| participant | Tidak membaca tabel induk/item, termasuk self | Organisasi dan participant_id sendiri | Ditolak |
| staff, psychologist, tanpa context | Tidak ada | Tidak ada | Ditolak |

Kebijakan baru hanya FOR SELECT; service policy P6a/P6b tetap satu-satunya
kebijakan tulis. Proyeksi checkout privat kelak dapat menampilkan informasi
invoice self yang diizinkan tanpa memberi SELECT batch. Tidak ada hasil klinis
di tabel ini; akses pembayaran tidak memperluas izin ke schema DASS.
down P6c hanya menghapus policy baca, mempertahankan FORCE RLS/service policy
dan seluruh baris. Rollback tabel P6b/P6a berbeda: destruktif, hanya untuk tes
disposable dan wajib urutan dependensi terbalik.

## Bukti implementasi lokal

P6a lulus 10 tes skema SQLite/40 assertions dan suite PostgreSQL runtime
66 tes/184 assertions; regresi lokal 508 tes/2.571 assertions. Catatan RED/GREEN,
review, perintah dan batas pengujian:
[ORGANIZATION_CHECKOUT_VALIDATION.md](ORGANIZATION_CHECKOUT_VALIDATION.md#p6a--penyimpanan-billcharge-2026-08-31).
Migrasi belum dijalankan pada database aktif. Kebijakan P6c melengkapi isolasi
penyimpanan; berikutnya P7a untuk snapshot/preview, bukan aktivasi invoice.

P6b lulus 12 tes skema SQLite/65 assertions, regresi 520 tes/2.636 assertions,
dan PostgreSQL runtime 98 tes/327 assertions. Review serta batas pembuktian
tercatat pada bagian P6b dokumen verifikasi yang sama. Harga aktif, legacy
entitlement, serta alur pembayaran publik tidak berubah.

P6c lulus 121 tes PostgreSQL/504 assertions dan regresi 520 tes/2.636 assertions.
Policy rollback mempertahankan row dan FORCE RLS; populated schema down/up
mempertahankan data legacy, diuji pada koneksi owner disposable yang diverifikasi.
Matriks izin dibuktikan terpisah sebagai runtime non-owner. Rincian checkpoint
dan batas tersedia pada dokumen verifikasi; bukan bukti checkout end-to-end.
