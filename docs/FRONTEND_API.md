# CareerPrep Frontend API Contract (`/api/v1`)

This contract was taken from the code in `routes/api.php`, `app/Http/**` and `app/Services/**` on 2026-10-13. Statements marked **(tested)** are covered by the feature suite. **(code)** means the behavior was confirmed by reading the code but has no test.

## Conventions

- **Base URL:** `{API_ORIGIN}/api/v1`. The frontend reads it from `NEXT_PUBLIC_API_URL`.
- **Format:** send `Accept: application/json` on every request. Request bodies are JSON, except resume upload, which is `multipart/form-data`. API routes always return JSON errors (`bootstrap/app.php`).
- **IDs** are integers. **Timestamps** are ISO-8601 UTC strings or `null`. **Money** is an integer in the smallest currency unit (kobo for NGN).
- **Single resources** are wrapped as `{ "data": { ... } }`.
- **Lists** are paginated with 15 items per page, using `?page=N`. They use Laravel's standard envelope:
  ```json
  { "data": [ ... ],
    "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
    "meta": { "current_page": 1, "from": 1, "last_page": 3, "path": "...", "per_page": 15, "to": 15, "total": 40, "links": [ ... ] } }
  ```
  Lists are ordered newest first (`created_at DESC, id DESC`).

### Authentication: Sanctum bearer tokens only

- Register or log in to receive a plain-text token. Send it on every protected call as `Authorization: Bearer <token>`.
- **Cookies and sessions are not used.**
  - The API routes have no session middleware, and `statefulApi()` is not enabled.
  - CORS has `supports_credentials = false`, so the frontend must use `credentials: "omit"`.
  - `GET /sanctum/csrf-cookie` is not needed.
- **Tokens do not expire.** `config/sanctum.php` is not published, so Sanctum's default `expiration = null` applies. Logout revokes only the token used for that request. Each login creates a new token named `careerprep-web`.
- **Missing or invalid token:** `401 { "message": "Unauthenticated." }` **(tested)**. Guests are never redirected.

### Error shapes

| Status | When | Body |
|---|---|---|
| 401 | Missing or invalid bearer token, or bad webhook signature | `{ "message": "Unauthenticated." }` |
| 404 | Record not found **or owned by another user**. Ownership failures are deliberately reported as 404. | `{ "message": "..." }` |
| 409 | Deleting a resume that is linked to a payment | `{ "message": "A resume linked to a payment cannot be deleted." }` |
| 413 | Request body exceeds PHP `post_max_size` (Laravel `PostTooLargeException`) | `{ "message": "..." }` |
| 422 | Validation or business-rule failure | `{ "message": "...", "errors": { "field": ["..."] } }` |
| 429 | Rate limit hit | `{ "message": "Too Many Attempts." }` |
| 500 | Unexpected error, including Paystack HTTP failures | `{ "message": "Server Error" }` (production must use `APP_DEBUG=false`) |
| 503 | Paystack is not configured on the server | `{ "message": "Payments are not configured." }` |

### Rate limits (`AppServiceProvider`)

| Limiter | Limit | Key |
|---|---|---|
| `api` (all `/v1` routes) | 60 per minute | user ID, or IP for guests |
| `registration` | 5 per minute | IP **(tested)** |
| `login` | 5 per minute | lower-cased email and IP **(tested)** |
| `resume-uploads` | 10 per minute | user ID |
| Paystack webhook | not throttled | — |

### CORS

- `config/cors.php` allows exactly one origin: `FRONTEND_URL`.
  - Vercel **preview** deployment URLs are **not** allowed.
  - Additional origins require a config change, either a list or `allowed_origins_patterns`.
- `Content-Disposition` is exposed, so a cross-origin `fetch` can read the download filename **(tested)**.

## Resource shapes

**User**
```json
{ "id": 1, "name": "Ada", "email": "ada@example.com", "phone": null, "location": null,
  "professional_title": null, "created_at": "...", "updated_at": "..." }
```

**Resume.** File paths and URLs are never returned.
```json
{ "id": 3, "original_name": "cv.pdf", "mime_type": "application/pdf", "size": 182311,
  "extraction_status": "succeeded", "extraction_error": null, "extracted_at": "...",
  "created_at": "...", "updated_at": "..." }
```
`extraction_status` is one of `pending | succeeded | failed`.

