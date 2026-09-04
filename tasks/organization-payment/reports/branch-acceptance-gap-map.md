# P12/P17c — peta bukti dan gap acceptance cabang

Tanggal audit: 2026-09-04. Status: **laporan read-only, bukan acceptance P17**.
Root dibaca di `D:/LSI/Web/Psikotes`, HEAD teramati `2201483`; worker
berangkat dari `90bea2a`. Tidak reset/merge baseline, menjalankan server/browser/
database/tes, atau mengubah aplikasi, verifier, gate, maupun dokumen kanonik.
Hanya laporan ini ditambahkan. Skills documentation-and-adrs dan Git workflow
dipakai untuk membatasi klaim dan commit.

## Dasar dan cara membaca bukti

Rujukan kanonik: `parallel-work.md`, bagian **Parallel follow-ups dispatched —
2026-09-04**; `plan.md`; `todo.md` bagian P12a/b/c dan P17a/b/c; serta
`SPEC-organization-billing.md`, khususnya aturan pembayaran dan seleksi.
Path pendek dokumen koordinasi dalam laporan ini relatif terhadap
`tasks/organization-payment/`; path `app/`, `tests/`, `tools/`, dan `docs/`
relatif terhadap root repository.

ADR-004 (`docs/decisions/0004-checkout-partial-profile.md`) melarang placeholder
profil tersimpan dan mengharuskan prasyarat akses tetap berlaku. ADR-005
(`0005-checkout-initial-funding-snapshot.md`) memisahkan funding awal dari
lifecycle; pilihan pembayar bukan bukti settlement. ADR-012
(`0012-private-integrated-checkout-session.md`, termasuk amendment 2026-09-04)
membatasi checkout principal ke satu attempt dan tidak memberikan authority
admin atau pembayaran. Ringkasan peserta tidak boleh membawa batch/invoice/proof
organisasi. Persetujuan lokal ADR tersebut bukan aktivasi publik.

**Inspeksi** berarti source/test ditemukan dan dibaca; tidak berarti tes baru
lulus. **Historis** berarti hasil run yang dicatat laporan existing, tidak
dijalankan ulang pada audit ini. Artifact ignored yang disebut laporan lama
tidak saya validasi ulang. **Gap** berarti bukti alur yang diminta belum ditemukan
dalam sumber yang ditinjau, bukan pernyataan semua backend belum dibuat.

Todo bagian P12 mencatat acceptance lokal default-off. Header ringkas plan/todo
masih menyebut sebagian browser sebagai pekerjaan berikutnya, tetapi bagian
P12c dan `reports/branch-portal.md` sudah mencatat hasil lebih baru. Audit memakai
bagian rinci itu; tidak mengedit status kanonik atau menutup checkpoint/P17.

## Kebutuhan sepuluh peserta sekali bayar versus mandiri

Kontraknya satu bill induk dan satu transaksi untuk seluruh item berbiaya dari
organisasi sama, bukan sepuluh invoice individual. Mandiri tetap satu attempt
milik peserta dengan payer persisted yang diizinkan server. Tidak boleh mengambil
alih invoice mandiri aktif atau menggandakan claim attempt. Ini bukan dana talang
atau pencatatan utang peserta kepada lembaga.

Sepuluh pilihan tidak selalu sepuluh bill item: total nol harus dikeluarkan dari
tagihan berbayar, all-free tidak menghasilkan invoice, dan free + konsultasi
berbayar mengikuti total komponennya. Paid tidak otomatis READY: profil,
consent, identitas, dan prasyarat per-test tetap harus sah.

## Matriks bukti dan gap

