# Image API

Laravel 13 API for authenticated PNG/JPEG uploads, asynchronous WebP conversion, global content deduplication, private downloads, and safe storage cleanup.

## Architecture

The data model deliberately separates physical content from user ownership:

- `image_assets` represents one physical source/content hash and its processing result. `sha256` has a database unique index and is the deduplication key.
- `image_uploads` represents a user's upload/reference, keeps the original filename, and has its own ULID exposed by the API.
- A single asset may therefore have many references belonging to different users. Authorization always applies to the reference, never directly to a shared asset.

Both tables use constraints and indexes suited to PostgreSQL. External image IDs are ULIDs, so sequential database IDs are not exposed.

### Upload flow

1. Sanctum authenticates the request and the upload rate limiter runs.
2. `StoreImageRequest` enforces an exact 5 MiB application limit. PHP accepts up to 6 MiB so Laravel can return JSON validation errors. The validator uses `finfo`, `getimagesize`, `jpeginfo -c`, and a GD decode to accept only genuine, structurally valid PNG/JPEG content.
3. Before GD decoding, dimensions are capped at 5,000 pixels per side and 6 megapixels. The validator estimates a worst-case two-buffer GD allocation at 8 bytes/pixel, reserves another 16 MiB, and accepts it only within 80% of the PHP memory limit.
4. SHA-256 is calculated with `hash_update_stream`; the request file is copied with a stream to private temporary storage.
5. Inside a database transaction, `insertOrIgnore` plus the unique `image_assets.sha256` index handles concurrent duplicate uploads. The asset row is locked while a separate user reference is created.
6. A `ProcessImageAsset` job is dispatched with `afterCommit()`. New/pending work and a re-upload of failed content return `202 Accepted`; a reference to an already-ready asset returns `201 Created` immediately.
7. The Redis worker decodes from disk, applies JPEG EXIF orientation when present, writes WebP at quality 85, calculates the representation hash, and moves it to a content-addressed private path.
8. Only after the final file exists and references are rechecked under the asset row lock does the worker mark the asset `ready`. The source is removed after success and best-effort after terminal failure.

The job is unique per asset for ten minutes and uses a runtime overlap lock with a 300-second expiry. It is also idempotent at the database level: execution against a `ready` asset returns without rewriting it. It has three attempts, effective retry delays of `10` and `60` seconds, a 120-second timeout, Redis `retry_after=180`, and a terminal `failed` status/reason.

## Storage and deduplication

Files are never written under `public/`. The local disk stores them under:

```text
storage/app/private/images/tmp/{ulid}.source
storage/app/private/images/assets/{hash[0:2]}/{hash[2:4]}/{sha256}.webp
```

`app` and `queue` share the Docker `image_storage` volume. Downloads pass through the authorized controller and include `Content-Type`, `Content-Length`, a processed-content `ETag`, and `Cache-Control: private`.

Concurrent uploads rely on PostgreSQL's unique index as the final race-condition guard. `INSERT ... ON CONFLICT DO NOTHING` selects and locks the winner, so only one physical asset and one processing job are created. Deleting the last reference marks the asset orphaned but does not remove its row or files in the request. Cleanup removes it after the grace period while holding the asset row lock and rechecking references. A duplicate uploaded before cleanup clears `orphaned_at` and safely reuses the asset.

## Fresh installation with Docker

Requirements: Docker Engine and Docker Compose.

```bash
cp .env.example .env
docker compose build
docker compose run --rm app php artisan key:generate
docker compose up -d
docker compose exec app php artisan migrate
docker compose ps
```

The application is available at <http://localhost:8020>. PostgreSQL and Redis healthchecks gate both `app` and `queue`. The queue worker is already part of Compose and runs:

```bash
php artisan queue:work redis --sleep=1 --tries=3 --timeout=120
```

Redis uses AOF persistence in its own named volume. `REDIS_QUEUE_RETRY_AFTER=180` is intentionally greater than the worker timeout.

For a previously initialized PostgreSQL volume, create the isolated integration database once if it is absent:

```bash
docker compose exec postgres sh /docker-entrypoint-initdb.d/10-create-test-database.sh
```

On a fresh volume the same script runs automatically.

## API endpoints

| Method | Endpoint | Auth | Result |
| --- | --- | --- | --- |
| `POST` | `/api/register` | No | Create user and Sanctum token |
| `POST` | `/api/login` | No | Issue Sanctum token |
| `POST` | `/api/logout` | Bearer | Revoke current token |
| `GET` | `/api/user` | Bearer | Current user |
| `POST` | `/api/images` | Bearer | Upload one `image` multipart field |
| `GET` | `/api/images` | Bearer | Paginated list of own references |
| `GET` | `/api/images/{image}` | Bearer | Download own ready WebP |
| `DELETE` | `/api/images/{image}` | Bearer | Delete own reference |

Access to another user's ULID returns `404`, including delete, so resource existence is not disclosed. Authentication failures return `401`; validation failures use Laravel's JSON `422` shape. Register is limited to 5 requests/minute per IP, login to 10/minute per email/IP key, and upload to 30/minute per user.

