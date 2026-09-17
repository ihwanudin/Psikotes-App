# ADR-001: Kredensial dan autentikasi peserta

## Status

Accepted

## Date

2026-08-25

## Context

Peserta masuk dengan nomor tes dan tanggal lahir tanpa bergantung pada email atau SMS saat ujian. Nomor tes harus dapat diurutkan per bulan untuk operasi LSI, tetapi urutan yang sepenuhnya dapat ditebak akan memperlemah tanggal lahir sebagai faktor autentikasi. API peserta juga harus tetap stateless dan tidak boleh menerima identitas peserta dari parameter request.

## Decision

- Nomor tes memakai format `LSI-YYYYMM-NNNNNN-RRRRRR`. `NNNNNN` berasal dari sequence per periode yang dinaikkan dalam transaksi dengan row lock; `RRRRRR` adalah 30 bit acak untuk mengurangi enumerasi. Constraint unik pada `participants.test_number` tetap menjadi pertahanan terakhir.
- Periode disiapkan idempoten pada hari pertama setiap bulan oleh Laravel Scheduler. Issuer juga melakukan lazy initialization, sehingga scheduler yang terlambat tidak menghentikan registrasi dan tidak pernah mengatur ulang periode yang sudah digunakan.
- Login peserta dibatasi lima permintaan per menit per IP. Kegagalan per nomor tes memakai key HMAC di cache bersama: lock 60 detik mulai kegagalan ketiga, 5 menit pada kegagalan berikutnya, lalu 15 menit. Login berhasil menghapus riwayat kegagalan nomor tersebut.
- Token peserta adalah HS256 selama 12 jam tanpa refresh token. Secret harus berupa random key `base64:` minimal 32 byte. Profil token memakai `typ=participant+jwt` serta memvalidasi algoritme tetap, signature, `iss`, `aud`, `sub`, `participant_id`, `branch_id`, `iat`, `nbf`, `exp`, dan durasi tepat 12 jam.
- Middleware memverifikasi bahwa peserta belum dihapus dan branch claim masih cocok dengan database. Principal tervalidasi menjadi satu-satunya sumber konteks RLS; endpoint peserta tidak menerima participant ID.
- Task 12 hanya membangun entitlement gate. Status selain `ready` menghasilkan 403. Entitlement `ready` belum membuat sesi sampai engine sesi dapat menghasilkan `ends_at`, konfigurasi instrumen, seed, dan one-attempt lock secara atomik; selama batas fase ini endpoint mengembalikan 501 tanpa mengubah state.

## Alternatives Considered

### Sequence bulanan tanpa suffix acak

Lebih mudah dibaca, tetapi nomor dapat dienumerasi dan tanggal lahir relatif mudah ditebak. Ditolak karena threat model secara eksplisit mencantumkan brute force kredensial peserta.

### UUID acak tanpa sequence

Memberi entropi lebih besar, tetapi menghilangkan urutan bulanan yang dibutuhkan operasi. Ditolak; format gabungan mempertahankan kedua sifat.

### Sesi Laravel atau refresh token peserta

Menambah state/revocation flow untuk sesi tes yang pendek. Ditolak pada F1 sesuai SPEC; peserta dapat login ulang dengan biaya operasional rendah.

### Membuat sesi kosong ketika entitlement ready

Akan menghasilkan kontrak palsu karena durasi dan konfigurasi instrumen belum tersedia. Ditolak agar state `in_progress` tidak tercipta tanpa engine yang lengkap.

## Consequences

- Redis/cache bersama wajib untuk lockout konsisten pada lebih dari satu instance aplikasi.
- Cron Laravel Scheduler wajib berjalan; lazy initialization hanya menjaga availability, bukan menggantikan monitoring scheduler.
- Rotasi `PARTICIPANT_JWT_SECRET` membatalkan seluruh token peserta yang masih aktif.
- PostgreSQL tetap menjadi gerbang akhir untuk pembuktian locking/RLS lintas koneksi pada F1 Task 18.
