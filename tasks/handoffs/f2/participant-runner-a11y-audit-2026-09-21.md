# Audit aksesibilitas & tampilan HP — prototipe runner peserta (PAPI, RMIB, Kraepelin, IST) — 2026-09-21

Dicatat oleh GLM, atas instruksi Lead (2026-09-21) dan instruksi pemilik proyek
yang diteruskan Lead pada hari yang sama (wajib pakai skill
`ui-ux-pro-max:ui-ux-pro-max` sebagai acuan pemeriksaan). Cakupan: lebar 360px,
target sentuh minimal 44px, fokus keyboard terlihat, label pembaca layar, dan
kontras — pada keempat prototipe runner peserta yang sedang berjalan di cabang
masing-masing.

**Urutan prioritas yang dipakai** (sesuai instruksi pemilik): instruksi
pemilik → sistem desain repo (token Tailwind `brand-*` di
`resources/css/app.css`, komponen shadcn `Button` yang sudah ada) → saran
skill. Skill mengisi celah (angka ambang, istilah kriteria WCAG), bukan
mengganti gaya yang sudah dipakai. Tidak ada dependency baru yang
diusulkan atau ditambahkan.

**Cara kerja**: PAPI, RMIB, dan IST punya fixture Vite yang benar-benar
dirender di browser (`tests/Frontend/<Nama>/`), diperiksa langsung di viewport
360×800 lewat browser bawaan Claude, dengan skrip JS yang mengukur
`getBoundingClientRect()` (ukuran target), fokus-lalu-`getComputedStyle()`
(indikator fokus), dan resolusi warna lewat kanvas 1×1 (kontras — aman
terhadap `oklch()`, format yang dipakai browser ini untuk token Tailwind v4,
bukan `rgb()`). Kraepelin (PR #69) **belum punya fixture/harness** — hanya
tiga berkas komponen presentasional tanpa halaman perakit — jadi bagiannya
adalah tinjauan kode statis (kelas Tailwind, atribut ARIA), bukan pengukuran
langsung di browser. Ini dicatat eksplisit, bukan ditutup-tutupi.

Ambang 44px yang dipakai adalah **ambang proyek** (instruksi Lead), lebih
ketat dari minimum WCAG 2.2 AA untuk web (`Target Size (Minimum)`, 24×24 CSS
px — lihat catatan skill di bawah). Jadi target 25–37px di bawah gagal
ambang 44px proyek, walau sebagian masih lolos batas minimum WCAG AA murni.
Ini dicatat supaya urgensi tiap temuan tidak dibesar-besarkan.

---

## Ringkasan lintas-instrumen

| Pola | PAPI | RMIB | Kraepelin (statis) | IST | Skala |
|---|---|---|---|---|---|
| Tombol shadcn `Button` default (`h-9`≈36-37px) dipakai untuk navigasi utama | ✓ | ✓ (37px) | — (belum ada nav) | ✓ (37px) | Sistemik |
| Tombol "teks-sebagai-tautan" tanpa padding (`Lihat ringkasan`, dst.) | ✓ (16-20px) | ✓ (20px) | — | ✓ (20px) | Sistemik |
| Elemen interaktif custom tanpa `focus-visible:` sama sekali | ✓ | ✓ | Sebagian (lihat T-K1/T-K2) | ✓ | Sistemik |
| Kontras teks | Semua lolos (5.36–20.16:1) | Semua lolos (5.36–20.16:1) | Tidak diukur langsung | Semua lolos (5.36–20.16:1) | Baik |
| Label pembaca layar | Ada, tapi huruf+teks nempel tanpa pemisah | (opsi drag-list sudah pakai `aria-label` deskriptif) | Label lengkap & deskriptif | Ada, huruf+teks nempel tanpa pemisah | Perlu 1 perbaikan kecil |

Temuan positif yang perlu dicatat eksplisit (bukan cuma daftar masalah):
tidak ada masalah kontras teks di satu pun dari tiga prototipe yang terukur
langsung — semua rasio terukur 5.36:1–20.16:1, jauh di atas 4.5:1
(`Accessibility / Color Contrast`, skill `ux`). Kraepelin's jawaban-kotak
(`kraepelin-column.tsx`) justru satu-satunya kontrol custom di keempat
prototipe yang **sudah** punya `focus-visible:` ring yang benar — pola yang
sebaiknya dicontoh saat memperbaiki tiga instrumen lain.

---

## PAPI (`glm/papi-runner-prototype`, fixture port 8013)

Diukur langsung di 360×800.

| # | Elemen | Ukuran | Kontras | Fokus terlihat? | Catatan |
|---|---|---|---|---|---|
| T-P1 | Tombol "Mulai" (layar instruksi) | 360×36px | 20.16:1 | Tidak — `outline-style: none`, tanpa ring pengganti | shadcn `Button` default |
| T-P2 | Opsi radio butir (`papi-item.tsx`) | ~360×59px | 13.9–20.16:1 | Tidak | Ukuran OK, fokus tidak ada sama sekali |
| T-P3 | "Sebelumnya" / "Berikutnya" | ~111×37px / 101×37px | 20.16:1 | Tidak | shadcn `Button` default |
| T-P4 | "Lihat ringkasan" | 80×16px | 5.36:1 | Tidak | Tanpa padding sama sekali — target tersentuh terkecil di seluruh audit |
| T-P5 | Chip lompat "Butir N" (layar ringkasan) | ~25px tinggi, ±90 chip | — | Tidak | Jarak antar-chip 8px sudah sesuai (`Touch / Touch Spacing`) |
| T-P6 | "Kirim jawaban" (submit) | 360×36px | 20.16:1 | Tidak | shadcn `Button` default |
| T-P7 | "Kembali ke soal" | 96×20px | — | Tidak | Tanpa padding |
| T-P8 | Nama aksesibel opsi radio | — | — | — | `aria-label`/teks gabungan huruf+pernyataan tanpa pemisah, mis. "Amurah" bukan "A. murah" |

Kutipan skill yang dipakai: `Accessibility / Target Size (Minimum)` (WCAG
2.2 AA, 24×24 CSS px — T-P4/T-P7 gagal bahkan ambang ini, bukan cuma ambang
44px proyek), `Touch / Touch Target Size` (rekomendasi 44pt/48dp untuk
konteks mobile — dasar ambang 44px yang dipakai Lead), `Interaction / Focus
States` ("Use a visible focus ring on every interactive control... Don't
remove focus outline without replacement" — T-P1/T-P2/T-P3/T-P4/T-P6/T-P7
semuanya melanggar ini), `Accessibility / Compact Control Semantics` (nama
aksesibel harus cocok dengan label yang terlihat — dasar temuan T-P8).

---

## RMIB (`glm/rmib-runner-prototype`, fixture port 8014)

Diukur langsung di 360×800, layar kelompok (drag-list 12 item) dan layar
ringkasan.

| # | Elemen | Ukuran | Kontras | Fokus terlihat? | Catatan |
|---|---|---|---|---|---|
| T-R1 | Tombol pegangan seret "Seret untuk mengurutkan ulang" | 44×44px | **2.63:1** (ikon vs putih) | Tidak | Ukuran target sudah pas 44px — masalahnya kontras ikon, lihat di bawah |
| T-R2 | Tombol ▲ "Naikkan peringkat" / ▼ "Turunkan peringkat" | 44×44px | 20.16:1 | Tidak | Ukuran target sudah benar, hanya kurang ring fokus |
| T-R3 | "Sebelumnya" / "Berikutnya" | 111×37px / 101×37px | 20.16:1 | Tidak | shadcn `Button` default, sama seperti PAPI |
| T-R4 | "Lihat ringkasan" | 93×20px | 5.36:1 | Tidak | Tanpa padding |
| T-R5 | Chip lompat "Kelompok X" (layar ringkasan, 9 kelompok) | 87–93×25px | 9.06:1 | Tidak | |
| T-R6 | "Kirim jawaban" | 279×36px | 20.16:1 | Tidak | shadcn `Button` default |
| T-R7 | "Kembali ke kelompok" | 133×20px | 5.36:1 | Tidak | Tanpa padding |

**Temuan baru dibanding PAPI**: T-R1, tombol pegangan-seret, memakai ikon
(warna `oklch(0.704 0.04 256.788)` ≈ abu-abu sedang) di atas latar putih —
rasio 2.63:1. Ini bukan teks (jadi ambang 4.5:1 teks tidak berlaku langsung),
tapi WCAG 1.4.11 *Non-text Contrast* mensyaratkan 3:1 untuk komponen
antarmuka/objek grafis yang harus dikenali — 2.63:1 gagal ambang itu juga.
Pencarian skill untuk topik ini (`--domain icons`, kata kunci ikon-kontras)
**tidak menemukan hasil** — dicatat eksplisit, ini bukan kutipan skill,
tapi kriteria WCAG umum di luar basis data skill. Kutipan skill terdekat
yang tersedia adalah `Accessibility / Color Contrast` (prinsip umum kontras
terbaca) sebagai referensi tambahan, bukan pengganti 1.4.11.

Sisanya mengulang pola PAPI: `Interaction / Focus States` untuk
T-R1–T-R7, `Accessibility / Target Size (Minimum)` untuk T-R4/T-R7 (di
bawah 24px bahkan ambang WCAG AA murni).

Ukuran ▲/▼ dan pegangan-seret sudah 44×44 — pola drag-and-drop ini justru
bagian yang paling rapi ukurannya di RMIB; masalahnya murni kontras dan
fokus, bukan ukuran.

---

## Kraepelin (PR #69, `glm/kraepelin-runner-items`) — tinjauan kode statis

**Tidak ada fixture/harness untuk instrumen ini** — `kraepelin-column-runner.tsx`
adalah cakupan sempit yang disetujui Lead (2026-09-21): satu kolom, tanpa
layar instruksi/navigasi/ringkasan, tanpa submit (didokumentasikan eksplisit
di berkas itu sebagai keputusan cakupan, bukan kelalaian). Jadi tidak ada
yang bisa dirender di 360px untuk diukur langsung selain lewat kelas
Tailwind di kode. Bagian navigasi/ringkasan Kraepelin (kalau nanti dibangun)
perlu diaudit ulang secara langsung, bukan diasumsikan sama dengan yang di
bawah.

| # | Elemen | Berkas | Kelas ukuran | Fokus terlihat (dari kode)? | Catatan |
|---|---|---|---|---|---|
| T-K1 | Tombol angka 0-9 (`kraepelin-keypad.tsx:44-57`) | `h-11` = 44px | Tidak — hanya `active:bg-slate-100`, tanpa `focus-visible:` | Ukuran sudah pas 44px, kurang ring fokus |
| T-K2 | Tombol "Hapus" (`kraepelin-keypad.tsx:59-71`) | `h-11 col-span-5` = 44px penuh lebar | Tidak — sama seperti T-K1 | |
| T-K3 | Kotak jawaban `<input>` per slot (`kraepelin-column.tsx:108-151`) | `h-8 w-10` = 32×40px | **Ya** — `focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]` (baris 122) | Satu-satunya kontrol custom di keempat prototipe yang sudah benar fokusnya. Ukuran di bawah 44px, tapi ini kemungkinan pembatasan tata letak: 28 angka + 27 kotak tersusun vertikal meniru lembar jawaban fisik (didokumentasikan di baris 36-38) |
| T-K4 | Tombol "Coba lagi" (state reconnecting, `kraepelin-column-runner.tsx:64-66`) | Tanpa kelas sama sekali — hanya default browser | Default browser (biasanya terlihat, tapi tidak konsisten dengan sisa desain) | Edge-case (hanya muncul saat gagal sambung), belum distilasi sama sekali |

Label ARIA (`role="group"` + `aria-label` pada keypad dan kolom,
`aria-label` deskriptif per kotak termasuk status "terkunci") sudah lengkap
dan tidak butuh perbaikan — `Accessibility / ARIA Labels` terpenuhi dengan
baik.

Kutipan skill: `Accessibility / Target Size (Minimum)` untuk T-K3 (32×40px
gagal ambang 44px proyek, tapi *masih lolos* minimum WCAG AA 24×24px murni
— karena itu ini ditandai untuk didiskusikan ke Lead, bukan diperbaiki
langsung, lihat bagian rekomendasi), `Interaction / Focus States` untuk
T-K1/T-K2/T-K4.

---

## IST (`glm/ist-runner-prototype`, fixture port 8017)

Diukur langsung di 360×800. Catatan: tombol "Ganti ke subtes GE" yang
tampak di fixture adalah kontrol debug milik `tests/Frontend/IstSubtestScreen/preview.tsx`
sendiri, bukan bagian dari komponen produksi — dikeluarkan dari tabel di
bawah.

| # | Elemen | Berkas | Ukuran | Kontras | Fokus terlihat? | Catatan |
|---|---|---|---|---|---|---|
| T-I1 | Opsi radio pilihan ganda (`ist-multiple-choice-item.tsx:49-65`) | — | 279×53px | 9.02–10.36:1 | Tidak | Ukuran OK |
| T-I2 | "Sebelumnya" / "Berikutnya" (`ist-subtest-nav.tsx:28-43`) | — | 111×37px / 101×37px | 20.16:1 | Tidak | shadcn `Button` default, identik pola PAPI/RMIB |
| T-I3 | "Lihat ringkasan" (`ist-subtest-nav.tsx:49-55`) | — | 93×20px | 5.36:1 | Tidak | Tanpa padding, identik T-P4/T-R4 |
| T-I4 | Chip lompat "Butir N" (`ist-subtest-summary.tsx:63-69`, layar ringkasan) | — | `px-3 py-1 text-xs` (terukur ~25px tinggi) | — | Tidak | Identik pola T-P5/T-R5 |
| T-I5 | Tombol "Selesai" (`ist-subtest-summary.tsx:76-82`) | — | 36px tinggi (shadcn default) | — | Tidak | |
| T-I6 | Nama aksesibel opsi radio (`ist-multiple-choice-item.tsx:61-64`) | — | — | — | — | Sama seperti T-P8: huruf+teks opsi nempel tanpa pemisah ("amurah") |

IST mengulang persis pola PAPI (arsitektur & komponen navigasi/ringkasan
memang mirror satu sama lain) — semua kutipan skill sama dengan bagian PAPI
di atas.

---

## Rekomendasi & rencana perbaikan

Mengikuti instruksi Lead: **kecil & tidak mengubah perilaku → langsung di
cabang masing-masing; lebih besar → dikirim ke Lead dulu.**

### Kategori A — kecil, aman diperbaiki langsung di tiap cabang

1. **Tambah `focus-visible:` ring** pada semua tombol custom yang belum
   punya (opsi radio PAPI/IST, tombol nav/ringkasan PAPI/RMIB/IST, chip
   lompat, keypad Kraepelin, pegangan-seret & ▲/▼ RMIB). Ini murni
   penambahan kelas CSS (pola yang sudah dipakai `kraepelin-column.tsx:122`
   dan `button.tsx`'s shadcn base: `focus-visible:border-ring
   focus-visible:ring-ring/50 focus-visible:ring-[3px]`) — tidak mengubah
   perilaku, tidak mengubah urutan/isi soal, tidak menyentuh logika skor.
2. **Tambah padding pada tombol "teks-sebagai-tautan"** ("Lihat ringkasan",
   "Kembali ke soal/kelompok") supaya tinggi ≥44px — cukup ubah kelas
   Tailwind (mis. tambah `px-3 py-2`), tidak mengubah teks atau perilaku
   klik.
3. **Naikkan tinggi tombol nav shadcn `Button` default** ("Sebelumnya" /
   "Berikutnya" / "Kirim jawaban" / "Selesai") ke varian `size="lg"` yang
   sudah ada di `button.tsx` (kalau tersedia) atau kelas `h-11`, konsisten
   dengan T-K1/T-K2 Kraepelin yang sudah 44px — perubahan kelas murni.
4. **Perbaiki nama aksesibel opsi radio** (T-P8/T-I6): beri pemisah antara
   huruf opsi dan teks jawaban, mis. `aria-label` eksplisit `"${key}. ${teks}"`
   alih-alih membiarkan dua `<span>` bersebelahan digabung otomatis oleh
   pembaca layar. Perubahan lokal, tidak menyentuh urutan opsi (larangan
   CLAUDE.md soal pengacakan tidak tersentuh — ini murni soal pemisah teks
   label, bukan urutan).
5. **Styling tombol "Coba lagi" Kraepelin** (T-K4): beri kelas dasar yang
   konsisten dengan tombol lain di repo (border, padding, `focus-visible:`)
   — edge-case kecil, tidak mengubah perilaku retry itu sendiri.

### Kategori B — perlu keputusan Lead dulu

1. **Kontras ikon pegangan-seret RMIB (T-R1, 2.63:1)**: menaikkan kontras
   berarti mengganti warna ikon (kemungkinan dari abu-abu netral ke token
   `brand-green`/`slate-600` yang lebih gelap) — ini keputusan visual yang
   menyentuh nuansa desain drag-list, bukan sekadar CSS tambahan, jadi
   dikirim dulu untuk persetujuan warna yang dipakai.
2. **Ukuran kotak jawaban Kraepelin (T-K3, 32×40px)**: menaikkan ke ≥44px
   berarti mengubah tinggi/lebar setiap slot dalam kolom yang meniru lembar
   jawaban fisik — berpotensi mengubah berapa banyak angka+slot yang muat
   di satu layar tanpa scroll berlebihan, dan ini instrumen paten yang
   formatnya sensitif (CLAUDE.md: larangan mengubah presentasi baku
   instrumen tanpa izin). Perlu keputusan psikolog/Lead, bukan diputuskan
   sepihak oleh audit ini.

Tidak ada dependency baru yang diperlukan untuk kategori A maupun B — semua
perbaikan berbasis kelas Tailwind/token yang sudah ada di repo.

## Status setelah audit ini

Dokumen ini murni pencatatan temuan; perbaikan Kategori A akan dikerjakan
menyusul di masing-masing cabang (`glm/papi-runner-prototype`,
`glm/rmib-runner-prototype`, `glm/kraepelin-runner-items`,
`glm/ist-runner-prototype`) sebagai commit terpisah, supaya riwayat tiap PR
tetap bersih dan bisa ditinjau independen. Kategori B menunggu keputusan
Lead sebelum dikerjakan.
