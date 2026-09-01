# cachewraith/laravel-template-structure

An installer-style Laravel package — in the spirit of Laravel Breeze — that scaffolds a
clean, scalable, **OOAD-based**, **OWASP-hardened**, **versioned-API** architecture into any
Laravel 10, 11, 12 or 13 application.

It is not a runtime framework. One command copies real, working code into your application
and wires it up; after that the package can be removed and your application keeps working.

- **Vendor:** cachewraith (Somonor Hong)
- **Package:** `cachewraith/laravel-template-structure`
- **Namespace:** `Cachewraith\LaravelTemplateStructure`
- **Install command:** `php artisan cachewraith:install`
- **License:** MIT

---

## Installation

```bash
composer require cachewraith/laravel-template-structure
php artisan cachewraith:install
composer dump-autoload
php artisan install:api      # Laravel 11+ — installs Sanctum
php artisan migrate
```

The service provider is auto-discovered, so there is nothing to register by hand.

The installer reads its stub tree straight out of the package: **your project gets the
generated application code and nothing else — no `stubs/` directory left behind.**

### Testing it locally before publishing

Point a throwaway Laravel application at your working copy:

```jsonc
// composer.json of the consuming application
{
    "repositories": [
        { "type": "path", "url": "../smn-laravel-template-structure" }
    ]
}
```

```bash
composer require cachewraith/laravel-template-structure:*
php artisan cachewraith:install
php artisan route:list --path=api      # api/v1/items should be listed
```

### Command options

| Option | Effect |
| --- | --- |
| `--force` | Overwrite scaffolded files that already exist. Without it, existing files are left untouched and reported as skipped. |
| `--publish-stubs` | Also copy the raw stub tree to `stubs/cachewraith-template/` so you can customise the generated code. Off by default. |

The installer is **idempotent**: running it twice creates nothing new and patches nothing
twice. Anything it cannot patch safely — because your application already customised the
file — is printed as a snippet to paste in yourself, rather than being forced.

### Customising the generated code

```bash
php artisan cachewraith:install --publish-stubs
# edit stubs/cachewraith-template/**
php artisan cachewraith:install --force
```

Once `stubs/cachewraith-template/app` exists, the installer prefers it over the packaged
originals, so your edits survive a re-run. Delete the directory to go back to the packaged
stubs.

| Publishing tag | Publishes |
| --- | --- |
| `cachewraith-template-stubs` | The whole stub tree to `stubs/cachewraith-template/` |
| `cachewraith-template-config` | `config/cachewraith-template.php` |

---

## What gets scaffolded

```
app/
├── Exceptions/
│   └── Handler.php                       global exception → JSON error mapping
├── Http/
│   ├── Controllers/Api/
│   │   ├── V1/
│   │   │   ├── AuthController.php        Sanctum token issue/revoke
│   │   │   ├── UserController.php        thin: authorise → service → resource
│   │   │   └── ItemController.php
│   │   └── V2/
│   │       └── ItemController.php        only the endpoints that broke
│   ├── Middleware/
│   │   ├── ForceJsonResponse.php         API errors never render HTML
│   │   ├── RateLimitApi.php              per-bucket ceiling: api / login
│   │   └── SecurityHeaders.php           CSP, framing, sniffing, referrer, HSTS
│   ├── Requests/V1/                      Form Request validation, per endpoint
│   │   ├── LoginRequest.php
│   │   ├── StoreUserRequest.php          real authorize() + allowlist rules
│   │   ├── UpdateUserRequest.php
│   │   ├── StoreItemRequest.php
│   │   └── UpdateItemRequest.php
│   └── Resources/                        API response transformers
│       ├── V1/{UserResource,ItemResource}.php
│       └── V2/ItemResource.php           a deliberate breaking change
├── Models/
│   └── Item.php                          your existing models are left alone
├── Services/
│   ├── UserService.php                   orchestration + audit logging
│   └── ItemService.php                   + the status-transition rules
├── Repositories/
│   ├── Contracts/{User,Item}RepositoryInterface.php
│   ├── UserRepository.php
│   └── ItemRepository.php
├── Policies/
│   ├── UserPolicy.php                    the single source of access rules
│   └── ItemPolicy.php
├── Providers/
│   └── RepositoryServiceProvider.php     contract → implementation bindings
└── Traits/
    └── ApiResponse.php                   consistent success/error JSON shape

config/
├── cachewraith-template.php              feature toggles, versions, limits
└── cors.php                              explicit allow-list, empty by default

database/
├── migrations/2024_01_15_000000_create_items_table.php
├── factories/ItemFactory.php
└── seeders/UserSeeder.php                refuses to run in production

routes/
├── api_v1.php                            v1 routes only
└── api_v2.php                            v2 routes only

tests/
├── Feature/V1/{AuthTest,ItemTest}.php
└── Unit/ItemServiceTest.php              no database, no HTTP

docker/
├── Dockerfile                            multi-stage; Composer never ships
├── Dockerfile.dev                        Xdebug, dev deps, opcache off
├── nginx/default.conf                    front controller only
└── php/php.ini                           expose_php Off, errors to the log
docker-compose.yml                        nginx → php-fpm → postgres + redis
docker-compose.dev.yml                    bind-mounted source, published ports
.dockerignore

docs/
├── ARCHITECTURE.md                       layers, versioning, OWASP mapping
└── API.md                                endpoints, envelope, v1 → v2 diff

CLAUDE.md                                 house rules for Claude Code
AGENTS.md                                 the same, for other coding agents
```

