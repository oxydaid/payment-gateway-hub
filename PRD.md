# 📄 Product Requirements Document (PRD)

**Project Name:** API Payment Gateway Bridge (Internal)

**Author/Maintainer:** Oxyda

**Tech Stack:** Laravel 11.x, Livewire v4, Tailwind CSS v4, MySQL/MariaDB, Redis (Cache & Queue)

**Deployment Target:** VPS / Docker Environment / coolify / cPanel (shared / cloud)

## 1. Tujuan Aplikasi

Membangun sebuah *REST API Payment Gateway Bridge* yang bertindak sebagai *single integration point* untuk berbagai *website* internal. Sistem ini akan menyeragamkan kontrak API dari berbagai penyedia (Payment Gateway) menjadi satu format standar, menangani webhooks secara terpusat, dan mendistribusikan *callback* ke *website* asal (merchant) dengan jaminan reliabilitas tinggi (toleransi kesalahan, anti-*race condition*, dan rekonsiliasi otomatis).

## 2. Actor & Kredensial

* **Super Admin (Single User):** Memiliki akses penuh ke *dashboard* berbasis Livewire v4 untuk memantau transaksi, mengatur metode pembayaran, melihat log *webhook*, dan mengelola *App Settings*.
* **Merchant / Internal Websites (API Client):** Aplikasi internal yang menggunakan API terpusat ini. Diotentikasi menggunakan **Laravel Sanctum** (Bearer Token).

## 3. Fitur Utama

1. **API Endpoint Terpusat (REST API):**
* Menggunakan *Form Request* untuk validasi dan *API Resources* untuk standardisasi *response*.
* Dokumentasi otomatis dan interaktif menggunakan **Scramble**.


2. **Modular Gateway Driver (Service Layer):**
* Menggunakan *Strategy Pattern*.
* Driver *hardcode* di sisi *Service*, namun dinamis dari sisi *Database* (Dashboard).
* Driver inisial: **Midtrans**. (Struktur disiapkan untuk ekspansi ke Tokopay, Xendit, Stripe, dll).


3. **Manajemen Metode Pembayaran (CRUD):**
* Pengaturan tarif yang fleksibel: *Fix*, *Percent*, atau *Mix* (kombinasi keduanya).
* Aktivasi/Deaktivasi *payment method* secara instan tanpa re-*deploy*.


4. **Unified Webhook & Callback System:**
* Menerima webhook dari PG, memvalidasi *signature*, dan menyamakan *payload*.
* Meneruskan status ke *website* internal menggunakan **Laravel Queue**.
* Dilengkapi *Exponential Backoff Retry* (mencoba ulang 3x jika *website* internal *down* atau *timeout*).


5. **Manajemen Transaksi & Log:**
* Pencatatan seluruh siklus transaksi (Inquiry, Pending, Paid, Expired, Failed).
* Pencatatan *Webhook Logs* (Incoming dari PG & Outgoing ke Merchant) untuk audit trail.



## 4. Fitur Pendukung & Reliability (Reliabilitas Sistem)

1. **Penanganan Race Condition & Double Webhook:**
* Menggunakan *Atomic Locks* (`Cache::lock`) berbasis Redis menggunakan kombinasi ID Transaksi dan Status untuk mencegah eksekusi ganda jika PG mengirimkan *callback* beruntun dalam milidetik yang sama.
* Menggunakan *Database Transactions* (`DB::transaction`) untuk memastikan operasi berjalan secara ACID.


2. **Automated Status Reconciliation (Cron):**
* *Command scheduler* yang berjalan otomatis mengecek transaksi `PENDING` yang sudah mendekati masa kedaluwarsa.
* Melakukan sinkronisasi langsung ke API Payment Gateway untuk mengatasi *silent failure* (jika jaringan gagal saat PG mengirim webhook).


3. **Fault Tolerance & Isolasi:**
* Kegagalan pengiriman *callback* ke satu *merchant* tidak akan memblokir antrean *callback* untuk *merchant* lain karena diproses secara terisolasi di *background job*.



## 5. App Settings & Branding

Sesuai aturan *workflow*, tabel `app_settings` tetap ada untuk konfigurasi global, namun dioptimasi murni untuk kebutuhan aplikasi internal (tanpa injeksi SEO publik/GA4/OpenGraph ke halaman depan):

* **Branding:** Nama Aplikasi, Logo, Favicon.
* **Theme:** Primary Color, Secondary Color (dinamis untuk UI Admin Panel).

## 6. Alur Sistem (System Flow)

1. **Create Payment:**
`Website A` mengirim request ke `Bridge API` -> `Bridge API` menggunakan *Service Driver* memformat request ke `Midtrans` -> `Midtrans` membalas dengan URL Pembayaran -> `Bridge API` mencatat *Transaction* dan mengembalikan respons ke `Website A`.
2. **Payment Success (Webhook):**
User membayar -> `Midtrans` mengirim Webhook ke `Bridge API` -> `Bridge API` melakukan Lock, validasi, dan Update status Transaksi -> `Bridge API` menyimpan `Webhook Log`.
3. **Callback to Merchant:**
Setelah update sukses, `Bridge API` memasukkan tugas ke *Queue* -> *Worker* mengeksekusi pengiriman *Callback* ke URL milik `Website A` -> Jika gagal, *Worker* mencoba ulang hingga batas maksimal (3x).

---

Sistem ini dirancang untuk sangat tahan banting (*resilient*) sebagai *middleware* finansial internal. Jika spesifikasi di dalam PRD ini sudah sesuai dengan visi Anda, kita bisa langsung melangkah ke **Tahap 2: Perancangan Database, Skema Migration, dan Model Relasional**.