**Payment**
```json
{ "id": 7, "resume_id": 3, "reference": "CP-01J...", "amount": 500000, "currency": "NGN",
  "status": "verified", "authorization_url": "https://checkout.paystack.com/...", "access_code": "...",
  "entitlement_status": "available", "review_id": null, "verified_at": "...", "created_at": "..." }
```
- `status` is one of `pending | initialized | verified | failed`.
- `entitlement_status` is one of:
  - `available`: verified and not yet used
  - `consumed`: a review was created
  - `unavailable`: not verified
- `review_id` is the ID of the review created from this payment, or `null`.

**Review**
```json
{ "id": 9, "resume_id": 3, "payment_id": 7, "resume_name": "cv.pdf", "status": "completed",
  "assessment_version": "rule-based-v1", "overall_score": 72,
  "category_scores": { "contact_information": 80, "...": 0 },
  "weaknesses": ["..."], "recommendations": ["..."], "failure_reason": null,
  "disclaimer": "This automated rule-based review is guidance only. ...",
  "completed_at": "...", "created_at": "..." }
```
- `status` is one of `pending | completed | failed`.
- Scores are 0–100.
- `category_scores` is an **object** keyed by category slug, not an array.
- `weaknesses` and `recommendations` are arrays of strings.
- There is no `strengths` field.

## Endpoints

All endpoints except register, login and the webhook require a bearer token.

### Auth and profile

| Method and path | Request | Success | Failures |
|---|---|---|---|
| `POST /auth/register` | `name` (≤120), `email` (unique, lower-cased), `password` (≥8, ≤72), `password_confirmation` | **201** `{ data: User, token }` **(tested)** | 422, 429 |
| `POST /auth/login` | `email`, `password` | **200** `{ data: User, token }` **(tested)** | 422 `errors.email` ("The provided credentials are incorrect."), 429 |
| `GET /auth/user` | — | 200 `{ data: User }` | 401 |
| `POST /auth/logout` | — | 200 `{ message: "Logged out successfully." }`. The current token is deleted **(tested)**. | 401 |
| `GET /profile` | — | 200 `{ data: User }` | 401 |
| `PATCH /profile` | Any of `name`, `email` (unique), `phone` (≤30, nullable), `location` (≤120, nullable), `professional_title` (≤120, nullable) | 200 `{ data: User }` **(tested)** | 422 |

Changing `email` clears `email_verified_at`. That field is not exposed, and email verification is not enforced anywhere.

### Resumes (private PDFs)

| Method and path | Request | Success | Failures |
|---|---|---|---|
| `GET /resumes` | `?page` | 200, paginated list of Resume objects (own only) **(tested)** | 401 |
| `POST /resumes` | multipart field **`resume`**: PDF, at most 5 MB | **201** `{ data: Resume }`. Text extraction runs synchronously, so `extraction_status` is already `succeeded` or `failed` in the response **(tested)**. | 422, 429, 413 |
| `GET /resumes/{id}` | — | 200 `{ data: Resume }` **(tested)** | 404 if it belongs to another user **(tested)** |
| `DELETE /resumes/{id}` | — | **204**. The stored file is also deleted **(tested)**. | 404, 409 if a payment references it **(tested)** |

**Privacy (code and tests):**
- Files are stored under `users/{userId}/` on `RESUME_DISK` (`local` or `resumes` in development, `r2` in deployment).
- Every one of those disks is private. The `local` disk's `/storage` route only serves private files with a valid signature, and nothing in the app generates one.
- Only metadata is returned. No endpoint returns the PDF bytes or a URL, not even to the owner.

### Payments (Paystack)

