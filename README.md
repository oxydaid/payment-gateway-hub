# Payment Bridge 🛡️💳

[![Laravel Version](https://img.shields.io/badge/laravel-v13-red.svg)](https://laravel.com)
[![PHP Version](https://img.shields.io/badge/php-8.4-blue.svg)](https://php.net)
[![Inertia.js](https://img.shields.io/badge/inertia-v3-purple.svg)](https://inertiajs.com)
[![TailwindCSS](https://img.shields.io/badge/tailwind-v4-blueviolet.svg)](https://tailwindcss.com)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

**Payment Bridge** adalah platform router/aggregator pembayaran terpadu berbasis **Laravel 13**, **Inertia.js v3 (Vue 3)**, dan **Tailwind CSS v4**. Sistem ini bertindak sebagai jembatan tunggal (*hub*) yang menghubungkan berbagai aplikasi/merchant Anda ke beragam penyedia payment gateway melalui satu kontrak REST API dan sistem webhook routing yang aman, idempoten, dan asinkron.

---

## ✨ Fitur Utama

### 🌓 Spotify-Inspired Admin Dashboard
* UI adaptif untuk mode Terang (*Light*) dan Gelap (*Dark*) dengan branding dinamis (`app_name`, logo, favicon, warna primer/sekunder).
* KPI ter-cache di Redis serta loading grafik 30 hari & transaksi terbaru berbasis **Inertia v3 Deferred Props** (`<Deferred>` + skeleton) untuk navigasi instan.

### 🔌 Multi-Driver Payment Gateway Support
Terintegrasi melalui pola Strategy/Adapter (`GatewayDriverInterface`) dengan in-memory driver caching:
1. **Midtrans** (Snap — Sandbox & Production)
2. **Xendit** (v3 Payment Requests)
3. **Tokopay** (VA, QRIS, E-Wallet, Retail)
4. **Pakasir** (QRIS & Virtual Account)
5. **Ariepulsa** (QRIS Standard, QRIS GO, QRIS Realtime)

*(Catatan: Tripay dihapus karena secara native sudah mendukung multi-merchant).*

### 🛍️ Public Checkout & Dynamic On-The-Fly QRIS Rendering
* **Dynamic Local QRIS (`/payments/qris/{reference_id}.{png|svg}`):** Merender gambar QRIS secara lokal (on-the-fly) menggunakan `chillerlan/php-qrcode` langsung dari raw EMVCo `payment_code` (`000201...`) — **tanpa API pihak ketiga** dan **tanpa menyimpan file gambar di disk**, lengkap dengan header `Cache-Control: public, max-age=86400, immutable`.
* **VA Copy & Real-time Polling:** Salin nomor VA sekali klik, polling status otomatis tiap 3 detik saat `PENDING`, dan auto-redirect 5 detik saat status `PAID` / `DONE`.

### 🔐 Keamanan & Reliabilitas Tingkat Produksi
* **Hashed API Keys (SHA-256):** Token Global (`pb_live_*`) & Merchant (`pb_mcht_*`) disimpan sebagai hash SHA-256 (`key_hash`) dan hanya ditampilkan sekali melalui modal saat pembuatan.
* **Encrypted Credentials & Webhook Secret:** Kredensial upstream disimpan terenkripsi (`encrypted:array`), dan callback outbound ditandatangani dengan `X-Bridge-Signature` (HMAC-SHA256) memakai kolom terenkripsi `merchants.webhook_secret` (`whsec_*`).
* **Idempotency Transaksi:** `POST /api/v1/payments` mendukung header `Idempotency-Key` dan deduplikasi `(merchant_id, merchant_ref_id)` → mengembalikan `200 OK` (`idempotent: true`) untuk retry identik, atau `409 Conflict` jika parameter nominal/metode berbeda.
* **Anti-Race Condition Webhook:** Distributed cache lock (Redis) + `DB::transaction()` dengan `lockForUpdate()`, serta respons `200 OK` idempoten saat duplikat webhook datang bersamaan dan `403 Forbidden` untuk signature tidak valid (`hash_equals()`).
* **Rate Limiting & SSRF Protection:** Throttle aktif di Merchant API (`60/menit`), Upstream Webhooks & QRIS (`120/menit`), dan Login (`10/menit`), serta validasi SSRF yang memblokir URL webhook ke loopback/private/link-local IP (IPv4 & IPv6).

### 🔄 Asynchronous Callback Engine & Reconciler
* Default `MERCHANT_CALLBACK_SYNC=false` mengirim callback secara asinkron lewat Queue Worker (`after_commit: true`) dengan **5x retry exponential backoff** (`10s → 60s → 300s → 1800s → 3600s`).
* Command terjadwal `php artisan app:reconcile-transactions` berjalan tiap 5 menit menggunakan `chunkById(100)` untuk merekonsiliasi transaksi `PENDING` dan menandai transaksi kedaluwarsa (`EXPIRED`).

---

## 📚 Dokumentasi API & Webhook Terintegrasi

Setelah aplikasi berjalan, tersedia dua dokumentasi interaktif bawaan:
* **OpenAPI / Scalar API Reference:** `GET /docs/api` (dihasilkan otomatis oleh `dedoc/scramble` dari kode aplikasi).
* **Merchant Webhook Manual:** `GET /docs/webhook` (panduan skema payload, verifikasi HMAC-SHA256, aturan SSRF, serta contoh kode PHP, Node.js, dan Python).

---

## 📂 Konfigurasi Deployment

Tersedia konfigurasi siap pakai di root & folder `deploy/`:
* **Docker:** [Dockerfile](Dockerfile) & [docker-compose.yml](docker-compose.yml) (`app`, `web`, `db`, `redis`, `queue-worker`, `scheduler`).
* **Coolify / Nixpacks:** [nixpacks.toml](nixpacks.toml).
* **VPS Native:** [deploy/vps/nginx.conf](deploy/vps/nginx.conf) & [deploy/vps/supervisor.conf](deploy/vps/supervisor.conf).
* **cPanel:** [deploy/cpanel/htaccess](deploy/cpanel/htaccess) & [deploy/cpanel/cron_job.txt](deploy/cpanel/cron_job.txt).
* Panduan lengkap tersedia di [deploy/DEPLOYMENT.md](deploy/DEPLOYMENT.md).

---

## 🚀 Panduan Instalasi Quickstart

### 1. Klon Repositori & Instal Dependensi
```bash
git clone https://github.com/username/pg-bridge.git
cd pg-bridge
composer install
pnpm install
```

### 2. Konfigurasi Environment File
Salin `.env.example` menjadi `.env`, atur koneksi MariaDB/MySQL & Redis (`CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `MERCHANT_CALLBACK_SYNC=false`), lalu generate app key:
```bash
cp .env.example .env
php artisan key:generate
```

### 3. Jalankan Migrasi & Database Seeder
```bash
php artisan migrate --force --seed
```
*Akun administrator default:*
* **Email:** `admin@example.com`
* **Password:** `password`

### 4. Jalankan Aplikasi, Queue Worker & Frontend
```bash
# Terminal 1 — Laravel HTTP Server
php artisan serve

# Terminal 2 — Queue Worker (wajib jika MERCHANT_CALLBACK_SYNC=false)
php artisan queue:work

# Terminal 3 — Vite Dev Server (atau jalankan `pnpm build` untuk produksi)
pnpm run dev
```

---

## 🛰️ Contoh Integrasi API (Merchant)

### 1. Membuat Pembayaran (`POST /api/v1/payments`)

**Headers:**
```text
Authorization: Bearer pb_mcht_your_api_key_here
Idempotency-Key: order-2026-0001
Content-Type: application/json
Accept: application/json
```

**Payload Request:**
```json
{
  "merchant_ref_id": "INV-2026-0001",
  "payment_method_id": 1,
  "amount": 150000,
  "redirect_url": "https://tokomu.com/checkout/success"
}
```

**Response Success (`201 Created`):**
```json
{
  "success": true,
  "data": {
    "reference_id": "8a987e4e-3cd1-4777-b9ba-66537bef28cb",
    "merchant_ref_id": "INV-2026-0001",
    "payment_method": {
      "id": 1,
      "name": "QRIS (Pakasir)",
      "code": "qris",
      "type": "qris",
      "fee_type": "percent",
      "fee_fix": 0,
      "fee_percent": 0.7,
      "icon_url": null,
      "gateway_code": "pakasir",
      "is_active": true
    },
    "amount": 150000,
    "fee": 1050,
    "total_amount": 151050,
    "status": "PENDING",
    "pg_status": "PENDING",
    "checkout_url": "https://app.pakasir.com/pay/demo/151050?order_id=8a987e4e-3cd1-4777-b9ba-66537bef28cb",
    "qris_url": "http://localhost:8000/payments/qris/8a987e4e-3cd1-4777-b9ba-66537bef28cb.png",
    "payment_code": "0002010102122661...",
    "redirect_url": "https://tokomu.com/checkout/success",
    "pg_ref_id": "8a987e4e-3cd1-4777-b9ba-66537bef28cb",
    "paid_at": null,
    "expired_at": "2026-11-19T17:00:00+07:00",
    "created_at": "2026-11-18T17:00:00+07:00"
  }
}
```

> **Catatan Idempotency & QRIS:**
> * Mengirim ulang request dengan `Idempotency-Key` atau `merchant_ref_id` yang sama beserta `amount` & `payment_method_id` identik akan mengembalikan **`200 OK`** dengan `"idempotent": true` tanpa membuat transaksi ganda ke gateway.
> * Untuk format vektor SVG, cukup ganti ekstensi `qris_url` dari `.png` menjadi `.svg`.

---

## 🧪 Pengujian & Kualitas Kode

```bash
# Jalankan seluruh test Pest
php artisan test --compact

# Validasi OpenAPI Scramble (0 errors, 0 warnings)
php artisan scramble:analyze --fail-on-empty --fail-on-unknown

# Format kode PHP dengan Laravel Pint
./vendor/bin/pint --dirty
```

---

## 🛡️ Lisensi & Kontribusi
* 📄 **Lisensi:** Proyek ini dilisensikan di bawah lisensi MIT.
* ⛔ **PENTING:** Proyek ini **100% GRATIS** untuk digunakan. **Dilarang keras memperjualbelikan** software ini atau turunannya dalam bentuk komersial apa pun!
* 🤝 **Kontribusi:** Baca [CONTRIBUTING.md](CONTRIBUTING.md) sebelum membuka Pull Request (PR) atau Issue.