| Kebutuhan | Source/test aktual dan bukti yang tersedia | Batas / gap acceptance |
| --- | --- | --- |
| P12a list/detail, filter, pagination dan alokasi | `app/Filament/Resources/OrganizationBills/OrganizationBillResource.php` dan `Pages/ViewOrganizationBill.php`; `tests/Feature/Admin/OrganizationBillAccessTest.php::test_paid_history_filter_search_and_pagination_use_same_scoped_bills`, `test_two_attempts_of_one_participant_keep_server_count_total_and_allocations`. `reports/branch-portal.md` mencatat browser dan focused P12a historis. | Riwayat memakai bill scoped yang sama; tampilan paid dari fixture bukan bukti pembayaran melalui UI. Discovery non-testing tetap tertutup. |
| Sepuluh attempt → preview → satu reservasi | `CreateCollectiveBillAction::confirm()` mendelegasikan satu `ReserveAssessmentBill`, lalu `Pages/CreateCollectiveBill::confirm()` redirect ke bill. `tests/Feature/Admin/CollectiveBillSelectionTest.php::test_preview_then_confirmation_delegates_to_one_canonical_bill_and_replays`; `tests/Feature/Payments/AssessmentBillReservationTest.php::test_ten_attempts_create_one_reserved_bill_and_no_access_or_external_effects` menguji 10 item, IDR 1030, satu reserved bill, gateway_ref null. | Membuktikan reservasi, bukan invoice terbit atau semua paid. Tidak ada pemanggilan issuer/finalizer dalam confirm portal yang dibaca. |
| Mixed package, free, konsultasi dan profil parsial | `tests/Feature/Admin/CollectiveBillPreviewComponentTest.php::test_all_choices_render_and_ten_mixed_items_show_only_server_preview`: IDR 1140, 8 berbiaya/2 gratis. `test_all_free_then_consultation_uses_server_counts_and_amounts`: nol menjadi 30; `test_nullable_or_blank_profile_only_changes_display`. Helper `tools/testing/verify-collective-preview-browser.mjs` dan laporan Vite/browser mencatat 20 aksi native/12 geometry historis. | Ini komponen preview test-only, bukan reservasi final. `CreateCollectiveBillAction::choices()` hanya enabled untuk `payable` dan `preview()` menolak seluruh pilihan bila ada non-payable. Backend `AssessmentBillReservationTest::test_free_items_are_not_claimed_or_settled_and_all_free_requires_separate_flow` mendukung mengeluarkan free, all-free ditolak `FREE_CHECKOUT_REQUIRED`. Komposisi UI final untuk daftar campuran gratis belum terbukti setara spec; perlu review terpisah, jangan membuka policy/UI di audit ini. |
| Satu invoice fake, kemudian semua alokasi paid | `tests/Feature/Payments/AssessmentBillInvoiceIssuanceTest.php::test_ten_items_use_one_create_and_one_strict_lookup_after_permit_commit` memakai 10 fixture dan mock provider. `AssessmentBillPaymentFinalizationTest.php::test_collective_payment_settles_every_item_and_activates_only_complete_attempts` menguji dua alokasi, hanya satu READY karena consent lainnya declined. | Bukti backend terpisah. Belum ada bukti browser satu rantai 10 pilihan → invoice fake → settlement seluruh alokasi → portal/checkout peserta membaca hasil yang sama. Jangan menggabungkan dua tes menjadi klaim E2E 10 peserta. |
| Mandiri tetap tersedia tanpa double bill | `tests/Feature/Payments/AssessmentBillReservationTest.php::test_self_reservation_derives_payer_from_persisted_participant`, `test_foreign_selection_and_self_identity_spoof_are_rejected`; `tests/Postgres/AssessmentBillReservationTest.php::test_self_and_organization_compete_for_the_same_attempt_claim` serta `test_overlapping_batches_have_one_winner_and_no_partial_loser`. | Ada tes backend race khusus; bukan bukti browser mandiri-vs-kolektif. P17b keseluruhan masih unchecked. Status pemenang tidak boleh disimpulkan dari checkbox browser atau sequential double-enter. |
| Stale item/harga, tampered hash, reload/replay | `CollectiveBillSelectionTest::test_price_or_status_change_after_preview_fails_closed_without_partial_bill`, `test_livewire_selection_change_clears_preview_and_blocks_confirmation`, `test_foreign_duplicate_free_and_tampered_hash_are_rejected_without_writes`. `tools/testing/verify-collective-bill-page-browser.mjs`; laporan P12b mencatat stale price, double-enter, replay/reload. | Historis browser P12b mencakup native flow; tidak membuktikan dua proses race, recovery invoice unknown, atau durable checkout intent. Perubahan scope/member diuji lagi pada boundary server. |
| IDOR role/tenant/direct URL/action | `OrganizationBillAccessTest::test_list_and_direct_url_are_scoped_to_organization_payer_and_owner`, `test_revoked_role_and_membership_fail_closed_on_livewire_refresh`; `OrganizationBillProofTest::test_wrong_role_deleted_and_cross_tenant_actors_cannot_probe_proof`. `tests/Postgres/OrganizationBillPortalTest.php` memuat nonowner forced RLS dan reused context; `tests/Postgres/CollectiveBillPreviewTest.php` memuat foreign/nonexistent tanpa label, context restore sukses/error, dan participant berubah antara preview/label. | Bukti PG dan HTTP/Livewire berbeda dari browser. Laporan hardening P12b/P12c mencatat direct-detail dan action denial historis; tidak otomatis mencakup seluruh endpoint checkout P14/P15/P16. |
| Query bounded dan pilihan lintas pagination | `OrganizationBillAccessTest::test_paginated_livewire_query_counts`; `OrganizationBillPortalTest::test_paginated_resource_query_count_and_detail_eager_loading`; `CollectiveBillPreviewTest::test_query_count_is_bounded_at_ten_and_configured_selection_limit` (laporan PG: 10/100 masing-masing 14 query). | Angka adapter preview tidak berlaku otomatis untuk `CreateCollectiveBillAction::choices()` yang memanggil preview per ID. Halaman pilihan memakai daftar dibatasi max_items, bukan seleksi lintas paginator; belum ada bukti persistensi pilihan antarhalaman untuk page tersebut. Jangan menyamakan pagination list bill dengan selection pagination. |
| Expiry, rejected, callback terlambat | `AssessmentBillReservationTest::test_terminal_bill_never_releases_the_claim`; `AssessmentBillPaymentFinalizationTest::test_exact_replay_and_late_terminal_events_are_no_ops`; `tests/Feature/Payments/AssessmentBillStatusReconciliationTest.php::test_paid_and_expired_results_use_the_claim_dispatcher_and_finalizer_path`. Browser proof historis menyembunyikan upload pada expired/rejected/paid/nonmanual. | `tools/testing/serve-collective-bill-page.php` control `bill-paid` menyetel state fixture langsung. Itu uji UI, bukan settlement. Belum ada bukti native P17c dari invoice fake expired hingga recovery/reload aman; terminal tidak berarti boleh reinvoice otomatis. |
| Proof privat dan riwayat cabang | `OrganizationBillProofTest::test_branch_page_uploads_and_replaces_canonical_private_proof_without_marking_paid`, `test_access_rejects_replacement_during_url_generation_without_audit`; `tools/testing/verify-organization-bill-proof-browser.mjs`. Laporan P12c terbaru: JPEG/PNG/PDF, invalid/oversize, stale/replay, URL lama 404, audit terikat actor/tenant/bill. | Upload tetap pending, bukan verifikasi pembayaran. Pemilih berkas memakai `setInputFiles`, sedangkan aktivasi/submit/cancel native. Tidak mengklaim dialog OS native atau storage provider produksi. |
| Consent tertunda, paid tetapi akses locked, privasi peserta | Finalization test di atas mempertahankan attempt incomplete PROVISIONED dan entitlement locked. `reports/frontend.md` bagian summary-v1 memisahkan free/paid/partial/ready dengan actions literal false; `reports/checkout-keyboard-verification.md` mencatat native draft UI. | Draft callback/frontend fixtures bukan endpoint/session checkout nyata atau bukti paid membuka tes setelah consent. P14 summary/page, P15, P16 masih dependensi; tidak boleh menampilkan anggota/total/proof organisasi pada peserta. |
| Desktop/mobile/native keyboard dan diagnostics | `reports/branch-portal.md`, bagian verifier postcondition: fixture `oncam-collective-page-eba0f6aaa78a41818a58e71f7c845189`, P12b 20 native/480 trusted/9 geometry/85 response; P12c 386 trusted/6 geometry/346 response, 6 denial expected. Ukuran 320/390/1280; console/outbound/request failure nihil menurut run itu. | Bukti historis Chrome installed/headless. Bukan device mobile fisik, semua engine, screen reader, WCAG penuh atau zoom 200%. P17c browser terpadu belum diuji; tidak ada run ulang pada laporan ini. |

