# Diagnosis — standalone frontend fixtures can't load: missing ONCAM token bridge plugin

**Tanggal:** 2026-09-21. **Diagnosis oleh:** kanal GLM (lane Laporan), ditemukan
saat mengerjakan `glm/participant-lobby-status-labels`.
**Status:** penyebab teridentifikasi dan terbukti. **Perbaikan BELUM dicoba
sampai tuntas** — lihat §3 untuk jalan buntu yang sudah ditempuh.
**Pemilik perbaikan:** lane tooling/infrastruktur frontend (belum ditentukan).
Dokumen ini tidak memilih satu opsi perbaikan.

Baseline: `main` `184f2a5`. Semua bukti di bawah dihasilkan ulang sendiri oleh
penulis dokumen, bukan disalin dari laporan pihak lain.

## 1. Gejala

`tests/Frontend/ParticipantLobby/browser.test.mjs` tidak bisa dijalankan sama
sekali. Prosedurnya (pola yang sama dipakai lane lain, lihat
`tasks/evidence/browser-qa-2026-09-14-f5-review/README.md`):

```
npx vite --config tests/Frontend/ParticipantLobby/vite.config.ts
npx --yes --package @playwright/cli playwright-cli -s=<sesi> open http://127.0.0.1:8011
npx --yes --package @playwright/cli playwright-cli -s=<sesi> run-code --filename tests/Frontend/ParticipantLobby/browser.test.mjs
```

Hasilnya: `TimeoutError: locator.waitFor: Timeout 30000ms exceeded` menunggu
teks "Memuat data peserta" — halaman tidak pernah menampilkan konten apa pun.
Judul tab tetap `Fixture lobby sintetis` (judul statis dari `index.html`),
membuktikan komponen React/Inertia **tidak pernah ter-mount**, bukan sekadar
gagal memuat gaya.

Console browser mencatat:

```
[ERROR] Failed to load resource: the server responded with a status of 500 (Internal Server Error) @ http://127.0.0.1:8011/preview.css:0
```

Log server Vite:

```
[vite] Internal server error: Can't resolve 'virtual:oncam-design-tokens.css' in '.../resources/css'
  Plugin: @tailwindcss/vite:generate:serve
  File: .../tests/Frontend/ParticipantLobby/preview.css
```

## 2. Penyebab — plugin token bridge tidak pernah didaftarkan di fixture ini

`resources/css/app.css:5` mengimpor `virtual:oncam-design-tokens.css`, modul
virtual yang HANYA di-resolve oleh plugin Vite kustom
`tools/design-tokens/oncam-runtime-bridge.mjs` (`oncamTokenRuntimeBridge()`).
Plugin ini terdaftar di `vite.config.ts` utama proyek
(`vite.config.ts:27`) tapi TIDAK PERNAH ditambahkan ke
`tests/Frontend/ParticipantLobby/vite.config.ts`, karena fixture itu lebih
tua dari plugin-nya:

```
git log --oneline -1 -- tests/Frontend/ParticipantLobby/vite.config.ts
  78d7c53  2026-08-31 23:50:02 +0700  "F16: handle missing participant names and test numbers in lobby"

git log --oneline -1 -- tools/design-tokens/oncam-runtime-bridge.mjs
  17470c5  2026-09-14 10:02:38 +0700  "feat: bridge ONCAM tokens into frontend runtime"
```

Dua minggu setelah fixture ini terakhir disentuh, plugin token bridge masuk
ke `resources/css/app.css`, dan tidak ada yang memperbarui fixture ini (atau
fixture sejenis) untuk ikut mendaftarkannya.

**Bukan cacat khusus fixture ini.** `tests/Frontend/IntegratedCheckout/vite.config.ts`
punya celah identik: `preview.css`-nya juga mengimpor `resources/css/app.css`
penuh (lihat komentarnya: "keep existing ONCAM tokens ... in sync"), tapi
`plugins: [react(), tailwindcss()]`-nya juga tidak memuat
`oncamTokenRuntimeBridge()`. Kemungkinan besar fixture itu juga gagal
dimuat dengan cara yang sama, meski belum diverifikasi ulang di dokumen
ini (di luar cakupan tugas lobi).

