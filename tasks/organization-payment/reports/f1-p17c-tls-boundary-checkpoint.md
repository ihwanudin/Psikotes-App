# F1 P17c — checkpoint boundary TLS struktural

Tanggal audit: 2026-09-08. Status: **audit-only; P17c dan P18 tetap
terbuka**. Audit ini tidak menjalankan browser, native process, listener,
server, provider, database, network, atau layanan hidup.

## Ringkasan

Empat increment test-only membentuk rantai struktural public TLS evidence sampai
verification request. Rantai ini memperkecil prasyarat kontrak untuk secure
candidate, tetapi belum menghasilkan authority yang dapat memverifikasi atau
menjalankan candidate. Semua hasil diberi marker `structuralOnly`; seluruh gate
dan payment tetap default **OFF**.

| Commit | Boundary yang diterima | Kontribusi terbatas terhadap P17c | Bukan bukti |
|---|---|---|---|
| `fee63d44bb6b17ade09b366d9af9a7474a1ceca9` | Codec public TLS evidence ADR-025 dan browser-config yang mengikat tepat satu SPKI serta disposable run-local profile | Membekukan bentuk evidence publik dan konfigurasi browser yang kelak diperlukan oleh secure-origin candidate | X.509 validation, private-key custody, trust, freshness, effective process arguments, browser launch, atau TLS transport |
| `ef2a2beba7f5afe8b4c69a2a100c2d1c82130833` | Candidate TLS-material package yang mengikat exact evidence/artifact digest, generation, replay, run/lease, dan relative create-new destinations | Membekukan packaging dan destination/profile intent tanpa path ambient atau profile reuse | File materialization, ACL efficacy, no-reparse/TOCTOU containment, cleanup, atau candidate build |
| `4326bda83e31729e62034afbe13dafe38e6f2c5b` | Future envelope v2 khusus role `tls-material`, dengan domain exact `oncam.checkout.tls-material.v1\0` dan detached Ed25519 fields yang hanya diperiksa bentuknya | Menutup role/domain confusion pada batas canonical payload/envelope; envelope v1 tetap menolak role ini | Signature verification, signer authentication, trust/revocation/time, replay consumption, atau artifact admission |
| `f189ad1e5d88571f34d4918f8fd208d1e90aa81c` | Verification-request khusus TLS yang mengikat payload/envelope, evidence/package, trust bundle, revocation snapshot, trusted-time challenge, generation, run/lease, dan one-shot replay ID | Menyediakan request input tertutup untuk verifier terpisah tanpa menerima boolean hasil dari caller | Keaslian/currentness trust bundle, snapshot, atau time challenge; signature/trust/freshness decision; replay/high-water persistence; runtime authority |

Commit tersebut hanya menyentuh
`tools/testing/tests/Browser/checkout-tls-material-*.py` dan pasangan tesnya.
Tidak ada route, production config, lockfile, checklist, ADR, candidate-builder,
supervisor, atau participant driver yang diubah oleh rangkaian ini.

## Bukti yang diulang koordinator

Koordinator tidak hanya menerima laporan worker, tetapi mengulang pemeriksaan
berikut pada masing-masing checkpoint:

- `fee63d4`: **10/10** tes codec baru dan **46/46** regresi terkait lulus;
  `py_compile` dan `diff-check` bersih.
- `ef2a2be`: **65/65** focused/regression tests lulus; `py_compile` dan
  `diff-check` bersih.
- `4326bda`: **74/74** tes terkait lulus; `py_compile` dan `diff-check`
  bersih; koordinator mengonfirmasi envelope v1 tetap fail closed dan v2 tetap
  structural-only.
- `f189ad1`: **9/9** tes request baru serta dependency set sampai total
  **96/96** lulus setelah nama modul dikoreksi; `py_compile` dan `diff-check`
  bersih; koordinator mengonfirmasi boundary tetap non-authorizing.

Angka di atas adalah rerun per checkpoint dan saling overlap; angka tersebut
tidak boleh dijumlahkan sebagai jumlah tes unik atau sebagai acceptance browser.

