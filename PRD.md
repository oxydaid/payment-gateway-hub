# Product Requirements Document (PRD) — Payment Gateway Hub

**Project Name:** API Payment Gateway Bridge (Internal Hub)
**Author/Maintainer:** Oxyda
**Tech Stack:** Laravel 13.x, Inertia.js (v2), Vue 3 (`<script setup>` Composition API), Tailwind CSS v4, MySQL/MariaDB, Redis (Cache & Queue)
**Deployment Target:** VPS / Docker / Coolify (with Supervisor/Horizon for persistent Queue Workers)

---

## 1. Tujuan & Batasan Sistem
Membangun **Payment Gateway Hub** terpusat sebagai *single integration point* bagi berbagai *website* internal (*merchants*). Sistem bertugas menyeragamkan kontrak API berbagai Payment Gateway (PG), menerima webhook terpusat, dan merutekan *callback* ke *website* asal secara aman, idempoten, dan tahan banting.

* **Boundary Arsitektur:**
  * **Admin Panel (`/admin/*`):** Menggunakan **Inertia.js + Vue 3** (SPA stateful berbasis session).
  * **Gateway & Webhook (`/api/v1/*`, `/webhooks/*`):** Murni **Stateless REST API** (JSON-only, tanpa Inertia/CSRF middleware).

---

## 2. Actor & Autentikasi
1. **Super Admin (Single User):** Mengakses *dashboard* Vue 3 untuk mengelola *merchant*, metode pembayaran, tarif biaya, log transaksi/webhook, dan pengaturan branding aplikasi.
2. **Merchant / Internal Website (API Client):** Terdaftar pada entitas `merchants` (memiliki `code`, `callback_url`, `webhook_secret`, `is_active`) dan diautentikasi menggunakan **Laravel Sanctum** (Bearer Token).

---

## 3. Fitur Utama & Kontrak Teknis

### A. Modular Gateway Driver (Strategy Pattern)
* Struktur service berada di `app/Services/Gateways/` dengan kontrak `PaymentGatewayInterface`:
  * `createTransaction(Transaction $tx, array $payload): GatewayChargeResponse`
  * `verifyAndParseWebhook(Request $request): NormalizedWebhookData`
  * `checkTransactionStatus(Transaction $tx): NormalizedWebhookData`
* Konfigurasi kredensial PG disimpan di database (terenkripsi), sedangkan logika integrasi berada di class driver.
* **Driver Inisial:** `MidtransDriver` (arsitektur siap ekspansi untuk `TokopayDriver`, `XenditDriver`, `TripayDriver`).

### B. Manajemen Metode Pembayaran & Fee Engine
* CRUD metode pembayaran (QRIS, VA, E-Wallet) yang terikat ke driver PG tertentu.
* Kalkulasi biaya admin dinamis: `fixed` (nominal tetap), `percent` (persentase), atau `mixed` (kombinasi keduanya), dengan opsi pembebanan biaya ke *merchant* atau *customer*.
* Aktivasi/deaktivasi metode pembayaran secara instan tanpa *re-deploy*.

### C. Unified Webhook Router & Outgoing Callback
* **Incoming (`/webhooks/{provider}`):** Memvalidasi *signature* asli dari PG, menormalisasi status ke enum standar (`PENDING`, `PAID`, `EXPIRED`, `FAILED`), lalu mengidentifikasi *merchant* pemilik transaksi.
* **Outgoing (`DispatchMerchantCallbackJob`):** Mengirim POST JSON ke `merchant.callback_url` melalui **Laravel Queue** dengan header keamanan `X-Hub-Signature` (HMAC-SHA256 dari payload menggunakan `merchant.webhook_secret`).
* **Retry Policy:** *Exponential backoff* maksimal **3x percobaan** (misal: jeda 10s, 60s, 300s) jika *merchant* merespons status non-2xx atau *timeout*.

### D. Audit Trail & Monitoring
* Pencatatan siklus lengkap transaksi beserta *snapshot* tarif saat transaksi dibuat.
* Pencatatan `webhook_logs` untuk *incoming* (dari PG) dan *outgoing* (ke *merchant*, termasuk HTTP status code & *response body*) guna mempermudah *debugging* dan *manual retry* dari dashboard.

