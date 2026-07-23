# Panduan Deployment Produksi: PG Bridge

Dokumen ini berisi panduan langkah demi langkah untuk melakukan deployment aplikasi **PG Bridge** ke berbagai infrastruktur produksi.

---

## 1. Deployment VPS (Native - Ubuntu/Debian)

Deployment native di VPS menggunakan kombinasi Nginx, PHP-FPM, MariaDB/MySQL, Redis, dan Supervisor.

### Prasyarat System
* PHP 8.4+ CLI & FPM dengan ekstensi: `pdo_mysql`, `mbstring`, `exif`, `pcntl`, `bcmath`, `xml`, `gd`, `zip`, `opcache`, `redis`.
* Nginx Web Server.
* MariaDB / MySQL Server.
* Redis Server.
* Supervisor.

### Langkah-langkah Deployment:
1. **Clone Repository & Set Permissions:**
   ```bash
   cd /var/www
   git clone https://github.com/your-username/pg-bridge.git
   cd pg-bridge
   chown -R www-data:www-data storage bootstrap/cache
   chmod -R 775 storage bootstrap/cache
   ```

2. **Instal Dependencies & Build Assets:**
   ```bash
   composer install --no-dev --optimize-autoloader
   pnpm install
   pnpm build
   ```

3. **Konfigurasi Environment (.env):**
   Salin `.env.example` ke `.env`, isi credentials database, redis, mode dispatch callback (`MERCHANT_CALLBACK_SYNC`), dan jalankan generator key:
   ```bash
   cp .env.example .env
   php artisan key:generate
   php artisan migrate --force --seed
   ```

