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
(`Accessibility / Color Contrast`, skill `ux`); (2) **indikator fokus
keyboard sebenarnya sudah terlihat di semua kontrol yang diverifikasi
langsung** — bukan lewat styling kustom yang disengaja, tapi outline default
browser yang belum disupresi. Ini artinya rekomendasi "tambah ring fokus di
semua tombol" dari draf pertama audit ini **ditarik** — lihat bagian
Rekomendasi.

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
| T-P8 | Nama aksesibel opsi radio | — | — | — | `aria-label`/teks gabungan huruf+pernyataan tanpa pemisah, mis. "Amurah" bukan "A. murah" |

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
| T-R1 | Tombol pegangan seret "Seret untuk mengurutkan ulang" | 44×44px | **2.63:1** (ikon vs putih) | **Ya** — ring abu-emas jelas (diverifikasi langsung) | Ukuran target sudah pas 44px — masalahnya kontras ikon, lihat di bawah |
| T-R2 | Tombol ▲ "Naikkan peringkat" / ▼ "Turunkan peringkat" | 44×44px | 20.16:1 | Tidak diverifikasi langsung (pola sama dengan T-R1) | Ukuran target sudah benar |
| T-R3 | "Sebelumnya" / "Berikutnya" | 111×37px / 101×37px | 20.16:1 | Tidak diverifikasi langsung (komponen shadcn `Button` sama dengan PAPI T-P3) | shadcn `Button` default |
| T-R4 | "Lihat ringkasan" | 93×20px | 5.36:1 | Tidak diverifikasi langsung (pola sama dengan PAPI T-P4) | Tanpa padding |
| T-R5 | Chip lompat "Kelompok X" (layar ringkasan, 9 kelompok) | 87–93×25px | 9.06:1 | Tidak diverifikasi langsung | |
| T-R6 | "Kirim jawaban" | 279×36px | 20.16:1 | Tidak diverifikasi langsung | shadcn `Button` default |
| T-R7 | "Kembali ke kelompok" | 133×20px | 5.36:1 | Tidak diverifikasi langsung | Tanpa padding |

**Satu-satunya temuan riil dibanding PAPI**: T-R1, tombol pegangan-seret,
memakai ikon (warna `oklch(0.704 0.04 256.788)` ≈ abu-abu sedang) di atas
latar putih — rasio 2.63:1. Ini bukan teks (jadi ambang 4.5:1 teks tidak
berlaku langsung), tapi WCAG 1.4.11 *Non-text Contrast* mensyaratkan 3:1
untuk komponen antarmuka/objek grafis yang harus dikenali — 2.63:1 gagal
ambang itu juga. Pencarian skill untuk topik ini (`--domain icons`, kata
kunci ikon-kontras) **tidak menemukan hasil** — dicatat eksplisit, ini bukan
kutipan skill, tapi kriteria WCAG umum di luar basis data skill. Kutipan
skill terdekat yang tersedia adalah `Accessibility / Color Contrast` (prinsip
umum kontras terbaca) sebagai referensi tambahan, bukan pengganti 1.4.11.

Ukuran ▲/▼ dan pegangan-seret sudah 44×44 — pola drag-and-drop ini justru
bagian yang paling rapi ukurannya di RMIB. `Accessibility / Target Size
(Minimum)` tetap dikutip untuk T-R4/T-R7 (di bawah 24px, gagal bahkan
ambang WCAG AA murni).

---

## Kraepelin (PR #69, `glm/kraepelin-runner-items`) — tinjauan kode statis

**Tidak ada fixture/harness untuk instrumen ini** — `kraepelin-column-runner.tsx`
adalah cakupan sempit yang disetujui Lead (2026-09-21): satu kolom, tanpa
layar instruksi/navigasi/ringkasan, tanpa submit (didokumentasikan eksplisit
di berkas itu sebagai keputusan cakupan, bukan kelalaian). Jadi tidak ada
yang bisa dirender di 360px untuk diukur langsung selain lewat kelas
Tailwind di kode. Bagian navigasi/ringkasan Kraepelin (kalau nanti dibangun)
perlu diaudit ulang secara langsung, bukan diasumsikan sama dengan yang di
atas.

