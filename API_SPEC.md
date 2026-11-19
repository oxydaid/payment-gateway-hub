# Payment Gateway Hub — API & Webhook Integration Specification
> **Dokumen Panduan & Kontrak Integrasi untuk AI Agent & Vibe Coding**
> 
> *Instruksi Khusus untuk AI:* Saat Anda diinstruksikan untuk mengintegrasikan pembayaran dengan Payment Gateway Hub, ikuti dokumen ini sebagai sumber kebenaran tunggal (*single source of truth*). Gunakan endpoint, header, tipe data, cara penanganan response (QRIS, VA, Redirect), serta mekanisme verifikasi webhook HMAC-SHA256 sesuai kontrak yang tertera di bawah ini.

---

## Daftar Isi
1. [Overview & Konvensi Integrasi](#1-overview--konvensi-integrasi)
2. [Autentikasi & Header Standar](#2-autentikasi--header-standar)
3. [Protokol Idempotensi (Anti-Double Charge)](#3-protokol-idempotensi-anti-double-charge)
4. [Tiga Variasi Alur Pembayaran](#4-tiga-variasi-alur-pembayaran-qris-va-redirect)
   - [4.1. Tipe A: QRIS (Dynamic QR Image & EMVCo String)](#41-tipe-a-qris-dynamic-qr-image--emvco-string)
   - [4.2. Tipe B: Virtual Account (Bank Transfer)](#42-tipe-b-virtual-account-bank-transfer)
   - [4.3. Tipe C: Redirect Checkout / E-Wallet / Snap](#43-tipe-c-redirect-checkout--e-wallet--snap)
5. [Referensi Endpoint API](#5-referensi-endpoint-api)
   - [5.1. GET /api/v1/payment-methods](#51-get-apiv1payment-methods)
   - [5.2. POST /api/v1/payments (Create Payment)](#52-post-apiv1payments-create-payment)
   - [5.3. GET /api/v1/transactions/{reference_id}](#53-get-apiv1transactionsreference_id)
   - [5.4. GET /api/v1/webhook-logs](#54-get-apiv1webhook-logs)
6. [Matriks Error & HTTP Status Codes](#6-matriks-error--http-status-codes)
7. [Spesifikasi Webhook Callback (Hub ke Merchant)](#7-spesifikasi-webhook-callback-hub-ke-merchant)
   - [7.1. Header & Payload Webhook](#71-header--payload-webhook)
   - [7.2. Kewajiban Respon Merchant](#72-kewajiban-respon-merchant)
   - [7.3. Kebijakan Retry & Backoff](#73-kebijakan-retry--backoff)
8. [Code Snippet Verifikasi Webhook Multi-Bahasa](#8-code-snippet-verifikasi-webhook-multi-bahasa)
   - [PHP / Laravel](#php--laravel)
   - [Node.js / Express / Next.js](#nodejs--express--nextjs)
   - [Python / FastAPI / Django](#python--fastapi--django)
   - [Go (Golang)](#go-golang)
9. [Prompting Cheat Sheet untuk Vibe Coding](#9-prompting-cheat-sheet-untuk-vibe-coding)

---

## 1. Overview & Konvensi Integrasi

Payment Gateway Hub bertindak sebagai bridge cerdas dan multi-tenant yang menghubungkan berbagai upstream payment gateway (seperti Tokopay, Midtrans, Xendit, Pakasir, Ariepulsa) ke downstream website/aplikasi klien melalui satu API terpadu.

### Base URL
- **Production:** `https://your-hub-domain.com/api/v1`
- **Development / Localhost:** `http://localhost:8000/api/v1`

### Standar Response Envelope
Setiap respon API dibungkus dalam format JSON standar:

**Respon Sukses:**
```json
{
  "success": true,
  "data": { ... }
}
```

**Respon Error:**
```json
{
  "success": false,
  "message": "Human-readable error explanation.",
  "errors": {
    "field_name": [
      "Validation error message."
    ]
  }
}
```

---

## 2. Autentikasi & Header Standar

Semua pemanggilan API merchant (kecuali webhook publik dari upstream gateway) wajib menyertakan token API Key pada header HTTP.

### Format Header
```http
Authorization: Bearer <API_KEY>
Content-Type: application/json
Accept: application/json
```

### Tipe Kunci API (API Key)
1. **Merchant-Scoped Key (`pb_mcht_*`):**
   - Kunci yang terikat langsung ke satu merchant tertentu.
   - Parameter `merchant_id` **tidak perlu** dikirim di body request.
2. **Global / Admin Key (`pb_live_*` atau `pb_test_*`):**
   - Kunci global tingkat platform.
   - Wajib menyertakan parameter `merchant_id` di dalam body JSON atau melalui header `X-Merchant-ID: <id>`.

### Rate Limiting
- **Merchant API Endpoints:** Maksimum **60 request / menit** per IP/token (`throttle:60,1`).
- **Webhooks & Dynamic QRIS Endpoints:** Maksimum **120 request / menit** (`throttle:120,1`).
- Jika limit terlampaui, server mengembalikan status HTTP `429 Too Many Requests`.

---

## 3. Protokol Idempotensi (Anti-Double Charge)

Untuk mencegah transaksi terbuat dua kali saat terjadi *network timeout*, *double-click*, atau retry otomatis dari klien, Hub menyediakan dua lapis perlindungan idempotensi:

1. **Header `Idempotency-Key` (Sangat Disarankan):**
   - Kirim header `Idempotency-Key: <unique-uuid-or-id>` (string acak, UUID v4, atau Order ID merchant, max 128 karakter) pada request `POST /api/v1/payments`.
2. **Kombinasi Unik `(merchant_id, merchant_ref_id)`:**
   - Di tingkat database, satu merchant tidak diperbolehkan memiliki dua transaksi dengan `merchant_ref_id` yang sama.

### Aturan Perilaku Idempotensi:
- **Idempotent Replay (Aman / Sukses):**
  Jika request dikirim ulang dengan `Idempotency-Key` atau `merchant_ref_id` yang sama persis serta nilai `amount` dan `payment_method_id` yang identik:
  - Hub **tidak akan** membuat tagihan baru ke upstream gateway.
  - Hub mengembalikan transaksi yang sudah ada dengan status HTTP `200 OK` (bukan `201`), disertai flag `"idempotent": true`.
- **Conflict Rejection (Mencegah Manipulasi):**
  Jika `Idempotency-Key` atau `merchant_ref_id` yang sama dikirimkan kembali namun nilai `amount` atau `payment_method_id` **berbeda**, Hub menolak request dengan status HTTP `409 Conflict`.

---

## 4. Tiga Variasi Alur Pembayaran (QRIS, VA, Redirect)

Saat memanggil `POST /api/v1/payments`, Hub akan mengembalikan objek transaksi. Di sisi downstream (frontend/backend merchant), periksa field respon berikut untuk menampilkan metode pembayaran ke pengguna:

```mermaid
flowchart TD
    CreatePayment["POST /api/v1/payments"] --> CheckType{"payment_method.type / fields"}
    CheckType -->|"type == 'qris'"| FlowQRIS["Tampilkan QR Image via qris_url<br/>atau render payment_code (EMVCo)"]
    CheckType -->|"type == 'va'"| FlowVA["Tampilkan payment_code sebagai Nomor VA<br/>beserta petunjuk transfer"]
    CheckType -->|"checkout_url != null"| FlowRedirect["Redirect browser ke checkout_url<br/>(Midtrans Snap, Tokopay, atau Hosted Checkout)"]
```

### 4.1. Tipe A: QRIS (Dynamic QR Image & EMVCo String)
Berlaku jika `payment_method.type == "qris"` atau terdapat nilai `qris_url`.

- **Field Utama:**
  - `qris_url` (string URL): URL gambar QR dinamis resolusi tinggi yang di-generate langsung oleh server Hub, contoh: `https://your-hub.com/payments/qris/{reference_id}.png` (tersedia juga format `.svg`).
  - `payment_code` (string): Raw EMVCo QRIS payload string standar Indonesia (dimulai dengan `00020101...`).
- **Implementasi Frontend yang Direkomendasikan:**
  - **Pilihan 1 (Paling Mudah):** Pasang `data.qris_url` langsung ke tag `<img src={data.qris_url} alt="Scan QRIS" />`. Tidak membutuhkan library QR scanner di frontend!
  - **Pilihan 2 (Client-side Rendering):** Gunakan nilai `data.payment_code` dengan library QR seperti `qrcode.react` (React) atau `qrcode-vue` (Vue) untuk merender canvas lokal.

### 4.2. Tipe B: Virtual Account (Bank Transfer)
Berlaku jika `payment_method.type == "va"` (misal: BCA, BNI, BRI, Mandiri, Permata).

- **Field Utama:**
  - `payment_code` (string): Nomor Virtual Account yang dituju (contoh: `88081234567890`).
  - `total_amount` (number): Nominal tepat yang harus ditransfer (sudah termasuk fee jika dibebankan).
  - `expired_at` (ISO 8601 string): Batas waktu pembayaran.
- **Implementasi Frontend yang Direkomendasikan:**
  - Tampilkan nama bank (`payment_method.name`), nomor rekening VA (`payment_code`), dan total tagihan (`total_amount`).
  - Sediakan tombol **"Salin Nomor VA"** (`navigator.clipboard.writeText(payment_code)`).
  - Tampilkan instruksi transfer ATM, Mobile Banking, dan Internet Banking sesuai bank yang dipilih.

### 4.3. Tipe C: Redirect Checkout / E-Wallet / Snap
Berlaku jika `checkout_url` terisi (misal: Midtrans Snap, Tokopay E-Wallet / Retail, Pakasir link).

- **Field Utama:**
  - `checkout_url` (string URL): URL halaman pembayaran upstream resmi atau halaman hosted checkout milik Hub (`/payments/checkout/{reference_id}`).
- **Implementasi Frontend yang Direkomendasikan:**
  - Redirect pengguna langsung melalui `window.location.href = data.checkout_url;`.
  - Atau untuk pop-up/modal (seperti Midtrans Snap JS), gunakan token `pg_ref_id` dengan Snap SDK.

---

## 5. Referensi Endpoint API

### 5.1. GET `/api/v1/payment-methods`
Mengambil daftar metode pembayaran yang sedang aktif dan dapat digunakan untuk transaksi.

#### Request
```http
GET /api/v1/payment-methods HTTP/1.1
Host: your-hub-domain.com
Authorization: Bearer pb_mcht_abcdef123456...
Accept: application/json
```

#### Response (200 OK)
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "QRIS",
      "code": "QRIS",
      "type": "qris",
      "fee_type": "percent",
      "fee_fix": 0,
      "fee_percent": 0.7,
      "icon_url": "https://your-hub-domain.com/storage/icons/qris.svg",
      "gateway_code": "tokopay",
      "is_active": true
    },
    {
      "id": 2,
      "name": "BCA Virtual Account",
      "code": "BCAVA",
      "type": "va",
      "fee_type": "fix",
      "fee_fix": 4000,
      "fee_percent": 0,
      "icon_url": "https://your-hub-domain.com/storage/icons/bca.svg",
      "gateway_code": "tokopay",
      "is_active": true
    },
    {
      "id": 3,
      "name": "Midtrans Snap (All-in-One)",
      "code": "midtrans_snap",
      "type": "ewallet",
      "fee_type": "mix",
      "fee_fix": 2000,
      "fee_percent": 1.5,
      "icon_url": "https://your-hub-domain.com/storage/icons/midtrans.svg",
      "gateway_code": "midtrans",
      "is_active": true
    }
  ]
}
```

---

### 5.2. POST `/api/v1/payments` (Create Payment)
Membuat invoice pembayaran baru dan memicu pembuatan kode pembayaran/URL ke upstream payment gateway.

#### Request Headers
```http
POST /api/v1/payments HTTP/1.1
Host: your-hub-domain.com
Authorization: Bearer pb_mcht_abcdef123456...
Idempotency-Key: 8f448c97-6a17-4835-a6a3-2c4f09d8412e
Content-Type: application/json
Accept: application/json
```

#### Request Body Parameters
| Parameter | Tipe | Wajib? | Deskripsi |
| :--- | :--- | :--- | :--- |
| `merchant_ref_id` | `string` | **Ya** | Order ID / Invoice ID unik dari sistem merchant (max 255 karakter). |
| `payment_method_id` | `integer` | **Ya** | ID metode pembayaran aktif (diambil dari `GET /api/v1/payment-methods`). |
| `amount` | `numeric` | **Ya** | Nominal dasar transaksi dalam Rupiah (minimum: `100`). |
| `redirect_url` | `string (url)` | **Ya** | URL halaman redirect di website merchant setelah pembayaran selesai (max 500 karakter). |
| `merchant_id` | `integer` | *Kondisional* | Wajib hanya jika menggunakan Global API Key (`pb_live_*`). Tidak diperlukan jika menggunakan Merchant Token (`pb_mcht_*`). |

#### Contoh Payload Request
```json
{
  "merchant_ref_id": "INV-20261119-0012",
  "payment_method_id": 1,
  "amount": 50000,
  "redirect_url": "https://my-store.com/checkout/finish?order=INV-20261119-0012"
}
```

---

#### Respon Varian 1: Pembayaran QRIS (201 Created)
```json
{
  "success": true,
  "data": {
    "reference_id": "9d901865-c967-4d7a-b5e0-91a5db4b3211",
    "merchant_ref_id": "INV-20261119-0012",
    "payment_method": {
      "id": 1,
      "name": "QRIS",
      "code": "QRIS",
      "type": "qris",
      "fee_type": "percent",
      "fee_fix": 0,
      "fee_percent": 0.7,
      "icon_url": "https://your-hub-domain.com/storage/icons/qris.svg",
      "gateway_code": "tokopay",
      "is_active": true
    },
    "amount": 50000,
    "fee": 350,
    "total_amount": 50350,
    "status": "PENDING",
    "pg_status": "PENDING",
    "checkout_url": "https://api.tokopay.id/pay/9d901865",
    "qris_url": "https://your-hub-domain.com/payments/qris/9d901865-c967-4d7a-b5e0-91a5db4b3211.png",
    "payment_code": "00020101021226670016ID.CO.TOKOPAY5145011593600520000000021400000000000000005204581253033605405503505802ID5914PAYMENT HUB6007JAKARTA61051234062070703A01630489AB",
    "redirect_url": "https://my-store.com/checkout/finish?order=INV-20261119-0012",
    "pg_ref_id": "TRX-TOKOPAY-887711",
    "paid_at": null,
    "expired_at": "2026-11-20T16:00:00Z",
    "created_at": "2026-11-19T16:00:00Z"
  }
}
```

---

#### Respon Varian 2: Pembayaran Virtual Account (201 Created)
```json
{
  "success": true,
  "data": {
    "reference_id": "9d902001-f11a-4299-8ab1-11a5db4b9988",
    "merchant_ref_id": "INV-20261119-0013",
    "payment_method": {
      "id": 2,
      "name": "BCA Virtual Account",
      "code": "BCAVA",
      "type": "va",
      "fee_type": "fix",
      "fee_fix": 4000,
      "fee_percent": 0,
      "icon_url": "https://your-hub-domain.com/storage/icons/bca.svg",
      "gateway_code": "tokopay",
      "is_active": true
    },
    "amount": 100000,
    "fee": 4000,
    "total_amount": 104000,
    "status": "PENDING",
    "pg_status": "PENDING",
    "checkout_url": null,
    "qris_url": null,
    "payment_code": "88081234567890",
    "redirect_url": "https://my-store.com/checkout/finish?order=INV-20261119-0013",
    "pg_ref_id": "TRX-VA-443322",
    "paid_at": null,
    "expired_at": "2026-11-20T16:00:00Z",
    "created_at": "2026-11-19T16:00:00Z"
  }
}
```

---

#### Respon Varian 3: Pembayaran Redirect / Checkout Link (201 Created)
```json
{
  "success": true,
  "data": {
    "reference_id": "9d903551-77bb-4311-bf32-99a5db4b7711",
    "merchant_ref_id": "INV-20261119-0014",
    "payment_method": {
      "id": 3,
      "name": "Midtrans Snap",
      "code": "midtrans_snap",
      "type": "ewallet",
      "fee_type": "mix",
      "fee_fix": 2000,
      "fee_percent": 1.5,
      "icon_url": "https://your-hub-domain.com/storage/icons/midtrans.svg",
      "gateway_code": "midtrans",
      "is_active": true
    },
    "amount": 250000,
    "fee": 5750,
    "total_amount": 255750,
    "status": "PENDING",
    "pg_status": "PENDING",
    "checkout_url": "https://app.sandbox.midtrans.com/snap/v4/redirection/8f9b9f71-2993-4e4b-91bb-7cb941e79f64",
    "qris_url": null,
    "payment_code": null,
    "redirect_url": "https://my-store.com/checkout/finish?order=INV-20261119-0014",
    "pg_ref_id": "8f9b9f71-2993-4e4b-91bb-7cb941e79f64",
    "paid_at": null,
    "expired_at": "2026-11-20T16:00:00Z",
    "created_at": "2026-11-19T16:00:00Z"
  }
}
```

---

#### Respon Varian 4: Idempotent Replay (200 OK)
Dihasilkan jika request diulang dengan `Idempotency-Key` atau `merchant_ref_id` yang sama persis:
```json
{
  "success": true,
  "idempotent": true,
  "message": "Transaction already exists. Returning existing record.",
  "data": {
    "reference_id": "9d901865-c967-4d7a-b5e0-91a5db4b3211",
    "merchant_ref_id": "INV-20261119-0012",
    "amount": 50000,
    "fee": 350,
    "total_amount": 50350,
    "status": "PENDING",
    "qris_url": "https://your-hub-domain.com/payments/qris/9d901865-c967-4d7a-b5e0-91a5db4b3211.png",
    "payment_code": "0002010102122667...",
    "checkout_url": "https://api.tokopay.id/pay/9d901865"
  }
}
```

---

### 5.3. GET `/api/v1/transactions/{reference_id}`
Memeriksa status terkini dari suatu transaksi. Parameter `{reference_id}` fleksibel: dapat diisi dengan **Hub UUID** (`9d901865-...`) ataupun **Order ID Merchant** (`merchant_ref_id`).

#### Request
```http
GET /api/v1/transactions/INV-20261119-0012 HTTP/1.1
Host: your-hub-domain.com
Authorization: Bearer pb_mcht_abcdef123456...
Accept: application/json
```

#### Response (200 OK)
```json
{
  "success": true,
  "data": {
    "reference_id": "9d901865-c967-4d7a-b5e0-91a5db4b3211",
    "merchant_ref_id": "INV-20261119-0012",
    "amount": 50000,
    "fee": 350,
    "total_amount": 50350,
    "status": "PAID",
    "pg_status": "PAID",
    "checkout_url": "https://api.tokopay.id/pay/9d901865",
    "qris_url": "https://your-hub-domain.com/payments/qris/9d901865-c967-4d7a-b5e0-91a5db4b3211.png",
    "payment_code": "0002010102122667...",
    "redirect_url": "https://my-store.com/checkout/finish?order=INV-20261119-0012",
    "pg_ref_id": "TRX-TOKOPAY-887711",
    "paid_at": "2026-11-19T16:04:12Z",
    "expired_at": "2026-11-20T16:00:00Z",
    "created_at": "2026-11-19T16:00:00Z"
  }
}
```

#### Status Lifecycle:
- `PENDING`: Menunggu pembayaran dari pelanggan.
- `PAID`: Pembayaran berhasil diverifikasi dari payment gateway upstream.
- `DONE`: Pembayaran telah berhasil dikonfirmasi dan callback webhook telah diterima dengan sukses oleh sistem merchant (`{"success": true}`).
- `EXPIRED`: Batas waktu pembayaran habis sebelum dibayar.
- `FAILED`: Pembayaran dibatalkan atau ditolak.

---

### 5.4. GET `/api/v1/webhook-logs`
Melihat riwayat log pengiriman webhook dari Hub ke merchant untuk keperluan auditing dan penanganan kendala delivery.

#### Request
```http
GET /api/v1/webhook-logs?per_page=10 HTTP/1.1
Host: your-hub-domain.com
Authorization: Bearer pb_mcht_abcdef123456...
Accept: application/json
```

#### Response (200 OK)
```json
{
  "success": true,
  "data": [
    {
      "id": 142,
      "reference_id": "9d901865-c967-4d7a-b5e0-91a5db4b3211",
      "direction": "outgoing",
      "status_code": 200,
      "notes": "Callback sent successfully and accepted by merchant.",
      "created_at": "2026-11-19T16:04:15Z"
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 10,
    "total": 1
  }
}
```

---

## 6. Matriks Error & HTTP Status Codes

| HTTP Code | Label | Kondisi Pemicu | Format Respon Body |
| :--- | :--- | :--- | :--- |
| **`400`** | Bad Request | Request body rusak, format JSON invalid, atau URL webhook diblokir pengaman SSRF. | `{"success": false, "message": "Callback blocked by SSRF protection."}` |
| **`401`** | Unauthorized | Token API Key tidak disertakan, format salah, atau token tidak ditemukan/nonaktif. | `{"success": false, "message": "Invalid or inactive API Key."}` |
| **`403`** | Forbidden | Merchant context tidak aktif atau API key tidak memiliki izin pada merchant tersebut. | `{"success": false, "message": "Merchant context not found or inactive."}` |
| **`404`** | Not Found | Transaksi tidak ditemukan berdasarkan `reference_id` atau `merchant_ref_id`. | `{"success": false, "message": "Transaction not found."}` |
| **`409`** | Conflict | `Idempotency-Key` atau `merchant_ref_id` sama namun parameter `amount` atau `payment_method_id` berbeda. | `{"success": false, "message": "Idempotency conflict: transaction exists with different amount or payment method."}` |
| **`422`** | Unprocessable Entity | Validasi parameter input gagal (contoh: nominal < 100, field wajib kosong, metode pembayaran nonaktif). | `{"success": false, "message": "The given data was invalid.", "errors": {"amount": ["The amount must be at least 100."]}}` |
| **`429`** | Too Many Requests | Rate limit per menit terlampaui. | `{"message": "Too Many Attempts.", "retry_after": 60}` |
| **`500`** | Server Error | Terjadi kegagalan komunikasi dengan payment gateway upstream atau internal server error. | `{"success": false, "message": "Failed to communicate with payment gateway: <detail>"}` |

---

## 7. Spesifikasi Webhook Callback (Hub ke Merchant)

Ketika transaksi berubah status menjadi `PAID`, `EXPIRED`, atau `FAILED`, Hub akan mengirimkan HTTP POST secara asinkron ke URL Webhook yang didaftarkan merchant pada dashboard.

### 7.1. Header & Payload Webhook

#### Request Headers dari Hub
```http
POST /your-merchant-webhook-endpoint HTTP/1.1
Host: my-store.com
Content-Type: application/json
Accept: application/json
X-Bridge-Signature: 7c34b123a491ef... (HMAC-SHA256 hex string)
```

#### Payload JSON dari Hub
```json
{
  "reference_id": "9d901865-c967-4d7a-b5e0-91a5db4b3211",
  "merchant_ref_id": "INV-20261119-0012",
  "payment_gateway": "tokopay",
  "payment_method": "QRIS",
  "amount": 50000,
  "fee": 350,
  "total_amount": 50350,
  "status": "PAID",
  "pg_status": "PAID",
  "pg_ref_id": "TRX-TOKOPAY-887711",
  "checkout_url": "https://api.tokopay.id/pay/9d901865",
  "qris_url": "https://your-hub-domain.com/payments/qris/9d901865-c967-4d7a-b5e0-91a5db4b3211.png",
  "created_at": "2026-11-19T16:00:00Z",
  "paid_at": "2026-11-19T16:04:12Z"
}
```

### 7.2. Kewajiban Respon Merchant

> [!IMPORTANT]
> Sistem merchant **WAJIB** merespon dengan status HTTP **`200 OK`** dan payload JSON berikut:
> ```json
> {
>   "success": true
> }
> ```
> Jika sistem merchant mengembalikan HTTP selain 200, atau mengembalikan HTTP 200 tetapi tanpa properti `"success": true`, Hub menganggap pengiriman **GAGAL** dan akan menjadwalkan pengiriman ulang (*retry*).

Ketika merchant membalas dengan `{"success": true}`, status transaksi di Hub otomatis di-update menjadi **`DONE`**.

### 7.3. Kebijakan Retry & Backoff
Jika endpoint merchant mengalami *timeout*, error 5xx/4xx, atau gagal merespon `{"success": true}`, Hub akan mencoba mengirim ulang hingga **5 kali** dengan skema *exponential backoff*:
1. Percobaan 1: Langsung saat event terjadi
2. Percobaan 2: Menunggu **10 detik**
3. Percobaan 3: Menunggu **60 detik** (1 menit)
4. Percobaan 4: Menunggu **300 detik** (5 menit)
5. Percobaan 5: Menunggu **1.800 detik** (30 menit)
6. Percobaan 6 (Final): Menunggu **3.600 detik** (1 jam)

---

## 8. Code Snippet Verifikasi Webhook Multi-Bahasa

> [!WARNING]
> **PENTING UNTUK AI DEVELOPER:**
> Jangan pernah memvalidasi signature menggunakan JSON yang sudah di-*decode* lalu di-*encode* ulang (`json_encode($data)`), karena urutan spasi dan kunci dapat berubah. **Selalu gunakan RAW REQUEST BODY murni (buffer bytes/string)**!

### PHP / Laravel

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $signature = $request->header('X-Bridge-Signature');
        $rawPayload = $request->getContent(); // Raw body string
        $webhookSecret = config('services.payment_hub.webhook_secret');

        if (empty($signature) || empty($webhookSecret)) {
            return response()->json(['success' => false, 'message' => 'Missing signature or secret'], 401);
        }

        // Hitung signature HMAC-SHA256 dari raw payload
        $expectedSignature = hash_hmac('sha256', $rawPayload, $webhookSecret);

        // Gunakan hash_equals untuk mencegah timing attack
        if (! hash_equals($expectedSignature, $signature)) {
            return response()->json(['success' => false, 'message' => 'Invalid webhook signature'], 401);
        }

        $data = $request->json()->all();
        $merchantRefId = $data['merchant_ref_id'];
        $status = $data['status']; // PAID, EXPIRED, FAILED

        if ($status === 'PAID') {
            // TODO: Tandai pesanan lunas di database merchant Anda
            // Order::where('invoice_number', $merchantRefId)->update(['status' => 'PAID']);
        }

        // WAJIB kembalikan {"success": true} dengan status 200 OK
        return response()->json(['success' => true]);
    }
}
```

---

### Node.js / Express / Next.js

#### Express.js (Gunakan `express.raw` atau simpan rawBody)
```typescript
import express, { Request, Response } from 'express';
import crypto from 'crypto';

const app = express();

// Tangkap raw body untuk verifikasi webhook
app.post(
  '/api/webhook',
  express.raw({ type: 'application/json' }),
  (req: Request, res: Response) => {
    const signature = req.headers['x-bridge-signature'] as string;
    const webhookSecret = process.env.PAYMENT_HUB_WEBHOOK_SECRET || '';

    if (!signature || !webhookSecret) {
      return res.status(401).json({ success: false, message: 'Missing signature/secret' });
    }

    const rawBody = req.body.toString('utf8');
    const expectedSignature = crypto
      .createHmac('sha256', webhookSecret)
      .update(rawBody)
      .digest('hex');

    // Hindari timing attack dengan timingSafeEqual
    const sigBuf = Buffer.from(signature, 'hex');
    const expectedBuf = Buffer.from(expectedSignature, 'hex');

    if (sigBuf.length !== expectedBuf.length || !crypto.timingSafeEqual(sigBuf, expectedBuf)) {
      return res.status(401).json({ success: false, message: 'Invalid signature' });
    }

    const data = JSON.parse(rawBody);

    if (data.status === 'PAID') {
      // TODO: Proses aktivasi pesanan
      console.log(`Payment received for order: ${data.merchant_ref_id}`);
    }

    // WAJIB merespon {"success": true}
    return res.status(200).json({ success: true });
  }
);
```

#### Next.js (App Router: `app/api/webhook/route.ts`)
```typescript
import { NextRequest, NextResponse } from 'next/server';
import crypto from 'crypto';

export async function POST(req: NextRequest) {
  const signature = req.headers.get('x-bridge-signature');
  const webhookSecret = process.env.PAYMENT_HUB_WEBHOOK_SECRET || '';

  if (!signature || !webhookSecret) {
    return NextResponse.json({ success: false, message: 'Unauthorized' }, { status: 401 });
  }

  // Baca raw text dari body stream
  const rawBody = await req.text();
  const expectedSignature = crypto
    .createHmac('sha256', webhookSecret)
    .update(rawBody)
    .digest('hex');

  const sigBuf = Buffer.from(signature, 'hex');
  const expectedBuf = Buffer.from(expectedSignature, 'hex');

  if (sigBuf.length !== expectedBuf.length || !crypto.timingSafeEqual(sigBuf, expectedBuf)) {
    return NextResponse.json({ success: false, message: 'Invalid signature' }, { status: 401 });
  }

  const payload = JSON.parse(rawBody);

  if (payload.status === 'PAID') {
    // TODO: Update order status to paid in DB
  }

  return NextResponse.json({ success: true });
}
```

---

### Python / FastAPI / Django

#### FastAPI
```python
from fastapi import FastAPI, Request, HTTPException, Header
import hmac
import hashlib
import json
import os

app = FastAPI()
WEBHOOK_SECRET = os.getenv("PAYMENT_HUB_WEBHOOK_SECRET", "")

@app.post("/api/webhook")
async def handle_payment_webhook(request: Request, x_bridge_signature: str = Header(None)):
    if not x_bridge_signature or not WEBHOOK_SECRET:
        raise HTTPException(status_code=401, detail="Missing signature or secret")

    raw_body = await request.body()
    expected_signature = hmac.new(
        WEBHOOK_SECRET.encode('utf-8'),
        raw_body,
        hashlib.sha256
    ).hexdigest()

    # Cegah timing attack
    if not hmac.compare_digest(expected_signature, x_bridge_signature):
        raise HTTPException(status_code=401, detail="Invalid signature")

    payload = json.loads(raw_body.decode('utf-8'))

    if payload.get("status") == "PAID":
        # TODO: Proses pesanan berhasil
        pass

    return {"success": True}
```

---

### Go (Golang)

```go
package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"io"
	"net/http"
	"os"
)

type WebhookPayload struct {
	ReferenceID   string  `json:"reference_id"`
	MerchantRefID string  `json:"merchant_ref_id"`
	Status        string  `json:"status"`
	TotalAmount   float64 `json:"total_amount"`
}

func handleWebhook(w http.ResponseWriter, r *http.Request) {
	signature := r.Header.Get("X-Bridge-Signature")
	webhookSecret := os.Getenv("PAYMENT_HUB_WEBHOOK_SECRET")

	if signature == "" || webhookSecret == "" {
		http.Error(w, `{"success":false,"message":"Missing signature"}`, http.StatusUnauthorized)
		return
	}

	rawBody, err := io.ReadAll(r.Body)
	if err != nil {
		http.Error(w, `{"success":false}`, http.StatusBadRequest)
		return
	}

	mac := hmac.New(sha256.New, []byte(webhookSecret))
	mac.Write(rawBody)
	expectedMAC := hex.EncodeToString(mac.Sum(nil))

	if !hmac.Equal([]byte(signature), []byte(expectedMAC)) {
		http.Error(w, `{"success":false,"message":"Invalid signature"}`, http.StatusUnauthorized)
		return
	}

	var payload WebhookPayload
	if err := json.Unmarshal(rawBody, &payload); err == nil {
		if payload.Status == "PAID" {
			// TODO: Update status pesanan
		}
	}

	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	w.Write([]byte(`{"success":true}`))
}
```

---

## 9. Prompting Cheat Sheet untuk Vibe Coding

Salin contoh instruksi di bawah ini saat memberikan tugas ke AI (Cursor, Claude Code, Windsurf, ChatGPT) saat Anda sedang membuat aplikasi klien:

### Contoh Prompt 1: Membuat Halaman Pembayaran (Next.js / Vue / React)
```markdown
Tolong buatkan halaman checkout pembayaran menggunakan panduan @API_SPEC.md:
1. Panggil endpoint `GET /api/v1/payment-methods` untuk mengambil daftar metode pembayaran aktif dan tampilkan pilihan radio button lengkap dengan icon dan perkiraan fee.
2. Ketika user menekan tombol "Bayar Sekarang", kirim request `POST /api/v1/payments` dengan header `Idempotency-Key` berupa UUID v4.
3. Handle respon:
   - Jika tipe `qris`, tampilkan gambar QR menggunakan URL pada field `data.qris_url`.
   - Jika tipe `va`, tampilkan nomor VA dari field `data.payment_code` lengkap dengan tombol copy ke clipboard.
   - Jika ada `data.checkout_url`, lakukan redirect pengguna ke URL tersebut.
4. Lakukan polling status setiap 5 detik ke `GET /api/v1/transactions/{reference_id}` hingga status menjadi `PAID` atau `DONE`.
```

### Contoh Prompt 2: Membuat Endpoint Penerima Webhook (Laravel / Express)
```markdown
Tolong buatkan endpoint webhook handler untuk menerima notifikasi pembayaran sesuai spesifikasi @API_SPEC.md:
1. Validasi header `X-Bridge-Signature` menggunakan HMAC-SHA256 dari RAW request body dan secret key di .env.
2. Tolak request dengan status 401 jika signature tidak cocok (gunakan constant-time comparison).
3. Jika status transaksi adalah "PAID", update status order di database menjadi "COMPLETED" dan kirim email konfirmasi ke customer.
4. Pastikan response yang dikembalikan selalu status 200 OK dengan JSON persis: `{"success": true}` agar server Hub tidak melakukan retry berulang.
```
