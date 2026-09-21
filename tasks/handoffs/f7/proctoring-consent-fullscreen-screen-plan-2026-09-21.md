# Rencana — layar persetujuan proctoring & mode layar penuh

**Tanggal:** 2026-09-21. **Ditulis oleh:** kanal FE-Infra, atas instruksi
Lead (disetujui pemilik proyek). **Sifat:** rencana saja — tidak ada
komponen/kode produksi di PR ini. Dibaca sebelum implementasi.

## 0. Sumber yang dibaca

- `SPEC.md` §8A seluruhnya (8A.1–8A.7), terutama 8A.7 (consent),
  8A.3 (fullscreen & deteksi fokus), 8A.4 (pengerasan ringan), 8A.2
  (kebijakan kamera), 8A.6 (matriks ancaman → sinyal → konsekuensi).
- `CLAUDE.md` — larangan keras proctoring (deteksi bukan cegah).
- `PRIVACY_POLICY.md` baris 5–9 (retensi, dasar consent).
- Kode yang sudah ada: `use-proctoring-camera.ts`,
  `camera-controller.ts`, `proctoring-reporter.ts`,
  `session-runner-shell.tsx` (dibaca, **tidak diedit** — sesuai batas
  berkas dari Lead).
- `tasks/handoffs/f7/proctoring-persistence-proposal.md` (proposal F7,
  belum ada migrasi/route/controller apa pun — status masih "PROPOSAL
  ONLY").
- `app/Domain/Proctoring/ProctoringEventKind.php` — enum kind yang
  sudah ada di domain PHP; **tidak ada** kind untuk "consent
  diberikan" saat ini.

## 1. Cakupan increment ini — apa yang DIBANGUN, apa yang TIDAK

**Dibangun (kalau rencana ini disetujui):**
- Komponen layar persetujuan proctoring (kamera + ringkasan kebijakan)
  yang tampil sebelum sesi tes dimulai.
- Logika permintaan fullscreen per-subtes, dengan deteksi dukungan
  perangkat (lihat §3).
- Fixture harness baru untuk menguji layar ini secara terisolasi.

**SENGAJA TIDAK dibangun di increment ini** (dicatat di sini supaya
tidak diasumsikan termasuk, dan supaya Lead/pemilik proyek bisa
mengoreksi kalau memang harus termasuk):

- **Pengerasan ringan 8A.4** (nonaktifkan klik-kanan/copy-paste/drag,
  watermark nomor tes, blokir shortcut, autofill). Ini perilaku di
  HALAMAN SOAL (per-instrumen), bukan di layar persetujuan itu sendiri
  — scope alami untuk task terpisah setelah halaman per-tes (IST/PAPI/
  RMIB/Kraepelin) mulai dibangun GLM. Teks di layar persetujuan
  **menyebutkan** bahwa tindakan ini akan aktif selama tes (supaya
  peserta tidak kaget), tapi kode-nya bukan bagian PR ini.
- **Pencocokan wajah** (8A.2's face matching) — model verifikasi lain,
  komponen lain, tidak disentuh.
- **Backend penyimpanan proctoring** (F7) — proposal-only, lihat §4.
- **Mode "wajib kamera" per cabang** — konfigurasi ini belum ada
  tempat penyimpanannya (dicatat sebagai keputusan terbuka F7 proposal
  poin 8). Komponen dirancang MENERIMA prop untuk ini (lihat §2), tapi
  siapa yang mengisi prop itu dari data cabang sungguhan adalah kerja
  lane lain setelah F7/entitlement API menyediakannya.
  **[Diperbarui 2026-09-21, lihat §8.1]:** pertanyaan ini sudah dijawab
  pemilik proyek — kamera WAJIB untuk semua peserta, tanpa pengecualian
  per cabang. Prop `cameraMandatory` tetap ada untuk testability, tapi
  tidak ada lagi konfigurasi per-cabang yang perlu dibangun.

## 2. Titik integrasi — tanpa menyentuh berkas GLM

**Lokasi berkas baru:** `resources/js/components/participant/proctoring/`
(direktori baru, terpisah dari `session-runner/**` GLM), mengikuti pola
pemisahan hook/komponen-murni yang sudah dipakai `camera-controller.ts`
↔ `use-proctoring-camera.ts`:

- `proctoring-consent-copy.ts` — SATU-SATUNYA tempat string bahasa
  Indonesia hidup (bukan tersebar di JSX), supaya psikolog/legal bisa
  meninjau teks tanpa membaca kode komponen. Fungsi murni yang
  mengambil `{ cameraStatus, fullscreenSupported, cameraMandatory,
  persistenceEnabled }` dan mengembalikan potongan teks yang sesuai
  (lihat §3 untuk kenapa teksnya kondisional).
- `fullscreen-controller.ts` — modul murni (pola sama seperti
  `camera-controller.ts`): mendeteksi dukungan Fullscreen API,
  membungkus `requestFullscreen()`/`exitFullscreen()`, mendengarkan
  `fullscreenchange`. Tidak tahu apa pun soal proctoring/consent —
  reusable untuk kebutuhan fullscreen lain kalau ada.
- `use-fullscreen.ts` — pembungkus hook React di atas
  `fullscreen-controller.ts`, pola sama seperti
  `use-proctoring-camera.ts` membungkus `camera-controller.ts`.
- `proctoring-consent-screen.tsx` — komponen presentasi. Menerima
  `camera: UseProctoringCameraResult` (dari hook GLM, **type saja yang
  diimpor**, bukan hook-nya dipanggil ulang di sini — instance yang
  SAMA harus dipakai supaya `activate()` benar-benar menyalakan kamera
  yang ditampilkan shell) dan `fullscreen: UseFullscreenResult` (hook
  baru di atas) sebagai props, plus callback `onProceed: () =>
  void` yang dipanggil setelah peserta menekan tombol lanjut (baik
  dengan maupun tanpa kamera aktif).

**Cara halaman per-tes (belum ada — punya GLM nanti) memakainya, tanpa
mengedit `session-runner-shell.tsx` sekarang:**

```
function IstTestPage(/* ... */) {
  // useFullscreen() dipanggil di TINGKAT ATAS komponen halaman, bukan di
  // dalam callback render-prop di bawah — memanggilnya di dalam
  // `{(runner) => ...}` melanggar Rules of Hooks React (hook yang
  // dipanggil di dalam callback bisa terpanggil kondisional/jumlah
  // berbeda antar render, dan lint react-hooks akan menolaknya). Hasilnya
  // diteruskan ke bawah sebagai nilai biasa.
  const fullscreen = useFullscreen();
  const [consentGiven, setConsentGiven] = useState(false);

  return (
    <SessionRunnerShell fetchSession={...} proctoring={{ reporter, getUserMedia }}>
      {(runner) =>
        !consentGiven ? (
          <ProctoringConsentScreen
            camera={runner.camera}
            fullscreen={fullscreen}
            cameraMandatory={true} // lihat §8.1 — wajib untuk semua peserta, bukan lagi per-cabang
            onProceed={() => setConsentGiven(true)}
          />
        ) : (
          <ActualTestContent session={runner.session} />
        )
      }
    </SessionRunnerShell>
  );
}
```

`session-runner-shell.tsx` **tidak perlu diubah sama sekali** —
`children` render-prop yang sudah ada cukup untuk pola gating ini.
Halaman per-instrumen (milik GLM, belum dibangun) yang memutuskan
kapan merender `ProctoringConsentScreen` vs konten tes sungguhan.
Titik integrasi ini dicatat di sini supaya siapa pun yang membangun
halaman IST/PAPI/RMIB/Kraepelin tahu pola yang dimaksud, tanpa saya
perlu menyentuh berkas GLM sekarang. **Catatan Lead (2026-09-21):**
contoh sebelumnya memanggil `useFullscreen()` di dalam callback
render-prop — salah, sudah diperbaiki di atas. Pola yang benar (hook di
tingkat atas komponen halaman) juga yang dipakai di fixture-nya (§6),
karena GLM akan meniru contoh ini untuk halaman IST/PAPI/RMIB/Kraepelin.

## 3. Isi teks persetujuan (draf, Bahasa Indonesia)

Prinsip penulisan: manusiawi (8A.7: "ditulis manusiawi, bukan
mengancam"), jujur soal batas teknis (CLAUDE.md: deteksi bukan cegah),
dan membedakan `denied` vs `unavailable` secara eksplisit karena
keduanya butuh respons berbeda — peserta yang KAMERANYA RUSAK tidak
boleh diberi tahu seolah-olah dia "menolak".

### 3.1 Layar awal (sebelum peserta berinteraksi)

> **Sebelum memulai: persetujuan pemantauan**
>
> Untuk menjaga keadilan bagi semua peserta, sesi ini dipantau selama
> berlangsung:
>
> - Kamera Anda akan mengambil foto secara berkala (bukan merekam
>   video terus-menerus), termasuk saat mulai dan saat mengirim
>   jawaban.
> - Wajah Anda dibandingkan sekali di awal dengan foto identitas Anda,
>   dan sesekali selama sesi, untuk memastikan Anda peserta yang
>   terdaftar.
> - Sistem mencatat bila Anda meninggalkan layar tes (berpindah
>   aplikasi/tab, mengunci layar, atau keluar dari mode layar penuh).
>   **Waktu tidak berhenti saat ini terjadi.**
> - [tampil hanya bila `fullscreenSupported`] Layar akan beralih ke
>   mode penuh setiap subtes dimulai. Anda tetap bisa keluar kapan
>   saja — ini pengingat, bukan kuncian.
> - [tampil hanya bila **bukan** `fullscreenSupported`] Perangkat/
>   peramban Anda tidak mendukung mode layar penuh. Sesi tetap
>   berjalan normal; kamera dan pencatatan kepergian layar tetap
>   aktif seperti biasa.
> - Selama mengerjakan soal, klik kanan, seleksi teks, salin, dan
>   tempel dinonaktifkan pada halaman soal.
>
> **Pemantauan ini mendeteksi dan mencatat — bukan mencegah.** Batas
> teknis peramban web membuat kami tidak bisa benar-benar mengunci
> perangkat Anda. Keputusan akhir soal keabsahan hasil Anda selalu ada
> di tangan psikolog yang meninjau laporan, bukan sistem otomatis.
>
> [tampil hanya bila `persistenceEnabled`] Foto dan catatan ini
> disimpan sementara (maksimal 90 hari); setelah itu hanya ringkasan
> peristiwa yang bertahan, tanpa gambar.
>
> [tampil hanya bila **bukan** `persistenceEnabled` — lihat §4]
> Sesi ini menggunakan kamera dan pencatatan kepergian layar secara
> langsung di perangkat Anda; penyimpanan otomatis ke server psikolog
> masih dalam pengembangan.
>
> [Tombol utama] **Izinkan kamera & mulai**
> [Tombol sekunder — hanya tampil bila `!cameraMandatory`] **Lanjutkan
> tanpa kamera**

### 3.2 Setelah peserta menekan "Izinkan kamera & mulai"

Kamera state dari `useProctoringCamera` yang SUDAH ADA
(`requesting` → `active`/`denied`/`unavailable`) memberi tahu
komponen ini kondisi mana yang perlu ditampilkan:

**`active`:** langsung panggil `onProceed()`, tidak perlu layar
tambahan.

**`denied`** (peserta secara eksplisit menolak izin kamera di dialog
peramban):

> Anda menolak izin kamera di peramban.
>
> [bila `cameraMandatory`] Cabang Anda mewajibkan kamera untuk tes
> ini, jadi sesi belum bisa dimulai. Aktifkan izin kamera lewat
> pengaturan peramban Anda lalu muat ulang halaman ini, atau hubungi
> pengawas/LPK Anda untuk jalur pengawasan lain.
>
> **[Draf awal — lihat §8.2]:** "hubungi pengawas/LPK Anda" DITARIK dari
> teks yang sebenarnya dikirim ke kode. Dibiarkan di sini apa adanya
> sebagai draf historis; teks final ada di
> `proctoring-consent-copy-variants-2026-09-21.md`.
>
> [bila `!cameraMandatory`] Anda tetap bisa melanjutkan tanpa kamera.
> Sesi Anda akan ditandai memerlukan catatan prosedur tambahan
> sebelum psikolog menandatangani laporan — ini bukan penalti
> otomatis, hanya langkah tinjauan ekstra.
>
> [Tombol] **Coba lagi** / [bila `!cameraMandatory`] **Lanjutkan
> tanpa kamera**

**`unavailable`** (kamera tak terdeteksi, sedang dipakai aplikasi
lain, atau kendala teknis lain — BUKAN penolakan eksplisit):

> Kamera tidak terdeteksi di perangkat Anda saat ini. Ini bisa karena
> kamera sedang dipakai aplikasi lain, perangkat tidak punya kamera,
> atau kendala teknis lain — bukan berarti Anda menolak.
>
> [bila `cameraMandatory`] Cabang Anda mewajibkan kamera untuk tes
> ini. Silakan periksa kamera perangkat Anda, tutup aplikasi lain yang
> mungkin memakainya, lalu coba lagi — atau hubungi LPK Anda untuk
> jalur pengawasan alternatif (8A.7: peserta dengan keterbatasan
> perangkat tidak langsung gugur).
>
> **[Draf awal — lihat §8.2]:** "hubungi LPK Anda untuk jalur
> pengawasan alternatif" DITARIK — belum pernah ada kebijakan atau
> proses operasional untuk ini. Diganti dengan langkah periksa/ganti
> perangkat + kalimat netral "hubungi penyelenggara tes Anda" tanpa
> menjanjikan hasil. Teks final ada di
> `proctoring-consent-copy-variants-2026-09-21.md`.
>
> [bila `!cameraMandatory`] Anda tetap bisa melanjutkan tanpa kamera.
> Sesi Anda akan ditandai memerlukan catatan prosedur tambahan.
>
> [Tombol] **Coba lagi** / [bila `!cameraMandatory`] **Lanjutkan
> tanpa kamera**

**`interrupted`/`reactivating`/`reactivation_failed`:** state ini
milik `useProctoringCamera` untuk KAMERA YANG SUDAH AKTIF lalu
terputus (app-switch di HP) — terjadi SETELAH `onProceed()` dipanggil,
di dalam sesi tes yang sedang berjalan, bukan di layar persetujuan
ini. Sudah ditangani `session-runner-shell.tsx`'s badge status
(`CAMERA_STATUS_LABEL`, sudah ada, tidak perlu diubah). Disebutkan di
sini hanya supaya jelas kenapa `ProctoringConsentScreen` tidak perlu
menangani state ini sendiri.

## 4. "Apakah persetujuan dicatat ke server?" — jawaban jujur

**Belum bisa, saat ini.** Tidak ada endpoint apa pun untuk proctoring
— bukan cuma "belum ada endpoint consent", tapi seluruh ingest
proctoring (foto & event) masih berstatus proposal
(`tasks/handoffs/f7/proctoring-persistence-proposal.md`, eksplisit
"PROPOSAL ONLY — no migration, code, route, controller, test... in
this increment"). Endpoint yang diusulkan di sana
(`POST /participant/sessions/{session}/proctoring/events` dan
`.../photos`) belum dibangun. `ProctoringEventKind.php` (enum PHP yang
sudah ada) juga **belum punya** kind untuk "consent diberikan" — ini
konsep yang belum ada sama sekali di domain, bukan cuma belum
tersambung.

**Bagaimana teks tetap jujur sekarang:** komponen menerima prop
`persistenceEnabled: boolean` (default `false`). Selama `false` (yaitu
selama `proctoring-reporter.ts` masih `noopProctoringReporter` —
persis situasi sekarang), teks TIDAK PERNAH menyebut penyimpanan
server/psikolog sebagai sesuatu yang sedang terjadi (lihat varian teks
di §3.1). Yang ditampilkan hanya perilaku yang BENAR-BENAR jalan di
klien sekarang: kamera aktif, layar penuh diminta, kepergian layar
terdeteksi — semua ini nyata dan berfungsi tanpa backend, karena
`useProctoringCamera`/`fullscreen-controller.ts` murni klien. Tidak
ada klaim "sudah dikirim" atau "sudah tersimpan" untuk apa pun yang
sebenarnya cuma state React sementara.

**Bagaimana teks berubah kelak:** begitu F7 landing (endpoint nyata +
`ProctoringReporter` implementasi nyata menggantikan
`noopProctoringReporter`), pemanggil `ProctoringConsentScreen` mengganti
prop `persistenceEnabled` jadi `true`. Baris retensi 90 hari (§3.1)
otomatis muncul — tidak perlu ubah komponen ini lagi, hanya nilai
prop yang berubah di titik pemanggilan. `proctoring-consent-copy.ts`
sudah menulis KEDUA varian dari awal, supaya transisi ini bukan
pekerjaan tulis-ulang teks nanti, cuma flip satu boolean setelah F7
review menyetujui backend-nya.

**Rekomendasi tambahan (keputusan Lead/pemilik, bukan saya putuskan
sendiri):** karena §3.1 SPEC 8A.7 secara eksplisit mewajibkan peserta
diberi tahu soal retensi SEBELUM mulai, dan itu hanya jujur untuk
dikatakan setelah backend-nya benar ada — pertimbangkan **komponen ini
dibangun & diuji lewat fixture sekarang, tapi TIDAK disambungkan ke
alur peserta sungguhan (halaman per-tes GLM) sampai F7 backend +
reporter nyata siap.** Ini bukan blocker untuk MERGE komponennya
(kode React murni, tidak berbahaya sendirian), hanya soal KAPAN
halaman per-tes mulai memanggilnya dengan `persistenceEnabled: true`
di produksi.

## 5. Perilaku layar penuh — termasuk batas HP

**Deteksi dukungan, bukan asumsi:** `fullscreen-controller.ts`
memeriksa `document.documentElement.requestFullscreen` (atau prefix
vendor lama bila masih relevan untuk target browser proyek) ada
sebagai fungsi SEBELUM pernah mencoba memanggilnya. Hasil deteksi ini
adalah `fullscreenSupported: boolean`, dipakai `proctoring-consent-copy.ts`
untuk memilih varian teks (§3.1) — **tidak pernah menjanjikan
fullscreen di perangkat yang tak mendukungnya**, sesuai permintaan
Lead eksplisit.

**iOS Safari — batas nyata, bukan bug untuk "diperbaiki":** Fullscreen
API standar (`Element.requestFullscreen()`) **tidak didukung** di
Safari iOS pada elemen halaman biasa (berbeda dari `<video>`, yang
punya `webkitEnterFullscreen()` miliknya sendiri, tak relevan di sini
karena bukan video). Ini bukan kekurangan implementasi kita — ini batas
platform Apple yang harus diterima, sesuai prinsip 8A.1 ("jujur, tanpa
janji berlebih"). Karena mayoritas peserta memakai HP (SPEC §8A intro),
dan iOS adalah sebagian besar dari itu, **banyak peserta TIDAK AKAN
pernah melihat mode fullscreen** — kamera dan deteksi kepergian layar
tetap berfungsi penuh terlepas dari ini (keduanya tidak butuh
fullscreen API).

**Per-subtes, bukan sekali per sesi:** SPEC 8A.3 minta permintaan
fullscreen SETIAP subtes dimulai, bukan sekali di layar persetujuan
awal. `ProctoringConsentScreen` (layar SEBELUM sesi dimulai) hanya
MENJELASKAN bahwa ini akan terjadi — pemanggilan `requestFullscreen()`
yang sesungguhnya per-subtes adalah tanggung jawab halaman per-instrumen
(belum dibangun), memakai `use-fullscreen.ts` yang sama. Dicatat di
sini supaya jelas kenapa `use-fullscreen.ts` diekspor sebagai hook
generik (dipakai lebih dari satu titik), bukan disembunyikan di dalam
`ProctoringConsentScreen` saja.

**Reaksi keluar fullscreen (di dalam sesi, bukan di layar
persetujuan):** event `fullscreenchange` yang menunjukkan status
BUKAN fullscreen lagi (setelah tadinya fullscreen) → event proctoring
`SCREEN_DEPARTURE`-serupa (SPEC 8A.3: "keluar-fullscreen" adalah salah
satu sinyal `visibilitychange`/`blur`/keluar-fullscreen yang sama-sama
dicatat) → banner non-blokir, timer TIDAK berhenti, 1× kejadian → V2.
Ini terjadi di HALAMAN TES (per-instrumen), bukan di komponen
persetujuan — disebut di sini untuk kelengkapan gambaran, bukan
sesuatu yang dibangun di PR ini.

## 6. Rencana teknis ringkas

- `resources/js/components/participant/proctoring/` (baru):
  `fullscreen-controller.ts` + `.test.ts`, `use-fullscreen.ts`,
  `proctoring-consent-copy.ts` + `.test.ts` (uji setiap kombinasi
  `cameraStatus × fullscreenSupported × cameraMandatory ×
  persistenceEnabled` menghasilkan teks yang benar — terutama bahwa
  `persistenceEnabled: false` TIDAK PERNAH memunculkan kata "disimpan
  ke server"/"psikolog akan meninjau" dalam bentuk klaim masa
  kini/lampau), `proctoring-consent-screen.tsx` + fixture visual.
- Pola test murni vs hook mengikuti persis yang sudah dipakai
  `camera-controller.ts`/`use-proctoring-camera.ts` — supaya "layar
  persetujuan tidak pernah memanggil `activate()` sendiri, hanya
  merespons klik peserta" bisa diuji `node --test` murni, bukan cuma
  lewat browser.
- Fixture baru: `tests/Frontend/ProctoringConsent/`, port **8015**
  (env var `PROCTORING_CONSENT_FIXTURE_PORT`, pola sama seperti PR
  #63 — default berbeda dari fixture lain supaya tidak tabrakan).
  `vite.config.ts` mendaftarkan `oncamTokenRuntimeBridge()` (lihat PR
  #53) sejak awal, bukan ditambahkan belakangan — menghindari
  mengulang bug harness yang sudah diperbaiki bulan ini.
  `browser.test.mjs` menguji: kedua varian teks (`persistenceEnabled`
  true/false), `denied` vs `unavailable` menampilkan pesan berbeda,
  tombol "Lanjutkan tanpa kamera" hanya muncul saat `!cameraMandatory`,
  dan varian fullscreen-didukung/tidak.

## 7. Pertanyaan terbuka — butuh keputusan sebelum/selama implementasi

1. **Kapan `persistenceEnabled` boleh `true` di produksi?** Rekomendasi
   §4: tidak sebelum F7 backend + reporter nyata ada. Keputusan Lead/
   pemilik proyek.
2. **[TERJAWAB 2026-09-21, lihat §8.1]** ~~Siapa yang mengisi
   `cameraMandatory` per peserta/cabang?~~ Kamera wajib untuk semua
   peserta, tanpa pengecualian per cabang — tidak ada lagi konfigurasi
   yang perlu dibangun untuk pertanyaan ini.
3. **Haruskah 8A.4 (pengerasan ringan) masuk PR yang sama atau PR
   terpisah?** Rencana ini mengasumsikan TERPISAH (§1) — teks di layar
   persetujuan menyebutkannya, kodenya tidak. Tolong konfirmasi kalau
   asumsi ini salah.
4. **Redaksi teks final** — draf §3 adalah titik awal, bukan final;
   psikolog/legal (per struktur dokumen CLAUDE.md — `SPEC_v4.md`/dokumen
   psikolog otoritatif untuk redaksi consent) sebaiknya meninjau kalimat
   persisnya sebelum ini tampil ke peserta sungguhan. Teks yang
   sebenarnya diimplementasikan (bukan draf §3) diekspor lengkap di
   `proctoring-consent-copy-variants-2026-09-21.md` untuk tinjauan ini.
5. **Usulan terbuka (bukan keputusan):** kalau kamera benar-benar tidak
   bisa dipakai peserta (`unavailable` + `cameraMandatory`) dan
   penyelenggara tes tidak punya jalur dukungan operasional yang jelas,
   apakah perlu satu didefinisikan (bukan LPK — lihat §8.2 kenapa itu
   ditarik)? Dicatat di sini untuk pemilik proyek, bukan diputuskan
   sendiri.

## 8. Perubahan pasca-persetujuan rencana ini

Rencana di atas (§1–§7) disetujui Lead/pemilik proyek pada 2026-09-21.
Dua keputusan berikut datang SETELAH persetujuan itu, selama
implementasi — dicatat di sini untuk arsip, bukan mengubah §1–§7 secara
diam-diam (anotasi inline di atas menunjuk balik ke sini).

### 8.1 Kamera wajib untuk semua peserta (2026-09-21)

Keputusan pemilik proyek untuk pertanyaan terbuka §7 butir 2 (relay
Lead, dicatat sebagai butir 11 di dokumen keputusan, PR #81): **kamera
WAJIB untuk semua peserta, tanpa pengecualian per cabang.** Dampaknya
ke implementasi:

- `cameraMandatory` tetap ada sebagai prop `ProctoringConsentScreen`
  (supaya kedua cabang tetap testable), tapi tidak ada pemanggil
  produksi yang boleh mengisinya `false` — tidak ada jalur "lanjut
  tanpa kamera" di produksi.
- `denied` dan `unavailable` sama-sama memblokir mulainya sesi, dengan
  notice yang berbeda (prompt izin peramban yang ditolak, vs perangkat
  yang memang tidak ada/tidak berfungsi) — keduanya tidak boleh
  menyalahkan peserta.
- Perilaku kamera mati di TENGAH sesi (`interrupted`/`reactivating`/
  `reactivation_failed`) sengaja TIDAK dirancang di increment ini
  (sudah dicatat §1) — Lead eksplisit minta ditanyakan dulu sebelum
  dirancang, bukan diasumsikan mengikuti pola mandatory ini.
- Belum ada perubahan pada kapan komponen ini disambungkan ke alur
  peserta sungguhan (masih menunggu F7 backend, §4) — keputusan ini
  hanya mengubah ISI prop, bukan kapan komponennya dipakai produksi.

### 8.2 Penarikan klaim jalur LPK untuk kamera `unavailable` (2026-09-21)

Draf §3.2 (dan implementasi awal saya) menulis "atau hubungi
pengawas/LPK Anda untuk jalur pengawasan alternatif" untuk kasus
`unavailable` + `cameraMandatory`. Lead mengoreksi ini: **belum ada
siapa pun — bukan SPEC.md, bukan proses operasional, bukan psikolog —
yang pernah memutuskan jalur itu ada.** Menampilkannya ke peserta
sungguhan akan mengarahkan mereka ke staf LPK yang tidak tahu harus
berbuat apa dengan permintaan itu.

**Perbaikan yang masuk kode:** untuk `unavailable` + `cameraMandatory`,
teks memberi langkah konkret memeriksa/mengganti perangkat (tutup
aplikasi lain yang memakai kamera, pastikan kamera berfungsi, coba
perangkat lain), diikuti SATU kalimat netral yang mengarahkan peserta
menghubungi **penyelenggara tes** tanpa menjanjikan hasil tertentu:
"Jika kamera tetap tidak dapat digunakan, hubungi penyelenggara tes
Anda." Tidak ada klaim jalur/hasil yang belum diputuskan siapa pun.
Teks lengkap semua varian ada di
`proctoring-consent-copy-variants-2026-09-21.md`; test regresi
(`proctoring-consent-copy.test.ts` dan `browser.test.mjs`) menegaskan
kata "LPK" tidak pernah muncul lagi di notice ini.

**Usulan terbuka** (bukan keputusan, untuk pemilik proyek): kalau jalur
dukungan operasional yang jelas memang dibutuhkan untuk kasus kamera
benar-benar tidak bisa dipakai, itu perlu didefinisikan dulu di luar
kode ini (siapa yang dihubungi, apa yang mereka lakukan) sebelum masuk
ke teks aplikasi lagi.
