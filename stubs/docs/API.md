# API

Base URL: `/{prefix}/{version}` — by default `/api/v1`. The prefix comes from
`CACHEWRAITH_API_PREFIX`; the enabled versions from
`config/cachewraith-template.php`.

## Conventions

**Content type.** Requests and responses are `application/json`. The `Accept`
header is forced server-side, so a client that forgets it still gets JSON
errors rather than an HTML page.

**Authentication.** Bearer tokens (Laravel Sanctum).

```
Authorization: Bearer 1|abcdef...
```

**One envelope, always.** Success and failure share a shape, so a client parses
one thing:

```jsonc
// success
{ "success": true,  "message": "OK",  "data": { }, "errors": null, "code": 200 }

// failure
{ "success": false, "message": "The given data was invalid.",
  "errors": { "email": ["The email has already been taken."] }, "code": 422 }
```

A paginated listing adds a `pagination` block:

```json
{
  "success": true,
  "message": "OK",
  "data": [],
  "pagination": { "current_page": 1, "per_page": 15, "total": 42, "last_page": 3 },
  "errors": null,
  "code": 200
}
```

**Status codes.**

| Code | When |
| --- | --- |
| 200 | Read, update, delete succeeded |
| 201 | Created |
| 401 | No token, or an invalid one |
| 403 | Authenticated, but the policy said no |
| 404 | No such record — or one you may not know exists |
| 405 | Wrong method for the route |
| 422 | Validation failed, or a domain rule refused the transition |
| 429 | Rate limit exceeded; see `Retry-After` |
| 500 | Unhandled failure. Details are logged, never returned |

**Rate limits.** Every response carries `X-RateLimit-Limit` and
`X-RateLimit-Remaining`. A 429 also carries `Retry-After` in seconds. Defaults:
60 requests/minute for the `api` bucket, 5/minute for `login`, keyed on
email + IP.

**Pagination.** `?per_page=` is clamped to
`cachewraith-template.pagination.max_per_page` (100 by default). Asking for
more is not an error — you just get the maximum.

---

## Endpoints

### `GET /api/v1/health`

Unauthenticated liveness check.

```json
{ "success": true, "message": "ok", "errors": null, "code": 200 }
```

### `POST /api/v1/login`

Exchange credentials for a token. Rate limited to 5 attempts per minute per
email + IP.

```json
{
  "email": "demo@example.test",
  "password": "Correct-Horse-Battery-1!",
  "device_name": "iphone-15"
}
```

**201/200**

```json
{
  "success": true,
  "message": "Authenticated.",
  "data": { "token": "1|abc...", "user": { "id": 1, "name": "Demo User" } },
  "errors": null,
  "code": 200
}
```

**422** — returned identically for a wrong password and an unknown email. This
is deliberate: the endpoint must not tell an attacker which accounts exist.

### `POST /api/v1/logout`

Revokes **only** the token that made the call. Other devices stay signed in.

---

### Users

| Method | Path | Notes |
| --- | --- | --- |
| `GET` | `/api/v1/users` | Paginated. `email` is present only for callers allowed to view that user in full. |
| `POST` | `/api/v1/users` | Requires `UserPolicy::create`. |
| `GET` | `/api/v1/users/{user}` | Owner only, by default. |
| `PATCH` | `/api/v1/users/{user}` | Partial update; only validated keys are applied. |
| `DELETE` | `/api/v1/users/{user}` | Owner only, by default. |

`POST /api/v1/users`:

```json
{
  "name": "Ada Lovelace",
  "email": "ada@example.test",
  "password": "Correct-Horse-Battery-1!",
  "password_confirmation": "Correct-Horse-Battery-1!"
}
```

Passwords must be at least 12 characters with mixed case, a number and a
symbol, and are checked against the Have I Been Pwned range API. No response
ever contains a password or its hash.

---

### Items

Scoped to the authenticated caller. There is no owner parameter — the listing
cannot be pointed at someone else's records.

| Method | Path | Notes |
| --- | --- | --- |
| `GET` | `/api/v1/items` | `?status=draft\|published\|archived`, `?per_page=` |
| `POST` | `/api/v1/items` | Ownership is taken from the token; a `user_id` in the body is ignored |
| `GET` | `/api/v1/items/{item}` | 403 unless you own it |
| `PATCH` | `/api/v1/items/{item}` | Archived items are read-only |
| `DELETE` | `/api/v1/items/{item}` | |

`POST /api/v1/items`:

```json
{
  "name": "Reference item",
  "description": "Optional, up to 5000 characters.",
  "price_cents": 1999,
  "status": "draft"
}
```

**Status transitions.** Validation accepts the vocabulary; the domain enforces
the grammar. `draft → published`, `draft → archived`, `published → archived`.
Anything else is a 422 — and an archived item is refused at the policy with a
403 before it gets that far.

---

## v1 → v2

`v2` exists to demonstrate a breaking change without one. Only `items` changed
shape; `users`, `login` and `logout` are the same controllers behind
`/api/v2/`.

| | v1 | v2 |
| --- | --- | --- |
| `price` | `"19.99"` — decimal string, currency unstated | `{ "amount_minor": 1999, "currency": "USD" }` |
| timestamps | `created_at`, `updated_at` at the top level | nested under `timestamps` |
| owner | absent | `{ "id": 1, "name": "Demo User" }` |

v1 item:

```json
{
  "id": 1,
  "name": "Reference item",
  "description": null,
  "price": "19.99",
  "status": "published",
  "created_at": "2026-01-15T09:00:00+00:00",
  "updated_at": "2026-01-15T09:00:00+00:00"
}
```

v2 item:

```json
{
  "id": 1,
  "name": "Reference item",
  "description": null,
  "price": { "amount_minor": 1999, "currency": "USD" },
  "status": "published",
  "owner": { "id": 1, "name": "Demo User" },
  "timestamps": {
    "created_at": "2026-01-15T09:00:00+00:00",
    "updated_at": "2026-01-15T09:00:00+00:00"
  }
}
```

Enable it by adding `'v2'` to the `versions` array in
`config/cachewraith-template.php`. v1 keeps working, unchanged, for as long as
you support it.

## CORS

`config/cors.php` is an explicit allow-list and is **empty by default**. Browser
clients need their origins named:

```
CORS_ALLOWED_ORIGINS="https://app.example.com,https://admin.example.com"
```

Server-side clients need nothing. CORS is a browser convenience, not an
authorisation boundary — the policies are what actually protect a record.