## Pemetaan ke acceptance P17c

Acceptance P17c meminta observasi browser desktop/mobile/keyboard untuk alur
multi-select 10 hingga paid, termasuk disabled reason, stale preview, reload,
expired, pending consent, IDOR, test-only origin, dan bukti tanpa token.
Checkpoint HTTP-loopback sebelumnya sudah memberi bukti cabang parsial, tetapi
belum membuktikan participant browser pada exact secure origin melalui candidate
yang memenuhi authority ADR-021–025.

Rangkaian empat commit ini hanya menutup sebagian prasyarat kontrak secure
origin: public TLS evidence, one-SPKI/browser-profile binding, candidate package,
future envelope v2, dan verification request. Tidak ada satu pun acceptance UI
P17c yang menjadi observed/pass karena increment ini. Secara khusus belum ada:

- signed dan independently verified TLS artifact yang current, tidak revoked,
  serta replay-consumed melalui trusted-time dan protected high-water state;
- real certificate/key generation, exact X.509 policy validation, private-key
  handle custody, one-shot passphrase/acknowledgment, atau no-path-reopen proof;
- accepted final preparation/composition/release chain dan native launcher
  identity, token, job, handle-list, listener ordering, serta cleanup/crash proof;
- exact effective Chromium process arguments, single-SPKI enforcement,
  disposable profile lifecycle, DNS/resolver/network containment, atau TLS
  handshake pada `https://psikotes.oncam.id`;
- participant DOM/keyboard/geometry observations, paid/locked atau paid/ready
  projection, IDOR/reload/expiry behavior, screenshots, dan evidence envelope
  tanpa credential pada secure candidate.

Karena bukti di atas belum ada, browser atau supervisor lama tidak boleh
dijalankan untuk membuat status tampak hijau. HTTP fallback,
`ignoreHTTPSErrors=true`, global certificate bypass, ambient trust/key material,
atau profile reuse tetap berada di luar authority yang diterima.

## Dependency aman berikutnya

Audit read-only menemukan vocabulary belum lengkap untuk verifikasi TLS:
`checkout-verifier-request.py` sudah memuat role `tls-material`, tetapi
`checkout-trust-root-bundle.py` dan `checkout-revocation-snapshot.py` belum
memuat role tersebut. Verification request baru karena itu hanya dapat mengikat
opaque digest/generation; ia belum mempunyai accepted TLS trust/revocation
namespace yang dapat divalidasi.

Increment aman berikutnya harus dimulai dengan **contract freeze oleh
koordinator** untuk exact TLS issuer-key entry, trust-generation relation, dan
revocation namespace/generation. Setelah freeze, satu increment pure,
default-OFF, test-first dapat menambah boundary struktural TLS pada trust bundle
dan revocation snapshot atau codec khusus non-overlap. Increment itu tetap hanya
boleh memvalidasi canonical schema dan cross-binding; jangan mengklaim signature,
trust, freshness, currentness, high-water durability, atau replay consumption.
Verifier evidence/composition baru layak dijadwalkan sesudah vocabulary tersebut
diterima dan direview bersama envelope v2/request boundary.

Native materializer, launcher, browser, listener, dan secure participant run
tetap dependency yang lebih akhir, bukan next safe pure increment.

## Status formal dan resume point

Perhitungan ulang langsung dari checkbox canonical pada audit ini:

- `tasks/todo.md`: **81/100** selesai;
- `tasks/organization-payment/todo.md`: **92/120** selesai;
- total: **173/220 = 78,6%**.

Tidak ada checkbox atau persentase canonical yang diubah. P17c tetap unchecked;
P18 bergantung pada P17c dan juga tetap unchecked. Checkpoint setelah P18 tetap
terbuka. Report ini bukan runbook P18, bukan evidence browser, dan bukan izin
deploy/activation.

Jika sesi terputus, lanjutkan dari review commit terakhir `f189ad1`, baca ADR-021
dan ADR-025 serta report ini, lalu minta contract freeze trust/revocation TLS dari
koordinator. Jangan memulai native/browser/live run dari checkpoint struktural
ini.
