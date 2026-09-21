# Rencana — kamera mati di tengah tes (butir 12) & foto berkala

**Tanggal:** 2026-09-21. **Ditulis oleh:** kanal FE-Infra, atas instruksi
Lead (disetujui pemilik proyek). **Sifat:** rencana saja — tidak ada
komponen/kode produksi di PR ini. Dibaca sebelum implementasi.

Topik (keputusan pemilik butir 12, relay Lead): kalau kamera mati di
tengah tes, tes tetap berlanjut, kamera dicoba diaktifkan ulang, dan
jeda tanpa kamera dicatat untuk psikolog.

## 0. Sumber yang dibaca

Kode (dibaca langsung, bukan ditebak — semua kutipan di bawah punya
rujukan baris):

- `resources/js/components/participant/session-runner/camera-controller.ts`
  (state machine murni: `inactive → requesting → active`,
  `active → interrupted → reactivating → active|reactivation_failed`).
- `resources/js/components/participant/session-runner/camera-controller.test.ts`
  (kontrak yang sudah terkunci test — dikutip di §2).
- `resources/js/components/participant/session-runner/use-proctoring-camera.ts`
  (pembungkus hook: `getUserMedia` sungguhan, listener `track.onended`,
  `visibilitychange`/`focus` yang memicu `reactivate()`).
- `resources/js/components/participant/session-runner/proctoring-reporter.ts`
  (`ProctoringEventKind`, `ProctoringEvent`, `noopProctoringReporter`).
- `resources/js/components/participant/session-runner/session-runner-shell.tsx`
  (badge status kamera `CAMERA_STATUS_LABEL`, timer server-authoritative
  yang independen dari status kamera, render-prop `children`).
- `app/Domain/Proctoring/ProctoringEvent.php`,
  `ProctoringEventKind.php`, `ProctoringEvidenceSource.php`,
  `ProctoringInstrument.php`, `ProctoringValidityPolicy.php` — domain
  murni PHP yang **sudah ada di `main`** (bukan proposal), menentukan
  jenis event yang sah dan bagaimana V1/V2/V3 diputuskan.
- `tests/Unit/Proctoring/ProctoringValidityPolicyTest.php` — mengunci
  perilaku "kamera terputus, durasi berapa pun → V2" dengan nama test
  eksplisit (dikutip §3).
- `tasks/handoffs/f7/proctoring-persistence-proposal.md` — proposal F7
  yang sudah ada (skema `proctor_photos`/`proctor_logs`, endpoint
  ingest, RLS). Status: **PROPOSAL ONLY**, belum ada migrasi/route.
  Dipakai sebagai rujukan bentuk yang paling mungkin dipakai F2, bukan
  diputuskan ulang di sini.

Dokumen kebijakan:

- `CLAUDE.md` — proctoring = deteksi bukan cegah; larangan menanam
  bobot/ambang di kode; timer tidak boleh berhenti; HP kamera berhenti
  saat app-switch/lock wajib dideteksi + dicoba aktif ulang.
- `SPEC.md` §8A.1–8A.7 (dikutip §1–§4 di bawah) dan §13 T-25–T-28.
- `tasks/handoffs/decisions/owner-decisions-2026-09-21.md` — belum
  memuat butir 11/12 (masih relay pesan Lead langsung); dicatat di sini
  supaya tidak diasumsikan sudah tertulis di tempat lain.

## 1. Cakupan increment ini

**Direncanakan di sini (menunggu tinjauan Lead sebelum ditulis kodenya):**

- Perilaku deteksi & pelaporan jeda kamera di tengah sesi (§2–§3).
- Bentuk kontrak yang FE kirim ke reporter untuk jeda tersebut, dan
  batas jelas ke sisi server (§3).
- Rancangan modul foto berkala (§4) — **tanpa nilai piksel/kualitas
  final**, karena itu masih usulan pemilik yang belum diputuskan.
- Pembagian berkas GLM vs berkas baru (§5).

**SENGAJA TIDAK direncanakan di sini:**

- Skema migrasi/route/controller/RLS backend — itu sudah dibahas
  panjang di `proctoring-persistence-proposal.md` (F2/Codex), dan tetap
  keputusan F2, bukan diulang atau diputuskan ulang di sini.
- Pencocokan wajah berkala (8A.2 butir pencocokan wajah) — model
  verifikasi terpisah, di luar topik butir 12.
