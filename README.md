# cachewraith/laravel-template-structure

An installer-style Laravel package — in the spirit of Laravel Breeze — that scaffolds a
clean, scalable, **OOAD-based**, **OWASP-hardened** architecture into any Laravel 10, 11, 12
or 13 application, behind a **versioned JSON API**, a **server-rendered Blade UI**, or both.

It is not a runtime framework. One command copies real, working code into your application
and wires it up; after that the package can be removed and your application keeps working.

Not writing an API? `--stack=web` scaffolds the Blade half on its own — session
authentication, forms, views, no build step — on the same services, repositories and
policies. Installing both front doors costs a controller, two form requests and some
templates, and **no business logic at all**. That is the whole argument for the layering.

- **Vendor:** cachewraith (Somonor Hong)
- **Package:** `cachewraith/laravel-template-structure`
- **Namespace:** `Cachewraith\LaravelTemplateStructure`
- **Install command:** `php artisan cachewraith:install`
- **License:** MIT

---

## Installation

```bash
composer require cachewraith/laravel-template-structure
php artisan cachewraith:install          # asks which front door; defaults to api
composer dump-autoload
php artisan install:api                  # Laravel 11+ — installs Sanctum (API stack only)
php artisan migrate
```

Or name the stack outright and skip the question:

```bash
php artisan cachewraith:install --stack=api    # versioned JSON API (the default)
php artisan cachewraith:install --stack=web    # Blade UI, session auth, no build step
php artisan cachewraith:install --stack=both   # one set of layers, two front doors
```

The service provider is auto-discovered, so there is nothing to register by hand.

### Choosing a stack

| | `--stack=api` | `--stack=web` | `--stack=both` |
| --- | --- | --- | --- |
| Front door | `routes/api_v{n}.php` | `routes/web_ui.php` | both |
| Controllers | `Http/Controllers/Api/V{n}` | `Http/Controllers/Web` | both |
| Auth | Sanctum tokens | sessions + CSRF | both |
| Output | `Http/Resources` + JSON envelope | Blade views | both |
| CSP profile | `default-src 'none'` | `default-src 'self'` | picked per request |
| Needs `laravel/sanctum` | yes | no | yes |
| Services · repositories · policies · models | **shared — identical in all three** | | |

The stack only ever changes the transport. Everything that decides *what may happen* is
installed either way, because a rule enforced in two places is a rule that will diverge.

Re-running with a different `--stack` adds the missing half; it never removes the one you
already have. Where the wiring cannot be extended safely — an existing `then:` closure in
`bootstrap/app.php`, say — the installer prints the snippet instead of guessing.

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
php artisan cachewraith:install --stack=both
php artisan route:list --path=api      # api/v1/items should be listed
php artisan route:list --path=items    # the Blade pages should be listed
```

### Command options

| Option | Effect |
| --- | --- |
| `--stack=api\|web\|both` | Which front door to scaffold. Asked interactively when omitted; defaults to `api` under `--no-interaction`, so existing scripts keep their behaviour. |
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

### Shared by every stack

The part that decides *what may happen*. Installed identically whichever front door you
chose, because duplicating it per transport is how the two halves start disagreeing.

```
app/
├── Exceptions/
│   └── Handler.php                       global exception → JSON error mapping
├── Http/Middleware/
│   ├── RateLimitApi.php                  per-bucket ceiling: api / login
│   └── SecurityHeaders.php               CSP, framing, sniffing, referrer, HSTS
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
└── Providers/
    └── RepositoryServiceProvider.php     contract → implementation bindings

config/
├── cachewraith-template.php              feature toggles, versions, limits, header profiles
└── cors.php                              explicit allow-list, empty by default

database/
├── migrations/2024_01_15_000000_create_items_table.php
├── factories/ItemFactory.php
└── seeders/UserSeeder.php                refuses to run in production

tests/Unit/ItemServiceTest.php            no database, no HTTP

docker/
├── Dockerfile                            multi-stage; Composer never ships
├── Dockerfile.dev                        Xdebug, dev deps, opcache off
├── nginx/default.conf                    front controller only
└── php/php.ini                           expose_php Off, errors to the log
docker-compose.yml                        nginx → php-fpm → postgres + redis
docker-compose.dev.yml                    bind-mounted source, published ports
.dockerignore

