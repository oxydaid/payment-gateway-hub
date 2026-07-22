# Payment Bridge 🛡️💳

**Payment Bridge** adalah platform router/aggregator pembayaran terpadu berbasis **Laravel 13**, **Inertia.js v3 (Vue 3)**, dan **Tailwind CSS v4**. Sistem ini bertindak sebagai jembatan tunggal (bridge) yang menghubungkan aplikasi/merchant Anda dengan berbagai penyedia payment gateway melalui satu integrasi API terpadu.

---

## ✨ Fitur Utama
* 🌓 **Spotify-Inspired Dashboard**: Desain UI premium, adaptif terhadap mode Terang (Light) dan Gelap (Dark), serta warna primer dinamis dari konfigurasi admin.
* 🧩 **Multi-Driver Architecture**: Terintegrasi langsung dengan **Midtrans**, **Tripay**, dan **Tokopay**.
* ⚙️ **Dynamic Schema Form Creator**: Tambah payment gateway baru langsung dari admin panel dengan form input kredensial yang otomatis menyesuaikan jenis driver terpilih.
* 🔑 **API Key Management**: Token otentikasi Bearer bertingkat: *Global API Key* (akses seluruh merchant) dan *Merchant API Key* (akses terbatas merchant terkait).
* 📁 **Asset Icon Management**: Unggah ikon custom secara dinamis untuk masing-masing payment gateway maupun saluran channel metode pembayaran.
* 📊 **Interactive Insights**: Grafik tren volume transaksi 30 hari dengan pelacakan titik koordinat reaktif terhadap mouse.
* 🔔 **Webhook Logs Auditing**: Riwayat percobaan webhook callback lengkap dengan status HTTP response dari server merchant Anda.

---

## 🛠️ Persyaratan Sistem
* PHP `8.2` atau lebih baru
* Composer
* Node.js & PNPM / NPM
* Database: MariaDB / MySQL / PostgreSQL

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
Salin file `.env.example` menjadi `.env`, lalu konfigurasikan koneksi database Anda:
```bash
cp .env.example .env
php artisan key:generate
```

### 3. Jalankan Migrasi & Database Seeder
```bash
php artisan migrate:fresh --seed
```
*Perintah di atas akan membuat semua tabel dan mengisi akun administrator default:*
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

## 🛡️ Lisensi & Kontribusi
* 📄 **Lisensi**: Proyek ini dilisensikan di bawah lisensi MIT dengan batasan komersial.
* ⛔ **PENTING**: Proyek ini **100% GRATIS** untuk digunakan. **Dilarang keras memperjualbelikan** software ini atau turunannya dalam bentuk komersial apa pun!
* 🤝 **Kontribusi**: Baca berkas [CONTRIBUTING.md](CONTRIBUTING.md) untuk pedoman kontribusi Anda.


