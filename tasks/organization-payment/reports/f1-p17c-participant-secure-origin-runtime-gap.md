# F1 P17c — gap runtime participant pada secure origin

Tanggal audit: 2026-09-08. Baseline: `b039307014e91803aeb34f033c876d23d000d244`.
Status: **audit lokal non-runtime; P17c dan P18 tetap open**.

## Keputusan bounded

Participant UI belum aman dijalankan melalui secure-origin candidate. Codec TLS
yang sudah diterima membekukan bentuk evidence, package, envelope v2, dan request
verifikasi, tetapi semuanya masih `structuralOnly`. Trust/revocation authority,
materializer, native handle consumer, dan exact browser-process binding belum
tersedia sebagai satu rantai yang dapat mengotorisasi runtime.

Karena itu audit ini sengaja tidak menjalankan browser, server, listener, native
process, database, provider, payment, atau notification. Menjalankan runner lama
melalui HTTP, memasang trust ambient, menyuplai key dari caller, atau memakai TLS
bypass akan menghindari—not prove—boundary ADR-021/022/024/025.

## Evidence dan residual gap

| Boundary | Evidence lokal | Kesimpulan terbatas |
|---|---|---|
| Public TLS evidence dan browser intent | `checkout-tls-material-evidence.py:76,308-326,356-369` mengikat satu SPKI, disposable persistent profile, serta `ignoreHTTPSErrors=false`; 10 test evidence lulus sebagai bagian dari 37 test TLS-material | Bentuk canonical terbukti; X.509, private-key custody, effective Chromium arguments, dan transport tidak terbukti |
| Package, envelope, dan verification request | `checkout-tls-material-package.py:236,253`, `checkout-tls-material-envelope-v2.py:222-223,293`, dan `checkout-tls-material-verification-request.py:232,253` mempertahankan marker `structuralOnly`; seluruh 37 test TLS-material lulus | Digest/identity/replay intent terikat secara struktural; signature, trust, freshness, revocation, dan replay consumption tidak terjadi |
| Trust/revocation namespace | `checkout-trust-root-bundle.py:20-29` dan `checkout-revocation-snapshot.py:31-58` belum memiliki role `tls-material`; masing-masing 15 dan 10 test lulus untuk schema yang ada | Verification request hanya dapat menunjuk digest/generation opaque; belum ada accepted TLS trust/revocation authority |
| Candidate materialization | `checkout-candidate-builder.py:43` masih mendefinisikan `cert` dan `key` sebagai run-local files; `:712` membuka ulang keduanya melalui `SSLContext.load_cert_chain`; `:807` menulis `key.pem` | Jalur candidate lama bertentangan dengan custody/no-path-reopen yang diperlukan ADR-025; tidak boleh dipakai sebagai bukti secure composition |
| Browser config/supervisor | `checkout-candidate-builder.py:816-819` dan `checkout-supervisor.py:191-192` memakai schema lama `{offline, serviceWorkers}`; `checkout-supervisor.py:92` belum menerima exact one-SPKI/process-profile binding dari codec TLS baru | Empat guard/schema test lulus, tetapi codec TLS baru belum menjadi input candidate/supervisor dan belum menjadi effective browser process configuration |
| Participant driver | `node --check tools/testing/tests/Browser/checkout-session.browser.mjs` lulus; test `test_participant_driver_never_bypasses_tls_errors_for_secondary_context` lulus | Syntax dan guard statis terbukti; tidak ada navigasi, secure context, screenshot, keyboard, mobile, atau participant-flow observation pada audit ini |

Tidak ditambahkan test-only protection baru. Assertion yang hanya memastikan
wiring tetap hilang akan membekukan gap, bukan menutupnya. Mengubah schema
supervisor lama agar menerima object TLS baru juga akan mengklaim integrasi tanpa
trust decision, key custody, atau native authority yang diperlukan.

## Perintah verifikasi

Semua perintah berikut murni lokal dan tidak meluncurkan browser atau service:

```text
python -m unittest discover -s tools/testing/tests/Browser -p "test_checkout_tls_material_*.py"
# 37 tests, PASS

python -m unittest discover -s tools/testing/tests/Browser -p "test_checkout_trust_root_bundle.py"
# 15 tests, PASS

python -m unittest discover -s tools/testing/tests/Browser -p "test_checkout_revocation_snapshot.py"
# 10 tests, PASS

python -m unittest tools.testing.tests.Browser.test_checkout_supervisor.SupervisorTests.test_candidate_config_shape_is_exact_before_filesystem_or_identity tools.testing.tests.Browser.test_checkout_supervisor.SupervisorTests.test_browser_config_decoder_accepts_only_exact_semantic_schema tools.testing.tests.Browser.test_checkout_supervisor.SupervisorTests.test_browser_launch_args_require_exact_canonical_ordered_list tools.testing.tests.Browser.test_checkout_supervisor.SupervisorTests.test_participant_driver_never_bypasses_tls_errors_for_secondary_context
# 4 tests, PASS

node --check tools/testing/tests/Browser/checkout-session.browser.mjs
# PASS
```

Total Python: **66 tests PASS**. Ini evidence structural/static saja, bukan
acceptance browser P17c.

## Dependency aman berikutnya

Increment terkecil berikutnya bukan participant browser run. Koordinator perlu
lebih dahulu membekukan exact `tls-material` trust-root dan revocation namespace,
kemudian menerima verifier yang benar-benar menghasilkan keputusan signature,
trust, freshness, generation/high-water, dan one-shot replay. Setelah authority
itu tersedia, candidate/supervisor v2 dapat diintegrasikan dengan materializer
dan native inherited-handle consumer tanpa serialisasi private-key path atau
path reopen, lalu effective one-SPKI Chromium args dan disposable profile dapat
dibuktikan sebelum navigasi participant.

P17c tidak mendapat status observed/pass dari audit ini. P18 tetap bergantung
pada P17c dan juga tetap open; checklist, plan, todo, parallel-work, serta ADR
tidak diubah.
