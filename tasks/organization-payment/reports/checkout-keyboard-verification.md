# Checkout: verifikasi keyboard native dan reflow

Tanggal: 2026-08-31. Pemilik: Koordinator.

Pengguna meminta pekerjaan independen dilanjutkan saat backend mengerjakan token.
Verifikasi ini tidak mengedit komponen, kontrak, harga, consent server atau backend.
Skill Playwright digunakan untuk browser terpisah; Debugging & Error Recovery
memisahkan kendala alat dari perilaku aplikasi. Documentation & ADRs dan Git
Workflow menjaga bukti sebagai checkpoint, bukan klaim fitur end-to-end selesai.

## Lingkungan dan batas keamanan

- Checkout induk setelah 803c5aa; harness frontend existing, data sintetis saja.
- Vite config tests/Frontend/IntegratedCheckout/vite.config.ts, mode preview,
  origin 127.0.0.1:8011, tanpa Laravel plugin/DB/.env.
- Playwright CLI cached 0.1.18, Chrome terpasang 151.0.7922.171, session baru
  `oncam-keyboard`, headless dan profil sementara terpisah. Tidak attach ke tab
  pengguna atau memakai profil browser pengguna.
- Tidak install atau upgrade dependency/browser. Percobaan channel chromium
  gagal karena build 1237 tidak tersedia; channel chrome berhasil memakai browser
  terpasang. Help CLI sempat memberi libuv assertion saat exit; operasi pengujian
  berhasil. Kegagalan tooling tersebut bukan bukti gagal/lulus UI.
- Satu console error adalah favicon.ico 404 pada preview. Tidak diklaim zero-error
  console. Tidak ada error JavaScript aplikasi pada log yang diperiksa.

## Hasil teramati

| Skenario | Bukti runtime |
| --- | --- |
| Urutan Tab | Dari kontrol fixture melewati skip-link, disclosure psikotes, checkbox, disclosure DASS, radio, kemudian submit setelah pilihan dibuat |
| Checkbox utama | Mulai kosong; Space mengubah menjadi checked; telemetri `trusted=true` |
| Disclosure consent | Enter pada summary membuka dokumen DASS |
| Radio DASS | Space memilih setuju; ArrowDown memindahkan pilihan ke menolak dan membatalkan radio setuju; `trusted=true` |
| Submit | Enter pada tombol enabled memanggil callback tepat satu kali; payload DASS accepted=false; status pembayaran/akses tidak berubah |
| Required field | Enter dengan nomor kosong tidak menambah counter dan memindahkan fokus ke Nomor WhatsApp |
| Input dan submit ulang | Input nomor sintetis melalui keyboard lalu Enter menambah counter tepat satu; DASS yang belum dipilih tidak dikirim |
| Error summary | Aktivasi tombol simulasi error dengan Enter memindahkan fokus ke alert |
| Error link | Tab dari alert menuju link field, Enter mengarahkan fokus ke input WhatsApp |
| Legal pending | Perubahan flag fixture melalui Space kembali menahan konfirmasi meski checkbox utama dipilih; callback tidak bertambah |
| Fokus tombol mobile | Setelah Shift+Tab/Tab, `:focus-visible` aktif; ring gold 3px tampak pada screenshot; tombol berada dalam viewport |
| Reflow | document scrollWidth sama dengan clientWidth pada 320, 390, 768 dan 1280 CSS px pada skenario profil kurang + error |

Input Tab/Space/ArrowDown/Enter dikirim oleh Playwright `page.keyboard` atau
locator.press, bukan dispatchEvent/mutasi DOM. evaluate hanya membaca activeElement,
computed style, disclosure state dan ukuran. Locator fokus digunakan untuk
memulai beberapa probe terarah; tidak diklaim semua skenario dimulai dari Tab
pertama tanpa setup. Native event dibuktikan oleh telemetri harness existing.

Saat laporan gelombang kedua ditulis, input in-app browser menghasilkan
trusted=false. Bukti baru ini menutup gap keyboard untuk **checkout sintetis
pada Chrome di atas**, bukan mengubah hasil historis itu menjadi lulus.

## Pengulangan lokal

Pastikan 8011 kosong, lalu jalankan dari root proyek:

```powershell
node node_modules/vite/bin/vite.js --config tests/Frontend/IntegratedCheckout/vite.config.ts --mode preview
```

CLI di mesin audit ditemukan pada cache berikut (lokasi mesin, bukan dependency
proyek; verifikasi lokasinya jika dipindahkan):

```powershell
$checkoutCli = 'C:/Users/ThinkPad/AppData/Local/npm-cache/_npx/31e32ef8478fbf80/node_modules/@playwright/cli/playwright-cli.js'
node $checkoutCli -s=oncam-keyboard open http://127.0.0.1:8011/ --browser chrome
node $checkoutCli -s=oncam-keyboard snapshot
```

Gunakan ref dari snapshot terbaru. Pada fixture lembaga, matikan legal pending
fixture (bukan kebijakan nyata); Tab ke checkbox utama, Space, Tab ke disclosure
DASS, Enter, Tab ke radio, Space, ArrowDown, Tab ke submit, Enter. Periksa counter,
payload accepted=false, status payment tetap dan `trusted=true`. Ulang pada
Mandiri/profil kurang untuk required field dan error focus sesuai tabel di atas.
Selalu pisahkan setup fixture/click dari bukti keyboard. Jangan gunakan global
close-all/kill-all; tutup hanya session oncam-keyboard dan server uji yang dibuat.

Screenshot yang diinspeksi: `output/playwright/checkout-keyboard-mobile.png`
(390 x 3792), hanya lokal dan diabaikan Git. Snapshot/console CLI juga lokal pada
.playwright-cli. Tidak ada artefak berisi data pengguna yang dibuat.

## Batas yang tetap terbuka

- Bukan audit WCAG penuh, screen reader, semua browser/perangkat, atau zoom teks
  200%. Reflow empat ukuran bukan pengganti uji zoom/browser lain.
- Tidak mengetes endpoint checkout, settlement, token, invoice atau engine sesi.
- Native keyboard portal cabang memiliki task verifikasi terpisah, belum tercakup.
- Suite 670/156/19 dari gelombang kedua adalah hasil historis. Tidak dijalankan
  ulang karena tidak ada kode aplikasi/harness berubah pada increment ini.
- P16 tetap prep; wiring menunggu kontrak server dan dependensi P14/P15.
