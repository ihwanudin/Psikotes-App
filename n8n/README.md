# Workflow n8n

Workflow `participant-activation-waha.n8n.json` menerima event aktivasi dari Laravel,
melakukan deduplikasi atomik di PostgreSQL, lalu mengirim pesan melalui WAHA.
Definisi ini ditujukan untuk n8n 2.27.5 dan sengaja diimpor dalam keadaan nonaktif.

## Instalasi

1. Jalankan `sql/notification-delivery-dedup.sql` pada database PostgreSQL yang dapat
   diakses n8n.
2. Impor `workflows/participant-activation-waha.n8n.json` melalui menu **Import from
   File** di n8n.
3. Pada **Participant Activation Webhook**, pilih credential Header Auth dengan:
   - Name: `Authorization`
   - Value: `Bearer <nilai N8N_WEBHOOK_TOKEN>`
4. Pilih credential PostgreSQL yang sama pada **Claim Idempotency Key** dan **Mark
   Delivery Sent**.
5. Pada **Send WAHA Message**:
   - ganti `https://REPLACE-WAHA-HOST` dengan URL HTTPS WAHA;
   - pilih credential Header Auth dengan Name `X-Api-Key` dan token WAHA sebagai
     Value;
   - ubah session `default` pada body bila nama session WAHA berbeda.
6. Jalankan test workflow dengan data sintetis, aktifkan workflow, lalu isi Production
   URL webhook ke `N8N_WEBHOOK_URL` pada environment Laravel.

Jangan menaruh token langsung di JSON workflow atau mengirimkannya melalui chat.

## Perilaku deduplikasi

- Request pertama mengklaim `idempotency_key`, mengirim pesan, lalu menandainya
  `sent`.
- Pengulangan key dan payload yang sudah `sent` menghasilkan HTTP 200 `duplicate`
  tanpa mengirim ulang.
- Key yang sama dengan payload berbeda menghasilkan HTTP 422.
- Key berstatus `processing` menghasilkan HTTP 503 `uncertain` tanpa memanggil WAHA
  lagi. Operator harus memeriksa WAHA sebelum mengubah status; ini menghindari pesan
  kredensial ganda ketika request sebelumnya timeout setelah diterima provider.

Workflow menonaktifkan penyimpanan data eksekusi sukses, gagal, dan manual agar nomor
telepon serta nomor tes tidak menetap di riwayat n8n. Pastikan reverse proxy juga tidak
mencatat request body atau header otorisasi.
