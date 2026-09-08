# F1 P17c — diagnostic gap listener inspection

Tanggal diagnosis: 2026-09-08. Baseline:
`0c0415d8af60899a87daa7e142ba056dca409a75`. Status: **read-only diagnosis;
tidak ada code fix atau runtime authorization**.

Tidak ada browser, server, supervisor lifecycle, native candidate, database,
provider, firewall, atau listener eksternal yang dijalankan, dihentikan, atau
diubah. Focused reproduction berhenti sebelum PowerShell dan sebelum test
membuka socket miliknya sendiri.

## Reproduksi

Command existing yang dijalankan:

```powershell
$env:PYTHONDONTWRITEBYTECODE='1'
python tools/testing/tests/Browser/test_checkout_supervisor.py SupervisorTests.test_real_listener_inspector_covers_ipv4_and_ipv6_wildcards_without_killing
```

Hasil: **1 test, 1 error** dalam **0,004 detik**. Error terlihat pada pemanggilan
awal `run._listeners()` di line 2126 dan telah disanitasi menjadi
`listener_inspection_failed` oleh `_listeners`. Pemanggilan awal terjadi sebelum
blok yang bind socket `0.0.0.0:8126` dan `[::]:443`; karena itu reproduction ini
tidak membuka atau menutup listener apa pun.

Portable controls existing:

```powershell
python tools/testing/tests/Browser/test_checkout_supervisor.py `
  SupervisorTests.test_listener_outcomes_distinguish_free_occupied_and_inspection_failure `
  SupervisorTests.test_listener_inspection_failure_is_not_reported_as_occupation
```

Hasil: **2/2 lulus** dalam **0,001 detik**. Keduanya membuktikan parser/outcome
contract dengan `_ps` sintetis: array kosong berarti free, array valid nonempty
berarti occupied, dan command/JSON/shape failure tetap
`listener_inspection_failed` tanpa payload privat.

ACL transition control existing juga lulus **1/1** dalam **0,487 detik**:

```powershell
python tools/testing/tests/Browser/test_checkout_supervisor.py `
  SupervisorTests.test_acl_admission_transitions_are_exact_and_loads_are_one_shot
```

Control ini membuktikan `begin_acl_execution("fresh")` hanya tersedia setelah
anchor dan execution admission yang lengkap, dan token tersebut one-shot.

## Root cause exact

Jalur panggilan pada baseline adalah:

```text
test line 2126
  -> WindowsRun._listeners()
  -> WindowsRun._ps(..., timeout=6)
  -> WindowsRun._command(..., policy="untracked", role="helper")
  -> _require_acl_active("fresh")
  -> Refused("acl_admission")
  -> _listeners redacts to Refused("listener_inspection_failed")
