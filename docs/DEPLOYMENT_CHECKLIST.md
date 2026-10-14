# CareerPrep API Production Deployment Checklist

**Audience:** whoever deploys the Laravel API that serves the Vercel frontend.

**What has been verified:**
- Everything below was checked against the code on 2026-10-13.
- The automated suite runs on in-memory SQLite with faked Paystack HTTP and faked storage.
- **PostgreSQL, Cloudflare R2 and live Paystack have not yet been exercised by anyone.** The `Verify` steps are the first real test of each, so do them on staging before production.

Recommended layout:

| Environment | API | Frontend | Database | Paystack | Storage |
|---|---|---|---|---|---|
| Production | `APP_ENV=production` | production domain only | production PostgreSQL | **live** keys | production R2 bucket |
| Staging | `APP_ENV=staging` | staging domain plus Vercel previews | separate PostgreSQL | **test** keys | separate R2 bucket |

Vercel preview deployments should never call the production API. CORS ignores the preview settings when `APP_ENV=production`.

---

## 1. Runtime and application

- [ ] PHP 8.3 or newer, with these extensions:
  - `pdo_pgsql`
  - `mbstring`
  - `openssl`
  - `zlib` and `iconv` (required by `smalot/pdfparser` to read compressed PDFs and font encodings)
  - `curl`
  - `fileinfo`
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] Set `APP_ENV=production` and `APP_DEBUG=false`. With debug on, error responses leak stack traces.
- [ ] Set `APP_KEY`. Generate it once and keep it stable across deploys.
- [ ] Set `APP_URL=https://<api-domain>`.
- [ ] PHP upload limits: `upload_max_filesize >= 6M` and `post_max_size >= 8M`. Resumes may be up to 5 MB, plus multipart overhead.
- [ ] Each variable appears **once** in the server's environment. In a `.env` file a later duplicate silently overrides the earlier one.
  - The local development `.env` currently defines `RESUME_DISK` and `FRONTEND_URL` twice: lines 38 and 83, and lines 73 and 93. Delete the earlier, inactive lines (38 and 73).
- [ ] After setting the environment, run `php artisan config:cache`, `php artisan route:cache` and `php artisan event:cache`. Config caching was verified to work with the custom `cors`, `reviews` and `sanctum` config.
- [ ] No queue worker or Redis is needed. Review processing is synchronous. Cache, sessions and rate limits use the database tables created by the migrations.

## 2. HTTPS and proxies

- [ ] Serve the API only over HTTPS and redirect plain HTTP. Enable HSTS at the proxy or load balancer.
- [ ] **Configure trusted proxies for your host.**
  - `bootstrap/app.php` does not call `$middleware->trustProxies(...)` yet.
  - Behind a load balancer, `request()->ip()` would return the proxy's IP. All users would then share the login (5/min), registration (5/min) and guest API (60/min) rate limits.
  - Trust only your provider's proxy range, or `'*'` only if the app is unreachable except through that proxy.
- [ ] `PAYSTACK_CALLBACK_URL` and `FRONTEND_URL` both use `https://`.

## 3. PostgreSQL and migrations

- [ ] Create a dedicated database and a least-privilege user that owns that schema only.
- [ ] Set `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`.
  - For a managed or remote database, also set `DB_SSLMODE=require` (or `verify-full`). The default is `prefer`, which falls back to an unencrypted connection.
- [ ] **Back up before every migration run.** Then run `php artisan migrate --force` and check `php artisan migrate:status`.
  - The latest migration, `2026_10_13_173500_add_attempts_to_reviews_table`, only adds a column with a default, so it is safe on existing data. It can be rolled back with `php artisan migrate:rollback --step=1`.
- [ ] **Verify (not yet done anywhere):**
  - Register, upload, pay with test keys, and review end to end against PostgreSQL.
  - Check that two simultaneous `POST /reviews` calls for one payment produce exactly one review. The tests cannot exercise PostgreSQL row locks because they run on SQLite.

- [ ] Create the first admin after migrating: run `php artisan careerprep:make-admin <email>` on the server. It creates the account (name and password at hidden prompts), or promotes an existing one. Use `--revoke` to remove access (this also signs that user out everywhere). Keep the number of admins small, and review `GET /api/v1/admin/audit-log` periodically.

## 4. Private resume storage (Cloudflare R2)

- [ ] Create a dedicated bucket (and a separate one for staging) and keep it **private**:
  - no public bucket access
  - no `r2.dev` subdomain
  - no custom public domain
- [ ] Create an R2 API token scoped to **this bucket only**, with Object Read & Write permission.
- [ ] Set these variables:
  - `RESUME_DISK=r2`
  - `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY` and `R2_BUCKET`
  - `R2_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com`
  - `R2_REGION=auto`
- [ ] Never leave `RESUME_DISK=local` on hosts with ephemeral or shared disks. Uploaded resumes would be lost or mixed.
- [ ] **Verify (not yet done anywhere):**
  - Uploading a PDF creates an object under `users/{id}/`.
  - The extraction status in the response is `succeeded` for a text PDF.
  - Deleting an unpaid resume removes the object.
  - An unauthenticated request to the object's URL is refused (HTTP 400/403).
  - If uploads fail with an addressing error, try `R2_USE_PATH_STYLE_ENDPOINT=true`.

