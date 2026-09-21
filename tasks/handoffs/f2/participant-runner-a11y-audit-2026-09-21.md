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
360×800 lewat browser bawaan Claude. Ukuran target diukur lewat
`getBoundingClientRect()`; kontras lewat resolusi warna via kanvas 1×1 (aman
terhadap `oklch()`, format yang dipakai browser ini untuk token Tailwind v4,
bukan `rgb()`). Kraepelin (PR #69) **belum punya fixture/harness** — hanya
tiga berkas komponen presentasional tanpa halaman perakit — jadi bagiannya
adalah tinjauan kode statis (kelas Tailwind, atribut ARIA), bukan pengukuran
langsung di browser. Ini dicatat eksplisit, bukan ditutup-tutupi.

**Koreksi metodologi fokus-keyboard (penting, dicatat supaya jujur)**: giliran
pertama audit ini mengukur indikator fokus dengan memanggil `el.focus()` lewat
JS lalu membaca `getComputedStyle()` — dan menyimpulkan hampir semua kontrol
"tidak ada indikator fokus sama sekali". Kesimpulan itu **salah**. `el.focus()`
terprogram tidak memicu pseudo-class `:focus-visible` di Chromium (butuh
interaksi keyboard sungguhan), jadi ring yang sebenarnya tampil saat pengguna
menekan Tab tidak ikut terbaca oleh cara pengukuran itu. Setelah diverifikasi
ulang dengan Tab sungguhan (`computer{action:"key", text:"Tab"}`) lalu
screenshot visual — bukan cuma computed-style — **semua elemen yang diuji
ulang di PAPI dan RMIB menunjukkan ring fokus abu-emas yang jelas terlihat**:
tombol shadcn `Button` ("Mulai", "Berikutnya"), opsi radio custom, tombol
"Lihat ringkasan" (teks-tautan tanpa padding), dan tombol pegangan-seret RMIB.
Detail teknis: ring itu ternyata bukan ring `focus-visible:ring-*` kustom
Tailwind milik komponen (yang secara `box-shadow` tetap transparan saat
diukur) — melainkan outline default browser (`outline-style: auto`) yang
tidak berhasil disupresi oleh utilitas `outline-none`. Untuk tujuan audit ini
hasilnya sama saja: **indikator fokus memang tampil**, jadi ini dicatat
sebagai catatan teknis terpisah untuk FE (lihat bagian Kategori B), bukan
sebagai kegagalan aksesibilitas.

Karena keterbatasan waktu, verifikasi Tab-sungguhan dilakukan langsung di
PAPI dan RMIB (termasuk elemen paling tidak lazim: tombol pegangan-seret
RMIB). IST memakai persis komponen nav/ringkasan yang sama dengan PAPI
(`ist-subtest-nav.tsx`/`ist-subtest-summary.tsx` meniru kontrak
`papi-item-nav.tsx`/`papi-summary.tsx`, dikonfirmasi dari kode) dan tidak ada
satu pun kelas `outline-none`/`outline-hidden` di berkas-berkas IST — jadi
mekanisme (outline default browser) yang sama berlaku, diekstrapolasi dari
kode + hasil PAPI, bukan diukur ulang langsung di fixture 8017 (server
verifikasinya sempat rusak akibat sesi ini berpindah cabang git di worktree
yang sama saat server itu masih berjalan — insiden operasional yang sudah
diperbaiki, dicatat supaya jujur soal batasnya).

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
| Indikator fokus keyboard terlihat saat Tab sungguhan | ✓ (diverifikasi visual) | ✓ (diverifikasi visual, termasuk pegangan-seret) | Sebagian — lihat T-K1–T-K3 | ✓ (diekstrapolasi dari kode + pola PAPI) | Baik, bukan masalah |
| Kontras teks | Semua lolos (5.36–20.16:1) | Semua lolos (5.36–20.16:1) | Tidak diukur langsung | Semua lolos (5.36–20.16:1) | Baik |
| Label pembaca layar | Ada, tapi huruf+teks nempel tanpa pemisah | (opsi drag-list sudah pakai `aria-label` deskriptif) | Label lengkap & deskriptif | Ada, huruf+teks nempel tanpa pemisah | Perlu 1 perbaikan kecil |

Dua temuan positif yang perlu dicatat eksplisit (bukan cuma daftar masalah):
(1) tidak ada masalah kontras teks di satu pun dari tiga prototipe yang
terukur langsung — semua rasio terukur 5.36:1–20.16:1, jauh di atas 4.5:1
(`Accessibility / Color Contrast`, skill `ux`) — satu-satunya masalah kontras
yang ditemukan adalah kontras non-teks (ikon pegangan-seret RMIB, T-R1),
sudah diperbaiki; (2) **indikator fokus keyboard sebenarnya sudah terlihat
di semua kontrol yang diverifikasi langsung** — bukan lewat styling kustom
yang disengaja, tapi outline default browser yang belum disupresi. Ini
artinya rekomendasi "tambah ring fokus di semua tombol" dari draf pertama
audit ini **ditarik** — lihat bagian Rekomendasi.

---

## PAPI (`glm/papi-runner-prototype`, fixture port 8013)

Diukur langsung di 360×800. Ukuran/kontras dari pengukuran `getBoundingClientRect`
+ kanvas; fokus diverifikasi dengan Tab sungguhan + screenshot.

| # | Elemen | Ukuran | Kontras | Fokus terlihat (Tab sungguhan)? | Catatan |
|---|---|---|---|---|---|
| T-P1 | Tombol "Mulai" (layar instruksi) | 360×36px | 20.16:1 | **Ya** — ring abu-emas jelas | shadcn `Button` default; tinggi di bawah 44px |
| T-P2 | Opsi radio butir (`papi-item.tsx`) | ~360×59px | 13.9–20.16:1 | **Ya** — ring abu-emas jelas | Ukuran OK |
| T-P3 | "Sebelumnya" / "Berikutnya" | ~111×37px / 101×37px | 20.16:1 | **Ya** — ring abu-emas jelas | shadcn `Button` default; tinggi di bawah 44px |
| T-P4 | "Lihat ringkasan" | 80×16px | 5.36:1 | **Ya** — ring abu-emas jelas | Tanpa padding sama sekali — target tersentuh terkecil di seluruh audit |
| T-P5 | Chip lompat "Butir N" (layar ringkasan) | ~25px tinggi, ±90 chip | — | Tidak diverifikasi langsung (pola sama dengan T-P4, diasumsikan sama) | Jarak antar-chip 8px sudah sesuai (`Touch / Touch Spacing`) |
| T-P6 | "Kirim jawaban" (submit) | 360×36px | 20.16:1 | Tidak diverifikasi langsung (komponen shadcn `Button` sama dengan T-P1/T-P3) | shadcn `Button` default |
| T-P7 | "Kembali ke soal" | 96×20px | — | Tidak diverifikasi langsung (pola sama dengan T-P4) | Tanpa padding |
| T-P8 | Nama aksesibel opsi radio (`papi-item.tsx:70-97`) | — | — | — | **Lebih serius dari perkiraan awal**: diverifikasi lewat `read_page` (accessibility tree sungguhan, bukan `textContent`) — tombolnya **tidak punya nama aksesibel sama sekali**. Teks pernyataan dirender sebagai node `generic` terpisah di dalam radio, bukan ikut jadi nama radio itu sendiri (huruf opsi sudah benar `aria-hidden`, tapi itu tidak cukup). Screen reader akan mengumumkan "radio, tanpa label" untuk kedua opsi. Diverifikasi juga bahwa menambah `aria-label` eksplisit memperbaikinya (dicoba dulu via patch JS sebelum diterapkan ke kode sungguhan) |

Kutipan skill yang dipakai: `Accessibility / Target Size (Minimum)` (WCAG
2.2 AA, 24×24 CSS px — T-P4/T-P7 gagal bahkan ambang ini, bukan cuma ambang
44px proyek), `Touch / Touch Target Size` (rekomendasi 44pt/48dp untuk
konteks mobile — dasar ambang 44px yang dipakai Lead), `Accessibility /
Compact Control Semantics` (nama aksesibel harus cocok dengan label yang
terlihat — dasar temuan T-P8). `Interaction / Focus States` **tidak**
dikutip sebagai pelanggaran di sini — lihat koreksi metodologi di atas.

---

## RMIB (`glm/rmib-runner-prototype`, fixture port 8014)

Diukur langsung di 360×800, layar kelompok (drag-list 12 item) dan layar
ringkasan. Fokus diverifikasi dengan Tab sungguhan + screenshot, termasuk
elemen paling tidak lazim (tombol pegangan-seret).

| # | Elemen | Ukuran | Kontras | Fokus terlihat (Tab sungguhan)? | Catatan |
|---|---|---|---|---|---|
| T-R1 | Tombol pegangan seret "Seret untuk mengurutkan ulang" | 44×44px | ~~2.63:1~~ → **4.76:1 (diperbaiki, lihat di bawah)** | **Ya** — ring abu-emas jelas (diverifikasi langsung) | Ukuran target sudah pas 44px — masalahnya kontras ikon |
| T-R2 | Tombol ▲ "Naikkan peringkat" / ▼ "Turunkan peringkat" | 44×44px | 20.16:1 | Tidak diverifikasi langsung (pola sama dengan T-R1) | Ukuran target sudah benar |
| T-R3 | "Sebelumnya" / "Berikutnya" | 111×37px / 101×37px | 20.16:1 | Tidak diverifikasi langsung (komponen shadcn `Button` sama dengan PAPI T-P3) | shadcn `Button` default |
| T-R4 | "Lihat ringkasan" | 93×20px | 5.36:1 | Tidak diverifikasi langsung (pola sama dengan PAPI T-P4) | Tanpa padding |
| T-R5 | Chip lompat "Kelompok X" (layar ringkasan, 9 kelompok) | 87–93×25px | 9.06:1 | Tidak diverifikasi langsung | |
| T-R6 | "Kirim jawaban" | 279×36px | 20.16:1 | Tidak diverifikasi langsung | shadcn `Button` default |
| T-R7 | "Kembali ke kelompok" | 133×20px | 5.36:1 | Tidak diverifikasi langsung | Tanpa padding |

**Satu-satunya temuan riil dibanding PAPI**: T-R1, tombol pegangan-seret,
memakai ikon (warna semula `text-slate-400`, `oklch(0.704 0.04 256.788)` ≈
abu-abu sedang) di atas latar putih — rasio semula 2.63:1. Ini bukan teks
(jadi ambang 4.5:1 teks tidak berlaku langsung), tapi WCAG 1.4.11 *Non-text
Contrast* mensyaratkan 3:1 untuk komponen antarmuka/objek grafis yang harus
dikenali — 2.63:1 gagal ambang itu juga. Pencarian skill untuk topik ini
(`--domain icons`, kata kunci ikon-kontras) **tidak menemukan hasil** —
dicatat eksplisit, ini bukan kutipan skill, tapi kriteria WCAG umum di luar
basis data skill. Kutipan skill terdekat yang tersedia adalah `Accessibility
/ Color Contrast` (prinsip umum kontras terbaca) sebagai referensi tambahan,
bukan pengganti 1.4.11.

**Diperbaiki** (keputusan Lead 2026-09-21: perbaiki, pakai token yang sudah
ada — satu tingkat lebih gelap pada skala slate, bukan warna baru).
`rmib-group-list.tsx` diubah dari `text-slate-400` ke `text-slate-500`;
`hover:text-slate-600` tidak disentuh (masih lebih gelap dari resting state).
Kontras diukur ulang langsung di browser (resolusi warna via kanvas, aman
`oklch`) setelah perubahan: **4.76:1** — jauh di atas ambang 3:1. Commit
`8368fd0` di `glm/rmib-runner-prototype`.

Ukuran ▲/▼ dan pegangan-seret sudah 44×44 — pola drag-and-drop ini justru
bagian yang paling rapi ukurannya di RMIB. `Accessibility / Target Size
(Minimum)` tetap dikutip untuk T-R4/T-R7 (di bawah 24px, gagal bahkan
ambang WCAG AA murni).

---

## Kraepelin (PR #69, `glm/kraepelin-runner-items`, fixture port 8018)

**Update 2026-09-22**: fixture sekarang ada — `tests/Frontend/KraepelinRunner/`
(commit `9ef85ce`), pola sama seperti PAPI/RMIB/IST, data sintetis persis
bentuk respons `KraepelinItemContentReader.php` (50 subtes `col_01..col_50`,
masing-masing 28 `{position,value}`, sudah dalam urutan administrasi).
Fixture ini membuktikan lewat pengukuran urutan penuh (bukan cuma dua
ujung) bahwa klien **tidak** membalik urutan lagi — lihat T-K5 di bawah.
T-K1/T-K2/T-K4 sekarang diverifikasi visual langsung, bukan cuma dari kode.
`kraepelin-column-runner.tsx` masih cakupan sempit yang disetujui Lead
(2026-09-21): satu kolom, tanpa layar instruksi/navigasi-antarkolom-nyata/
ringkasan/submit (didokumentasikan eksplisit di berkas itu sebagai
keputusan cakupan, bukan kelalaian) — fixture ini mensimulasikan
perpindahan kolom lewat tombol uji milik fixture sendiri (state lokal,
bukan timer), bukan menambah cakupan komponen produksinya.

Fokus (T-K1/T-K2/T-K4) sekarang diverifikasi visual langsung di fixture,
bukan diekstrapolasi dari kode — semuanya menunjukkan ring abu-emas
(outline default browser), pola yang sama seperti PAPI/RMIB, dan T-K3
sendiri sudah punya ring kustom eksplisit yang juga terbukti tampil.

| # | Elemen | Berkas | Ukuran | Fokus terlihat (Tab sungguhan/klik)? | Catatan |
|---|---|---|---|---|---|
| T-K1 | Tombol angka 0-9 (`kraepelin-keypad.tsx:44-57`) | `h-11` = 44px | **Ya**, diverifikasi di fixture (klik keypad menulis ke slot yang fokus, ring tampil) | Ukuran sudah pas 44px, diukur langsung di browser.test.mjs |
| T-K2 | Tombol "Hapus" (`kraepelin-keypad.tsx:59-71`) | `h-11 col-span-5` = 44px penuh lebar | Sama seperti T-K1 (belum diklik langsung dalam sesi ini, tapi kelas identik) | |
| T-K3 | Kotak jawaban `<input>` per slot (`kraepelin-column.tsx:108-151`) | `h-8 w-10` = 32×40px — **pengecualian yang disengaja terhadap ambang 44px, keputusan Lead 2026-09-21, JANGAN diubah tanpa psikolog** | **Ya**, diverifikasi — fokus awal otomatis mendarat di slot 1, ring gold terlihat jelas di screenshot fixture | 32×40px **sudah di atas** minimum WCAG 2.2 AA murni (24×24px) — cuma di bawah ambang 44px proyek. Lead memutuskan (2026-09-21) untuk TIDAK menaikkan ukuran ini: Kraepelin adalah tes kecepatan, kotak lebih besar berarti lebih banyak gulir dalam 15 detik per kolom, yang bisa memengaruhi kinerja peserta secara psikometri — ini bukan keputusan tampilan, jadi kalau nanti perlu diubah, itu keputusan psikolog, bukan audit a11y ini. Lihat Kategori B #2 |
| T-K4 | Tombol "Coba lagi" (state reconnecting, `kraepelin-column-runner.tsx:60-70`) | ✅ **Diperbaiki** — sekarang shadcn `Button` (`h-11`=44px), diukur di browser: 44px persis | **Ya** | Fixture membuat fetch pertama selalu gagal (`network_error` sungguhan) supaya state ini benar-benar tampil untuk diperiksa, bukan cuma dibaca dari kode. Klik memicu `retry()` yang sukses di percobaan kedua |
| T-K5 | Urutan angka (baru, dari fixture) | `kraepelin-column.tsx` render, `items.ts`'s `columnNumbersFromItems` | — | **Terverifikasi**: `browser.test.mjs` membandingkan seluruh 28 angka yang tampil (bukan cuma dua ujung) terhadap urutan `position 28..1` yang diharapkan, untuk dua kolom berbeda — klien TIDAK membalik urutan administrasi yang sudah dikirim server. Ini bukti langsung di browser untuk kontrak yang sebelumnya cuma diverifikasi lewat unit test pure-logic (`items-loader.test.ts`, `grid-column.test.ts`) |

Label ARIA (`role="group"` + `aria-label` pada keypad dan kolom,
`aria-label` deskriptif per kotak termasuk status "terkunci") sudah lengkap
dan tidak butuh perbaikan — `Accessibility / ARIA Labels` terpenuhi dengan
baik.

Kutipan skill: `Accessibility / Target Size (Minimum)` untuk T-K3 (32×40px
gagal ambang 44px proyek, tapi *masih lolos* minimum WCAG AA 24×24px murni
— pengecualian yang disengaja, lihat Kategori B #2), `Interaction / Focus
States` untuk T-K4 (kini terpenuhi — ring terlihat + ukuran target benar).

---

## IST (`glm/ist-runner-prototype`, fixture port 8017)

Diukur langsung di 360×800 (ukuran/kontras). Fokus **tidak** diverifikasi
ulang secara visual langsung di fixture ini (server sempat rusak di
pertengahan audit — lihat catatan metodologi di atas) — disimpulkan dari
kode + kesamaan komponen dengan PAPI, bukan diukur langsung. Catatan:
tombol "Ganti ke subtes GE" yang tampak di fixture adalah kontrol debug
milik `tests/Frontend/IstSubtestScreen/preview.tsx` sendiri, bukan bagian
dari komponen produksi — dikeluarkan dari tabel di bawah.

| # | Elemen | Berkas | Ukuran | Kontras | `outline-none` tanpa pengganti di kode? | Catatan |
|---|---|---|---|---|---|---|
| T-I1 | Opsi radio pilihan ganda (`ist-multiple-choice-item.tsx:49-65`) | — | 279×53px | 9.02–10.36:1 | Tidak ada `outline-none` sama sekali | Ukuran OK |
| T-I2 | "Sebelumnya" / "Berikutnya" (`ist-subtest-nav.tsx:28-43`) | — | 111×37px / 101×37px | 20.16:1 | shadcn `Button`, identik T-P3 | shadcn `Button` default; tinggi di bawah 44px |
| T-I3 | "Lihat ringkasan" (`ist-subtest-nav.tsx:49-55`) | — | 93×20px | 5.36:1 | Tidak ada `outline-none` | Tanpa padding, identik T-P4/T-R4 |
| T-I4 | Chip lompat "Butir N" (`ist-subtest-summary.tsx:63-69`, layar ringkasan) | — | `px-3 py-1 text-xs` (terukur ~25px tinggi) | — | Tidak ada `outline-none` | Identik pola T-P5/T-R5 |
| T-I5 | Tombol "Selesai" (`ist-subtest-summary.tsx:76-82`) | — | 36px tinggi (shadcn default) | — | shadcn `Button` | |
| T-I6 | Nama aksesibel opsi radio (`ist-multiple-choice-item.tsx:49-65`) | — | — | — | — | Sama seperti T-P8, dan sedikit lebih parah: huruf opsi di sini bahkan tidak `aria-hidden` (beda dari PAPI), tapi lewat `read_page` tetap terbukti radio-nya sendiri tidak bernama — teks jadi node `generic` terpisah, bukan nama radio |
| T-I7 | Tombol "Coba lagi" gambar opsi gagal dimuat (`ist-asset-image.tsx:65-79`) | — | Semula `px-2 py-1 text-xs` (~20px tinggi), ✅ **diperbaiki** ke `min-h-11 min-w-11` (64×44px terukur) — commit `be6b77a` | — | — | Beda dari T-I2–T-I5: bukan cuma target-sentuh-kecil biasa, tapi kegagalan gambar opsi FA/WU membuat butir itu **tidak bisa dijawab** sama sekali — kalau peserta di HP tidak bisa mengetuk tombol ini, dia kehilangan skor tanpa kesalahan, sementara waktu subtes terus berjalan (Lead, 2026-09-21). Diverifikasi dengan kegagalan `fetchAssetUrl` sungguhan (bukan cuma `<img>` 404, yang TIDAK memicu state `error` ini — lihat `ist-asset-url-loader.ts`), di 360px |

IST mengulang persis pola PAPI (arsitektur & komponen navigasi/ringkasan
memang mirror satu sama lain, dan tidak ada `outline-none` di satu pun
berkas IST) — kemungkinan besar semua kontrolnya menunjukkan ring fokus yang
sama seperti PAPI, tapi ini **ekstrapolasi, bukan pengukuran langsung** —
perlu diverifikasi ulang dengan Tab sungguhan begitu fixture 8017 dijalankan
lagi.

---

## Rekomendasi & rencana perbaikan

Mengikuti instruksi Lead: **kecil & tidak mengubah perilaku → langsung di
cabang masing-masing; lebih besar → dikirim ke Lead dulu.**

### Kategori A — kecil, aman diperbaiki langsung di tiap cabang

Status: **1–3 sudah diterapkan dan didorong ke masing-masing cabang** (lihat
tabel commit di bagian Status di bawah). 4 (Kraepelin) belum, karena Kraepelin
belum punya fixture untuk diverifikasi visual — lihat catatan di bawah.

1. ✅ **Tambah padding pada tombol "teks-sebagai-tautan"** ("Lihat ringkasan",
   "Kembali ke soal/kelompok", chip lompat "Butir N"/"Kelompok X") supaya
   tinggi ≥44px — kelas Tailwind (`min-h-11`/`min-w-11` + `flex items-center
   justify-center` agar teks tetap tercentang, bukan cuma kotak yang
   membesar), tidak mengubah teks atau perilaku klik. Diverifikasi ulang di
   browser (360px) di ketiga cabang setelah diterapkan — semua elemen
   sekarang 44px, tidak ada regresi layout/overflow.
2. ✅ **Naikkan tinggi tombol nav shadcn `Button` default** ("Sebelumnya" /
   "Berikutnya" / "Kirim jawaban" / "Selesai", semula 36-37px) — dipakai
   `className="h-11"` langsung di pemanggilan `<Button>` (varian `size="lg"`
   bawaan cuma `h-10`=40px, masih kurang), memakai `tailwind-merge` yang
   sudah ada di `cn()` jadi override-nya bersih. Diverifikasi 44px di browser.
3. ✅ **Perbaiki nama aksesibel opsi radio** (T-P8/T-I6) — **ternyata bukan
   sekadar soal pemisah teks seperti dugaan awal**: opsi radio PAPI dan IST
   sama sekali tidak punya nama aksesibel (dikonfirmasi via `read_page`
   sebelum diperbaiki). Ditambahkan `aria-label` eksplisit
   (`` `${key}. ${statement}` ``) pada tombol radio-nya sendiri, diverifikasi
   ulang via `read_page` setelah perbaikan — radio sekarang bernama "a.
   Pernyataan A untuk butir 1" dsb.
4. ✅ **Styling tombol "Coba lagi" Kraepelin (T-K4)** — **selesai 2026-09-22**,
   setelah fixture `tests/Frontend/KraepelinRunner/` dibangun (Lead
   menegaskan: jangan menebak tampilan tanpa melihat, jadi fixture dulu,
   baru fix). Diganti dari `<button>` polos tanpa kelas ke shadcn `Button`
   (`h-11`=44px), sama seperti tombol lain di repo. Diverifikasi langsung
   di browser (fixture membuat fetch pertama gagal sungguhan supaya state
   ini benar-benar tampil, bukan cuma dibaca dari kode) dan lewat asersi
   baru di `browser.test.mjs`. Commit `9ef85ce` di `glm/kraepelin-runner-items`.

**Ditarik dari draf pertama**: "tambah `focus-visible:` ring ke semua
tombol custom" — temuan itu berdasarkan metodologi pengukuran yang salah
(lihat koreksi di atas). Indikator fokus sudah terlihat di semua kontrol
yang diverifikasi visual langsung (PAPI, RMIB). Tidak ada perbaikan yang
perlu dikerjakan untuk ini di Kategori A.

### Kategori B — keputusan Lead (2026-09-21)

1. ✅ **Kontras ikon pegangan-seret RMIB (T-R1)** — **diperbaiki**. Lead:
   pakai token yang sudah ada (satu tingkat lebih gelap pada skala slate),
   bukan warna baru. `text-slate-400` → `text-slate-500`, 2.63:1 → 4.76:1
   (diukur ulang di browser). Commit `8368fd0` di `glm/rmib-runner-prototype`.
2. ❌ **Ukuran kotak jawaban Kraepelin (T-K3, 32×40px)** — **JANGAN diubah**.
   Lead: ukuran itu sudah di atas minimum WCAG 2.2 AA (24px); Kraepelin
   adalah tes kecepatan, kotak lebih besar berarti lebih banyak gulir dalam
   15 detik per kolom, yang bisa memengaruhi kinerja peserta — ini urusan
   psikometri, bukan sekadar tampilan. Ditandai di tabel T-K3 di atas sebagai
   **pengecualian yang disengaja** terhadap ambang 44px proyek. Perubahan di
   masa depan, kalau ada, diputuskan psikolog.
3. ✅ **Tombol "Coba lagi" Kraepelin (T-K4)** — **selesai**. Fixture dibangun
   dulu (`tests/Frontend/KraepelinRunner/`, port 8018, commit `9ef85ce`),
   lalu tombolnya diperbaiki dan diverifikasi visual di dalamnya — lihat
   Kategori A #4.
4. **Catatan teknis untuk FE** (bukan temuan aksesibilitas, di luar keputusan
   Lead di atas, dibiarkan sebagai catatan): ring `focus-visible:ring-ring/50
   ring-[3px]` milik shadcn `Button` (dan Kraepelin T-K3) tampaknya tidak
   benar-benar ter-render sebagai `box-shadow` di fixture-fixture ini — yang
   tampil secara visual adalah outline default browser (`outline-style:
   auto`), bukan ring kustom Tailwind. Praktiknya aman (indikator tetap
   terlihat), tapi kalau maksudnya memang memakai ring kustom bermerek, ada
   sesuatu di build Tailwind v4 fixture ini yang layak dicek FE — di luar
   cakupan audit a11y ini untuk diperbaiki.

Tidak ada dependency baru yang diperlukan untuk kategori A maupun B — semua
perbaikan berbasis kelas Tailwind/token yang sudah ada di repo.

## Status setelah audit ini

Dokumen ini sudah dikoreksi sekali setelah audit menemukan kesalahan
metodologinya sendiri pada pengukuran fokus (dicatat transparan di atas,
bukan diam-diam ditimpa), dan sekali lagi setelah verifikasi ulang T-P8/T-I6
lewat accessibility tree sungguhan mengungkap bug yang lebih serius dari
perkiraan awal (nama aksesibel kosong, bukan cuma tanpa pemisah).

Semua perbaikan Kategori A dan Kategori B (kecuali item yang Lead putuskan
untuk TIDAK dikerjakan) sudah diterapkan, diverifikasi di browser 360px
(ukuran via `getBoundingClientRect`, nama aksesibel via `read_page`, kontras
via resolusi warna kanvas, urutan angka Kraepelin via perbandingan urutan
penuh), dan didorong ke masing-masing cabang:

| Cabang | Commit | Isi |
|---|---|---|
| `glm/papi-runner-prototype` | `517e850` | Touch target 44px (nav, ringkasan, chip) + `aria-label` opsi radio |
| `glm/rmib-runner-prototype` | `f0d296b` | Touch target 44px (nav, ringkasan, chip, kembali) |
| `glm/rmib-runner-prototype` | `8368fd0` | Kontras ikon pegangan-seret 2.63:1 → 4.76:1 (keputusan Lead) |
| `glm/ist-runner-prototype` | `ac22a63` | Touch target 44px (nav, ringkasan, chip, kembali, selesai) + `aria-label` opsi radio |
| `glm/kraepelin-runner-items` | `9ef85ce` | Fixture `tests/Frontend/KraepelinRunner/` (port 8018) baru + tombol "Coba lagi" (T-K4) diperbaiki ke 44px dan diverifikasi visual |

**Diputuskan Lead, tidak dikerjakan (dengan alasan)**: ukuran kotak jawaban
Kraepelin (32×40px) — pengecualian disengaja, urusan psikometri kecepatan
tes, bukan cacat tampilan.

Dengan ini, semua item Kategori A dan B dari audit 2026-09-21 sudah
diselesaikan atau diputuskan secara eksplisit — tidak ada temuan a11y/HP
yang masih menggantung dari audit ini.

**Masih menunggu**: tombol "Coba lagi" Kraepelin (T-K4) — menunggu fixture,
sesuai instruksi Lead untuk tidak menebak tampilan tanpa melihat.