| Method and path | Request | Success | Failures |
|---|---|---|---|
| `GET /payments` | `?page`, optional `?reference=CP-...` | 200, paginated list of Payment objects (own only) **(tested)** | 401 |
| `POST /payments` | `{ "resume_id": int }` | **201** `{ data: Payment }`. `status` is `initialized` and includes `authorization_url` **(tested)**. | 422 (missing or unknown `resume_id`), 404 (another user's resume, **(tested)**), 503 (not configured), 500 (Paystack error; the payment is marked `failed`) |
| `GET /payments/{id}` | — | 200 `{ data: Payment }` **(tested)** | 404 if it belongs to another user **(tested)** |
| `POST /payments/{id}/verify` | no body | 200 `{ data: Payment }` with `status: "verified"` **(tested)** | 422 `errors.payment` if Paystack does not report success with the expected reference, amount and currency **(tested for amount)**. 404, 500. |

**Payment flow:**
1. `POST /payments` creates the payment and returns a Paystack checkout link. Redirect the browser to `authorization_url`.
2. Paystack redirects back to `PAYSTACK_CALLBACK_URL` with `?reference=...&trxref=...`. Never trust those query values.
3. Call `POST /payments/{id}/verify`. If the payment ID is not known locally, find it first with `GET /payments?reference=<ref>`.
4. When `entitlement_status` is `available`, call `POST /reviews` with that `payment_id`.
5. To resume a payment that was abandoned partway, list `GET /payments` and look for `entitlement_status: "available"`.

**Server-side protections (unchanged):**
- The amount and currency come from server config (`PAYSTACK_REVIEW_AMOUNT`, `PAYSTACK_CURRENCY`). The client never sends a price.
- References are server-generated (`CP-<ULID>`). The reference Paystack echoes back must match the stored one.
- A payment is marked verified only when Paystack reports `status=success` and the reference, amount and currency all match the stored payment (`PaymentVerifier`).
- Verification is idempotent. It runs under a `SELECT ... FOR UPDATE` row lock, and an already-verified payment is returned unchanged.
- The Paystack secret key never leaves the server, and `provider_payload` is never returned to the client.

### Webhook (server-to-server only, not called by the frontend)

`POST /api/v1/paystack/webhook`. There is no auth middleware and no throttle.

| Case | Response |
|---|---|
| Valid signature. `x-paystack-signature` must equal `HMAC-SHA512(raw body, PAYSTACK_SECRET_KEY)`, compared with `hash_equals`. | 200 `{ "message": "Webhook acknowledged." }` |
| Missing or invalid signature | 401 **(tested)** |
| Body is not valid JSON | 400 **(code)** |
| No secret key configured | 503 **(code)** |

On a valid request:
- Only `charge.success` events are acted on. They go through the same `PaymentVerifier` as the verify endpoint, so the same amount, currency and reference checks apply. A payload with a mismatched amount is ignored **(code)**.
- Events of any other type, and unknown references, are acknowledged with 200 and change nothing.
- Retries are idempotent **(tested)**.

### Reviews

| Method and path | Request | Success | Failures |
|---|---|---|---|
| `GET /reviews` | `?page`, optional `?resume_id=int` | 200, paginated list of Review objects (own only) **(tested)** | 401 |
| `POST /reviews` | `{ "payment_id": int }` | **201** `{ data: Review }` **(tested)** | 422 `errors.payment_id`: "This payment has not been verified." **(tested)** or "This review entitlement has already been used." **(tested)**. 422 for a missing or unknown ID. 404 for another user's payment. |
| `GET /reviews/{id}` | — | 200 `{ data: Review }` | 404 if it belongs to another user **(tested)** |
| `GET /reviews/{id}/download` | — | 200 `text/plain; charset=UTF-8`, `Content-Disposition: attachment; filename="careerprep-review-{id}.txt"` **(tested)** | 404 |

**Entitlement rules:**
- One verified payment gives one review.
- `CreateReview` checks the entitlement and consumes it inside a transaction that locks the payment row. In addition, `reviews.payment_id` is `UNIQUE`.
- The review is assessed synchronously, so the 201 response already contains the final `status`.
- **The entitlement is consumed even when the review ends in `failed`**, for example when the PDF text can't be extracted. The frontend should warn the user before they pay for a resume whose `extraction_status` is `failed`.

## Frontend mismatches found in `careerprep-ng/src/lib/laravel` (not changed here)

1. `toResume` reads `status`, but the API sends `extraction_status`, so the extraction state is never shown.
2. `downloadReview` falls back to a filename ending in `.pdf`, but the file is `.txt`. The real filename is now readable because `Content-Disposition` is exposed.
3. The payment callback page depends only on `localStorage` (`careerprep.pendingPayment`). It can now fall back to `GET /payments?reference=` using the `reference` query parameter Paystack appends.
4. The resume detail page filters reviews from the first page of `GET /reviews` only. Use `GET /reviews?resume_id=`.
5. The bearer token is kept in `localStorage` and never expires (see Authentication). Any XSS bug would expose a long-lived token.