## Batas postcondition yang tidak boleh dilonggarkan

Commit worker `c817fa0`/`90bea2a` diterima root sebagai `47d12e3`/`a7f70f5`
menurut todo. `tools/testing/serve-collective-bill-page.php` menerima tepat
profil post-P12b baseline (11 charge/2 bill/11 item, audit reserved 2, tanpa proof)
atau P12c (count billing sama, audit reserved 2 + satu audit akses, satu proof).
Entitlement/outbox/order/consent/identity serta settlement tetap nol pada bukti
browser tersebut. Probe audit URL terselubung dan audit duplikat ditolak pada run
historis. Karena itu harness ini sengaja **tidak** membuktikan pembayaran sukses;
jangan menaikkan expected count atau memasukkan finalizer diam-diam agar E2E hijau.

Laporan modal P12c awal yang RED sudah dikoreksi oleh reproduksi dan acceptance
berikutnya; tidak dipakai sebagai blocker baru. Demikian juga fix Vite
`49c0ff4`/root `20cd529` sudah ada, tidak membutuhkan pekerjaan ulang.

## Satu usulan slice berikutnya — menunggu review, bukan implementasi sekarang

Usulkan **tes komposisi backend sintetis 10 attempt dari bill yang sama**, sebelum
merangkai browser P17c. Maksimal tiga file baru/diubah, tanpa ownership writer:

