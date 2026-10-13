# CareerPrep Backend Context

CareerPrep is a minimal paid resume-review SaaS. Candidates upload a PDF resume, pay for one review, and receive practical rule-based feedback. The existing frontend is deployed separately on Vercel.

## Stack

- Laravel 13 / PHP 8.3+
- PostgreSQL
- Laravel Sanctum bearer tokens
- Cloudflare R2 through Laravel's S3 filesystem driver
- Paystack through Laravel's HTTP client

## Current scope

Implemented: candidate authentication/profiles, private resume management, Paystack payment initialization and verification, signed webhooks, one-use review entitlements, synchronous PDF text extraction, versioned rule-based assessment, review history/results, and plain-text review downloads.

The Next.js frontend (`careerprep-ng`, Vercel) already uses this API for the candidate dashboard (`src/lib/laravel/*`). Its admin panel, public job board and content pages still read Prisma tables directly; Laravel has no equivalent yet (see `IMPLEMENTATION_STATUS.md`).

Not implemented: admin features, job board/content APIs, production deployment configuration, AI analysis.

The frontend API contract is documented in `docs/FRONTEND_API.md`; deployment steps in `docs/DEPLOYMENT_CHECKLIST.md`.

## Decisions

- API routes are versioned under `/api/v1`.
- The V1 frontend authenticates with Sanctum bearer tokens only: no session middleware on API routes, no `statefulApi()`, CORS `supports_credentials=false`, a single allowed origin from `FRONTEND_URL`, and `Content-Disposition` exposed for downloads. Tokens expire after `SANCTUM_EXPIRATION` minutes (default 7 days), measured from creation. CORS allows exact `FRONTEND_URL` origins plus, outside production only, the project's Vercel preview URLs.
- Candidate profile fields live on `users`: name, email, phone, location, and professional title.
- Resumes are PDF-only, limited to 5 MB, and stored privately. The database stores the disk, private object key, original filename, MIME type, and size; public URLs are never stored.
- Local development uses a private local disk (`local` or `resumes`; both are private and never served unsigned). Deployment should set `RESUME_DISK=r2` after R2 credentials are configured. No endpoint returns resume bytes or URLs.
- Cross-candidate resume access returns 404 to avoid disclosing another candidate's records.
- Tests use in-memory SQLite; the deployed and local application database target is PostgreSQL.
- Review pricing and currency are server configuration. The frontend never supplies the payable amount.
- Payment success requires Paystack status, reference, amount, and currency to match the stored payment. Webhooks require Paystack's HMAC-SHA512 signature.
- Candidates can list/look up their own payments (`GET /payments`, `?reference=`) so a paid-but-unused entitlement is recoverable after the Paystack redirect without client storage.
- The review price is published at `GET /pricing` from the same config used to charge.
- Each verified payment exposes one review entitlement. Creating a review consumes it atomically, and `reviews.payment_id` is unique.
- Technical review failures are recoverable: the consumed entitlement covers up to `REVIEW_MAX_ATTEMPTS` processing attempts of the same review row (optionally switching to another owned resume); refunds beyond that are manual. Payments are refused for resumes whose extraction already failed.
- PDF extraction runs synchronously after upload and is retried when a review starts if needed. Status is persisted as `pending`, `succeeded`, or `failed`.
- The dependency-free PDF extractor supports common uncompressed and Flate-compressed text streams. Unsupported or encrypted PDFs fail safely; the extractor contract allows a fuller parser later.
- Assessment rules are implemented by `ResumeAssessor` and versioned as `rule-based-v1`.
- Review scores are heuristic guidance, not real ATS scores and not hiring guarantees.