docs/ARCHITECTURE.md                      layers, versioning, OWASP mapping
CLAUDE.md                                 house rules for Claude Code
AGENTS.md                                 the same, for other coding agents
```

### `--stack=api` adds

```
app/
├── Http/
│   ├── Controllers/Api/
│   │   ├── V1/
│   │   │   ├── AuthController.php        Sanctum token issue/revoke
│   │   │   ├── UserController.php        thin: authorise → service → resource
│   │   │   └── ItemController.php
│   │   └── V2/
│   │       └── ItemController.php        only the endpoints that broke
│   ├── Middleware/ForceJsonResponse.php  API errors never render HTML
│   ├── Requests/V1/                      Form Request validation, per endpoint
│   │   ├── LoginRequest.php
│   │   ├── StoreUserRequest.php          real authorize() + allowlist rules
│   │   ├── UpdateUserRequest.php
│   │   ├── StoreItemRequest.php
│   │   └── UpdateItemRequest.php
│   └── Resources/                        API response transformers
│       ├── V1/{UserResource,ItemResource}.php
│       └── V2/ItemResource.php           a deliberate breaking change
└── Traits/
    └── ApiResponse.php                   consistent success/error JSON shape

routes/
├── api_v1.php                            v1 routes only
└── api_v2.php                            v2 routes only

tests/Feature/V1/{AuthTest,ItemTest}.php
docs/API.md                               endpoints, envelope, v1 → v2 diff
```

### `--stack=web` adds

```
app/
├── Http/
│   ├── Controllers/Web/
│   │   ├── Auth/LoginController.php      session auth — skipped if you have Breeze etc.
│   │   ├── DashboardController.php
│   │   └── ItemController.php            the same seven decisions as the API's
│   └── Requests/Web/
│       ├── LoginRequest.php
│       ├── StoreItemRequest.php          a sibling of V1's, not a reuse
│       └── UpdateItemRequest.php
└── Support/
    └── Money.php                         decimals ↔ integer minor units, in one place

resources/views/
├── layouts/app.blade.php                 the one layout; no {!! !!} anywhere
├── partials/{alerts,field-error,pagination}.blade.php
├── auth/login.blade.php
├── dashboard.blade.php
├── items/{index,create,edit,show,_form}.blade.php
└── errors/{403,404,419,429,500,503}.blade.php   standalone; touch no session

public/vendor/cachewraith/app.css         no npm, no Vite, no CDN
routes/web_ui.php                         your routes/web.php is never touched

tests/
├── Feature/Web/{LoginTest,ItemPageTest}.php
└── Unit/MoneyTest.php
docs/WEB.md                               pages, session hardening, CSP, styling
```

Files your application already owns — `app/Models/User.php`, the users migration,
`UserFactory`, `DatabaseSeeder`, `routes/web.php`, `config/app.php` — are never
overwritten. Existing files are reported as skipped unless you pass `--force`.

> **If you already run Breeze, Jetstream, Fortify or `laravel/ui`,** the installer detects
> it and generates **no** sign-in code: `routes/web_ui.php` guards its credential routes on
> `class_exists`, so your flow keeps the `login` route name and the scaffolded pages sit
> behind it. It also checks your `resources/views/layouts/app.blade.php`: those packages
> ship a *component* layout with no `@yield('content')`, which would render the scaffolded
> pages empty, so the installer says so rather than letting you find out in the browser.

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
                       API                                    web
  → ForceJsonResponse      errors stay JSON      → web group        session, CSRF, cookies
  → RateLimitApi           api / login bucket    → ratelimit.api    login bucket
  → auth:sanctum           who is calling        → auth             who is signed in
  → FormRequest            authorize() via Policy + allowlist validation
  → Controller             authorise, delegate, shape the response
  → Service                the use case, the audit trail
  → Repository (interface) persistence only
  → Eloquent
  ← Resource               explicit JSON shape   ← Blade view       escaped output
  ← SecurityHeaders        hardening headers, applied globally, profile per request
```

The middle five rows are literally the same objects. That is not a coincidence to admire —
it is the constraint the layering exists to enforce, and the reason a rule cannot hold over
JSON while quietly failing over HTML.

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
| "When X happens, do A then B, and record it" | Service — shared by both front doors |
| "Fetch/store this aggregate" | Repository — shared |
| "May this caller do this?" | Policy — shared, asked by FormRequest/Controller |
| "What does the client receive?" | Resource (API) or a Blade view (web) |
| "What is a valid payload?" | FormRequest — one per transport |
| "What shape is every JSON response?" | `App\Traits\ApiResponse` |
| "How does a decimal become minor units?" | `App\Support\Money` |

### The `Item` reference slice

`Item` is a complete vertical — model, migration, factory, policy, requests, resources in
two API versions, service, repository, feature test, unit test, and on a `web` or `both`
install a Blade controller, templates and their own feature test. It exists to be read and
then copied for your own aggregate. Delete it when you have your own; nothing else depends
on it.

