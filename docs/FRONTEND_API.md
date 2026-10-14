# CareerPrep Frontend API Contract (`/api/v1`)

This contract was taken from the code in `routes/api.php`, `app/Http/**` and `app/Services/**` on 2026-10-13 (updated after the production-readiness changes). Statements marked **(tested)** are covered by the feature suite. **(code)** means the behavior was confirmed by reading the code but has no test.

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
- **Tokens expire** after `SANCTUM_EXPIRATION` minutes (default 10080, which is 7 days; `0` disables expiry).
  - Login and registration return `expires_at`, an ISO timestamp, or `null` when expiry is disabled.
  - Expiry is measured from token creation, so tokens issued before expiry was configured also expire by age **(tested)**.
  - An expired token gets the same `401 { "message": "Unauthenticated." }` as an invalid one **(tested)**. The frontend should clear the stored token and send the user to sign in, which `useCandidateAuth` already does on any 401. There is no refresh endpoint; the user signs in again.
  - Logout revokes only the token used for that request. Each login creates a new token named `careerprep-web`.
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
| 503 | Paystack is not configured on the server. Check `GET /pricing` first to avoid this. | `{ "message": "Payments are not configured." }` |

### Rate limits (`AppServiceProvider`)

| Limiter | Limit | Key |
|---|---|---|
| `api` (all `/v1` routes) | 60 per minute | user ID, or IP for guests |
| `registration` | 5 per minute | IP **(tested)** |
| `login` | 5 per minute | lower-cased email and IP **(tested)** |
| `resume-uploads` | 10 per minute | user ID |
| Paystack webhook | not throttled | — |

### CORS

- `FRONTEND_URL` is a comma-separated list of exact origins. Trailing slashes and spaces are tolerated **(tested)**.
- Vercel preview URLs (`https://<VERCEL_PREVIEW_PROJECT>-<id>-<VERCEL_PREVIEW_SCOPE>.vercel.app`) are allowed only when both variables are set **and** `APP_ENV` is not `production`. In other words, previews must use a staging API **(tested)**.
- Credentials are never allowed.
- `Content-Disposition` is exposed, so a cross-origin `fetch` can read the download filename **(tested)**.

## Resource shapes

**User**
```json
{ "id": 1, "name": "Ada", "email": "ada@example.com", "role": "candidate", "phone": null, "location": null,
  "professional_title": null, "created_at": "...", "updated_at": "..." }
```
`role` is `candidate` or `admin`. Register, login, `GET /auth/user` and the profile endpoints all include it **(tested)**. Use it only to show or hide admin navigation; the server enforces admin access on every request. No endpoint accepts `role` as input **(tested)**.

**Resume.** File paths and URLs are never returned.
```json
{ "id": 3, "original_name": "cv.pdf", "mime_type": "application/pdf", "size": 182311,
  "extraction_status": "succeeded", "extraction_error": null, "extracted_at": "...",
  "created_at": "...", "updated_at": "..." }
```
`extraction_status` is one of `pending | succeeded | failed`. When `failed`, `extraction_error` is a user-facing reason:
- "Text could not be extracted from this PDF.": the PDF couldn't be parsed, for example because it's encrypted or corrupt.
- "Not enough readable text was found in this PDF. Upload a text-based PDF, not a scanned image.": the PDF parsed but yielded fewer than 30 words, or mostly symbols. This usually means a scanned or image-only resume.

Show `extraction_error` on the resume page. A resume with failed extraction cannot be paid for.

**Payment**
```json
{ "id": 7, "resume_id": 3, "reference": "CP-01J...", "amount": 500000, "currency": "NGN",
  "status": "verified", "authorization_url": "https://checkout.paystack.com/...", "access_code": "...",
  "entitlement_status": "available", "review_id": null, "verified_at": "...", "created_at": "..." }
```
- `status` is one of `pending | initialized | verified | failed | refunded`. `refunded` is set only by an admin, after a refund was made by hand in the Paystack dashboard.
- `entitlement_status` is one of:
  - `available`: verified and not yet used
  - `consumed`: a review was created
  - `unavailable`: not verified, or refunded before a review was created
