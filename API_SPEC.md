# Payment Gateway Hub — API & Webhook Spec

> AI Context Directive: Single-source-of-truth contract for downstream merchant integration. Strict types, exact payloads, and zero assumptions.

## 1. Authentication & Headers
- **Base URL:** `https://your-hub.com/api/v1` (Local: `http://localhost:8000/api/v1`)
- **Headers:**
  ```http
  Authorization: Bearer <API_KEY>
  Content-Type: application/json
  Accept: application/json
  Idempotency-Key: <unique-uuid-or-order-id>
  ```
- **Key Types:**
  - `pb_mcht_*`: Merchant-scoped key (no `merchant_id` needed).
  - `pb_live_*` / `pb_test_*`: Global key (requires `merchant_id` in body or `X-Merchant-ID` header).
- **Rate Limits:** 60 req/min for merchant endpoints (`throttle:60,1`), 120 req/min for webhooks & QRIS.

## 2. Idempotency Protocol
- Replaying `POST /api/v1/payments` with the same `Idempotency-Key` or `merchant_ref_id` and identical `amount` + `payment_method_id` returns **`200 OK`** with `"idempotent": true` (safe replay, no duplicate upstream charges).
- Replaying with conflicting `amount` or `payment_method_id` returns **`409 Conflict`**.

## 3. Standard Response Envelopes
```json
// Success
{ "success": true, "data": { ... } }

// Error
{ "success": false, "message": "Reason", "errors": { "field": ["detail"] } }
```

## 4. Endpoints Reference

### 4.1. List Payment Methods
`GET /api/v1/payment-methods`
```json
// 200 OK
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "QRIS",
      "code": "QRIS",
      "type": "qris", // "qris" | "va" | "ewallet" | "retail"
      "fee_type": "percent", // "fix" | "percent" | "mix"
      "fee_fix": 0,
      "fee_percent": 0.7,
      "icon_url": "https://your-hub.com/storage/icons/qris.svg",
      "gateway_code": "tokopay",
      "is_active": true
    }
  ]
}
```

---

### 4.2. Create Payment
`POST /api/v1/payments`

**Request Body:**
```json
{
  "merchant_ref_id": "INV-20261119-001", // required, string, max:255
  "payment_method_id": 1,                // required, integer, exists & active
  "amount": 50000,                       // required, numeric, min:100
  "redirect_url": "https://merchant.com/checkout/finish", // required, url, max:500
  "merchant_id": 1                       // optional, required only if using global key
}
```

**Client UI Handling by Payment Type:**
- **QRIS (`type: "qris"`):**
  - Render image directly via `qris_url` (`<img src={data.qris_url} />`) — dynamically rendered locally by Hub, no external dependency.
  - Or render raw EMVCo string from `payment_code` via local client QR canvas library.
- **Virtual Account (`type: "va"`):**
  - Display `payment_code` as VA Account Number + copy button + `total_amount`.
- **Redirect / E-Wallet (`checkout_url != null`):**
  - Redirect user to `checkout_url` (`window.location.href = data.checkout_url`).

**Responses (201 Created):**

*A. QRIS Response:*
```json
{
  "success": true,
  "data": {
    "reference_id": "9d901865-c967-4d7a-b5e0-91a5db4b3211",
    "merchant_ref_id": "INV-20261119-001",
    "payment_method": { "id": 1, "name": "QRIS", "code": "QRIS", "type": "qris" },
    "amount": 50000,
    "fee": 350,
    "total_amount": 50350,
    "status": "PENDING",
    "checkout_url": "https://api.tokopay.id/pay/9d901865",
    "qris_url": "https://your-hub.com/payments/qris/9d901865-c967-4d7a-b5e0-91a5db4b3211.png",
    "payment_code": "00020101021226670016ID.CO.TOKOPAY...5802ID630489AB",
    "redirect_url": "https://merchant.com/checkout/finish",
    "pg_ref_id": "TRX-TOKOPAY-1234",
    "expired_at": "2026-11-20T16:00:00Z",
    "created_at": "2026-11-19T16:00:00Z"
  }
}
```

*B. Virtual Account Response:*
```json
{
  "success": true,
  "data": {
    "reference_id": "9d902001-f11a-4299-8ab1-11a5db4b9988",
    "merchant_ref_id": "INV-20261119-002",
    "payment_method": { "id": 2, "name": "BCA Virtual Account", "code": "BCAVA", "type": "va" },
    "amount": 100000,
    "fee": 4000,
    "total_amount": 104000,
    "status": "PENDING",
    "checkout_url": null,
    "qris_url": null,
    "payment_code": "88081234567890",
    "redirect_url": "https://merchant.com/checkout/finish",
    "expired_at": "2026-11-20T16:00:00Z",
    "created_at": "2026-11-19T16:00:00Z"
  }
}
```

*C. Redirect / E-Wallet Response:*
```json
{
  "success": true,
  "data": {
    "reference_id": "9d903551-77bb-4311-bf32-99a5db4b7711",
    "merchant_ref_id": "INV-20261119-003",
    "payment_method": { "id": 3, "name": "Midtrans Snap", "code": "midtrans_snap", "type": "ewallet" },
    "amount": 250000,
    "fee": 5750,
    "total_amount": 255750,
    "status": "PENDING",
    "checkout_url": "https://app.sandbox.midtrans.com/snap/v4/redirection/8f9b9f71-2993-4e4b",
    "qris_url": null,
    "payment_code": null,
    "redirect_url": "https://merchant.com/checkout/finish"
  }
}
```

*D. Idempotent Replay (200 OK):*
```json
{
  "success": true,
  "idempotent": true,
  "message": "Transaction already exists. Returning existing record.",
  "data": { "reference_id": "9d901865...", "status": "PENDING", ... }
}
```