On `--stack=both`, read `Api/V1/ItemController` and `Web/ItemController` side by side. They
are the same seven decisions; everything that differs is transport, and everything that
does not is shared on purpose.

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

**The web front door is not versioned at all**, and that is deliberate: a version number is
a contract with clients you cannot redeploy, and the only consumer of a Blade page is a
browser you ship the HTML to. Change the page and ship it. For the same reason
`app/Http/Requests/Web/` duplicates the rules in `V1/` rather than importing them — the day
the form gains a field, borrowing V1's class would force a change into a frozen version.

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

XXE is not addressed because the scaffolding parses no XML.

### What the Blade front door adds

| ID | What is baked in |
| --- | --- |
| **A01** | Every state-changing action is a POST or DELETE carrying `@csrf` — no `GET /logout`, no delete link, because a GET that changes state is reachable from an `<img>` tag on any other site and never sees CSRF protection. `@can` in a template hides a control and is never the only check: the policy is asked again in the controller or the form request. |
| **A02** | `SecurityHeaders` serves a **second profile** for HTML — `default-src 'self'`, `form-action 'self'`, no `'unsafe-inline'`, no `'unsafe-eval'`. The API keeps `default-src 'none'`, which would block a page's own stylesheet and every form on it. Neither profile is loosened to accommodate the other; nginx sets `nosniff` on the static assets it serves without touching PHP. |
| **A03** | The stylesheet is self-hosted. No npm, no Vite, no CDN — nothing at runtime from an origin you do not control. |
| **A04** | `Cache-Control: no-store, private` on any page rendered for a signed-in visitor: a cached page is still on disk after they sign out, and still reachable with the back button on a shared machine. Session cookie hardening (`SESSION_SECURE_COOKIE`, `HTTP_ONLY`, `SAME_SITE`) is written into `.env.example`. |
| **A05** | Output escaping — the reason it now applies. Every interpolation in every scaffolded template is `{{ }}`; there is not one `{!! !!}` in the scaffold, and a feature test asserts that a stored `<script>` arrives as text. `App\Support\Money` parses prices from the string, never `(int) round($v * 100)`. |
| **A07** | The session id is regenerated on sign-in (fixation) and the session invalidated *and* the CSRF token rotated on sign-out. Sign-in is throttled on **email + IP** through the same bucket the API uses; unknown-email and wrong-password give an identical, timing-equalised answer; the submitted password is never repopulated into the form. |
| **A10** | `resources/views/errors/*` are standalone pages that read no session and no data — a 404 for an unmatched URL never reaches `StartSession`, and a layout that touched the session would turn a clean 404 into a 500. |

The scaffolded web tests assert these too: that a stranger gets a 403 on someone else's
item, that a form-supplied `user_id` is ignored, that the session id actually changes on
sign-in, that `1.15` is stored as `115` and `1,999.00` is rejected rather than silently
becoming `0`, and that a stored `<script>` renders as text.

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

### If you installed the Blade stack

1. **Seed an account to sign in with:** `php artisan db:seed --class=UserSeeder` (it refuses
   to run in production and reads its password from `SEED_USER_PASSWORD`).
2. **Visit `/login`,** then `/dashboard` and `/items`. There is no build step — the
   stylesheet is a static file at `public/vendor/cachewraith/app.css`.
3. **Harden the session before production.** Laravel's defaults are already `http_only` and
   `same_site=lax`; set them explicitly anyway, because a default you never wrote down is
   one a future config edit can quietly change:

   ```dotenv
   SESSION_DRIVER=database        # or redis; the file driver does not scale
   SESSION_SECURE_COOKIE=true     # A04 — a cookie over http is a session anyone on the path can take
   SESSION_HTTP_ONLY=true
   SESSION_SAME_SITE=lax          # A01 — a cross-site POST arrives without it
   ```
4. **Replace the stylesheet** whenever you like: change the `<link>` in
   `resources/views/layouts/app.blade.php` and delete the file. Nothing in the PHP depends
   on its class names. If your replacement loads from another origin, add that origin to
   `web_security_headers` — and do not reach for `'unsafe-inline'`.
5. **Read `docs/WEB.md`** for the page-level rules: no `{!! !!}`, no inline JavaScript, every
   `@can` backed by a real policy call.

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

Optional: `laravel/sanctum` — required by the API stack's auth routes, which register only
when it is installed. A `--stack=web` install does not need it; it authenticates with
sessions.

No Node, npm or Vite is required for the Blade stack. If you already run Breeze, Jetstream,
Fortify or `laravel/ui`, the installer defers to your sign-in flow rather than competing
with it.

## License

MIT. See [LICENSE](LICENSE).