Files your application already owns — `app/Models/User.php`, the users migration,
`UserFactory`, `DatabaseSeeder`, `routes/web.php`, `config/app.php` — are never
overwritten. Existing files are reported as skipped unless you pass `--force`.

The installer also patches, when it can do so safely:

| Laravel 11+ | Laravel 10 |
| --- | --- |
| `bootstrap/app.php` → `withRouting(then: …)` loads `routes/api_{version}.php` | `app/Providers/RouteServiceProvider.php` loads the same files |
| `bootstrap/app.php` → `withMiddleware()` registers the middleware and the `ratelimit.api` alias | `app/Http/Kernel.php` registers them |
| `bootstrap/app.php` → `withExceptions()` registers `App\Exceptions\Handler` | `app/Exceptions/Handler.php` delegates to a generated `ApiExceptionHandler` |
| `bootstrap/providers.php` registers `RepositoryServiceProvider` | `config/app.php` registers it |
| `app/Providers/AppServiceProvider::boot()` gets the global security defaults | same |
| `.env.example` gets the production hardening notes | same |

> **Laravel 10 note.** Laravel 10 already owns `app/Exceptions/Handler.php`, so the mapper
> is installed beside it as `ApiExceptionHandler` and your handler delegates to it. On
> Laravel 11+ the path is free, so it lands there under its own name.

---

## Architecture

### The request path

```
Request
  → ForceJsonResponse      errors stay JSON, never an HTML page
  → RateLimitApi           per-bucket ceiling (api / login)
  → auth:sanctum           who is calling
  → FormRequest            authorize() via Policy + allowlist validation
  → Controller             authorise, delegate, shape
  → Service                the use case, the audit trail
  → Repository (interface) persistence only
  → Eloquent
  ← Resource               explicit output shape
  ← SecurityHeaders        hardening headers, applied globally
```

### OOAD principles this enforces

**Single Responsibility.** A *service* sequences a use case and owns the audit trail. A
*repository* touches the database and nothing else — no authorisation, no logging, no
business rules. If a class needs the word "and" to describe it, it is doing too much.

**Dependency Inversion.** Controllers and services depend on
`App\Repositories\Contracts\*Interface`, never on Eloquent. `RepositoryServiceProvider` is
the one file that names a concrete implementation, which is what makes the persistence
layer swappable and trivially fakeable in tests — `tests/Unit/ItemServiceTest.php` exercises
the status-transition rules against a hand-written in-memory repository, with no database:

```php
$this->app->bind(ItemRepositoryInterface::class, InMemoryItemRepository::class);
```

**Interface Segregation.** One interface per aggregate root. Resist growing a shared
`CrudRepositoryInterface` — every consumer would then depend on methods it never calls.

**Open/Closed.** A released API version is frozen. New behaviour is a new version: a new
route file and new controllers, never an edit to `v1`.

### Choosing a layer

| Where does this code go? | Layer |
| --- | --- |
| "When X happens, do A then B, and record it" | Service |
| "Fetch/store this aggregate" | Repository |
| "May this caller do this?" | Policy (asked by FormRequest/Controller) |
| "What does the client receive?" | Resource |
| "What is a valid payload?" | FormRequest |
| "What shape is every response?" | `App\Traits\ApiResponse` |

### The `Item` reference slice

`Item` is a complete vertical — model, migration, factory, policy, requests, resources in
two versions, service, repository, feature test, unit test. It exists to be read and then
copied for your own aggregate. Delete it when you have your own; nothing else depends on
it.

---

## API versioning

`config/cachewraith-template.php` drives it:

