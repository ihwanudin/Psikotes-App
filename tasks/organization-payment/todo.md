# Calon tugas: organization-payment

Status: **DRAFT — pendamping review plan, bukan antrean yang sudah disetujui untuk implementasi.**

Rujuk [plan.md](plan.md). Setelah plan disetujui, tinjau rincian file/kontrak tiap tugas dan setujui checklist sebelum coding. Lokasi `<new>` adalah nama migrasi yang belum dialokasikan; path baru lain adalah usulan, bukan klaim file sudah ada. Jika satu tugas melebar melebihi lima file, pecah dahulu. Semua tes di bawah baru direncanakan; filter tanpa tes bukan keberhasilan.

Tidak ada butir implementasi selesai. Checklist F1 lama tetap terpisah.

## P1: Harness dan baseline aman

**Deskripsi / acceptance:**

- [ ] Runner menolak database aktif dan outbound nyata; baseline existing dicatat tanpa menandai skip sebagai lulus.

**Verification:** `php artisan test --filter=OrganizationPaymentTestEnvironmentTest` pada environment test terisolasi; jumlah tes harus nonzero. Untuk P18, verifikasi tautan/runbook dan kesesuaian dengan bukti P17, bukan menjalankan deploy.

**Dependencies:** None. **Scope:** M.

**Files likely touched:** `tests/Feature/Database/OrganizationPaymentTestEnvironmentTest.php`, `phpunit.organization-payment.xml`, `docs/ORGANIZATION_PAYMENT_TESTING.md`.

## P2: Konfigurasi pembayar

**Deskripsi / acceptance:**

- [ ] Schema additive dan RLS teruji; existing tidak otomatis memperoleh izin; default organisasi baru self saja.

**Verification:** `php artisan test --filter=PayerPolicySchemaTest` pada environment test terisolasi; jumlah tes harus nonzero. Untuk P18, verifikasi tautan/runbook dan kesesuaian dengan bukti P17, bukan menjalankan deploy.

**Dependencies:** P1. **Scope:** M.

**Files likely touched:** `database/migrations/<new>_add_payer_policy.php`, `app/Models/Branch.php`, `app/Models/IntegrationSource.php`, `tests/Feature/Database/PayerPolicySchemaTest.php`.

## P3: Keputusan kebijakan server

**Deskripsi / acceptance:**

- [ ] Irisan organisasi/sumber/paket dihormati; locked payer dan input palsu diuji; policy tidak membuka entitlement.

**Verification:** `php artisan test --filter=PayerPolicyTest` pada environment test terisolasi; jumlah tes harus nonzero. Untuk P18, verifikasi tautan/runbook dan kesesuaian dengan bukti P17, bukan menjalankan deploy.

**Dependencies:** P2. **Scope:** M.

**Files likely touched:** `app/Enums/PayerType.php`, `app/Services/Payments/ResolvePayerPolicy.php`, `app/Data/Payments/PayerDecision.php`, `tests/Unit/Payments/PayerPolicyTest.php`.

### Checkpoint setelah P3

- [ ] Focused tests tiga tugas terakhir dan regresi terkait lulus tanpa skip gerbang.
- [ ] Pint, PHPStan, lint/typecheck frontend, dan build lulus sesuai perintah plan.
- [ ] Slice berjalan end-to-end pada lingkungan test; RLS/concurrency memakai PostgreSQL bila terkait. Catat bukti dan tinjau bersama pengguna sebelum kelompok berikutnya.

## P4: Kontrol ON/OFF oleh ONCAM

**Deskripsi / acceptance:**

- [ ] Admin ONCAM dapat mengubah policy organisasi/sumber dengan audit; lembaga tidak dapat memberi izin sendiri; OFF tidak membatalkan order historis.

**Verification:** `php artisan test --filter=FundingPolicyManagementTest` pada environment test terisolasi; jumlah tes harus nonzero. Untuk P18, verifikasi tautan/runbook dan kesesuaian dengan bukti P17, bukan menjalankan deploy.

**Dependencies:** P3. **Scope:** M.

**Files likely touched:** `app/Filament/Resources/IntegrationSources/IntegrationSourceResource.php`, `app/Policies/FundingPolicyPolicy.php`, `app/Actions/Payments/UpdateFundingPolicy.php`, `tests/Feature/Admin/FundingPolicyManagementTest.php`.

## P5: Kontrak checkout opt-in

**Deskripsi / acceptance:**

- [ ] Versi baru dan mapping legacy eksplisit; unknown/SPONSORED/INTERNAL/WAIVED tidak otomatis paid; sumber cutover tidak fallback v1.

