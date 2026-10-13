# CareerPrep Backend Implementation Status

## Completed

- Laravel Boost development tooling installed.
- PostgreSQL environment variables and Laravel configuration prepared.
- Sanctum bearer-token registration, login, logout, and authenticated-user endpoints.
- Candidate profile retrieval and update.
- Authentication and login rate limits.
- Private resume upload, list, detail, and deletion with ownership authorization.
- Local private-storage and Cloudflare R2 disk configuration.
- Feature tests for authentication, profiles, resume validation, storage, and ownership.
- Paystack transaction initialization and server-side verification using Laravel's HTTP client.
- HMAC-SHA512 Paystack webhook validation and idempotent payment updates.
- One-use review entitlements with transactional locking and database uniqueness.
- Synchronous PDF text extraction with persisted pending/succeeded/failed status.
- Versioned `rule-based-v1` resume assessment with category scores, weaknesses, and recommendations.
- Candidate-owned review results, history, and plain-text downloads.
- Full automated suite verified in randomized order: 45 tests and 198 assertions passing on 2026-10-03.
- 2026-10-13 frontend-integration audit: added `GET /payments` (with `?reference=` lookup), `GET /payments/{payment}`, `GET /reviews?resume_id=`, and CORS exposure of `Content-Disposition`. Suite: 50 tests, 235 assertions passing. Contract: `docs/FRONTEND_API.md`.
- 2026-10-13 production readiness: git repository initialised; review retry/recovery policy (`POST /reviews/{review}/retry`, `REVIEW_MAX_ATTEMPTS`, `reviews.attempts` column); payments refused for unreadable resumes; public `GET /pricing`; configurable Sanctum expiry (`SANCTUM_EXPIRATION`, default 7 days) with `expires_at` in auth responses; multi-origin CORS with staging-only Vercel previews. Suite: 73 tests, 361 assertions passing. Deployment steps: `docs/DEPLOYMENT_CHECKLIST.md`.
- Composer manifest and installed PHP platform requirements verified.

## Pending

- Work through `docs/DEPLOYMENT_CHECKLIST.md`; every PostgreSQL, R2 and live Paystack step there is still unverified.
- Configure trusted proxies for the chosen host so IP-based rate limits work behind a load balancer.
- Provide `FRONTEND_URL` for CORS.
- Provide R2 credentials, bucket, and endpoint; then set `RESUME_DISK=r2`.
- Provide Paystack secret key, review price, currency, callback URL, and webhook URL.
- Fix the frontend mismatches listed at the end of `docs/FRONTEND_API.md`.
- Product decision on the admin panel and public site (below), then admin authorization in Laravel.
- Vercel deployment configuration.

## Known blockers

- PostgreSQL 17 is installed locally but is not currently accepting connections.
- PostgreSQL credentials and database name have not been provided.
- Cloudflare R2 credentials have not been provided. `.env` sets `FRONTEND_URL=https://careerprep-ng.vercel.app`, but the local `.env` defines `RESUME_DISK` and `FRONTEND_URL` twice (the later values win); delete the inactive earlier lines (see the deployment checklist). `.env.example` has no duplicates.
- Paystack credentials, review amount, currency, and callback URL have not been provided.

## Frontend features with no Laravel backend (2026-10-13 audit)

The candidate dashboard (`/dashboard/*`) is fully served by `/api/v1`. Everything else in `careerprep-ng` still reads/writes Prisma tables directly, and none of it exists in Laravel:

- Admin authentication and roles: Laravel `users` has no role/admin column; no admin gate or endpoints exist. The frontend admin uses NextAuth sessions against the Prisma `User` table.
- Admin panel data: resume orders (human-delivered workflow with statuses, assignee, deliverables, status history, internal notes), users (with role toggle), payments, jobs, employer submissions, job reports, testimonials, success stories, career guides, analytics, audit log, settings.
- Public site data: job board, job categories, saved jobs/applications, apply-click tracking, career guides, success stories, testimonials, employer job submissions, sitemap.
- Platform services: email (Resend), notifications, daily cron, DB-backed rate limiting, analytics events.

The Prisma `ResumeOrder`/`Payment` model (human review, statuses PENDING_PAYMENT→DELIVERED) is a different product from Laravel's rule-based `reviews`/`payments`. Removing Prisma requires deciding which survives before porting admin features. Data in Prisma tables is not visible to Laravel and vice versa.

## Known limitations

- Composer network access was unavailable, so a third-party pure-PHP PDF parser could not be installed. The current native extractor intentionally supports common uncompressed and Flate-compressed text streams only; encrypted and unsupported PDFs are recorded as failed.
- Failed reviews can be retried up to `REVIEW_MAX_ATTEMPTS`; beyond that, refunds are manual in the Paystack dashboard.
- Assessment is synchronous and rule-based to avoid queues, Redis, and server-specific workers in V1.

## Current state

The API exposes 21 routes under `/api/v1`. The implementation is verified with in-memory SQLite tests and isolated Paystack HTTP fakes, while Laravel's runtime database configuration resolves to `pgsql`. Runtime PostgreSQL, Paystack, and R2 integrations remain intentionally unverified until real connection details are supplied.