```php
'api_version_prefix' => env('CACHEWRAITH_API_PREFIX', 'api'),
'versions' => ['v1'],
```

Each entry loads `routes/api_{version}.php` under `{prefix}/{version}`, with the `api`
middleware group and the route-name prefix `{version}.` — so `routes/api_v1.php` is served
at `/api/v1/...`, its routes are named `v1.items.index`, and the file itself contains no
`prefix()`, no `middleware('api')` and no `name('v1.')`.

### Adding v2 without breaking v1

`routes/api_v2.php` and `App\Http\Controllers\Api\V2\ItemController` ship as the worked
example. Enable them with one line:

```php
'versions' => ['v1', 'v2'],
```

What the example demonstrates: only `items` changed shape, so only `ItemController` and
`ItemResource` were copied into `V2`. `users`, `login` and `logout` still point at the V1
controllers, and the V2 controller reuses the **V1** FormRequests because the accepted
input did not change. A `V2` directory that mirrors `V1` file-for-file is duplication, not
versioning.

| | v1 | v2 |
| --- | --- | --- |
| `price` | `"19.99"` — decimal string, currency unstated | `{ "amount_minor": 1999, "currency": "USD" }` |
| timestamps | top level | nested under `timestamps` |
| owner | absent | `{ "id": 1, "name": "…" }` |

`/api/v1/*` keeps serving its existing clients, untouched. **Never edit a released version
in place** — that is the Open/Closed principle with a deprecation policy attached.
Versioning is a property of the HTTP boundary; the service, repository, model and policy
underneath have one version.

---

## OWASP Top Ten defaults

Numbering follows the **OWASP Top 10:2025** categories.