**Verification:** `php artisan test --filter=CheckoutContractCompatibilityTest` pada environment test terisolasi; jumlah tes harus nonzero. Untuk P18, verifikasi tautan/runbook dan kesesuaian dengan bukti P17, bukan menjalankan deploy.

**Dependencies:** P4. **Scope:** M.

**Files likely touched:** `app/Services/Integrations/CheckoutContractAdapter.php`, `app/Http/Requests/ProvisionCheckoutParticipantRequest.php`, `tests/Feature/Integrations/CheckoutContractCompatibilityTest.php`, `docs/ORGANIZATION_CHECKOUT_CONTRACT.md`.

## P6: Schema tagihan attempt

**Deskripsi / acceptance:**

- [ ] FK payer/attempt dan unique order per attempt teruji; entitlement legacy tidak diubah; RLS database baru diuji dengan role runtime.

**Verification:** `php artisan test --filter=AttemptBillingSchemaTest` pada environment test terisolasi; jumlah tes harus nonzero. Untuk P18, verifikasi tautan/runbook dan kesesuaian dengan bukti P17, bukan menjalankan deploy.

**Dependencies:** P5. **Scope:** M.

**Files likely touched:** `database/migrations/<new>_add_attempt_billing.php`, `app/Models/Order.php`, `app/Models/AssessmentEntitlement.php`, `tests/Feature/Database/AttemptBillingSchemaTest.php`.

### Checkpoint setelah P6

- [ ] Focused tests tiga tugas terakhir dan regresi terkait lulus tanpa skip gerbang.
- [ ] Pint, PHPStan, lint/typecheck frontend, dan build lulus sesuai perintah plan.
- [ ] Slice berjalan end-to-end pada lingkungan test; RLS/concurrency memakai PostgreSQL bila terkait. Catat bukti dan tinjau bersama pengguna sebelum kelompok berikutnya.

## P7: Reservasi order idempotent

**Deskripsi / acceptance:**

- [ ] Harga IDR dari DB tersnapshot; retry/parallel hanya satu order; participant/payer/attempt wajib satu scope dan tidak dapat diganti.

**Verification:** `php artisan test --filter=AssessmentOrderTest` pada environment test terisolasi; jumlah tes harus nonzero. Untuk P18, verifikasi tautan/runbook dan kesesuaian dengan bukti P17, bukan menjalankan deploy.

**Dependencies:** P6. **Scope:** M.

**Files likely touched:** `app/Actions/Payments/CreateAssessmentOrder.php`, `app/Services/Payments/AssessmentPriceSnapshot.php`, `tests/Feature/Payments/AssessmentOrderTest.php`.

## P8: Gate akses khusus attempt

**Deskripsi / acceptance:**

- [ ] Gate memeriksa attempt milik principal; tidak fallback ke entitlement lama; paid tanpa consent/identitas yang diwajibkan tetap ditolak.

**Verification:** `php artisan test --filter=AttemptEntitlementGateTest` pada environment test terisolasi; jumlah tes harus nonzero. Untuk P18, verifikasi tautan/runbook dan kesesuaian dengan bukti P17, bukan menjalankan deploy.

**Dependencies:** P7. **Scope:** M.

**Files likely touched:** `app/Services/ParticipantAuth/AssessmentEntitlementGate.php`, `app/Http/Controllers/StartParticipantSessionController.php`, `app/Http/Requests/StartAssessmentSessionRequest.php`, `tests/Feature/Auth/AttemptEntitlementGateTest.php`.

## P9: Provisioning checkout tanpa akses dini

**Deskripsi / acceptance:**

- [ ] Profil lengkap/parsial tersimpan tanpa duplikasi dalam scope sumber; assessment PROVISIONED tanpa ready otomatis; kontrak v1 tidak diam-diam berubah.

**Verification:** `php artisan test --filter=CheckoutProvisioningTest` pada environment test terisolasi; jumlah tes harus nonzero. Untuk P18, verifikasi tautan/runbook dan kesesuaian dengan bukti P17, bukan menjalankan deploy.

**Dependencies:** P8. **Scope:** M.

**Files likely touched:** `app/Actions/Integrations/ProvisionCheckoutParticipant.php`, `app/Http/Controllers/CheckoutParticipantProvisioningController.php`, `routes/api.php`, `tests/Feature/Integrations/CheckoutProvisioningTest.php`.

### Checkpoint setelah P9

- [ ] Focused tests tiga tugas terakhir dan regresi terkait lulus tanpa skip gerbang.
- [ ] Pint, PHPStan, lint/typecheck frontend, dan build lulus sesuai perintah plan.
- [ ] Slice berjalan end-to-end pada lingkungan test; RLS/concurrency memakai PostgreSQL bila terkait. Catat bukti dan tinjau bersama pengguna sebelum kelompok berikutnya.

