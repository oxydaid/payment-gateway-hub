# Payment Bridge 🛡️💳

[![Laravel Version](https://img.shields.io/badge/laravel-v13-red.svg)](https://laravel.com)
[![PHP Version](https://img.shields.io/badge/php-8.4-blue.svg)](https://php.net)
[![Inertia.js](https://img.shields.io/badge/inertia-v3-purple.svg)](https://inertiajs.com)
[![TailwindCSS](https://img.shields.io/badge/tailwind-v4-blueviolet.svg)](https://tailwindcss.com)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](#)

**Payment Bridge** adalah platform router/aggregator pembayaran terpadu berbasis **Laravel 13**, **Inertia.js v3 (Vue 3)**, dan **Tailwind CSS v4**. Sistem ini bertindak sebagai jembatan tunggal (bridge) yang menghubungkan aplikasi/merchant Anda dengan berbagai penyedia payment gateway melalui satu integrasi API terpadu.

---

## ✨ Fitur Utama

### 🌓 Spotify-Inspired Admin Dashboard
* Desain UI premium, adaptif terhadap mode Terang (Light) dan Gelap (Dark).
* Kustomisasi logo, favicon, nama aplikasi, dan warna primer/sekunder yang langsung disinkronkan ke css variable di frontend secara real-time.

### 🔌 Multi-Driver Payment Gateway Support
Terintegrasi langsung dengan berbagai Payment Gateway terkemuka di Indonesia:
1. **Midtrans** 
2. **Xendit** 
3. **Tripay**  *(Catatan: Dihapus dari basis kode karena Tripay secara native telah mendukung multi-merchant sehingga tidak memerlukan router/bridge tambahan).*
4. **Tokopay** (Sandbox & Production)
5. **Pakasir** 

### 💎 Saluran Pembayaran & Auto-Icon Cerdas
* **Dropdown Dinamis:** Pilihan metode pembayaran pada admin panel otomatis mengambil data asli yang didukung oleh masing-masing driver/gateway, mencegah typo.
* **Auto-Icon Mapping:** Ikon metode pembayaran otomatis ter-resolve berdasarkan kecocokan nama file di `public/images/payment-method/`.

### 🛍️ Premium Public Checkout Page
* Menyediakan halaman instruksi pembayaran siap pakai untuk pembeli.
* **QRIS Cache:** Mendownload QRIS string dari gateway, menyimpannya di server bridge sebagai gambar lokal, dan menyajikannya kepada pembeli.
* **VA Copy:** Fitur salin nomor Virtual Account sekali klik.
* **Real-time Polling:** Sistem otomatis mem-polling status pembayaran setiap 3 detik ketika berstatus `PENDING`.
* **Auto-Redirect:** Menampilkan hitung mundur 5 detik dan otomatis mengalihkan pembeli ke `redirect_url` merchant begitu transaksi berstatus `PAID` / `DONE`.

### 🔑 Token & API Key Bertingkat
* **Global API Key:** Mengizinkan integrasi semua merchant dalam satu token master (cocok untuk platform SaaS).
* **Merchant API Key:** Mengunci token hanya untuk akses transaksi milik merchant yang bersangkutan.

### 🔄 Dynamic Callback Workers (Sync / Async Queue)
* Dapat dikonfigurasi melalui `.env` (`MERCHANT_CALLBACK_SYNC=true/false`).
* Mendukung pengiriman callback instan (synchronous dispatch) maupun antrean background queue (database queue) untuk memastikan callback sukses tersampaikan ke merchant dengan logging response detail.

---

## 📂 Struktur Direktori Konfigurasi Deployment

Kami menyediakan berkas konfigurasi siap pakai di dalam folder `deploy/` & root untuk mempercepat proses deployment:
* **Docker:** [Dockerfile](Dockerfile) (multi-stage build pnpm + php-fpm) & [docker-compose.yml](docker-compose.yml) (app, web, db, redis, queue-worker, scheduler).
* **Coolify / Nixpacks:** [nixpacks.toml](nixpacks.toml) untuk deteksi container builder otomatis.
* **VPS Native:** [deploy/vps/nginx.conf](deploy/vps/nginx.conf) (Server Block SSL) & [deploy/vps/supervisor.conf](deploy/vps/supervisor.conf) (Supervisor queue worker daemon).
* **cPanel:** [deploy/cpanel/htaccess](deploy/cpanel/htaccess) (URL rewrites) & [deploy/cpanel/cron_job.txt](deploy/cpanel/cron_job.txt) (Laravel scheduler cron).

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
Salin file `.env.example` menjadi `.env`, lalu lakukan konfigurasi database dan queue driver:
```bash
cp .env.example .env
php artisan key:generate
```

### 3. Jalankan Migrasi & Database Seeder
```bash
php artisan migrate --force --seed
```
*Akun administrator default:*
* **Email**: `admin@example.com`
* **Password**: `password`

### 4. Jalankan Aplikasi Secara Lokal
Jalankan server backend Laravel:
```bash
php artisan serve
```
Dan jalankan Vite dev server di terminal terpisah:
```bash
pnpm run dev
```

---

## 🛰️ Contoh Integrasi API (Merchant)

### 1. Membuat Pembayaran (POST `/api/v1/payments`)
**Headers:**
```text
Authorization: Bearer pb_mcht_your_api_key_here
Content-Type: application/json
Accept: application/json
```

**Payload Request:**
```json
{
  "merchant_ref_id": "INV-2026-0001",
  "payment_method_id": 5,
  "amount": 150000,
  "redirect_url": "https://tokomu.com/checkout/success"
}
```

**Response Success (201):**
```json
{
  "success": true,
  "message": "Payment created successfully.",
  "data": {
    "reference_id": "tx_202607231345_abcde",
    "merchant_ref_id": "INV-2026-0001",
    "amount": 150000,
    "fee": 4500,
    "total_amount": 154500,
    "status": "PENDING",
    "checkout_url": "http://localhost:8000/payments/checkout/tx_202607231345_abcde",
    "redirect_url": "https://tokomu.com/checkout/success"
  }
}
```

---

## 🛡️ Lisensi & Kontribusi
* 📄 **Lisensi**: Proyek ini dilisensikan di bawah lisensi MIT.
* ⛔ **PENTING**: Proyek ini **100% GRATIS** untuk digunakan. **Dilarang keras memperjualbelikan** software ini atau turunannya dalam bentuk komersial apa pun!
* 🤝 **Kontribusi**: Silakan buka Pull Request (PR) atau Issue jika menemukan bug atau ingin menambahkan driver gateway baru.