- Halaman per-instrumen (IST/PAPI/RMIB/Kraepelin) itu sendiri — belum
  dibangun GLM; rencana ini hanya menyentuh `session-runner-shell.tsx`
  (kerangka bersama) yang SUDAH ada.
- Nilai konfigurasi server (interval capture, ukuran, kualitas, siapa
  yang menyimpannya) — itu keputusan pemilik + F2, rencana ini hanya
  menegaskan bahwa klien TIDAK BOLEH menanam angka itu sebagai
  konstanta (lihat §4).

## 2. Deteksi stream mati — sudah dibangun, satu celah ditemukan

**Sinyal yang dipakai (sudah berjalan, tidak perlu diubah):**
`use-proctoring-camera.ts:104-108` memasang listener `track.addEventListener('ended', ...)`
pada `MediaStreamTrack` video begitu `getUserMedia` berhasil. Saat
peserta berpindah aplikasi/mengunci layar di HP, browser menghentikan
track itu sendiri (perilaku platform, bukan sesuatu yang kita picu) —
event `'ended'` itulah sinyalnya, persis yang CLAUDE.md minta ("WAJIB
deteksi stream-mati"). Ini **bukan** `visibilitychange`: `visibilitychange`/
`focus` (`use-proctoring-camera.ts:122-129`) dipakai untuk sinyal
**"peserta kembali"** yang memicu percobaan aktivasi ulang, sinyal yang
berbeda dari "stream mati".

**Model status (`camera-controller.ts:20-28`, sudah lengkap):**

```
active --(track 'ended')--> interrupted --(reactivate(), berhasil)--> active
                                         --(reactivate(), gagal)-----> reactivation_failed
```

Setiap transisi sudah melapor ke `ProctoringReporter` dengan timestamp
sendiri (`camera-controller.ts:126-170`):
`camera_interrupted` (saat track berakhir) → `camera_reactivation_attempted`
(saat `reactivate()` dipanggil) → `camera_reactivation_succeeded` ATAU
`camera_reactivation_failed`. Ini sudah diuji lengkap di
`camera-controller.test.ts:99-199` (empat skenario: interrupted-and-reported,
reactivate-no-op-unless-interrupted, reactivation-succeeds,
reactivation-fails-dan-tak-pernah-mengklaim-sukses).

**Percobaan aktivasi ulang — otomatis, dipicu "peserta kembali":**
`use-proctoring-camera.ts:117-129` memanggil `controller.reactivate()`
tanpa syarat setiap `visibilitychange` (jadi visible) atau `focus` —
aman karena `reactivate()` sendiri no-op kecuali status sedang
`interrupted` (`camera-controller.ts:139-141`). Ini persis yang SPEC
8A.2 minta: "mencoba mengaktifkan ulang kamera **saat peserta kembali**"
— bukan retry berbasis hitungan/waktu, tapi berbasis peristiwa peserta
kembali ke tab. Cocok juga dengan T-26 ("dicoba aktif ulang").

**Celah yang ditemukan (dibaca dari kode, bukan tebakan):** `reactivate()`
hanya bertindak bila `status === 'interrupted'`
(`camera-controller.ts:139-141`). Begitu satu percobaan gagal dan status
menjadi `reactivation_failed`, **tidak ada jalur otomatis lain yang
mengembalikannya**: `visibilitychange`/`focus` berikutnya tetap memanggil
`reactivate()`, tapi karena status sudah bukan `'interrupted'`, panggilan
itu selalu no-op — selamanya, untuk sisa sesi. Tidak ada test yang
menegaskan perilaku ini secara eksplisit, tapi ini konsekuensi langsung
dan tak terhindarkan dari guard tersebut. Dengan kata lain: **begitu satu
kali reaktivasi gagal, sistem berhenti mencoba lagi secara otomatis**,
padahal bahasa SPEC 8A.2/T-26 ("dicoba aktif ulang", tanpa batas jumlah
disebut) terbaca sebagai upaya berkelanjutan setiap peserta kembali.

**Dua opsi perbaikan (butuh keputusan Lead sebelum kode, karena ini
mengubah kontrak berkas GLM yang sudah diuji):**

1. **Longgarkan guard `reactivate()`** supaya juga bertindak dari status
   `reactivation_failed` (bukan cuma `interrupted`) — perbaikan satu baris
   secara konsep, tapi menyentuh state machine yang testnya sudah
   mengunci perilaku persis "no-op kecuali interrupted"
   (`camera-controller.test.ts:113-121`); test itu perlu diperbarui
   berbarengan. Ini yang paling dekat dengan bahasa SPEC — otomatis,
   tanpa aksi peserta tambahan, setiap kali mereka kembali ke tab.
2. **Tambahkan affordance manual** ("Coba lagi" yang memanggil
   `camera.activate()`, bukan `reactivate()` — `activate()` tidak
   punya guard status apa pun, jadi selalu bisa dipanggil ulang dari
   `reactivation_failed`) di `session-runner-shell.tsx`, di sebelah
   badge status. Ini pola yang sama dengan tombol "Coba lagi" di layar
   persetujuan (PR #83) untuk `denied`/`unavailable`.

Rekomendasi: **lakukan (1) sebagai perbaikan inti** (memenuhi bahasa
SPEC tanpa peserta harus berbuat apa-apa), dan pertimbangkan (2) sebagai
pelengkap opsional bila Lead/psikolog ingin peserta punya kendali
eksplisit juga — bukan pengganti (1). Catatan: memanggil `activate()`
dari `reactivation_failed` melapor lewat cabang `activate()`
(`camera_permission_denied`/`camera_unavailable`), BUKAN
`camera_reactivation_*` — jadi kalau (2) dibangun, riwayat peristiwa
tidak akan membedakan "percobaan ulang manual pasca-gagal" dari
"aktivasi awal yang gagal". Ini detail kecil, dicatat supaya tidak
mengejutkan saat psikolog membaca linimasa nanti — tidak menghalangi
keputusan (1)/(2), hanya perlu diketahui.

## 3. Aktivasi ulang — yang dilihat peserta, dan pencatatan jeda

**Tes tidak pernah dikunci/dijeda — sudah benar di kode saat ini:**
`session-runner-shell.tsx:103-107` membangun `displayRemainingSeconds`
dari `useAssessmentSession`, sepenuhnya independen dari `camera.status`
— tidak ada jalur di berkas ini yang menghentikan timer karena kamera
bermasalah. Tidak perlu perubahan untuk menjaga ini; hanya perlu
**tidak ditambahkan** logika apa pun yang mengaitkan timer dengan status
kamera nanti.

**Apa yang peserta lihat — sudah ada, sudah jujur ("deteksi, bukan cegah"):**
`CAMERA_STATUS_LABEL` (`session-runner-shell.tsx:61-71`) sudah memetakan
kedelapan status termasuk yang relevan di sini:
`interrupted` → "Kamera terputus — mencoba menyambungkan ulang",
`reactivating` → "Menyambungkan ulang kamera…",
`reactivation_failed` → "Gagal menyambungkan ulang kamera". Ini badge
pasif (ikon + teks di header), tampil otomatis mengikuti `camera.status`
— tidak perlu diubah untuk memenuhi butir 12, kecuali (2) di §2 disetujui
(tombol tambahan di sebelah badge yang sama).

**Pencatatan jeda — FE sudah melapor faktanya; yang belum ada hanya
saluran ke server:**
Seperti dikutip di §2, `camera-controller.ts` SUDAH memanggil
`reporter.report()` empat kali per siklus jeda, masing-masing dengan
timestamp sendiri lewat `now()` (`camera-controller.ts:38,69`):
`camera_interrupted` (mulai jeda) → `camera_reactivation_attempted` →
`camera_reactivation_succeeded` **atau** `camera_reactivation_failed`
(akhir jeda). Durasi jeda karena itu bisa dihitung dari selisih dua
timestamp ini — **tanpa perlu klien menghitung/menyimpan durasi
sendiri**, karena kedua ujungnya sudah dikirim sebagai fakta terpisah.
Ini sesuai instruksi "klien hanya melaporkan fakta": setiap `report()`
mengirim satu fakta bertanda waktu, bukan kesimpulan durasi atau
keputusan validitas.

**Yang belum ada — bukan di sisi FE, tapi celah interoperabilitas yang
perlu F2 tahu:**

1. `noopProctoringReporter` (`proctoring-reporter.ts:35-41`) memang
   sengaja tidak melakukan apa pun — belum ada endpoint ingest sama
   sekali (`proctoring-persistence-proposal.md` masih PROPOSAL ONLY).
   Begitu F2 membangun endpoint, implementasi `ProctoringReporter`
   sungguhan (mis. `http-proctoring-reporter.ts`, berkas BARU, bukan
   mengubah `noopProctoringReporter`) tinggal dioper sebagai prop
   `proctoring.reporter` ke `SessionRunnerShell` — tidak perlu
   mengubah `use-proctoring-camera.ts` atau `camera-controller.ts`
   sama sekali, karena keduanya sudah menerima `reporter` sebagai opsi.
2. **Celah enum:** `ProctoringEvent::allowedKinds()` untuk
   `ClientObservation` (`ProctoringEvent.php:37-47`) HANYA mengizinkan
   `CameraPermissionDenied`, `CameraUnavailable`, `CameraInterrupted`,
   `ScreenDeparture`, `FaceMismatch`, `SecondFaceDetected`,
   `AudioAssistanceDetected` — **tidak ada** kind untuk
   "reaktivasi dicoba/berhasil/gagal". FE-nya sudah dan akan terus
   mengirim ketiga fakta itu (lewat `reporter.report()`, sudah ada),
   tapi implementasi `ProctoringReporter` sungguhan tidak akan punya
   `ProctoringEventKind` PHP yang sah untuk dikirim sampai F2
   memutuskan salah satu: (a) menambah case baru ke
   `ProctoringEventKind.php`/`allowedKinds()` untuk reaktivasi (persis
   yang `proctoring-persistence-proposal.md` sendiri usulkan di daftar
   `event_kind` yang diperluas), atau (b) memetakan ketiganya jadi satu
   `CameraInterrupted` di sisi HTTP client FE dengan metadata tambahan.
   **Ini keputusan F2, bukan diputuskan di sini** — dicatat supaya
   implementasi `http-proctoring-reporter.ts` (saat waktunya tiba) tahu
   harus menunggu konfirmasi bentuk kind sebelum ditulis, bukan menebak.
3. Endpoint & skema penyimpanan: rujuk
   `proctoring-persistence-proposal.md` §"Event ingest proposal"
   (`POST /participant/sessions/{session}/proctoring/events`, kolom
   `proctor_logs`). Rencana ini tidak mengulang atau mengubah usulan
   itu — hanya menegaskan FE sudah siap mengirim begitu endpoint dan
   bentuk kind final ada.

**Ambang V2 — sudah dibaca dari data, bukan ditanam, dan sudah dikunci
test:** `ProctoringValidityPolicy::requiresV2()` (`ProctoringValidityPolicy.php:111-121`)
mencantumkan `CameraInterrupted` TANPA pemeriksaan durasi apa pun —
kemunculan satu kali sudah cukup. Ini dikonfirmasi eksplisit oleh nama
test `ProctoringValidityPolicyTest.php:42`: **"camera interrupted for
any duration"**. Ini menjawab satu ketegangan bahasa di SPEC.md sendiri
yang perlu dilaporkan (CLAUDE.md: "laporkan konfliknya, jangan diam-diam
memilih"): §8A.2 menulis "berapa pun durasinya → V2" (tegas, tanpa
ambang), sementara §13 T-26 menulis "gap **>ambang** → V2" (menyiratkan
ada angka ambang). Kode yang sudah berjalan (`ProctoringValidityPolicy.php`

- testnya) mengikuti §8A.2: **tidak ada ambang, satu kemunculan pun
  cukup**. Rencana ini mengikuti kode yang sudah ada dan teruji, dan
  melaporkan bahwa kalimat T-26 ("gap>ambang") sudah usang dibanding
  implementasi — bukan keputusan baru dari saya, hanya pengamatan yang
  perlu dicatat supaya T-26 diperbarui redaksinya kalau memang dianggap
  salah ketik. **Konsekuensi untuk FE:** tidak ada logika ambang yang
  perlu (atau boleh) ditulis di klien sama sekali — klien hanya mengirim
  fakta `camera_interrupted`/`camera_reactivation_*` dengan timestamp;
  keputusan V1/V2/V3 sepenuhnya di `ProctoringValidityPolicy` (server).

## 4. Foto berkala (12–20 detik acak) — rancangan, nilai belum final

**Status nilai (mengutip instruksi Lead langsung):** 480×360, JPEG
q≈0.6, retensi 90 hari adalah **usulan pemilik yang belum diputuskan**.
Rencana ini merancang MODULNYA dengan asumsi eksplisit bahwa semua
angka itu **dibaca dari konfigurasi server saat sesi dimulai**, bukan
konstanta di klien — konsisten dengan CLAUDE.md ("JANGAN menanam
bobot/ambang ... di kode — SEMUA dibaca dari Tabel Lookup") dan
instruksi Lead butir 4. **Tidak ada kode yang boleh ditulis untuk
modul ini sebelum: (a) pemilik proyek mengonfirmasi angkanya atau
memberi angka lain, DAN (b) ada sumber konfigurasi server yang nyata
untuk membacanya** — sampai saat itu, ini murni rancangan bentuk, bukan
sesuatu yang siap dibangun seperti §2–§3.

**Belum ada kode sama sekali untuk ini** — sudah diperiksa
(`grep -i "photo|snapshot|capture|canvas|drawImage|ImageCapture"` di
seluruh `session-runner/`): nol kecocokan relevan. Ini benar-benar
dari nol, beda dengan §2–§3 yang sebagian besar sudah terbangun.

**Bentuk yang direncanakan (mengikuti pola `camera-controller.ts` ↔
`use-proctoring-camera.ts`: modul murni + pembungkus hook):**

- `photo-capture-scheduler.ts` (BARU, murni, bisa diuji `node --test`
  tanpa DOM): jadwal acak dalam rentang `[minIntervalSeconds,
maxIntervalSeconds]` (dari config, bukan konstanta `12`/`20`), plus
  dua pemicu eksplisit non-acak: `session_start` (sekali, begitu kamera
  pertama kali `active`) dan `session_submit` (dipanggil eksplisit oleh
  pemanggil saat peserta mengirim jawaban — bukan jadwal, jadi modul
  ini tidak tahu kapan "submit" terjadi, hanya menyediakan fungsi untuk
  dipanggil). Jadwal periodik berhenti (bukan menumpuk percobaan) saat
  kamera BUKAN `active` — tidak ada gunanya mengambil foto dari kamera
  yang mati — dan otomatis lanjut lagi begitu kamera kembali `active`.
  Cermin persis pola `fullscreen-controller.ts`: tidak tahu apa pun
  soal DOM/canvas/upload, hanya tahu KAPAN harus memicu.
- `use-periodic-photo-capture.ts` (BARU, hook): mengambil
  `MediaStream` yang sedang aktif, menggambar frame ke `<canvas>`
  tersembunyi, `canvas.toBlob(..., 'image/jpeg', jpegQuality)` dengan
  `maxWidth`/`maxHeight`/`jpegQuality` dari config yang sama, lalu
  memanggil `onCapture(blob, kind)` yang dioper pemanggil — modul ini
  TIDAK mengunggah apa pun sendiri (persis pola `noopProctoringReporter`:
  ada `noopPhotoUploader` sampai endpoint `.../photos` nyata ada,
  supaya tidak pernah diam-diam berpura-pura tersimpan).

**Celah integrasi yang WAJIB diputuskan sebelum modul ini bisa
dipakai (menyentuh berkas GLM):** `useProctoringCamera` saat ini
menyimpan `MediaStream` di `streamRef` yang sepenuhnya privat
(`use-proctoring-camera.ts:66`) — **tidak diekspos** lewat
`UseProctoringCameraResult` (`use-proctoring-camera.ts:51-57` hanya
mengembalikan `status`/`activate`/`deactivate`). Tanpa akses ke stream
yang sama, `use-periodic-photo-capture.ts` tidak bisa mengambil frame
apa pun. Ini BUKAN sesuatu yang bisa diselesaikan dari berkas baru saja
— perlu satu dari dua perubahan kecil dan tegas di berkas GLM:

1. Tambah `getStream: () => MediaStream | null` ke
   `UseProctoringCameraResult`, ATAU
2. `useProctoringCamera` sendiri yang memasang video/canvas tersembunyi
   dan mengekspos `captureFrame(): Promise<Blob>` — menaruh mekanisme
   ambil-gambar di dalam hook kamera itu sendiri, bukan modul terpisah.

Rencana ini tidak memilih salah satu — itu keputusan desain milik
GLM/Lead karena mengubah kontrak berkas yang sudah dipakai
`session-runner-shell.tsx` dan sudah diuji. Dicatat di sini supaya
jelas: **modul foto berkala tidak bisa dibangun sampai celah ini
diputuskan**, terlepas dari status keputusan nilai piksel/kualitas.

## 5. Siapa memegang berkas apa

| Berkas                                                 | Status                                                                                              | Siapa                                                                                                                                      |
| ------------------------------------------------------ | --------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| `camera-controller.ts`, `camera-controller.test.ts`    | Ada, perlu perubahan kecil bila §2 opsi (1) disetujui                                               | **GLM** (milik `session-runner/**`)                                                                                                        |
| `use-proctoring-camera.ts`                             | Ada, perlu perubahan kecil bila §4 celah integrasi diputuskan                                       | **GLM**                                                                                                                                    |
| `session-runner-shell.tsx`                             | Ada, perlu perubahan kecil bila §2 opsi (2) disetujui                                               | **GLM**                                                                                                                                    |
| `proctoring-reporter.ts`                               | Ada, tidak perlu diubah untuk §2–§3; mungkin field `durationMs` opsional jika F2 minta (§3 celah 2) | **GLM**                                                                                                                                    |
| `photo-capture-scheduler.ts` (+ test)                  | Baru                                                                                                | **Bisa dibangun di luar `session-runner/**`**, mis. `resources/js/components/participant/proctoring/` (direktori yang sama dipakai PR #83) |
| `use-periodic-photo-capture.ts`                        | Baru                                                                                                | Sama — direktori `proctoring/`, TAPI bergantung pada celah integrasi §4 yang harus diputuskan GLM/Lead dulu                                |
| `http-proctoring-reporter.ts`                          | Baru, belum bisa dibangun (endpoint belum ada)                                                      | Direktori `proctoring/`, ditulis setelah F2 mengonfirmasi bentuk endpoint & `event_kind` (§3 celah 2–3)                                    |
| `app/Domain/Proctoring/**`, migrasi, route, controller | Ada (domain murni) / proposal (skema)                                                               | **F2** — tidak disentuh rencana ini                                                                                                        |

Tidak ada berkas GLM yang diusulkan diedit di luar tiga baris pertama
tabel di atas, dan ketiganya hanya kalau opsi terkait di §2/§4 disetujui
Lead — tidak ada yang dikerjakan tanpa persetujuan eksplisit itu, sesuai
batas berkas yang berlaku sepanjang lane ini.

## 6. Keputusan Lead atas rencana ini (2026-09-21, tinjauan PR #84)

Lima pertanyaan di bawah sudah dijawab Lead. Kutipan keputusan, bukan
parafrase, supaya tidak ada penafsiran ganda saat implementasi.

1. **Reaktivasi: dua-duanya.** Opsi (1) di §2 (`reactivate()` juga
   mencoba dari `reactivation_failed` saat `visibilitychange`/`focus`)
   **dan** opsi (2) (tombol manual "Coba aktifkan kamera lagi" di
   `session-runner-shell.tsx`). Alasan tombol manual tetap perlu
   walau (1) sudah ada: kalau kamera dicabut/diblokir izinnya saat
   halaman tetap terlihat (tidak pernah `blur`/hidden), event
   `visibilitychange`/`focus` tidak pernah terpicu — tanpa tombol,
   peserta tidak punya jalan kembali sama sekali. Batasan eksplisit
   dari Lead:
    - Tidak ada percobaan otomatis beruntun tanpa pemicu (tidak ada
      polling/loop latar belakang) — hanya bereaksi pada
      `visibilitychange`/`focus`/klik tombol, persis pola yang sudah
      ada.
    - Setiap percobaan (otomatis maupun manual) tetap dicatat lewat
      `ProctoringReporter`.
    - Tombol TIDAK menjeda atau mengunci tes.
    - **Pemilik berkas — klarifikasi Lead:** perubahan di
      `camera-controller.ts`, `use-proctoring-camera.ts`, dan
      `session-runner-shell.tsx` untuk butir ini "boleh dikerjakan
      dalam PR terpisah yang kecil," dengan Lead yang memberi tahu GLM,
      syarat test lama GLM tetap lulus tanpa berubah makna + test baru
      untuk jalur `reactivation_failed → berhasil`. **Catatan proses:**
      ini melebarkan lane FE-Infra ke berkas yang sejauh ini selalu
      ditetapkan milik GLM (`session-runner/**`) — kanal ini sudah
      pernah diminta menahan diri dari perluasan lane oleh peer
      (termasuk Lead sendiri) dan mengembalikannya ke pemilik proyek
      untuk diputuskan, bukan langsung dikerjakan. Keputusan jalan
      (siapa yang menulis kode untuk butir 1) dikonfirmasi dulu ke
      pemilik proyek sebelum PR kecil ini dibuat — lihat catatan di
      akhir dokumen.
2. **Bentuk event: `ProctoringEventKind` baru yang eksplisit**
   (reaktivasi dicoba/berhasil/gagal), bukan metadata yang ditumpuk di
   `CameraInterrupted` — alasan Lead: fakta yang punya nama lebih
   mudah diaudit psikolog. Keputusan akhir bentuk kolom/enum tetap di
   F2 saat endpoint ingest dibangun; Lead meneruskan ini ke F2 sebagai
   masukan. **`http-proctoring-reporter.ts` baru ditulis setelah F2
   mengonfirmasi** — tidak dibangun di peningkatan ini.
3. **T-26 "gap>ambang" vs `ProctoringValidityPolicy` (tanpa ambang):**
   dikonfirmasi Lead sebagai **konflik SPEC vs implementasi**, dan
   keputusannya ada di psikolog, bukan tim teknis. Lead memasukkan ini
   sebagai pertanyaan eksplisit ke psikolog: _"berapa lama kamera mati
   sebelum dianggap V2? Atau setiap jeda langsung V2?"_ — penting
   karena di HP, pindah aplikasi sebentar saja sudah memutus kamera.
   **Tidak ada perubahan ke `SPEC.md` maupun
   `ProctoringValidityPolicy.php` sampai psikolog menjawab.** Status
   butir ini: **menunggu psikolog**, dicatat di sini supaya siapa pun
   yang membaca dokumen ini tahu keputusannya belum final.
4. **Angka foto (480×360/JPEG q0.6/90 hari): masih menunggu pemilik**,
   tidak berubah dari §4. Yang berubah: `photo-capture-scheduler.ts`
   **boleh dibangun sekarang**, dengan interval, ukuran, dan kualitas
   sebagai **parameter yang disuntikkan saat konstruksi** (nanti
   diisi dari konfigurasi server) — tidak ada nilai default angka apa
   pun di klien, termasuk sebagai fallback sementara.
5. **Integrasi stream: `captureFrame()` di dalam hook** (opsi 2 di
   §4), bukan `getStream()` (opsi 1). Keputusan eksplisit Lead:
   `MediaStream` tetap privat sepenuhnya di `use-proctoring-camera.ts`
   — tidak ada apa pun yang membocorkan stream itu sendiri ke luar
   hook. Hook hanya mengembalikan `Blob` (atau penanda "tidak ada
   frame" bila kamera sedang tidak `active` — **tanpa melempar
   error**, karena kamera tidak aktif adalah kondisi normal yang bisa
   terjadi kapan saja, bukan kegagalan). Scheduler mencatat frame yang
   terlewat sebagai fakta (bukan diam-diam dilewati tanpa jejak).

## 7. Urutan kerja yang diminta Lead, dan satu catatan proses

Lead meminta urutan berikut setelah dokumen ini diperbarui:

- **(a)** PR kecil untuk butir 1 §6: perbaikan `reactivate()` +
  tombol manual, di `camera-controller.ts`/`use-proctoring-camera.ts`/
  `session-runner-shell.tsx`.
- **(b)** `photo-capture-scheduler.ts` murni + test, plus
  `captureFrame()` di `use-proctoring-camera.ts`, dalam satu PR
  terpisah dari (a). Upload/HTTP belum — itu menunggu F2 (butir 2 §6).

**Catatan proses (bukan bagian dari rencana teknis, dicatat untuk
kejelasan alur kerja):** kedua PR di atas mengedit berkas yang sejauh
lane ini berjalan selalu dianggap milik GLM
(`session-runner/**`) — batas yang berulang kali dijaga eksplisit
sepanjang increment ini (termasuk oleh Lead sendiri, dan pernah
dikembalikan ke pemilik proyek saat peer meminta perluasan serupa
sebelumnya). Sebelum PR (a)/(b) mulai ditulis, kanal ini meminta
konfirmasi eksplisit pemilik proyek soal jalan yang dipakai untuk
mengeksekusinya — apakah kanal ini yang menulis langsung ke berkas
GLM (seperti diusulkan Lead), GLM sendiri yang mengimplementasikan
dari rencana ini, atau jalur lain — bukan diasumsikan dari persetujuan
Lead saja. Ini murni soal siapa memegang pena, bukan soal apakah
rencananya benar; isi teknis §6 di atas tetap berlaku terlepas dari
siapa yang mengeksekusinya.