API-issued Sanctum tokens expire after `SANCTUM_TOKEN_EXPIRATION` minutes (1440 by default). Their lifetime and minimal abilities are defined under `api_tokens` in `config/auth.php`: `user:read`, `images:read`, `images:write`, and `tokens:revoke`. Routes enforce the corresponding ability and return `403` for an authenticated token without it.

The full OpenAPI 3 contract is in [`docs/openapi.yaml`](docs/openapi.yaml).

## curl example

Register and save the returned token:

```bash
curl -sS -X POST http://localhost:8020/api/register \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"name":"Demo","email":"demo@example.com","password":"Password123","password_confirmation":"Password123"}'
```

```bash
TOKEN='paste-token-here'

curl -sS -X POST http://localhost:8020/api/images \
  -H 'Accept: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -F 'image=@/absolute/path/photo.jpg'

curl -sS http://localhost:8020/api/images \
  -H 'Accept: application/json' \
  -H "Authorization: Bearer $TOKEN"

IMAGE_ID='paste-image-id-here'

curl -sS -o image.webp http://localhost:8020/api/images/$IMAGE_ID \
  -H "Authorization: Bearer $TOKEN"

curl -i -X DELETE http://localhost:8020/api/images/$IMAGE_ID \
  -H 'Accept: application/json' \
  -H "Authorization: Bearer $TOKEN"
```

## Response states

Upload/list resources include `pending`, `processing`, `ready`, or `failed`.

```json
{
  "data": {
    "id": "01K...",
    "original_filename": "photo.jpg",
    "original_mime": "image/jpeg",
    "original_size": 12345,
    "sha256": "...",
    "status": "pending",
    "dimensions": {"width": 800, "height": 600},
    "processed": null,
    "download_url": null,
    "failure_message": null
  }
}
```

Downloading `pending`/`processing` content returns `409` with code `image_not_ready`. A terminal processing failure returns `409` with code `image_processing_failed`; internal failure details remain stored for operations but are not exposed publicly.

Lists use Laravel pagination with `data`, `links`, and `meta`. Successful delete returns `204 No Content`.

## Testing and quality checks

Fast feature/unit suite (forced SQLite in-memory configuration, fake storage/queue where appropriate):

```bash
docker compose exec app php artisan test
```

Real PostgreSQL + Redis integration flow:

```bash
docker compose exec postgres sh /docker-entrypoint-initdb.d/10-create-test-database.sh
docker compose exec -e APP_ENV=testing -e DB_DATABASE=image_api_test \
  app php artisan migrate:fresh --force
docker compose exec app ./vendor/bin/phpunit --configuration=phpunit.integration.xml
```

The integration suite asserts `current_database() = image_api_test`, tests the PostgreSQL ready-asset constraint, and runs the Redis-backed image flow. All PHPUnit database variables use `force=true`, and an additional fail-fast guard refuses any destructive PostgreSQL suite unless the database name ends in `_test`.

After rebuilding and starting the complete stack, run the real Nginx/FPM multipart boundary test:

```bash
docker compose exec app ./vendor/bin/phpunit --configuration=phpunit.http.xml
```

It sends valid PNG files of exactly 5 MiB and 5 MiB + 1 byte through Nginx and PHP. The first is accepted; the second must return Laravel JSON `422`.

```bash
docker compose exec app ./vendor/bin/pint
docker compose exec app composer validate --strict
docker compose exec app php artisan route:list --path=api
```

## Cleanup

Preview candidates older than the configured 24-hour grace period:

```bash
docker compose exec app php artisan images:cleanup --dry-run
```

Delete safe candidates, including delayed orphan assets:

```bash
docker compose exec app php artisan images:cleanup
```

`--hours=N` must be a non-negative integer and overrides the grace period. The command removes unreferenced temporary files, delayed database assets with no user references, and physical asset files absent from the database. Before every deletion it repeats the database check; database assets are locked and their files are removed before the row is deleted.

Preview or recover stale referenced `pending`/`processing` assets (15 minutes by default):

```bash
docker compose exec app php artisan images:recover --dry-run
docker compose exec app php artisan images:recover
```

`--minutes=N` overrides the age. Recovery is idempotent: it row-locks each stale asset, refreshes its timestamp/state, and dispatches once after commit. A stale asset without references, or without its source, is marked failed instead of being requeued.

In production, schedule the command periodically and alert on growing `failed`/stuck-processing counts.

## Production scaling notes

- Run multiple stateless FPM instances and many Redis workers; `ShouldBeUnique`, `WithoutOverlapping`, database uniqueness, row locks, and recovery protect normal duplicate delivery and stale work.
- Move private assets to an object store for multi-host deployments. Keep content-addressed keys and use authorized short-lived delivery URLs or an authenticated proxy.
- Use managed PostgreSQL/Redis with monitoring, backups, queue-depth alerts, failed-job alerts, and worker autoscaling.
- For very large throughput, add direct-to-quarantine object uploads, malware scanning, an outbox for guaranteed post-commit publication, and dedicated image-processing worker pools.
- Current validation decodes the bounded image once during HTTP validation to reject corrupt content. The 5,000-dimension, 6-megapixel and runtime memory-budget checks happen before decode; transformation remains asynchronous.
- WebP generation uses GD and quality 85. PNG alpha is preserved; animated input is not accepted.