## P10: Invoice gateway dengan retry aman

**Deskripsi / acceptance:**

- [ ] Fake gateway dipanggil di luar transaksi reservasi; reference stabil saat timeout/retry; total nol tidak memanggil gateway.

**Verification:** `php artisan test --filter=AssessmentInvoiceTest` pada environment test terisolasi; jumlah tes harus nonzero. Untuk P18, verifikasi tautan/runbook dan kesesuaian dengan bukti P17, bukan menjalankan deploy.

**Dependencies:** P9. **Scope:** M.

**Files likely touched:** `app/Actions/Payments/IssueAssessmentInvoice.php`, `app/Services/Payments/AssessmentInvoiceReconciler.php`, `tests/Feature/Payments/AssessmentInvoiceTest.php`.

## P11: Finalisasi pembayaran attempt

**Deskripsi / acceptance:**

- [ ] Autentikasi/nominal/currency/reference divalidasi; hanya entitlement attempt benar diaktifkan dengan outbox idempotent; paid tidak turun akibat expired terlambat.

**Verification:** `php artisan test --filter=AssessmentPaymentFinalizationTest` pada environment test terisolasi; jumlah tes harus nonzero. Untuk P18, verifikasi tautan/runbook dan kesesuaian dengan bukti P17, bukan menjalankan deploy.

**Dependencies:** P10. **Scope:** M.

**Files likely touched:** `app/Actions/Payments/FinalizeAssessmentPayment.php`, `app/Actions/Payments/VerifyManualTransfer.php`, `app/Http/Controllers/XenditWebhookController.php`, `app/Actions/Notifications/EnqueueParticipantActivation.php`, `tests/Feature/Payments/AssessmentPaymentFinalizationTest.php`.

## P12: Portal tagihan lembaga

**Deskripsi / acceptance:**

- [ ] Lembaga melihat/membayar/mengunggah bukti tagihan sendiri saja; hanya ONCAM berwenang memverifikasi; hasil psikologis tidak ikut terbuka.

**Verification:** `php artisan test --filter=OrganizationOrderAccessTest` pada environment test terisolasi; jumlah tes harus nonzero. Untuk P18, verifikasi tautan/runbook dan kesesuaian dengan bukti P17, bukan menjalankan deploy.

**Dependencies:** P11. **Scope:** M.

**Files likely touched:** `app/Filament/Resources/OrganizationOrders/OrganizationOrderResource.php`, `app/Policies/OrganizationOrderPolicy.php`, `app/Actions/Payments/StoreOrganizationPaymentProof.php`, `tests/Feature/Admin/OrganizationOrderAccessTest.php`.

### Checkpoint setelah P12

- [ ] Focused tests tiga tugas terakhir dan regresi terkait lulus tanpa skip gerbang.
- [ ] Pint, PHPStan, lint/typecheck frontend, dan build lulus sesuai perintah plan.
- [ ] Slice berjalan end-to-end pada lingkungan test; RLS/concurrency memakai PostgreSQL bila terkait. Catat bukti dan tinjau bersama pengguna sebelum kelompok berikutnya.

## P13: Token checkout terbatas

**Deskripsi / acceptance:**

- [ ] Token hashed, expiring, single-use dengan scope attempt; reissue mencabut token lama; token checkout tidak memperoleh hak mulai tes.

**Verification:** `php artisan test --filter=CheckoutHandoffTest` pada environment test terisolasi; jumlah tes harus nonzero. Untuk P18, verifikasi tautan/runbook dan kesesuaian dengan bukti P17, bukan menjalankan deploy.

**Dependencies:** P12. **Scope:** M.

**Files likely touched:** `database/migrations/<new>_create_checkout_handoffs.php`, `app/Models/CheckoutHandoff.php`, `app/Actions/Integrations/IssueCheckoutHandoff.php`, `app/Actions/Integrations/ConsumeCheckoutHandoff.php`, `tests/Feature/Integrations/CheckoutHandoffTest.php`.

## P14: Sesi ringkasan privat

**Deskripsi / acceptance:**

- [ ] Handoff menghasilkan sesi privat/CSRF tanpa PII di URL; reload melanjutkan attempt sama; referral/token invalid tidak mengalihkan cabang atau membuat akun.

**Verification:** `php artisan test --filter=CheckoutSummaryTest` pada environment test terisolasi; jumlah tes harus nonzero. Untuk P18, verifikasi tautan/runbook dan kesesuaian dengan bukti P17, bukan menjalankan deploy.

**Dependencies:** P13. **Scope:** M.