4. **Konfigurasi Nginx Server Block:**
   Salin konfigurasi dari berkas [nginx.conf](file:///d:/LARAVEL/pg-bridge/deploy/vps/nginx.conf) ke `/etc/nginx/sites-available/bridge.conf`, lalu aktifkan dan reload:
   ```bash
   ln -s /etc/nginx/sites-available/bridge.conf /etc/nginx/sites-enabled/
   nginx -t
   systemctl reload nginx
   ```
   *Gunakan Certbot untuk SSL:*
   ```bash
   certbot --nginx -d bridge.yourdomain.com
   ```

5. **Konfigurasi Supervisor untuk Queue Workers:**
   Salin berkas [supervisor.conf](file:///d:/LARAVEL/pg-bridge/deploy/vps/supervisor.conf) ke `/etc/supervisor/conf.d/pg-bridge.conf`, lalu reload:
   ```bash
   supervisorctl reread
   supervisorctl update
   supervisorctl start all
   ```

6. **Konfigurasi Cron Task Scheduler:**
   Buka crontab sistem:
   ```bash
   crontab -e
   ```
   Tambahkan baris berikut di akhir file untuk memicu scheduler Laravel:
   ```text
   * * * * * cd /var/www/pg-bridge && php artisan schedule:run >> /dev/null 2>&1
   ```

---

## 2. Deployment Docker (Docker Compose)

Menggunakan Docker Compose adalah cara tercepat untuk mereplikasi environment produksi secara terisolasi.

### Langkah-langkah:
1. **Persiapkan Berkas Environment:**
   Pastikan berkas `.env` Anda sudah terisi dengan benar di root direktori.
2. **Jalankan Orchestrator:**
   ```bash
   docker compose up -d --build
   ```
3. **Jalankan Migrasi & Database Seeding (Pertama Kali):**
   ```bash
   docker compose exec app php artisan migrate --force --seed
   ```

### Arsitektur Container:
* `app`: PHP-FPM Service (Port 9000).
* `web`: Nginx Web Server (Port 80/443).
* `db`: MariaDB Database Server (Port 3306).
* `redis`: Cache & Queue Driver (Port 6379).
* `queue-worker`: Driver worker independen yang melakukan polling antrean pembayaran.
* `scheduler`: Pemicu schedule task Laravel otomatis setiap 60 detik.

---

## 3. Deployment Coolify & Nixpacks

Coolify menggunakan **Nixpacks** sebagai builder bawaan untuk menyusun container dari git repository secara otomatis.

### Langkah-langkah di Coolify Dashboard:
1. Buat **New Resource** -> **Git Repository**.
2. Pilih repository `pg-bridge` dan branch yang sesuai.
3. Coolify akan mendeteksi proyek secara otomatis menggunakan berkas [nixpacks.toml](file:///d:/LARAVEL/pg-bridge/nixpacks.toml) yang telah disediakan.
4. **Environment Variables:** Tambahkan seluruh variabel dari berkas `.env` ke kolom Environment Variables di panel Coolify.
5. **Queue Workers & Scheduler:**
   * **Queue:** Di panel Coolify, tambahkan **Service** tambahan atau tambahkan command worker pada tab *Processes*:
     ```bash
     php artisan queue:work --verbose --tries=3
     ```
   * **Scheduler:** Tambahkan cron job di Coolify settings atau jalankan scheduler background daemon:
     ```bash
     sh -c "while true; do php artisan schedule:run --no-interaction & sleep 60; done"
     ```

---

## 4. Deployment cPanel Shared Hosting (Tradisional)

Untuk hosting cPanel tradisional tanpa akses root/SSH penuh, ikuti struktur direktori aman berikut.

### Struktur Direktori yang Direkomendasikan (Sangat Aman):
Pisahkan berkas core Laravel dari folder publik agar berkas sensitif seperti `.env` tidak dapat diakses secara publik.

```text
/home/username/
  ├── pg-bridge/            <-- Taruh seluruh berkas proyek di sini (kecuali folder public)
  │     ├── app/
  │     ├── config/
  │     ├── bootstrap/
  │     ├── .env
  │     └── ...
  └── public_html/          <-- Folder publik bawaan cPanel (Isi dengan isi folder /public Laravel)
        ├── index.php
        ├── build/
        ├── images/
        └── .htaccess
```

### Langkah-langkah Deployment:
1. Upload folder `public` Laravel langsung ke dalam direktori `public_html`.
2. Buat folder baru bernama `pg-bridge` sejajar dengan `public_html` (di dalam `/home/username/`), lalu upload seluruh sisa berkas proyek ke sana.
3. Edit berkas `public_html/index.php` untuk menyesuaikan path path bootstrap dan autoload:
   ```php
   // Baris 14: Ubah path vendor/autoload.php
   require __DIR__.'/../pg-bridge/vendor/autoload.php';

   // Baris 24: Ubah path bootstrap/app.php
   $app = require_once __DIR__.'/../pg-bridge/bootstrap/app.php';
   ```
4. Gunakan berkas [htaccess](file:///d:/LARAVEL/pg-bridge/deploy/cpanel/htaccess) pada `public_html/.htaccess` untuk mengaktifkan URL rewriting yang aman.
5. **Cron Job:** Masuk ke menu **Cron Jobs** di cPanel Anda, isi waktu interval ke `Every Minute (* * * * *)`, dan jalankan command scheduler sesuai panduan [cron_job.txt](file:///d:/LARAVEL/pg-bridge/deploy/cpanel/cron_job.txt).

---

## 5. Deployment Cloud Hosting (Laravel Cloud & Laravel Forge)

Proyek ini sepenuhnya kompatibel dengan layanan deployment cloud kelas industri seperti **Laravel Cloud** dan **Laravel Forge**.

* **Laravel Cloud:** Layanan deployment serverless tercepat dari pencipta Laravel. Cukup sambungkan akun GitHub Anda ke [Laravel Cloud](https://cloud.laravel.com/), lalu sistem akan mendeteksi aplikasi Laravel 13 Anda secara otomatis dan melakukan build optimal secara serverless.
* **Laravel Forge:** Ideal jika Anda ingin mengelola server cloud sendiri (AWS, DigitalOcean, Linode) dengan provisioning otomatis. Forge akan menangani setup Nginx, SSL Let's Encrypt, daemon Supervisor, dan cron scheduler secara instan via dashboard-nya.