- `review_id` is the ID of the review created from this payment, or `null`.

**Review**
```json
{ "id": 9, "resume_id": 3, "payment_id": 7, "resume_name": "cv.pdf", "status": "completed",
  "assessment_version": "rule-based-v1", "overall_score": 72,
  "category_scores": { "contact_information": 80, "...": 0 },
  "weaknesses": ["..."], "recommendations": ["..."], "failure_reason": null,
  "attempts": 1, "max_attempts": 3, "can_retry": false,
  "disclaimer": "This automated rule-based review is guidance only. ...",
  "completed_at": "...", "created_at": "..." }
```
- `status` is one of `pending | completed | failed`.
- `can_retry` is true when the review is `failed`, or has been `pending` for more than 10 minutes, and `attempts < max_attempts`, and the payment has not been refunded.
- `max_attempts` is `REVIEW_MAX_ATTEMPTS` plus any attempts an admin has granted.
- Scores are 0–100.
- `category_scores` is an **object** keyed by category slug, not an array.
- `weaknesses` and `recommendations` are arrays of strings.
- There is no `strengths` field.

## Endpoints

All endpoints except pricing, register, login and the webhook require a bearer token.

### Pricing (public)

| Method and path | Success |
|---|---|
| `GET /pricing` | 200 `{ "data": { "review": { "amount": 500000, "currency": "NGN", "available": true } } }` **(tested)** |

- `amount` is in the smallest currency unit, so divide by 100 to display naira.
- When Paystack is not fully configured, the response is `{ "amount": null, "currency": null, "available": false }`. Hide or disable checkout in that case.
- The value comes from the same server config that `POST /payments` charges. A client-sent `amount` or `currency` is ignored **(tested)**.

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
| `POST /payments` | `{ "resume_id": int }` | **201** `{ data: Payment }`. `status` is `initialized` and includes `authorization_url` **(tested)**. | 422 (missing or unknown `resume_id`, or the resume's `extraction_status` is `failed`, **(tested)**), 404 (another user's resume, **(tested)**), 503 (not configured), 500 (Paystack error; the payment is marked `failed`) |
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
| `POST /reviews` | `{ "payment_id": int }` | **201** `{ data: Review }` **(tested)** | 422 `errors.payment_id`: "This payment has not been verified." **(tested)**, "This payment has been refunded." **(tested)**, or "This review entitlement has already been used." **(tested)**. 422 for a missing or unknown ID. 404 for another user's payment. |
| `POST /reviews/{id}/retry` | Optional `{ "resume_id": int }` to switch to another of the candidate's own resumes | 200 `{ data: Review }`, already reprocessed. `attempts` is incremented. **(tested)** | 422 `errors.review`: "This review has already been completed." / "This review is still being processed." / "This review has no retry attempts remaining. Please contact support." **(tested)**. 404 for another user's review or resume **(tested)**. |
| `GET /reviews/{id}` | — | 200 `{ data: Review }` | 404 if it belongs to another user **(tested)** |
| `GET /reviews/{id}/download` | — | 200 `text/plain; charset=UTF-8`, `Content-Disposition: attachment; filename="careerprep-review-{id}.txt"` **(tested)** | 404 |

**Entitlement rules:**
- One verified payment gives one review.
- `CreateReview` checks the entitlement and consumes it inside a transaction that locks the payment row. In addition, `reviews.payment_id` is `UNIQUE`.
- The review is assessed synchronously, so the 201 response already contains the final `status`.

