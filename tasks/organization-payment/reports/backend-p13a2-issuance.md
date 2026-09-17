# P13a2 backend — internal checkout handoff issuance

Tanggal: 2026-09-02

## Hasil

P13a2 menambah boundary internal `IssueCheckoutHandoff` tanpa route, controller,
consume, session, atau wiring publik. Surface typed hanya menerima persisted
`IntegrationClient`, public ULID attempt, source-system selector, opaque
idempotency key, dan enum ISSUE/REISSUE. Purpose, destination, contract version,
tenant, participant, package, payer, nominal, dan URL tidak menjadi input.

Input menyimpan raw idempotency key sebagai properti private dengan constructor
`SensitiveParameter`; result melakukan hal yang sama untuk raw bearer. Descriptor
dan JSON serialization tidak mengekspor credential. Token memakai `och1_` plus
64 lowercase hex dari `random_bytes(32)` dan hanya SHA-256 digest yang durable.
Replay exact mengembalikan raw null dan mewajibkan explicit reissue.

Action mengharuskan current service RLS context dan reload graph authoritative
dengan urutan organization → client → source → package/items → attempt →
participant → handoff issue/id. Client, source, organisasi, package, participant,
metadata checkout-v2, scope, status PROVISIONED, revocation, serta effective
windows diperiksa ulang. History divalidasi untuk scope, issue sequence, TTL,
active marker, dan timestamp lifecycle exact.

ISSUE hanya boleh membuat history pertama. REISSUE wajib mempunyai tepat satu
active row, lalu atomik mengubahnya menjadi REVOKED/REISSUED atau EXPIRED dan
membuat generasi baru. Audit issued/reissued memakai database clock, retensi dua
tahun, dan context allowlist tanpa bearer/digest/idempotency/request hash,
external identity, PII, URL, IP, atau credential reference. Audit/replay/failure
tidak membuat billing, entitlement, order, outbox, identity, consent, atau
session side effect.

## TDD dan verifikasi aktual

- RED: **3 tes, 3 assertions, 3 error** karena enum, input, dan action belum ada.
- Focused GREEN final: **12 tes, 144 assertions**.
- Related checkout/schema regression: **138 tes, 967 assertions**.
- Pint scoped lulus; PHPStan scoped testing/SQLite memory lulus **0 error**.
- `git diff --check` dan staged-path audit dijalankan sebelum setiap commit.

Focused test mencakup service/no-context dan seluruh role non-service, persisted
client/stale credential/disabled/effective windows, deleted source/client/
attempt, organization/source/package/participant/metadata/status invalid,
cross-source, strict config OFF/type/range dan TTL 60/600, input reflection,
malformed serta PII-like key, digest-only serialization/audit, first issue,
exact replay, same-key intent/scope conflict, explicit reissue, observed expiry,
corrupt history, insert/audit rollback, database clock, dan zero unrelated side
effects.

## Delta dan batas

File kode/tes lane:

- `app/Enums/CheckoutHandoffIntent.php`
- `app/Data/Integrations/CheckoutHandoffIssueInput.php`
- `app/Data/Integrations/CheckoutHandoffIssueResult.php`
- `app/Actions/Integrations/IssueCheckoutHandoff.php`
- `tests/Feature/Integrations/CheckoutHandoffIssuanceTest.php`

`config/assessment_integration.php` masih untracked pada snapshot worker, sehingga
tidak dikomit sebagai file baseline. Patch exact yang harus diterapkan root:

```php
// Internal issuer remains fail-closed until separately wired and activated.
'checkout_handoff' => [
    'enabled' => false,
    'ttl_seconds' => 600,
],
```

Tidak ada klaim concurrency PostgreSQL; two-process ISSUE/REISSUE tetap P13a3.
Tidak ada migration aktif, `.env`, credential/data nyata, outbound, route,
controller, consume, session, gate, command/scheduler, deploy, push, P13b, atau
P14. **STOP untuk review P13a2.**

## Review fix — post-lock database clock dan package contract

Clock database pertama sekarang hanya menjadi observasi cepat. Setelah
organization, client, source, package/items, attempt, participant, dan seluruh
handoff history terkunci, action membaca clock database kedua. Client/source
effective window divalidasi ulang terhadap clock final itu. Hanya clock final
yang dipakai untuk transition EXPIRED/REVOKED, `issued_at`, `expires_at`, dan
audit. Test menggeser application clock ke 2040 dan membuktikan timestamp issue
tetap dekat `CURRENT_TIMESTAMP` database.

P13a3 wajib menambah acceptance two-process eksplisit: worker issuer menunggu
canonical lock sampai `effective_until` client atau source terlewati; setelah
lock dilepas, final database clock recheck harus menolak tanpa handoff, audit,
atau raw result. P13a2 tidak mengklaim bukti race tersebut dari SQLite.

Package authoritative kini harus active, termasuk dalam allowlist source,
mempunyai item, amount integer persisted non-null dan >=0, currency tepat IDR,
serta consultation amount null atau >=0. Tidak ada harga tertentu yang
dihardcode. Nilai null/negatif, currency asing, item kosong, inactive, dan tidak
diizinkan semuanya fail closed.

Rollback audit failure juga diuji pada REISSUE setelah prior active diubah dan
generasi baru dimasukkan. Trigger audit menggagalkan transaksi; old active row
tetap byte-for-byte sama, tidak ada generation/audit baru, dan tidak ada result
raw. Existing insert failure serta first-issue audit failure tetap lulus.

Bukti final review fix: focused **13 tes, 156 assertions**; related checkout/
schema regression **139 tes, 979 assertions**; Pint dan PHPStan scoped lulus
tanpa error; diff-check bersih. Config baseline worker tidak disentuh/commit.
**STOP sebelum P13a3/route/P13b/P14.**