1. `tests/Feature/Payments/CollectiveBillLifecycleCompositionTest.php` (baru).
2. `tests/Support/CollectiveBillLifecycleFixture.php` (baru, hanya jika fixture
   existing tidak cukup; tidak mengubah helper bersama).
3. `tasks/organization-payment/reports/branch-portal.md` (bukti dan batas).

Setelah koordinator menyetujui fixture/kontrak, compose action canonical existing:
preview/reserve → claim/issue dengan mock provider → event/finalizer canonical.
Gunakan 10 attempt **berbiaya** mixed package termasuk konsultasi; assert satu
bill/10 item, satu create provider, total IDR snapshot konsisten, semua alokasi
settled tepat sekali, replay tidak menggandakan efek, dan attempt dengan consent
tertunda tetap locked. Baca ulang proyeksi portal sebagai tenant pemilik dan
pastikan tenant lain tidak mendapatkannya. Semua outbound fake/deny; tidak membuat
route, tombol, scheduler atau writer baru. Ini tes komposisi SQLite disposable,
bukan klaim race PG atau browser. Bila action contracts belum dapat dikomposisikan,
laporkan reproduksi kepada backend, jangan mengubah backend dari lane portal.

Slice ini menutup gap kecil antara tes issuance 10 item dan finalization dua item;
tidak menyelesaikan gap free-in-selection, self-pay UI, atau semua P17. P17a masih
bergantung P16; P17b bergantung P17a; P17c bergantung P17b. P14 browser/summary,
P15, dan P16 serta review koordinator tetap prasyarat sebelum acceptance terpadu.
Tidak ada izin transaksi nyata, source/gate activation, deploy, atau klaim P17
selesai dari laporan ini.