**Recovery policy for technical failures:**
- A review can fail because the PDF text can't be extracted, or because of an unexpected assessment error.
- The payment's entitlement stays consumed, but the same review may be reprocessed with `POST /reviews/{id}/retry`. That covers up to `REVIEW_MAX_ATTEMPTS` attempts in total (default 3).
- A retry may switch the review to another resume the candidate owns, typically a re-exported text-based PDF. `payment.resume_id` keeps the original record.
- A retry takes a row lock on the review. A duplicate retry while one is running, or a retry after completion, is rejected with 422. A retry never restores the payment entitlement, so `POST /reviews` for the same payment remains 422 **(tested)**.
- A review stuck in `pending` because its process died becomes retryable after 10 minutes **(tested)**.
- When attempts run out, `can_retry` is false. The candidate should contact support, and refunds are manual through Paystack.
- Prevention: `POST /payments` is refused for a resume whose `extraction_status` is `failed`.

## Admin API (`/api/v1/admin`)

### Access
- **Granting access.** Admins are created only on the server, with `php artisan careerprep:make-admin {email}`. It promotes an existing account. If the email doesn't exist yet, it offers to create the admin account, asking for the name and password at hidden prompts; this needs an interactive terminal **(tested)**. Use `--revoke` to return a user to `candidate`; revoking also deletes all of that user's tokens **(tested)**. No HTTP endpoint grants or changes a role **(tested)**.
- **Middleware.** Every route runs `throttle:api` (60/min per user), then `auth:sanctum`, then `EnsureAdmin`. The role is re-read from the database on every request, so a demoted admin is refused immediately, even with a valid token **(tested)**.
- **The admin check runs before record lookup.** Non-admins get 403 even for IDs that don't exist, so they can't tell which records exist **(tested)**.

| Case | Response |
|---|---|
| No or invalid token | `401 { "message": "Unauthenticated." }` **(tested for every route)** |
| Candidate token | `403 { "message": "This action is unauthorized." }` **(tested for every route)** |
| Unknown ID (admin) | `404 { "message": "..." }` **(code)** |
| Validation | `422 { "message", "errors": { "field": [..] } }` **(tested)** |

Lists use the standard paginated envelope (`data`, `links`, `meta`) with 15 per page, newest first (`created_at DESC, id DESC`), and accept `?page=`. Filter values are kept in the pagination links. IDs are integers, timestamps are ISO-8601 UTC, and money is in kobo.

**Never returned by any admin endpoint** **(tested)**:
- `provider_payload`, `access_code`, `authorization_url`
- the storage `disk`, `path` or URLs
- extracted resume text
- any secret

### Admin resource shapes

**UserSummary:** `{ "id", "name", "email" }`

**AdminUser**
```json
{ "id": 7, "name": "Ada", "email": "ada@example.com", "role": "candidate", "phone": null, "location": null,
  "professional_title": null, "resumes_count": 2, "payments_count": 1, "reviews_count": 1,
  "created_at": "...", "updated_at": "..." }
```
The `*_count` fields appear on the user list and the user detail.

**AdminResume**
```json
{ "id": 3, "user_id": 7, "user": UserSummary, "original_name": "cv.pdf", "mime_type": "application/pdf",
  "size": 81008, "extraction_status": "succeeded", "extraction_error": null, "extracted_at": "...",
  "created_at": "...", "updated_at": "..." }
```

**AdminPayment**
```json
{ "id": 1, "user_id": 7, "user": UserSummary, "resume_id": 3, "reference": "CP-01J...", "amount": 600000,
  "currency": "NGN", "status": "verified", "entitlement_status": "consumed", "review_id": 1,
  "provider_transaction_id": "4099260516", "verified_at": "...", "refunded_at": null, "refund_note": null,
  "created_at": "...", "updated_at": "..." }
```
- `status` is one of `pending | initialized | verified | failed | refunded`.
- `entitlement_status` is one of `available | consumed | unavailable`.

**AdminReview** has every candidate Review field (`id`, `resume_id`, `payment_id`, `resume_name`, `status`, `assessment_version`, `overall_score`, `category_scores`, `weaknesses`, `recommendations`, `failure_reason`, `attempts`, `max_attempts`, `can_retry`, `disclaimer`, `completed_at`, `created_at`), plus:
```json
{ "user_id": 7, "user": UserSummary, "extra_attempts": 0, "payment_status": "verified", "updated_at": "..." }
```
`can_retry` describes what the **candidate** can do. Admins can always retry a review that isn't completed.

