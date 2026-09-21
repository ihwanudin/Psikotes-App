# Teks layar persetujuan proctoring — semua varian

Status: untuk ditinjau pemilik proyek/psikolog sebelum rilis (CLAUDE.md G5).
Sumber: dijalankan langsung dari `getProctoringConsentCopy()` di
`resources/js/components/participant/proctoring/proctoring-consent-copy.ts`
pada 2026-09-21 (diperbarui setelah tinjauan Lead, lalu setelah pemenuhan
UU No. 27/2022 PDP butir 16) — bukan diketik ulang, jadi selalu
mencerminkan kode yang sebenarnya berjalan. Wording mengikuti
`tasks/handoffs/f7/proctoring-consent-fullscreen-screen-plan-2026-09-21.md`
§3 dan §8. Kepatuhan UU PDP: teks ini mengikuti keputusan pemilik butir
16 (PR #81 `c0c772a`) — "praktik terbaik", tanpa tinjauan kata per kata
dari pemilik. Daftar periksa lengkap (tujuan, jenis data, masa simpan,
pemroses, hak peserta) ada di doc-comment
`proctoring-consent-copy.ts`, dicek langsung oleh test
`proctoring-consent-copy.test.ts`.

**Kamera wajib untuk SEMUA peserta, tanpa pengecualian** (keputusan
pemilik proyek, PR #81 butir 11). Tidak ada parameter atau varian
"kamera tidak wajib" di kode maupun di dokumen ini — jangan membaca
dokumen ini seolah opsi itu ada. Satu-satunya jalan melewati layar ini
adalah kamera benar-benar aktif; `denied` dan `unavailable` sama-sama
memblokir mulainya sesi.

## Bagian yang sama di semua varian

**Judul:** Sebelum memulai: persetujuan pemantauan

**Paragraf kebijakan (saat layar penuh didukung):**

1. Untuk menjaga keadilan bagi semua peserta, sesi ini dipantau selama berlangsung: — **[UU PDP butir 1: tujuan]**
2. Kamera Anda akan mengambil foto secara berkala (bukan merekam video terus-menerus), termasuk saat mulai dan saat mengirim jawaban. — **[UU PDP butir 2: jenis data — foto berkala]**
3. Wajah Anda dibandingkan sekali di awal dengan foto identitas Anda, dan sesekali selama sesi, untuk memastikan Anda peserta yang terdaftar. — **[UU PDP butir 2: jenis data — pencocokan wajah]**
4. Sistem mencatat bila Anda meninggalkan layar tes (berpindah aplikasi/tab, mengunci layar, atau keluar dari mode layar penuh). Waktu tidak berhenti saat ini terjadi. — **[UU PDP butir 2: jenis data — catatan kepergian layar]**
5. Data pemantauan ini menjadi bagian penilaian psikolog yang menangani laporan Anda. — **[UU PDP butir 4: siapa memproses]**
6. Layar akan beralih ke mode penuh setiap subtes dimulai. Anda tetap bisa keluar kapan saja — ini pengingat, bukan kuncian.
7. Selama mengerjakan soal, klik kanan, seleksi teks, salin, dan tempel dinonaktifkan pada halaman soal.

**Paragraf kebijakan (saat layar penuh TIDAK didukung — baris ke-6 di atas diganti):** 6. Perangkat/peramban Anda tidak mendukung mode layar penuh. Sesi tetap berjalan normal; kamera dan pencatatan kepergian layar tetap aktif seperti biasa.

**Catatan kejujuran (selalu tampil):** — **[UU PDP butir 6: tidak menyalahkan, deteksi bukan cegah]**
Pemantauan ini mendeteksi dan mencatat — bukan mencegah. Batas teknis
peramban web membuat kami tidak bisa benar-benar mengunci perangkat Anda.
Keputusan akhir soal keabsahan hasil Anda selalu ada di tangan psikolog
yang meninjau laporan, bukan sistem otomatis.

## Catatan penyimpanan data (dataHandlingNote) — UU PDP butir 3: masa simpan

| `persistenceEnabled`                                                 | Teks                                                                                                                                                            |
| -------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `false` (kondisi produksi saat ini — belum ada endpoint penyimpanan) | Sesi ini menggunakan kamera dan pencatatan kepergian layar secara langsung di perangkat Anda; penyimpanan otomatis ke server psikolog masih dalam pengembangan. |
| `true` (baru berlaku setelah backend F7 nyata ada)                   | Foto dan catatan ini disimpan sementara (maksimal 90 hari); setelah itu hanya ringkasan peristiwa yang bertahan, tanpa gambar.                                  |

## Hak peserta & kontak (dataRightsNote) — UU PDP butir 5

Selalu tampil, tidak tergantung `persistenceEnabled` — hak untuk
bertanya/mengakses tidak bergantung pada apakah penyimpanan server
sudah nyata.

**Teks default (`contactReference` tidak diisi pemanggil):**

> Anda berhak mengetahui, mengoreksi identitas Anda, dan meminta
> penghapusan data pemantauan yang tersimpan tentang Anda, sesuai
> ketentuan yang berlaku. Untuk menggunakan hak ini atau bertanya lebih
> lanjut, hubungi penyelenggara tes Anda.

**PENTING — placeholder, bukan kontak final:** "penyelenggara tes Anda"
adalah nilai bawaan generik, BUKAN saluran kontak sungguhan. Belum ada
keputusan pemilik proyek soal alamat email/nomor telepon/kanal kontak
resmi (CLAUDE.md melarang mengarang kontak). Siapa pun yang
menyambungkan `ProctoringConsentScreen` ke halaman produksi WAJIB
mengisi prop `contactReference` dengan nilai sungguhan (idealnya dari
konfigurasi server) sebelum go-live — contoh bila diisi:

> ...Untuk menggunakan hak ini atau bertanya lebih lanjut, hubungi
> (contoh) admin cabang Anda.

## Notice + tombol per status kamera × layar penuh

Tabel di bawah pakai `persistenceEnabled: false` (kondisi produksi saat
ini). Tidak ada kolom "tombol kedua" — tidak ada jalan melewati layar
ini tanpa kamera aktif, di kombinasi manapun. Kolom "Notice" kosong
berarti tidak ada kotak peringatan yang tampil (layar hanya menampilkan
kebijakan + tombol utama).

### `cameraStatus: inactive` — sebelum peserta mengklik apa pun

| Layar penuh    | Notice | Tombol utama           |
| -------------- | ------ | ---------------------- |
| Didukung       | —      | Izinkan kamera & mulai |
| Tidak didukung | —      | Izinkan kamera & mulai |

### `cameraStatus: denied` — peserta menolak/menutup prompt izin kamera peramban

Judul notice (sama di kedua kombinasi):

> Izin kamera untuk halaman ini belum aktif di peramban Anda.

Isi notice (sama di kedua kombinasi — tidak tergantung layar penuh):

> Kamera wajib untuk semua peserta, jadi sesi belum bisa dimulai. Klik
> ikon kamera atau gembok di address bar peramban Anda dan izinkan
> akses kamera untuk halaman ini, lalu muat ulang. Di HP, buka
> pengaturan izin aplikasi peramban (Chrome/Safari) di perangkat Anda
> dan aktifkan izin kamera untuk peramban tersebut, lalu muat ulang
> halaman ini.

Tombol utama: **Coba lagi**.

### `cameraStatus: unavailable` — kamera tidak dapat diakses (dipakai aplikasi lain, tidak ada hardware, dll.)

Judul notice (sama di kedua kombinasi):

> Kamera tidak terdeteksi di perangkat Anda saat ini. Ini bisa karena
> kamera sedang dipakai aplikasi lain, perangkat tidak punya kamera, atau
> kendala teknis lain — bukan berarti Anda menolak.

Isi notice (sama di kedua kombinasi — tidak tergantung layar penuh):

> Kamera wajib untuk semua peserta. Tutup aplikasi lain yang mungkin
> memakai kamera Anda (panggilan video, aplikasi kamera lain), pastikan
> kamera perangkat terpasang dan berfungsi, lalu coba lagi. Jika kamera
> memang tidak berfungsi atau tidak tersedia di perangkat ini, gunakan
> perangkat lain yang punya kamera. Jika kamera tetap tidak dapat
> digunakan, hubungi penyelenggara tes Anda.

Tombol utama: **Coba lagi**.

## Riwayat koreksi (untuk catatan tinjauan)

1. **Klaim jalur LPK ditarik.** Draf awal `unavailable` sempat menulis
   "...atau hubungi pengawas/LPK Anda untuk jalur pengawasan
   alternatif". Ditarik karena belum ada siapa pun yang memutuskan
   kebijakan itu. Teks final di atas hanya memberi langkah
   periksa/ganti perangkat, lalu kalimat netral "hubungi penyelenggara
   tes Anda" tanpa menjanjikan hasil tertentu.
2. **Jalur "lanjut tanpa kamera" dihapus seluruhnya (tinjauan Lead,
   PR #83).** Versi sebelumnya menyimpan parameter `cameraMandatory`
   dengan komentar "no real caller may pass false", tapi kode tetap
   menyediakan tombol "Lanjutkan tanpa kamera" yang siap dipakai —
   larangan yang hanya dijaga komentar, bukan oleh kode. Parameter dan
   tombol itu sudah dihapus total dari `proctoring-consent-copy.ts`,
   `proctoring-consent-screen.tsx`, dan fixture-nya. Kamera wajib untuk
   semua peserta sekarang berlaku tanpa jalan pintas apa pun di kode.
3. **Kepatuhan UU No. 27/2022 PDP dilengkapi (2026-09-21, keputusan
   pemilik butir 16, PR #81 `c0c772a`).** Sebelumnya teks belum secara
   eksplisit menyebut siapa yang memproses data pemantauan (psikolog)
   maupun hak peserta atas datanya. Ditambahkan: satu kalimat "Data
   pemantauan ini menjadi bagian penilaian psikolog yang menangani
   laporan Anda" di daftar kebijakan, dan bagian baru "Hak peserta &
   kontak" (`dataRightsNote`) yang selalu tampil. Kontak untuk
   menggunakan hak itu adalah **placeholder** ("penyelenggara tes
   Anda") sampai pemilik proyek menentukan kanal kontak sungguhan —
   lihat peringatan di bagian "Hak peserta & kontak" di atas.

**Usulan terbuka** (bukan keputusan, hanya dicatat untuk pemilik proyek):
kalau memang dibutuhkan jalur dukungan operasional yang jelas untuk kasus
kamera benar-benar tidak bisa dipakai (bukan LPK, tapi mungkin kontak
penyelenggara tes yang sudah ada), itu perlu diputuskan dan didefinisikan
dulu di luar kode ini sebelum masuk ke teks aplikasi.