```

`WindowsRun` pada host-native test hanya diberi `directory`, path PowerShell,
environment minimal, dan infinite I/O deadline. Test tidak bind lifecycle lease,
ACL attestor, anchor publisher, atau memanggil preparation/begin ACL execution.
State privat ACL karena itu tetap `unbound`, sedangkan `_require_acl_active`
mewajibkan state `active`, execution token non-null, dan phase exact `fresh`.

History mengonfirmasi urutan regresi:

- `a6d72c7c8ee731a933a307ba7ff3eeba6cc59f35` menambahkan host-native listener
  test pada 2026-09-05;
- `15a5509f2dd3a51e1be619d0f0041ac595149e8e` kemudian menambahkan
  `_require_acl_active(phase)` ke `_command` pada 2026-09-06;
- fixture test lama tidak diadaptasi terhadap precondition baru tersebut.

Durasi 0,004 detik konsisten dengan refusal in-process sebelum `subprocess.Popen`.
Ia tidak konsisten dengan cold start PowerShell, timeout enam detik, kegagalan
module `Get-NetTCPConnection`, JSON output host, privilege jaringan, port yang
occupied, atau limitasi IPv4/IPv6. Pada reproduction ini exact PowerShell query
tidak pernah dijalankan, sehingga keadaan module/host network tidak dapat
disimpulkan dari error tersebut.

## Environment limitation versus code defect

| Pertanyaan | Verdict | Bukti |
|---|---|---|
| Apakah host tidak mendukung `Get-NetTCPConnection` atau IPv6? | **Tidak terbukti dan bukan trigger reproduction ini** | Refusal terjadi sebelum process PowerShell dibuat; decorator IPv6 lolos, tetapi test belum mencapai socket bind/query |
| Apakah parser free/occupied/failure rusak? | **Tidak** | Dua portable tests lulus dan tetap membedakan empty/nonempty/failure secara fail closed |
| Apakah production ACL gate rusak? | **Tidak ada bukti defect** | Gate menolak helper sebelum accepted execution seperti yang dirancang; ACL transition control lulus |
| Apakah full-suite error adalah defect? | **Ya, defect fixture test yang stale** | Host test memanggil jalur gated tanpa menyusun ACL execution prerequisite yang ditambahkan setelah test dibuat |

`listener_inspection_failed` tetap benar sebagai external sanitized category,
tetapi kategori itu menyembunyikan internal `acl_admission` secara sengaja. Ia
tidak boleh ditafsirkan sebagai bukti module/network host gagal.

Laporan historis `backend-supervisor-listener-diagnostic.md` mencatat kemungkinan
cold-module latency untuk kegagalan yang lebih lama dan menghasilkan allowance
enam detik. Temuan itu tidak menjelaskan baseline error saat ini: regression ACL
terjadi kemudian dan current focused test selesai jauh sebelum allowance
tersebut. Timeout enam detik tidak perlu diubah berdasarkan evidence ini.

## Smallest safe fix proposal — tidak diimplementasikan

Ubah **hanya**
`tools/testing/tests/Browser/test_checkout_supervisor.py` pada host-native test;
jangan menghapus atau melonggarkan `_require_acl_active` di production
`checkout-supervisor.py`.

Untuk menjaga test tetap fokus pada real PowerShell listener query, pasang
test-local exact-phase ACL precondition adapter pada instance `WindowsRun`:

1. adapter hanya menerima literal phase `fresh`, menolak phase lain, dan
   mencatat setiap pemanggilan;
2. adapter berlaku hanya selama satu test dan tidak mengubah class/module;
3. pertahankan PowerShell path, sanitized environment, six-second bound, real
   `_ps/_command`, JSON validation, owned IPv4/IPv6 socket handles, serta final
   empty inspection;
4. assertion memastikan ACL adapter benar-benar dipanggil sebelum setiap helper;
5. `finally` hanya menutup socket handles yang dibuat test; jangan mencari,
   mengadopsi, atau membunuh PID/listener eksternal.

Ini lebih kecil dan lebih terisolasi daripada membuat synthetic full supervisor
candidate hanya untuk menguji network inspector. Production ACL admission sudah
memiliki focused lifecycle tests sendiri. Alternatif yang lebih besar adalah
mengekstrak low-level query/parser di belakang injected command runner, tetapi
refactor tersebut tidak diperlukan untuk menutup regression fixture ini.

Required verification untuk fix terpisah:

- host-native focused test mencapai query dan lulus atau skip hanya bila exact
  controlled ports memang occupied/tidak dapat di-bind;
- dua portable listener tests tetap lulus;
- ACL transition/gating tests tetap lulus;
- full supervisor menjadi **135/135** pada host yang dapat bind kedua controlled
  listeners, tanpa mengurangi redaction atau fail-closed behavior;
- `py_compile` dan `git diff --check` lulus.

Diagnosis ini tidak mengotorisasi fix, native/browser run, smoke retry, port
cleanup, atau perubahan firewall. P17c/P18 dan seluruh checklist tetap terbuka.