---

## 4. Kontrak Payload Standar (Canonical API) `CONTOH`

### 1. Create Payment (`POST /api/v1/transactions`)
**Request (Merchant $\rightarrow$ Hub):**
```json
{
  "merchant_order_id": "INV-2026-0001",
  "payment_method_code": "QRIS",
  "amount": 50000,
  "customer": {
    "name": "Budi Santoso",
    "email": "budi@example.com",
    "phone": "08123456789"
  },
  "expired_minutes": 60,
  "metadata": {
    "source_app": "web-toko-a"
  }
}
```

**Response `201 Created` (Hub $\rightarrow$ Merchant):**
```json
{
  "success": true,
  "data": {
    "hub_reference": "HUB-9F8A7B6C5D",
    "merchant_order_id": "INV-2026-0001",
    "status": "PENDING",
    "amount": 50000,
    "fee": 350,
    "total_amount": 50350,
    "payment_url": "[https://app.midtrans.com/snap/v2/vtweb/](https://app.midtrans.com/snap/v2/vtweb/)...",
    "qr_string": null,
    "pay_code": null,
    "expired_at": "2026-11-18T17:00:00Z"
  }
}
```

### 2. Outgoing Callback (`POST {merchant.callback_url}`)
**Headers:** `X-Hub-Signature: sha256=<hmac_payload>`, `X-Hub-Event: transaction.status_updated`
```json
{
  "hub_reference": "HUB-9F8A7B6C5D",
  "merchant_order_id": "INV-2026-0001",
  "status": "PAID",
  "amount": 50000,
  "fee": 350,
  "total_amount": 50350,
  "paid_at": "2026-11-18T16:12:05Z",
  "metadata": {
    "source_app": "web-toko-a"
  }
}
```

---

## 5. Reliabilitas & Concurrency Control
1. **Idempotency Transaksi:** Kombinasi `(merchant_id, merchant_order_id)` bersifat *unique*. Jika *merchant* mengirim ulang *request* yang sama saat status masih `PENDING`, kembalikan data transaksi yang sudah ada tanpa membuat tagihan baru ke PG.
2. **Anti-Race Condition:** Pemrosesan webhook wajib dibungkus `Cache::lock("tx_lock:{$hubReference}", 10)` berbasis Redis dan `DB::transaction()` untuk mencegah eksekusi ganda saat PG mengirim webhook bersamaan.
3. **Automated Reconciliation (Scheduler):** Command terjadwal berjalan setiap 5–10 menit untuk mengecek transaksi `PENDING` yang mendekati atau melewati `expired_at` langsung ke API PG (mengatasi *silent failure* webhook).
4. **Isolasi Antrean:** Kegagalan *callback* satu *merchant* tidak memblokir antrean *merchant* lain karena diproses secara asinkron per *job*.

---

## 6. App Settings & Branding
Tabel `app_settings` digunakan murni untuk konfigurasi internal Admin Panel (tanpa injeksi SEO/analytics publik) yang dibagikan ke Vue melalui Inertia `HandleInertiaRequests`:
* **Branding:** `app_name`, `logo_path`, `favicon_path`.
* **Theme:** `primary_color`, `secondary_color` (diinjeksi sebagai CSS variables pada layout Inertia).

---

## 7. Alur Sistem (System Flow)
1. **Create Payment:** `Website A` $\rightarrow$ `POST /api/v1/transactions` (Bearer Token) $\rightarrow$ Hub validasi *merchant* & hitung *fee* $\rightarrow$ `GatewayDriver` panggil API PG $\rightarrow$ Simpan transaksi & kembalikan *response* standar.
2. **Incoming Webhook:** PG $\rightarrow$ `POST /webhooks/{provider}` $\rightarrow$ Validasi *signature* PG $\rightarrow$ Acquire Redis Lock $\rightarrow$ Update status di DB & catat `webhook_logs` $\rightarrow$ Dispatch `DispatchMerchantCallbackJob`.
3. **Outgoing Callback:** Queue Worker eksekusi *job* $\rightarrow$ Sign payload dengan `merchant.webhook_secret` $\rightarrow$ Kirim POST ke `merchant.callback_url` $\rightarrow$ Catat hasil log (retry otomatis hingga 3x jika gagal).