---

### 4.3. Check Transaction Status
`GET /api/v1/transactions/{reference_id}`
- `{reference_id}` accepts Hub UUID (`9d901865-...`) OR merchant's `merchant_ref_id`.
- Status Lifecycle: `PENDING` -> `PAID` -> `DONE` (or `EXPIRED` / `FAILED`).
```json
// 200 OK
{
  "success": true,
  "data": {
    "reference_id": "9d901865-c967-4d7a-b5e0-91a5db4b3211",
    "merchant_ref_id": "INV-20261119-001",
    "status": "PAID",
    "total_amount": 50350,
    "paid_at": "2026-11-19T16:04:12Z"
  }
}
```

---

## 5. Error Reference Matrix

| HTTP Code | Condition | Example Response Body |
| :--- | :--- | :--- |
| `400 Bad Request` | Malformed JSON / SSRF URL blocked | `{"success": false, "message": "Callback blocked by SSRF protection."}` |
| `401 Unauthorized` | Missing / invalid Bearer token | `{"success": false, "message": "Invalid or inactive API Key."}` |
| `403 Forbidden` | Inactive merchant / unauthorized scope | `{"success": false, "message": "Merchant context not found or inactive."}` |
| `404 Not Found` | Transaction reference does not exist | `{"success": false, "message": "Transaction not found."}` |
| `409 Conflict` | Same idempotency key with different payload | `{"success": false, "message": "Idempotency conflict: transaction exists with different amount or payment method."}` |
| `422 Unprocessable` | Validation failed | `{"success": false, "message": "The given data was invalid.", "errors": {"amount": ["The amount must be at least 100."]}}` |
| `429 Too Many Req` | Rate limit exceeded | `{"message": "Too Many Attempts."}` |
| `500 Server Error` | Upstream PG outage | `{"success": false, "message": "Failed to communicate with payment gateway: <error>"}` |

---

## 6. Outbound Webhook Specification (Hub -> Merchant)

Triggered asynchronously when transaction reaches `PAID`, `EXPIRED`, or `FAILED`.

### 6.1. Webhook Request
- **Method:** `POST` to merchant's registered `webhook_url`
- **Headers:**
  ```http
  Content-Type: application/json
  Accept: application/json
  X-Bridge-Signature: <hex_hmac_sha256>
  ```
- **Payload:**
  ```json
  {
    "reference_id": "9d901865-c967-4d7a-b5e0-91a5db4b3211",
    "merchant_ref_id": "INV-20261119-001",
    "payment_gateway": "tokopay",
    "payment_method": "QRIS",
    "amount": 50000,
    "fee": 350,
    "total_amount": 50350,
    "status": "PAID",
    "pg_status": "PAID",
    "pg_ref_id": "TRX-TOKOPAY-1234",
    "checkout_url": "https://api.tokopay.id/pay/9d901865",
    "qris_url": "https://your-hub.com/payments/qris/9d901865.png",
    "created_at": "2026-11-19T16:00:00Z",
    "paid_at": "2026-11-19T16:04:12Z"
  }
  ```

### 6.2. Mandatory Merchant Response
Merchant **MUST** return HTTP **`200 OK`** with JSON:
```json
{ "success": true }
```
- Returning `{"success": true}` transitions transaction in Hub to **`DONE`**.
- Any other response or timeout triggers retry backoff: 5 retries at `10s`, `60s`, `300s`, `1800s`, `3600s`.

---

## 7. Webhook Verification Snippets

> **CRITICAL:** Compute HMAC-SHA256 using the **RAW request body bytes/string** and merchant's `webhook_secret`. Never use parsed/re-encoded JSON.

### PHP / Laravel
```php
$signature = $request->header('X-Bridge-Signature');
$rawBody = $request->getContent();
$secret = config('services.hub.webhook_secret');

if (! $signature || ! hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)) {
    return response()->json(['success' => false, 'message' => 'Invalid signature'], 401);
}

$payload = json_decode($rawBody, true);
if ($payload['status'] === 'PAID') {
    // Process order fulfillment here
}
return response()->json(['success' => true]);
```

### Node.js / Next.js (App Router: `app/api/webhook/route.ts`)
```typescript
import { NextRequest, NextResponse } from 'next/server';
import crypto from 'crypto';

export async function POST(req: NextRequest) {
  const signature = req.headers.get('X-Bridge-Signature') || '';
  const rawBody = await req.text();
  const secret = process.env.HUB_WEBHOOK_SECRET || '';

  const expected = crypto.createHmac('sha256', secret).update(rawBody).digest('hex');
  const sigBuf = Buffer.from(signature, 'hex');
  const expBuf = Buffer.from(expected, 'hex');

  if (sigBuf.length !== expBuf.length || !crypto.timingSafeEqual(sigBuf, expBuf)) {
    return NextResponse.json({ success: false }, { status: 401 });
  }

  const payload = JSON.parse(rawBody);
  if (payload.status === 'PAID') {
    // Process order fulfillment here
  }
  return NextResponse.json({ success: true });
}
```

### Python / FastAPI
```python
from fastapi import FastAPI, Request, HTTPException, Header
import hmac, hashlib, json, os

app = FastAPI()
SECRET = os.getenv("HUB_WEBHOOK_SECRET", "").encode()

@app.post("/api/webhook")
async def webhook(request: Request, x_bridge_signature: str = Header(None)):
    raw_body = await request.body()
    expected = hmac.new(SECRET, raw_body, hashlib.sha256).hexdigest()
    if not x_bridge_signature or not hmac.compare_digest(expected, x_bridge_signature):
        raise HTTPException(status_code=401, detail="Invalid signature")

    data = json.loads(raw_body)
    if data.get("status") == "PAID":
        pass # Process order fulfillment
    return {"success": True}
```