Catatan penting mengikuti koreksi metodologi di atas: untuk PAPI/RMIB,
kontrol tanpa kelas `focus-visible:` eksplisit di kode **tetap** menunjukkan
ring fokus saat diverifikasi visual (outline default browser, tidak
disupresi). Tabel di bawah tetap menandai "tidak ada `focus-visible:` di
kode" apa adanya, tapi **tidak boleh dibaca sebagai "tidak ada indikator
fokus sama sekali"** tanpa verifikasi visual langsung — yang belum bisa
dilakukan di sini karena belum ada fixture.

| # | Elemen | Berkas | Kelas ukuran | `focus-visible:` di kode? | Catatan |
|---|---|---|---|---|---|
| T-K1 | Tombol angka 0-9 (`kraepelin-keypad.tsx:44-57`) | `h-11` = 44px | Tidak ada kelas `focus-visible:`/`outline-none` — kemungkinan sama seperti PAPI/RMIB (outline default browser tampil) | Ukuran sudah pas 44px |
| T-K2 | Tombol "Hapus" (`kraepelin-keypad.tsx:59-71`) | `h-11 col-span-5` = 44px penuh lebar | Sama seperti T-K1 | |
| T-K3 | Kotak jawaban `<input>` per slot (`kraepelin-column.tsx:108-151`) | `h-8 w-10` = 32×40px | Ya, eksplisit — `focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]` (baris 122), DAN `outline-none` (baris 121) | Satu-satunya kontrol di keempat prototipe yang secara eksplisit mengandalkan ring kustom, bukan warisan default browser — perlu dicek langsung begitu ada fixture, sebab pola PAPI/RMIB menunjukkan ring kustom `focus-visible:ring-*` ini sendiri tidak selalu tampak sebagai `box-shadow` (lihat catatan metodologi); di sini `outline-none` SEKALIGUS menyupresi default-nya, jadi kalau ring kustomnya juga tidak render, elemen ini bisa jadi satu-satunya yang benar-benar tanpa indikator fokus. Ukuran 32×40px di bawah 44px juga dicatat, kemungkinan pembatasan tata letak (28 angka + 27 kotak vertikal meniru lembar jawaban fisik, didokumentasikan di baris 36-38) |
| T-K4 | Tombol "Coba lagi" (state reconnecting, `kraepelin-column-runner.tsx:64-66`) | Tanpa kelas sama sekali | Tanpa `outline-none`, jadi default browser kemungkinan tetap tampil | Edge-case (hanya muncul saat gagal sambung), belum distilasi sama sekali secara visual |

Label ARIA (`role="group"` + `aria-label` pada keypad dan kolom,
`aria-label` deskriptif per kotak termasuk status "terkunci") sudah lengkap
dan tidak butuh perbaikan — `Accessibility / ARIA Labels` terpenuhi dengan
baik.

Kutipan skill: `Accessibility / Target Size (Minimum)` untuk T-K3 (32×40px
gagal ambang 44px proyek, tapi *masih lolos* minimum WCAG AA 24×24px murni
— karena itu ini ditandai untuk didiskusikan ke Lead, bukan diperbaiki
langsung, lihat bagian rekomendasi).

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
| T-I6 | Nama aksesibel opsi radio (`ist-multiple-choice-item.tsx:61-64`) | — | — | — | — | Sama seperti T-P8: huruf+teks opsi nempel tanpa pemisah ("amurah") |

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