**AuditLogEntry**
```json
{ "id": 5, "actor_id": 1, "actor": UserSummary | null, "action": "payment.mark_refunded",
  "target_type": "payment", "target_id": 12, "before": { ... }, "after": { ... },
  "ip_address": "203.0.113.4", "created_at": "..." }
```
- `actor` is `null` if that admin account was deleted.
- `action` is one of `resume.download | payment.reverify | payment.mark_refunded | review.retry | review.grant_attempt`.
- `before` and `after` are small summaries (status, attempts, entitlement, note). They never contain resume text, payloads or paths.

### Endpoints

| Method and path | Query or body | Success | Errors |
|---|---|---|---|
| `GET /admin/stats` | `from`, `to`: optional `YYYY-MM-DD`, inclusive, UTC | 200, see below **(tested)** | 422 for a bad date format or `to` < `from` **(tested)** |
| `GET /admin/users` | `search` (name or email, case-insensitive substring, `%` and `_` matched literally, max 100), `role` (`candidate`\|`admin`), `page` | 200, list of AdminUser **(tested)** | 422 for an invalid `role` **(tested)** |
| `GET /admin/users/{id}` | — | 200 `{ data: AdminUser + latest_resumes[], latest_payments[], latest_reviews[] }`. Each list holds up to 5, newest first **(tested)**. | 404 |
| `GET /admin/resumes` | `user_id`, `extraction_status` (`pending`\|`succeeded`\|`failed`), `page` | 200, list of AdminResume **(tested)** | 422 |
| `GET /admin/resumes/{id}` | — | 200 `{ data: AdminResume }` **(tested)** | 404 |
| `GET /admin/resumes/{id}/download` | — | 200 `application/pdf`, `Content-Disposition: attachment; filename="<original_name>"`, `Cache-Control: private, no-store`. Audited as `resume.download` **(tested)**. | 404 `"The resume file is no longer available."` (not audited) **(tested)** |
| `GET /admin/payments` | `status`, `reference` (exact), `user_id`, `from`, `to` (created date, `YYYY-MM-DD`), `page` | 200, list of AdminPayment **(tested)** | 422 **(tested)** |
| `GET /admin/payments/{id}` | — | 200 `{ data: AdminPayment }` **(tested)** | 404 |
| `POST /admin/payments/{id}/reverify` | no body | 200 `{ data: AdminPayment, meta: { "verified": bool, "provider_status": "success"\|"abandoned"\|... \| null } }`. Audited as `payment.reverify` **(tested)**. | 422 `errors.payment` for a refunded payment **(tested)**. 502 if Paystack can't be reached or answers badly (not audited) **(tested)**. 503 if Paystack isn't configured **(code)**. |
| `POST /admin/payments/{id}/mark-refunded` | `{ "note": string, required, ≤1000 }` | 200 `{ data: AdminPayment }` with `status: "refunded"`, `refunded_at` and `refund_note`. Audited as `payment.mark_refunded` **(tested)**. | 422 `errors.note`. 422 `errors.payment`: "Only verified payments can be marked as refunded." / "This payment is already marked as refunded." **(tested)** |
| `GET /admin/reviews` | `status` (`pending`\|`completed`\|`failed`), `user_id`, `resume_id`, `page` | 200, list of AdminReview **(tested)** | 422 |
| `GET /admin/reviews/{id}` | — | 200 `{ data: AdminReview }` **(tested)** | 404 |
| `POST /admin/reviews/{id}/retry` | optional `{ "resume_id": int }`, which must belong to the review's candidate | 200 `{ data: AdminReview }`, already reprocessed. Audited as `review.retry` **(tested)**. | 422 `errors.resume_id` (unknown, or another candidate's resume) **(tested)**. 422 `errors.review`: "already been completed" **(tested)** / "still being processed" **(code)**. |
| `POST /admin/reviews/{id}/grant-attempt` | no body | 200 `{ data: AdminReview }` with `extra_attempts` and `max_attempts` each increased by 1. Audited as `review.grant_attempt` **(tested)**. | 422 `errors.review` for a completed review (not audited) **(tested)** |
| `GET /admin/audit-log` | `actor_id`, `action` (exact), `page` | 200, list of AuditLogEntry **(tested)** | 422 |

