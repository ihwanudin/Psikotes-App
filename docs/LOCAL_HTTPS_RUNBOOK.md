# HTTPS untuk pengujian lokal ONCAM

## Batas penggunaan

Domain `https://psikotes.oncam.id` menuju aplikasi Docker lokal melalui named
tunnel `psikotes-oncam-local`. Laptop, Docker, dan koneksi internet harus aktif.
Konfigurasi ini untuk pengujian; Xendit tetap Test Mode. Jangan mengumpulkan data
peserta sungguhan sebelum pemeriksaan keamanan, privasi, dan persetujuan selesai.

## Konfigurasi runtime

Atur di `.env` yang diabaikan Git:

```dotenv
APP_URL=https://psikotes.oncam.id
APP_DEBUG=false
SESSION_SECURE_COOKIE=true
```

`APP_ENV` tetap `local`; perubahan ini bukan perpindahan ke production.
Jangan menyalin secret ke dokumentasi atau menampilkan seluruh Compose config.
Session tetap Redis, cookie host-only, HttpOnly, dan SameSite=Lax. Cookie XSRF
memang dapat dibaca JavaScript; jangan menyamakannya dengan cookie autentikasi.

Terapkan ke app, queue notifikasi/default, queue integrasi, dan scheduler memakai
image yang sudah diverifikasi:

```powershell
docker compose -f compose.yaml -f compose.tunnel.yaml up -d --no-deps --no-build --pull never --wait --wait-timeout 60 app queue integrations-queue scheduler
```

Perintah merekreasi layanan terkait dan bisa menyebabkan putus akses singkat.
Tidak melakukan build, migrasi, atau penghapusan volume. Jangan membangun image
dari worktree yang berisi perubahan lain yang belum disetujui.

Gunakan domain HTTPS untuk alur pendaftaran/login. HTTP loopback
`http://127.0.0.1:8000/health` tetap dapat dipakai untuk health check.

## Verifikasi 2026-08-31

- Sebelum perubahan: cookie publik tidak memiliki atribut Secure.
- Sesudah perubahan: GET `/register` 200; cookie sesi Secure, HttpOnly,
  SameSite=Lax; respons `no-cache, private`; tidak ada tautan localhost.
- URL `/register` yang dihasilkan oleh queue memakai domain HTTPS.
- POST `/registrations` kosong dengan sesi dan token CSRF valid menghasilkan
  422 (validasi), tanpa token menghasilkan 419; tidak membuat peserta baru.
- Callback dengan token salah menghasilkan 401. Data tetap satu payment event,
  satu entitlement, dan satu outbox aktivasi berstatus processed.
- Health check lokal 200 dan ketiga layanan kembali berjalan.
- HTTP publik `/`, `/register`, `/health`, dan jalur referral menghasilkan
  redirect 308 ke HTTPS; path, query string, dan encoding parameter terjaga.
- HTTPS `/register` dan `/health` tetap 200 tanpa loop. POST HTTP ke endpoint
  webhook menghasilkan 308; POST HTTPS dengan token salah tetap 401.

## Aturan redirect Cloudflare

Single Redirect aktif pada zona `oncam.id`:

- Nama: `Psikotes - HTTP to HTTPS`.
- Rule ID: `4836dffcecca4b43aeec57a29502c865`.
- Request URL: `http://psikotes.oncam.id/*`.
- Target URL: `https://psikotes.oncam.id/${1}`.
- Status: 308 (permanen, mempertahankan metode HTTP).
- Preserve query string: aktif.

Aturan hanya mencocokkan hostname tersebut dan tidak mengubah subdomain lain.
Pengaturan Always Use HTTPS seluruh zona tidak diubah. Redirect bukan pengganti
penggunaan HTTPS sejak permintaan pertama: jangan mengirim kredensial/data ke
HTTP lalu mengandalkan redirect. Callback Xendit tetap memakai URL HTTPS langsung.

## Pemulihan

Jika ada gangguan, pertahankan Secure cookie dan periksa health/log layanan
lebih dahulu. Jika redirect salah, koreksi target pada rule ID di atas. Untuk
pemulihan darurat, nonaktifkan hanya rule tersebut setelah persetujuan operator;
perubahan ini akan membuka kembali akses HTTP dan redirect permanen mungkin
masih tersimpan pada browser. Jangan mengubah aturan domain lain.

Untuk kembali ke pengembangan HTTP-only, hentikan publikasi
tunnel terlebih dahulu; baru kembalikan `APP_URL=http://localhost:8000` dan
`SESSION_SECURE_COOKIE=false`, lalu jalankan kembali perintah Compose di atas.
Jangan menonaktifkan Secure cookie ketika aplikasi masih tersedia publik.
Tidak perlu menghapus volume atau mengganti token/key.

Referensi: [sesi Laravel](https://laravel.com/framework/docs/13.x/session) dan
[rekreasi layanan Compose](https://docs.docker.com/reference/cli/docker/compose/up/).
Lihat juga [parameter Single Redirects Cloudflare](https://developers.cloudflare.com/rules/url-forwarding/single-redirects/settings/).
