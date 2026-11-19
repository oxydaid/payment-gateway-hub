# Payment Gateway Hub — API & Webhook Spec
> AI Agent: This is the single-source-of-truth for downstream merchant integration.

## AUTH
- **Base URL:** `https://your-hub.com/api/v1`
- **Headers:** `Authorization: Bearer <API_KEY>` · `Content-Type: application/json` · `Accept: application/json`
- **Key types:** `pb_mcht_*` (merchant-scoped, no `merchant_id` needed) · `pb_live_*` / `pb_test_*` (global, requires `merchant_id` in body)
- **Rate limits:** 60 req/min merchant endpoints · 120 req/min webhooks & QRIS

## RESPONSE ENVELOPE
```
Success → { "success": true, "data": {...} }
Error   → { "success": false, "message": "...", "errors": {"field": ["detail"]} }
```

---

## ENDPOINTS

### GET /payment-methods
Returns active payment methods array.

```json
{ "success": true, "data": [
  { "id": 1, "name": "QRIS", "code": "QRIS", "type": "qris",
    "fee_type": "percent", "fee_fix": 0, "fee_percent": 0.7,
    "icon_url": "https://your-hub.com/storage/icons/qris.svg",
    "gateway_code": "tokopay", "is_active": true }
]}
```
> `fee_type`: `"fix"` | `"percent"` | `"mix"` · `type`: `"qris"` | `"va"` | `"ewallet"` | `"retail"`

---

### POST /payments
| Field | Type | Req | Notes |
|---|---|---|---|
| `merchant_ref_id` | string | ✓ | Your order ID, max 255, unique per merchant |
| `payment_method_id` | int | ✓ | From GET /payment-methods, must be active |
| `amount` | numeric | ✓ | IDR, min 100 (before fee) |
| `redirect_url` | url | ✓ | Finish page, max 500, must be https |
| `merchant_id` | int | global key only | Required when using `pb_live_*` key |

**Optional header:** `Idempotency-Key: <uuid>` — same key + same params → `200 + "idempotent": true`. Same key + different `amount`/`payment_method_id` → `409`.

**Response 201 — Transaction Object:**
```json
{
  "reference_id": "9d901865-c967-4d7a-b5e0-91a5db4b3211",  // Hub UUID
  "merchant_ref_id": "INV-20261119-001",
  "payment_method": { "id": 1, "name": "QRIS", "code": "QRIS", "type": "qris" },
  "amount": 50000, "fee": 350, "total_amount": 50350,
  "status": "PENDING",
  "checkout_url": "https://upstream-pg.id/pay/xyz",   // null for QRIS & VA
  "qris_url": "https://your-hub.com/payments/qris/{reference_id}.png", // QRIS only, null otherwise
  "payment_code": "00020101...",  // QRIS: EMVCo string · VA: account number · null for redirect
  "redirect_url": "https://merchant.com/checkout/finish",
  "pg_ref_id": "TRX-TOKOPAY-1234",
  "expired_at": "2026-11-20T16:00:00Z",
  "created_at": "2026-11-19T16:00:00Z"
}
```

**Client rendering by type:**
| `type` | What to show | Key field |
|---|---|---|
| `qris` | QR image or canvas render | `qris_url` (img src) or `payment_code` (EMVCo) |
| `va` | VA number + copy button + total | `payment_code` |
| `ewallet` / `retail` | Redirect user | `checkout_url` |

**Idempotent replay (200):**
```json
{ "success": true, "idempotent": true,
  "message": "Transaction already exists. Returning existing record.",
  "data": { "reference_id": "...", "status": "PENDING" } }
```

---

### GET /transactions/{reference_id}
- Accepts Hub UUID **or** `merchant_ref_id`
- Status lifecycle: `PENDING` → `PAID` → `DONE` (or `EXPIRED` / `FAILED`)

```json
{ "success": true, "data": {
  "reference_id": "9d901865-c967-4d7a-b5e0-91a5db4b3211",
  "merchant_ref_id": "INV-20261119-001",
  "status": "PAID", "total_amount": 50350,
  "paid_at": "2026-11-19T16:04:12Z"
}}
```