1. **Tambah padding pada tombol "teks-sebagai-tautan"** ("Lihat ringkasan",
   "Kembali ke soal/kelompok", chip lompat "Butir N"/"Kelompok X") supaya
   tinggi ≥44px — cukup ubah kelas Tailwind (mis. tambah `px-3 py-2`), tidak
   mengubah teks atau perilaku klik. Ini satu-satunya kategori temuan yang
   konsisten gagal bahkan ambang WCAG AA 24px murni (T-P4/T-P7/T-R4/T-R7/T-I3),
   bukan cuma ambang 44px proyek — prioritas tertinggi di Kategori A.
2. **Naikkan tinggi tombol nav shadcn `Button` default** ("Sebelumnya" /
   "Berikutnya" / "Kirim jawaban" / "Selesai", saat ini 36-37px) ke varian
   `size="lg"` yang sudah ada di `button.tsx` (`h-10`=40px, masih di bawah
   44px) atau kelas kustom `h-11` langsung di pemanggilan `<Button>`,
   konsisten dengan T-K1/T-K2 Kraepelin yang sudah 44px — perubahan kelas
   murni, tidak mengubah `onClick`/perilaku.
3. **Perbaiki nama aksesibel opsi radio** (T-P8/T-I6): beri pemisah antara
   huruf opsi dan teks jawaban, mis. `aria-label` eksplisit `"${key}. ${teks}"`
   alih-alih membiarkan dua `<span>` bersebelahan digabung otomatis oleh
   pembaca layar. Perubahan lokal, tidak menyentuh urutan opsi (larangan
   CLAUDE.md soal pengacakan tidak tersentuh — ini murni soal pemisah teks
   label, bukan urutan).
4. **Styling tombol "Coba lagi" Kraepelin** (T-K4): beri kelas dasar yang
   konsisten dengan tombol lain di repo (border, padding) — edge-case kecil,
   tidak mengubah perilaku retry itu sendiri.

**Ditarik dari draf pertama**: "tambah `focus-visible:` ring ke semua
tombol custom" — temuan itu berdasarkan metodologi pengukuran yang salah
(lihat koreksi di atas). Indikator fokus sudah terlihat di semua kontrol
yang diverifikasi visual langsung (PAPI, RMIB). Tidak ada perbaikan yang
perlu dikerjakan untuk ini di Kategori A.

### Kategori B — perlu keputusan Lead/FE dulu (bukan diputuskan sepihak audit ini)

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
3. **Catatan teknis untuk FE (bukan temuan aksesibilitas, tapi layak
   diselidiki terpisah)**: ring `focus-visible:ring-ring/50 ring-[3px]`
   milik shadcn `Button` (dan Kraepelin T-K3) tampaknya tidak benar-benar
   ter-render sebagai `box-shadow` di fixture-fixture ini — yang tampil
   secara visual adalah outline default browser (`outline-style: auto`),
   bukan ring kustom Tailwind. Praktiknya aman (indikator tetap terlihat),
   tapi kalau maksudnya memang memakai ring kustom bermerek (warna brand,
   bukan warna default browser), ada sesuatu di build Tailwind v4 fixture
   ini yang layak dicek FE — di luar cakupan audit a11y ini untuk diperbaiki.

Tidak ada dependency baru yang diperlukan untuk kategori A maupun B — semua
perbaikan berbasis kelas Tailwind/token yang sudah ada di repo.

## Status setelah audit ini

Dokumen ini murni pencatatan temuan (sudah dikoreksi sekali setelah audit
menemukan kesalahan metodologinya sendiri pada pengukuran fokus — dicatat
transparan, bukan diam-diam ditimpa). Perbaikan Kategori A akan dikerjakan
menyusul di masing-masing cabang (`glm/papi-runner-prototype`,
`glm/rmib-runner-prototype`, `glm/kraepelin-runner-items`,
`glm/ist-runner-prototype`) sebagai commit terpisah, supaya riwayat tiap PR
tetap bersih dan bisa ditinjau independen. Kategori B menunggu keputusan
Lead sebelum dikerjakan.