`tests/Frontend/PsychologistReview/browser.test.mjs` TIDAK terdampak: fixture
itu tidak punya `vite.config.ts`/`preview.tsx` sendiri, ia menjalankan
`browser.test.mjs`-nya langsung terhadap `php artisan serve` (halaman Filament
sungguhan), bukan pratinjau Vite berdiri sendiri.

## 3. Jalan buntu yang sudah dicoba — supaya tidak diulang

**Percobaan: menambahkan `oncamTokenRuntimeBridge()` ke
`tests/Frontend/ParticipantLobby/vite.config.ts`, meniru persis urutan plugin
di `vite.config.ts` utama.** Tidak berhasil.

Langkah verifikasi yang sudah ditempuh setelah penambahan itu, untuk
menyingkirkan penyebab-penyebab yang jelas SEBELUM menyimpulkan ini bug nyata:

1. **Bukan masalah cache.** Proses Vite lama dihentikan paksa, direktori
   `node_modules/.vite` dihapus, server dijalankan ulang dari nol. Galat
   identik tetap muncul.
2. **Bukan perbedaan mode dev vs build.** `npm run build` di root proyek
   (memakai `vite.config.ts` utama, plugin yang sama) berhasil tanpa galat —
   `virtual:oncam-design-tokens.css` resolve dengan benar di mode build.
3. **Bukan galat dev-mode di seluruh proyek.** Server dev utama proyek
   (`npx vite` dari root, TANPA konfigurasi fixture) dijalankan terpisah;
   `http://localhost:5173/resources/css/app.css` memberi `200` dengan CSS
   Tailwind yang sudah ter-generate dengan benar. Jadi kombinasi plugin yang
   SAMA bekerja di server dev utama tapi tidak di fixture minimal ini.
4. **Urutan plugin dan `enforce: 'pre'` sudah sama.** Plugin punya
   `enforce: 'pre'` (lihat `oncam-runtime-bridge.mjs:782`) yang seharusnya
   membuatnya berjalan sebelum plugin lain terlepas dari urutan array; posisi
   dalam array (`react(), oncamTokenRuntimeBridge(), tailwindcss()`) juga
   sudah dicocokkan dengan urutan di `vite.config.ts` utama.

**Kesimpulan sementara (belum dibuktikan penuh):** resolver `@import`
internal `@tailwindcss/vite` (lewat `enhanced-resolve`, terlihat di jejak
galat) tampaknya tidak selalu berkonsultasi ke plugin container Vite untuk
modul virtual dalam konfigurasi fixture yang minimal ini — sesuatu selain
daftar plugin dan urutannya yang membedakan fixture ini dari konfigurasi
utama (kandidat yang belum diperiksa: `laravel-vite-plugin`/`inertia()`
mendaftarkan sesuatu yang memengaruhi ini, atau opsi `root` fixture
memengaruhi bagaimana `enhanced-resolve` membangun graf importnya). Ini
memerlukan penyelidikan lebih dalam ke internal `@tailwindcss/vite` yang di
luar cakupan tugas yang memicu temuan ini.

## 4. Rekomendasi untuk lane yang mengambil ini

- Perubahan `oncamTokenRuntimeBridge()` yang sudah dicoba (§3) TIDAK
  disertakan di cabang manapun — tidak ada kode setengah-jadi yang perlu
  dibersihkan. Mulai dari nol memakai temuan di dokumen ini sebagai titik
  awal, bukan mengulang §3.
- Prioritas: `tools/design-tokens/oncam-runtime-bridge.mjs` sendiri, dan
  bagaimana ia berinteraksi dengan resolver internal `@tailwindcss/vite`
  dalam konfigurasi Vite tanpa `laravel-vite-plugin`/`inertia()`. Bandingkan
  konfigurasi fixture minimal vs. `vite.config.ts` utama plugin demi plugin.
- Periksa juga `tests/Frontend/IntegratedCheckout` sekalian (celah yang sama,
  §2), supaya perbaikannya tidak perlu diulang per-fixture.
- Ini menjadi prasyarat untuk halaman pengerjaan tes (IST/PAPI/RMIB/Kraepelin)
  yang direncanakan Lead — halaman itu akan punya logika tampilan jauh lebih
  besar (timer, drag-drop, grid) di mana harness browser yang rusak adalah
  penghalang, bukan catatan kaki.