---

### GET /webhook-logs
Paginated outbound webhook delivery history. Auth required. Filtered by merchant scope.

---

## ERRORS
| Code | Condition |
|---|---|
| `400` | Malformed JSON / SSRF-blocked URL |
| `401` | Missing or invalid Bearer token |
| `403` | Inactive merchant / unauthorized scope |
| `404` | Transaction not found |
| `409` | Idempotency key conflict (different amount or method) |
| `422` | Validation failed — `errors` object contains field-level messages |
| `429` | Rate limit exceeded |
| `500` | Upstream PG outage or communication error |

---

## WEBHOOK (Hub → Merchant)

Triggered async when status reaches `PAID`, `EXPIRED`, or `FAILED`.

**Request:** `POST {merchant_webhook_url}`
```
X-Bridge-Signature: <hex_hmac_sha256>   ← HMAC-SHA256(raw_body, webhook_secret)
Content-Type: application/json
```

**Payload:**
```json
{
  "reference_id": "9d901865-c967-4d7a-b5e0-91a5db4b3211",
  "merchant_ref_id": "INV-20261119-001",
  "payment_gateway": "tokopay",
  "payment_method": "QRIS",
  "amount": 50000, "fee": 350, "total_amount": 50350,
  "status": "PAID",
  "pg_ref_id": "TRX-TOKOPAY-1234",
  "checkout_url": null,
  "qris_url": "https://your-hub.com/payments/qris/9d901865.png",
  "created_at": "2026-11-19T16:00:00Z",
  "paid_at": "2026-11-19T16:04:12Z"
}
```

**Merchant MUST respond:** `200 OK` + `{"success": true}` → transitions Hub status to `DONE`.
Any other response → retry backoff: `10s · 60s · 300s · 1800s · 3600s` (5 attempts).

> ⚠️ **CRITICAL:** Compute HMAC on **raw body bytes**, never on re-encoded JSON.

### Verification — PHP/Laravel
```php
$sig    = $request->header('X-Bridge-Signature');
$raw    = $request->getContent();
$secret = config('services.hub.webhook_secret');
if (!$sig || !hash_equals(hash_hmac('sha256', $raw, $secret), $sig)) {
    return response()->json(['success' => false], 401);
}
$data = json_decode($raw, true);
if ($data['status'] === 'PAID') { /* fulfill order */ }
return response()->json(['success' => true]);
```

### Verification — Node.js / Next.js
```typescript
import crypto from 'crypto';
export async function POST(req: NextRequest) {
  const sig = req.headers.get('X-Bridge-Signature') ?? '';
  const raw = await req.text();
  const exp = crypto.createHmac('sha256', process.env.HUB_WEBHOOK_SECRET!).update(raw).digest('hex');
  if (!crypto.timingSafeEqual(Buffer.from(sig, 'hex'), Buffer.from(exp, 'hex')))
    return NextResponse.json({ success: false }, { status: 401 });
  const data = JSON.parse(raw);
  if (data.status === 'PAID') { /* fulfill order */ }
  return NextResponse.json({ success: true });
}
```

### Verification — Python / FastAPI
```python
import hmac, hashlib, json, os
SECRET = os.getenv("HUB_WEBHOOK_SECRET", "").encode()

@app.post("/webhook")
async def webhook(request: Request, x_bridge_signature: str = Header(None)):
    raw = await request.body()
    exp = hmac.new(SECRET, raw, hashlib.sha256).hexdigest()
    if not x_bridge_signature or not hmac.compare_digest(exp, x_bridge_signature):
        raise HTTPException(401, "Invalid signature")
    data = json.loads(raw)
    if data.get("status") == "PAID": pass  # fulfill order
    return {"success": True}
```

---

## DYNAMIC QRIS IMAGE
`GET /payments/qris/{reference_id}.{png|svg}`
- No auth required (public, cacheable per transaction)
- Returns QR image rendered server-side from EMVCo string
- Use as `<img src="...">` directly in merchant UI
