# Teks layar persetujuan proctoring — semua varian

Status: untuk ditinjau pemilik proyek/psikolog sebelum rilis (CLAUDE.md G5).
Sumber: dijalankan langsung dari `getProctoringConsentCopy()` di
`resources/js/components/participant/proctoring/proctoring-consent-copy.ts`
pada 2026-09-21 — bukan diketik ulang, jadi selalu mencerminkan kode yang
sebenarnya berjalan. Wording mengikuti
`tasks/handoffs/f7/proctoring-consent-fullscreen-screen-plan-2026-09-21.md`
§3, dengan dua koreksi yang sudah masuk kode: kamera wajib untuk semua
peserta (keputusan pemilik proyek, PR #81 butir 11), dan penghapusan klaim
jalur LPK yang belum pernah diputuskan siapa pun (lihat bagian "Koreksi"
di bawah).

## Bagian yang sama di semua varian

**Judul:** Sebelum memulai: persetujuan pemantauan

**Paragraf kebijakan (saat layar penuh didukung):**
1. Untuk menjaga keadilan bagi semua peserta, sesi ini dipantau selama berlangsung:
2. Kamera Anda akan mengambil foto secara berkala (bukan merekam video terus-menerus), termasuk saat mulai dan saat mengirim jawaban.
3. Wajah Anda dibandingkan sekali di awal dengan foto identitas Anda, dan sesekali selama sesi, untuk memastikan Anda peserta yang terdaftar.
4. Sistem mencatat bila Anda meninggalkan layar tes (berpindah aplikasi/tab, mengunci layar, atau keluar dari mode layar penuh). Waktu tidak berhenti saat ini terjadi.
5. Layar akan beralih ke mode penuh setiap subtes dimulai. Anda tetap bisa keluar kapan saja — ini pengingat, bukan kuncian.
6. Selama mengerjakan soal, klik kanan, seleksi teks, salin, dan tempel dinonaktifkan pada halaman soal.

**Paragraf kebijakan (saat layar penuh TIDAK didukung — baris ke-5 di atas diganti):**
5. Perangkat/peramban Anda tidak mendukung mode layar penuh. Sesi tetap berjalan normal; kamera dan pencatatan kepergian layar tetap aktif seperti biasa.

**Catatan kejujuran (selalu tampil):**
Pemantauan ini mendeteksi dan mencatat — bukan mencegah. Batas teknis
peramban web membuat kami tidak bisa benar-benar mengunci perangkat Anda.
Keputusan akhir soal keabsahan hasil Anda selalu ada di tangan psikolog
yang meninjau laporan, bukan sistem otomatis.

## Catatan penyimpanan data (dataHandlingNote)

| `persistenceEnabled` | Teks |
|---|---|
| `false` (kondisi produksi saat ini — belum ada endpoint penyimpanan) | Sesi ini menggunakan kamera dan pencatatan kepergian layar secara langsung di perangkat Anda; penyimpanan otomatis ke server psikolog masih dalam pengembangan. |
| `true` (baru berlaku setelah backend F7 nyata ada) | Foto dan catatan ini disimpan sementara (maksimal 90 hari); setelah itu hanya ringkasan peristiwa yang bertahan, tanpa gambar. |

## Notice + tombol per status kamera × layar penuh × wajib/tidak

Tabel di bawah pakai `persistenceEnabled: false` (kondisi produksi saat
ini). Kolom "Notice" kosong berarti tidak ada kotak peringatan yang
tampil (layar hanya menampilkan kebijakan + tombol utama).

### `cameraStatus: inactive` — sebelum peserta mengklik apa pun

| Layar penuh | Wajib | Notice | Tombol utama | Tombol kedua |
|---|---|---|---|---|
| Didukung | Ya | — | Izinkan kamera & mulai | — |
| Didukung | Tidak | — | Izinkan kamera & mulai | Lanjutkan tanpa kamera |
| Tidak didukung | Ya | — | Izinkan kamera & mulai | — |
| Tidak didukung | Tidak | — | Izinkan kamera & mulai | Lanjutkan tanpa kamera |

### `cameraStatus: denied` — peserta menolak/menutup prompt izin kamera peramban

Judul notice (sama di keempat kombinasi):
> Izin kamera untuk halaman ini belum aktif di peramban Anda.

| Layar penuh | Wajib | Isi notice | Tombol kedua |
|---|---|---|---|
| Didukung | Ya | Kamera wajib untuk semua peserta, jadi sesi belum bisa dimulai. Klik ikon kamera atau gembok di address bar peramban Anda dan izinkan akses kamera untuk halaman ini, lalu muat ulang. Di HP, buka pengaturan izin aplikasi peramban (Chrome/Safari) di perangkat Anda dan aktifkan izin kamera untuk peramban tersebut, lalu muat ulang halaman ini. | — |
| Didukung | Tidak | Anda tetap bisa melanjutkan tanpa kamera. Sesi Anda akan ditandai memerlukan catatan prosedur tambahan sebelum psikolog menandatangani laporan — ini bukan penalti otomatis, hanya langkah tinjauan ekstra. | Lanjutkan tanpa kamera |
| Tidak didukung | Ya | *(sama seperti baris pertama)* | — |
| Tidak didukung | Tidak | *(sama seperti baris kedua)* | Lanjutkan tanpa kamera |

Tombol utama pada keempat kombinasi ini: **Coba lagi**.

### `cameraStatus: unavailable` — kamera tidak dapat diakses (dipakai aplikasi lain, tidak ada hardware, dll.)

Judul notice (sama di keempat kombinasi):
> Kamera tidak terdeteksi di perangkat Anda saat ini. Ini bisa karena
> kamera sedang dipakai aplikasi lain, perangkat tidak punya kamera, atau
> kendala teknis lain — bukan berarti Anda menolak.

| Layar penuh | Wajib | Isi notice | Tombol kedua |
|---|---|---|---|
| Didukung | Ya | Kamera wajib untuk semua peserta. Tutup aplikasi lain yang mungkin memakai kamera Anda (panggilan video, aplikasi kamera lain), pastikan kamera perangkat terpasang dan berfungsi, lalu coba lagi. Jika kamera memang tidak berfungsi atau tidak tersedia di perangkat ini, gunakan perangkat lain yang punya kamera. Jika kamera tetap tidak dapat digunakan, hubungi penyelenggara tes Anda. | — |
| Didukung | Tidak | Anda tetap bisa melanjutkan tanpa kamera. Sesi Anda akan ditandai memerlukan catatan prosedur tambahan. | Lanjutkan tanpa kamera |
| Tidak didukung | Ya | *(sama seperti baris pertama)* | — |
| Tidak didukung | Tidak | *(sama seperti baris kedua)* | Lanjutkan tanpa kamera |

Tombol utama pada keempat kombinasi ini: **Coba lagi**.

## Koreksi yang sudah masuk kode (untuk catatan tinjauan)

Draf awal `unavailable` + wajib sempat menulis "...atau hubungi
pengawas/LPK Anda untuk jalur pengawasan alternatif". Itu ditarik karena
**belum ada siapa pun yang memutuskan kebijakan itu** — tidak ada
ketentuan SPEC.md atau proses operasional untuknya, dan menunjukkan teks
itu ke peserta sungguhan akan mengarahkan mereka ke staf LPK yang tidak
tahu harus berbuat apa. Teks final di atas hanya memberi langkah
periksa/ganti perangkat, lalu kalimat netral "hubungi penyelenggara tes
Anda" tanpa menjanjikan hasil tertentu.

**Usulan terbuka** (bukan keputusan, hanya dicatat untuk pemilik proyek):
kalau memang dibutuhkan jalur dukungan operasional yang jelas untuk kasus
kamera benar-benar tidak bisa dipakai (bukan LPK, tapi mungkin kontak
penyelenggara tes yang sudah ada), itu perlu diputuskan dan didefinisikan
dulu di luar kode ini sebelum masuk ke teks aplikasi.