## 5. Paystack

- [ ] Production uses the **live** secret key in `PAYSTACK_SECRET_KEY`. Staging uses the test key. The key is only ever read server-side.
- [ ] `PAYSTACK_REVIEW_AMOUNT` is set in the **smallest unit** (kobo): ₦6,000 is `600000`. `PAYSTACK_CURRENCY=NGN`.
  - Confirm that `GET /api/v1/pricing` returns the expected amount, currency and `"available": true`.
- [ ] `PAYSTACK_CALLBACK_URL=https://<frontend-domain>/dashboard/payments/callback`. This is the existing frontend callback page.
- [ ] In the Paystack dashboard, under Settings → API Keys & Webhooks, set the webhook URL to `https://<api-domain>/api/v1/paystack/webhook` for both the live and test modes.
  - Webhooks are verified with HMAC-SHA512 using the same secret key, so **rotating the key also changes webhook signatures**. Update both together.
  - Optionally restrict the webhook route to Paystack's published source IPs at the proxy.
- [ ] **Verify on staging with test keys (not yet done anywhere):**
  - [ ] Successful card payment: the callback page verifies the payment, the review starts and completes.
  - [ ] Close the tab after paying. `GET /api/v1/payments` still shows `entitlement_status: "available"`, and the review can be started later.
  - [ ] A webhook delivery succeeds (Paystack dashboard shows 200), and a replayed delivery changes nothing.
  - [ ] An abandoned or failed payment does not unlock a review.
  - [ ] Test a review failure by paying for a readable PDF and forcing a failure on staging. Then check that `can_retry` is true and that `POST /reviews/{id}/retry` completes it.
- [ ] Refunds are manual. A review that has exhausted `REVIEW_MAX_ATTEMPTS` (default 3) without completing shows `can_retry: false`. Support refunds it in the Paystack dashboard. There is no automatic refund path.

## 6. Authentication tokens

- [ ] `SANCTUM_EXPIRATION` is set in minutes. The default is `10080` (7 days); `0` disables expiry.
  - Expiry is measured from each token's `created_at`. **Tokens issued before this setting existed expire by age on deploy.** For example, any token older than 7 days stops working immediately, and the frontend sends that user back to sign in after a 401.
  - Login and registration responses include `expires_at`.
- [ ] Optional cron entry: `* * * * * php /path/to/artisan schedule:run`. It runs `sanctum:prune-expired --hours=24` daily. Without it, expired tokens are still rejected but their rows accumulate.
- [ ] To force everyone to sign in again (for example after a suspected leak), delete all rows from `personal_access_tokens`.

## 7. Frontend CORS

- [ ] Production: `FRONTEND_URL` is the exact production origin, for example `https://careerprep.ng`, or a comma-separated list for apex and `www`. The local `.env` currently uses `https://careerprep-ng.vercel.app`; confirm whether that is the real production domain.
- [ ] Production: leave `VERCEL_PREVIEW_PROJECT` and `VERCEL_PREVIEW_SCOPE` empty. They are ignored there anyway.
- [ ] Staging: set `VERCEL_PREVIEW_PROJECT=<vercel project name>` and `VERCEL_PREVIEW_SCOPE=<vercel team slug>`.
  - This allows only `https://<project>-<id>-<scope>.vercel.app`.
  - Vercel shortens very long branch preview hostnames; those URLs won't match.
  - Point preview builds' `NEXT_PUBLIC_API_URL` at the staging API.
- [ ] The frontend sets `NEXT_PUBLIC_API_URL=https://<api-domain>` per environment.
- [ ] Credentials stay disabled (`supports_credentials=false`). The API is bearer-token only.

## 8. Backups and recovery

- [ ] PostgreSQL has automated daily backups with point-in-time recovery if your provider offers it. Keep at least 7 days, and do **one test restore** before launch.
- [ ] R2 has no automatic backups. Decide whether resume PDFs need a copy, for example a scheduled sync to a second private bucket. A database restore can reference objects that were deleted since the backup.
- [ ] Store secrets (`APP_KEY`, database, R2 and Paystack credentials) in the host's secret manager, not in the repository. `.env` and `.env.*` are git-ignored.

## 9. Post-deploy smoke test

1. `GET /up` returns 200.
2. `GET /api/v1/pricing` returns the correct price and `available: true`.
3. Register a test account and confirm `expires_at` is about 7 days ahead.
4. Upload a text-based PDF and confirm `extraction_status: succeeded`.
5. Pay with the smallest live amount or on staging, then verify, review and download.
6. A request with an expired or bogus token returns 401 `{"message":"Unauthenticated."}`.
7. A request from an unlisted `Origin` receives no `Access-Control-Allow-Origin` header.
8. Check the logs for errors, and confirm that no PII or secrets were logged.