| ID | Category | What is baked in |
| --- | --- | --- |
| **A01** | Broken Access Control | Every `FormRequest::authorize()` is a real policy call, never `return true`. Policies read the record's own columns, never a request parameter. Update/delete resolve the target from the **route binding**, never from a body-supplied id (IDOR). Read endpoints call `Gate::authorize` explicitly. `ItemRepositoryInterface` has no unscoped listing method, so there is none for a controller to reach for by accident. `per_page` is clamped. |
| **A02** | Security Misconfiguration | `SecurityHeaders` is registered **globally**, so endpoints added later inherit it: CSP `default-src 'none'`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `Permissions-Policy`, COOP/CORP, `Cache-Control: no-store`. `X-Powered-By` is stripped. `config/cors.php` is an explicit allow-list, empty by default. `php.ini` sets `expose_php=Off` and `display_errors=Off`; nginx sets `server_tokens off`. `.env.example` documents `APP_DEBUG=false`. |
| **A03** | Software Supply Chain Failures | `composer audit --locked` runs during the production image build; wire it into CI too — see [Dependency auditing](#dependency-auditing). |
| **A04** | Cryptographic Failures | Passwords are hashed at the single write path in `UserService` via `Hash::make` (bcrypt/argon2id — never a fast hash). `URL::forceScheme('https')` outside local/testing. HSTS on secure connections. `.env` is in `.dockerignore`, so it never reaches an image layer. Resources never emit a password, hash or token. |
| **A05** | Injection | Eloquent and the query builder only — no `DB::raw`, `whereRaw` or string-concatenated SQL anywhere in the stubs. Validation rules are allowlists, so unknown keys never reach persistence. The nginx config refuses to execute any path but `public/index.php`. |
| **A06** | Insecure Design | `RateLimitApi` gives every route a ceiling and **fails closed** on an unknown bucket name. Pagination is bounded. Item status transitions are enforced as a domain rule, not merely a validation rule. `forceDelete` is closed by default. Models run in strict mode outside production. |
| **A07** | Authentication Failures | Sanctum tokens, named per device, revoked individually on logout. Login is limited per **email + IP**, returns an identical response for unknown-email and wrong-password, and equalises response timing so the endpoint cannot be used to enumerate accounts. Password strength (12 chars, mixed case, numbers, symbols, HIBP-checked) is enforced on write, not on login. `UserSeeder` refuses to run in production and reads its demo password from the environment. |
| **A08** | Software & Data Integrity Failures | No `unserialize()`, no `eval()`, no dynamic class instantiation from request input anywhere in the stubs. The production image ships immutable code with only `storage/` and `bootstrap/cache` writable, and runs as `www-data`, never root. |
| **A09** | Logging & Alerting Failures | Services log create/update/delete and `AuthController` logs login success, failure and misconfiguration — each with actor id and source IP, and **never** a password, hash, token or full payload. |
| **A10** | Mishandling of Exceptional Conditions | `App\Exceptions\Handler` maps every failure to one envelope and **fails closed**: an unrecognised exception is a generic 500, with class/file/line attached only when `APP_DEBUG` is on. `ForceJsonResponse` stops error paths from rendering HTML. |

XXE is not addressed because the scaffolding parses no XML — the API is JSON only. Output
escaping is not addressed for the same reason: there are no Blade views, and `Resource`
classes control the exact response shape.

The scaffolded feature tests assert these properties rather than just the happy path — that
an unknown email and a wrong password are byte-identical, that a stranger gets a 403 on
someone else's item, that a client-supplied `user_id` is ignored, and that every response
carries the hardening headers.

### Uniform response envelope

Success and failure share a shape, so a client parses one thing:

```json
{
    "success": false,
    "message": "The given data was invalid.",
    "errors": { "email": ["The email has already been taken."] },
    "code": 422
}
```

### Dependency auditing

`composer audit` checks your installed dependency tree against the PHP Security Advisories
Database. Run it in CI so a newly disclosed CVE fails the build:

```yaml
# .github/workflows/ci.yml
- name: Audit dependencies
  run: composer audit --locked --no-interaction
```

Commit `composer.lock`, keep dependency updates small and frequent, and prefer
`composer update <package>` over a blanket update you cannot review.

---

## Docker

```bash
cp .env.example .env          # then set DB_PASSWORD and REDIS_PASSWORD
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
docker compose exec app php artisan migrate --seed
```

Production is `docker compose up -d` on its own. The development overlay is opt-in and must
stay that way: it bind-mounts the source, turns opcache off, enables Xdebug and publishes
the database port.

`docker-compose.yml` deliberately publishes **no** database or cache port — they are
reachable only from the internal network. Both passwords are required (`${DB_PASSWORD:?…}`),
so the stack refuses to start with a blank credential rather than starting insecurely.

---

## After installing

1. **Install Sanctum** (Laravel 11+): `php artisan install:api`
2. **Add the trait** to `App\Models\User`:

   ```php
   use Laravel\Sanctum\HasApiTokens;

   class User extends Authenticatable
   {
       use HasApiTokens, HasFactory, Notifiable;
   }
   ```
3. **Run the migrations:** `php artisan migrate` (creates the `items` table)
4. **Verify the routes:** `php artisan route:list --path=api`

   ```
   GET|HEAD   api/v1/health
   POST       api/v1/login
   POST       api/v1/logout
   GET|HEAD   api/v1/users
   …
   GET|HEAD   api/v1/items
   POST       api/v1/items
   GET|HEAD   api/v1/items/{item}
   PUT|PATCH  api/v1/items/{item}
   DELETE     api/v1/items/{item}
   ```
5. **Run the tests:** `php artisan test`
6. **Review the policies.** The generated rules are ownership-based, which is all a stock
   `User` model can express. Tighten them to your roles before shipping — and tighten
   rather than loosen: `UserPolicy::create` currently allows any authenticated user, which
   is right for an admin-only console and wrong for a public API.
7. **Review `config/cors.php`.** It is empty by default; name your browser origins in
   `CORS_ALLOWED_ORIGINS`.
8. **Read `docs/ARCHITECTURE.md`**, then copy the `Item` slice for your own aggregate and
   delete it.

### Where did the global security defaults go?

`URL::forceScheme`, Eloquent strict mode and the production destructive-command guard are
patched into your own `app/Providers/AppServiceProvider::boot()`, marked with a
`cachewraith-template` comment block. That is where a reviewer looks for them, and it is
one fewer generated provider to explain. If the installer cannot patch the file safely it
prints the block for you to paste.

Rate limiting is **not** a provider concern here: `RateLimitApi` is a route middleware, so
each route states its own budget (`->middleware('ratelimit.api:login')`) instead of
depending on a limiter registered invisibly elsewhere.

Policies are found by Laravel's naming convention (`App\Models\Item` →
`App\Policies\ItemPolicy`). If you move or rename either side, pair them explicitly with
`Gate::policy()` — a policy that silently stops being found fails *open*.

## Requirements

- PHP 8.2+
- Laravel 10, 11, 12 or 13

> **Laravel 10 is end-of-life.** The fallback wiring for it is implemented and tested, but
> Composer now blocks every 10.x release because of published security advisories (OWASP
> A03). Treat Laravel 10 support here as a migration aid, not a destination.

Optional: `laravel/sanctum` (the auth routes register only when installed).

## License

MIT. See [LICENSE](LICENSE).