**Files likely touched:** `app/Http/Controllers/IntegratedCheckoutController.php`, `app/Http/Requests/ConsumeCheckoutHandoffRequest.php`, `app/Http/Middleware/AuthenticateCheckoutSession.php`, `routes/web.php`, `tests/Feature/Integrations/CheckoutSummaryTest.php`.

## P15: Profil kurang dan persetujuan

**Deskripsi / acceptance:**

- [ ] Hanya field yang kurang dapat diisi; cabang/payer/paket/nominal locked ditolak bila dipalsukan; consent A dan pilihan DASS versioned disimpan tanpa duplikasi.

**Verification:** `php artisan test --filter=IntegratedCheckoutConsentTest` pada environment test terisolasi; jumlah tes harus nonzero. Untuk P18, verifikasi tautan/runbook dan kesesuaian dengan bukti P17, bukan menjalankan deploy.

**Dependencies:** P14. **Scope:** M.

**Files likely touched:** `app/Http/Requests/ConfirmIntegratedCheckoutRequest.php`, `app/Actions/Registration/ConfirmIntegratedCheckout.php`, `tests/Feature/Registration/IntegratedCheckoutConsentTest.php`.

### Checkpoint setelah P15

- [ ] Focused tests tiga tugas terakhir dan regresi terkait lulus tanpa skip gerbang.
- [ ] Pint, PHPStan, lint/typecheck frontend, dan build lulus sesuai perintah plan.
- [ ] Slice berjalan end-to-end pada lingkungan test; RLS/concurrency memakai PostgreSQL bila terkait. Catat bukti dan tinjau bersama pengguna sebelum kelompok berikutnya.

## P16: Halaman checkout peserta

**Deskripsi / acceptance:**

- [ ] Profil lengkap tanpa registrasi ulang; self/org/free menuju state benar tanpa invoice kedua; UI mobile/keyboard memakai identitas ONCAM dan nominal dari server.

**Verification:** `php artisan test --filter=IntegratedCheckoutPageTest` pada environment test terisolasi; jumlah tes harus nonzero. Untuk P18, verifikasi tautan/runbook dan kesesuaian dengan bukti P17, bukan menjalankan deploy.

**Dependencies:** P15. **Scope:** M.

**Files likely touched:** `resources/js/pages/integrated-checkout.tsx`, `resources/js/types/integrated-checkout.ts`, `app/Http/Controllers/IntegratedCheckoutController.php`, `tests/Feature/Integrations/IntegratedCheckoutPageTest.php`.

## P17: Regresi lintas alur

**Deskripsi / acceptance:**

- [ ] Fake kedua sumber seleksi mencakup alur lengkap dan parsial; dua attempt/cross-tenant/replay/paralel diuji PostgreSQL; browser dan suite lama tetap lulus.

**Verification:** `php artisan test --filter=OrganizationCheckout` pada environment test terisolasi; jumlah tes harus nonzero. Untuk P18, verifikasi tautan/runbook dan kesesuaian dengan bukti P17, bukan menjalankan deploy.

**Dependencies:** P16. **Scope:** M.

**Files likely touched:** `tests/Feature/Integrations/OrganizationCheckoutAcceptanceTest.php`, `tests/Feature/Database/OrganizationCheckoutRlsTest.php`, `tests/Feature/Payments/OrganizationCheckoutConcurrencyTest.php`, `docs/ORGANIZATION_CHECKOUT_VALIDATION.md`.

## P18: Runbook dan serah-terima

**Deskripsi / acceptance:**

- [ ] Bukti test dan batasan dicatat tanpa secret; cutover/rollback aplikasi mempertahankan histori pembayaran; tidak ada deploy/notifikasi nyata tanpa izin terpisah.

**Verification:** `git diff --check` pada environment test terisolasi; jumlah tes harus nonzero. Untuk P18, verifikasi tautan/runbook dan kesesuaian dengan bukti P17, bukan menjalankan deploy.

**Dependencies:** P17. **Scope:** M.

**Files likely touched:** `docs/ORGANIZATION_CHECKOUT_OPERATIONS.md`, `docs/ORGANIZATION_CHECKOUT_VALIDATION.md`, `tasks/organization-payment/todo.md`.

### Checkpoint setelah P18

- [ ] Focused tests tiga tugas terakhir dan regresi terkait lulus tanpa skip gerbang.
- [ ] Pint, PHPStan, lint/typecheck frontend, dan build lulus sesuai perintah plan.
- [ ] Slice berjalan end-to-end pada lingkungan test; RLS/concurrency memakai PostgreSQL bila terkait. Catat bukti dan tinjau bersama pengguna sebelum kelompok berikutnya.