**Stats response** **(tested)**:
```json
{ "data": {
  "period":   { "from": "2026-10-01T00:00:00.000000Z", "to": "2026-10-14T23:59:59.999999Z" },
  "users":    { "total": 3, "candidate": 2, "admin": 1 },
  "resumes":  { "total": 3, "pending": 0, "succeeded": 3, "failed": 0 },
  "payments": { "total": 2, "pending": 0, "initialized": 0, "verified": 2, "failed": 0, "refunded": 0 },
  "reviews":  { "total": 2, "pending": 0, "completed": 2, "failed": 0, "retryable_failed": 0 },
  "revenue":  [ { "currency": "NGN", "amount": 1200000, "payments": 2 } ],
  "refunds":  [] } }
```
- Without `from` and `to`, the period values are `null` and every figure covers all time.
- Counts are filtered by each record's `created_at`.
- `revenue` sums payments currently `verified`, filtered by `verified_at`, so it's net of refunds. `refunds` sums `refunded` payments, filtered by `refunded_at`. Both are grouped by currency.
- `retryable_failed` counts failed reviews that still have attempts left and whose payment isn't refunded.

### Behaviour notes
- **Mark-refunded** only records a refund already made in the Paystack dashboard; it never contacts Paystack and never moves money. After a refund:
  - an unused entitlement becomes `unavailable`, and `POST /reviews` with that payment returns 422 "This payment has been refunded." **(tested)**
  - a used one stays `consumed`, its review is kept, and the candidate can no longer retry it (`can_retry: false`) **(tested)**
  - the Paystack webhook, candidate verification and admin re-verification never turn a refunded payment back into `verified` **(tested)**
- **Re-verify** goes through the same `PaymentVerifier` as the candidate flow (status, reference, amount and currency must match) and is idempotent **(tested)**.
- **Admin retry** ignores the attempt limit and refund state (support override), but still refuses completed reviews and reviews already being processed. It increments `attempts` **(tested)**.
- **Grant-attempt** raises that one review's limit by 1, so the candidate's own retry works again **(tested)**.
- **Downloading** a resume from the browser needs the bearer token, so fetch it as a blob instead of using a plain link. `Content-Disposition` is exposed through CORS.

## Frontend mismatches found in `careerprep-ng/src/lib/laravel` (not changed here)

1. `toResume` reads `status`, but the API sends `extraction_status`, so the extraction state is never shown.
2. `downloadReview` falls back to a filename ending in `.pdf`, but the file is `.txt`. The real filename is now readable because `Content-Disposition` is exposed.
3. The payment callback page depends only on `localStorage` (`careerprep.pendingPayment`). It can now fall back to `GET /payments?reference=` using the `reference` query parameter Paystack appends.
4. The resume detail page filters reviews from the first page of `GET /reviews` only. Use `GET /reviews?resume_id=`.
5. The bearer token is kept in `localStorage`, so any XSS bug would expose it. Expiry now bounds that exposure to `SANCTUM_EXPIRATION`. The frontend could also use `expires_at` to sign the user out proactively.
6. The frontend doesn't use these new fields and endpoints yet:
   - `GET /pricing`, to display the price
   - `POST /reviews/{id}/retry` together with `can_retry`, `attempts` and `max_attempts`, to offer a retry on failed reviews
   - the new 422 on `POST /payments` for unreadable resumes, which should be shown on the resume page
