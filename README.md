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
php artisan migrate
```

The service provider is auto-discovered, so there is nothing to register by hand.

### Testing it locally before publishing

Point a throwaway Laravel application at your working copy:

```jsonc
// composer.json of the consuming application
{
    "repositories": [
        { "type": "path", "url": "../cachewraith-laravel-template-structure" }
    ]
}
```

```bash
composer require cachewraith/laravel-template-structure:*
php artisan cachewraith:install
php artisan route:list --path=api      # api/v1/users should be listed
```

### Command options

| Option | Effect |
| --- | --- |
| `--force` | Overwrite scaffolded files that already exist. Without it, existing files are left untouched and reported as skipped. |

The installer is **idempotent**: running it twice creates nothing new and patches nothing
twice. Anything it cannot patch safely — because your application already customised the
file — is printed as a snippet to paste in yourself, rather than being forced.

### Publishing tags

| Tag | Publishes |
| --- | --- |
| `cachewraith-template-stubs` | The whole stub tree to `stubs/cachewraith-template/` |
| `cachewraith-template-config` | `config/cachewraith-template.php` |

```bash
php artisan vendor:publish --tag=cachewraith-template-stubs
```

`cachewraith:install` publishes the stub tree for you, and **prefers the published stubs over the
packaged ones** on subsequent runs. Edit `stubs/cachewraith-template/**` to make the scaffolding
match your house style, then re-run `php artisan cachewraith:install --force`.

---

## What gets scaffolded

```
app/
├── Actions/
│   └── CreateUserAction.php                  one unit of work, hashes on write
├── DTOs/
│   └── UserData.php                          immutable input (spatie/laravel-data aware)
├── Exceptions/
│   └── ApiExceptionHandler.php               uniform JSON error envelope
├── Http/
│   ├── Controllers/Api/V1/
│   │   ├── AuthController.php                Sanctum token issue/revoke
│   │   └── UserController.php                thin: authorise → service → resource
│   ├── Middleware/Security/
│   │   ├── ForceJsonResponse.php             API errors never render HTML
│   │   └── SecurityHeaders.php               CSP, framing, sniffing, referrer, HSTS
│   ├── Requests/Api/V1/
│   │   ├── LoginRequest.php
│   │   ├── StoreUserRequest.php              real authorize() + allowlist rules
│   │   └── UpdateUserRequest.php
│   └── Resources/Api/V1/
│       └── UserResource.php                  explicit output shape
├── Models/                                   your existing models are left alone
├── Policies/
│   └── UserPolicy.php                        the single source of access rules
├── Providers/
│   ├── RepositoryServiceProvider.php         contract → implementation bindings
│   └── SecurityServiceProvider.php           HTTPS, policies, rate limiters, strict models
├── Repositories/
│   ├── Contracts/UserRepositoryInterface.php
│   └── Eloquent/EloquentUserRepository.php
└── Services/
    └── UserService.php                       orchestration + audit logging

routes/api/
└── v1.php                                    Route::apiResource('users', ...)

config/
└── cachewraith-template.php                          feature toggles
```

The installer also patches, when it can do so safely:

|  Laravel 11+ | Laravel 10 |
| --- | --- |
| `bootstrap/app.php` → `withRouting(then: …)` loads `routes/api/{version}.php` | `app/Providers/RouteServiceProvider.php` loads the same files |
| `bootstrap/app.php` → `withMiddleware()` registers both security middleware | `app/Http/Kernel.php` registers both |
| `bootstrap/app.php` → `withExceptions()` registers `ApiExceptionHandler` | `app/Exceptions/Handler.php` registers it |
| `bootstrap/providers.php` registers both providers | `config/app.php` registers both |
| `.env.example` gets the production hardening notes | same |

---

## Architecture

### The request path

```
Request
  → ForceJsonResponse            (errors stay JSON)
  → throttle                     (rate limit)
  → auth:sanctum                 (who is calling)
  → FormRequest                  (authorize() via Policy + allowlist validation)
  → Controller                   (authorise reads, delegate, shape response)
  → Service                      (orchestrate the use case, log the audit trail)
  → Action                       (one unit of work)
  → Repository (interface)       (persistence only)
  → Eloquent
  ← Resource                     (explicit output shape)
  ← SecurityHeaders              (hardening headers)
```

### OOAD principles this enforces

**Single Responsibility.** An *action* does exactly one thing (`CreateUserAction::execute`).
A *service* sequences actions and repositories and owns the audit trail. A *repository*
touches the database and nothing else — no authorisation, no logging, no business rules. If
a class needs the word "and" to describe it, it is doing too much.

**Dependency Inversion.** Controllers and services depend on
`App\Repositories\Contracts\*Interface`, never on Eloquent. `RepositoryServiceProvider` is
the one file that names a concrete implementation, which is what makes the persistence
layer swappable and trivially fakeable in tests:

```php
$this->app->bind(UserRepositoryInterface::class, InMemoryUserRepository::class);
```

**Interface Segregation.** One interface per aggregate root. Resist growing a shared
`CrudRepositoryInterface` — every consumer would then depend on methods it never calls.

**Open/Closed.** A released API version is frozen. New behaviour is a new version: a new
route file and new controllers, never an edit to `v1`.

### Choosing a layer

| Where does this code go? | Layer |
| --- | --- |
| "When X happens, do A then B, and record it" | Service |
| "Do A, correctly, everywhere it is invoked" | Action |
| "Fetch/store this aggregate" | Repository |
| "May this caller do this?" | Policy (asked by FormRequest/Controller) |
| "What does the client receive?" | Resource |
| "What is a valid payload?" | FormRequest |

---

## API versioning

`config/cachewraith-template.php` drives it:

```php
'api_version_prefix' => env('CACHEWRAITH_API_PREFIX', 'api'),
'versions' => ['v1'],
```

Every entry is loaded from `routes/api/{version}.php` under `{prefix}/{version}` with the
`api` middleware group — so `routes/api/v1.php` is served at `/api/v1/...` and contains no
`prefix()` or `middleware('api')` of its own.

### Adding v2 without breaking v1

```bash
cp routes/api/v1.php routes/api/v2.php
mkdir -p app/Http/Controllers/Api/V2 app/Http/Resources/Api/V2 app/Http/Requests/Api/V2
```

1. Copy only the classes whose contract actually changes into the `V2` namespaces.
2. Point `routes/api/v2.php` at them; anything unchanged may keep referencing `V1`.
3. Add `'v2'` to the `versions` array.

`/api/v1/*` keeps serving its existing clients, untouched. **Never edit a released version
in place** — that is the Open/Closed principle with a deprecation policy attached.

---

## OWASP Top Ten defaults

Numbering follows the **OWASP Top 10:2025** categories.

| ID | Category | What is baked in |
| --- | --- | --- |
| **A01** | Broken Access Control | Every `FormRequest::authorize()` is a real policy call, never `return true`. `UserPolicy` is the single source of access rules, registered explicitly. Update/delete resolve the target from the **route binding**, never from a body-supplied id (IDOR). Read endpoints call `Gate::authorize` explicitly. `per_page` is clamped. |
| **A02** | Security Misconfiguration | `SecurityHeaders` is registered **globally**, so endpoints added later inherit it: CSP `default-src 'none'`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `Permissions-Policy`, COOP/CORP, `Cache-Control: no-store`. `X-Powered-By` is stripped. `.env.example` documents `APP_DEBUG=false` for production. |
| **A03** | Software Supply Chain Failures | See [Dependency auditing](#dependency-auditing) below — run `composer audit` in CI. |
| **A04** | Cryptographic Failures | Passwords are hashed at the single write path via `Hash::make` (bcrypt/argon2id — never a fast hash). `URL::forceScheme('https')` outside local/testing. HSTS on secure connections. `UserResource` never emits a password, hash or token. |
| **A05** | Injection | Eloquent and the query builder only — no `DB::raw`, `whereRaw` or string-concatenated SQL anywhere in the stubs. Validation rules are allowlists, so unknown keys never reach persistence. |
| **A06** | Insecure Design | Rate limiters registered for `api` and `login`; the credential route additionally carries `throttle:5,1`. Pagination is bounded. `forceDelete` is closed by default. Models run in strict mode outside production. |
| **A07** | Authentication Failures | Sanctum tokens, named per device, revoked individually on logout. Login is limited per **email + IP**, returns an identical response for unknown-email and wrong-password, and equalises response timing so the endpoint cannot be used to enumerate accounts. Password strength (12 chars, mixed case, numbers, symbols, HIBP-checked) is enforced on write, not on login. |
| **A08** | Software & Data Integrity Failures | No `unserialize()`, no `eval()`, no dynamic class instantiation from request input anywhere in the stubs. |
| **A09** | Logging & Alerting Failures | `UserService` logs create/update/delete and `AuthController` logs login success, failure and throttling — each with actor id and source IP, and **never** a password, hash, token or full payload. |
| **A10** | Mishandling of Exceptional Conditions | `ApiExceptionHandler` maps every failure to one envelope and **fails closed**: an unrecognised exception is a generic 500, with class/file/line attached only when `APP_DEBUG` is on. `ForceJsonResponse` stops error paths from rendering HTML. |

XXE is not addressed because the scaffolding parses no XML — the API is JSON only. XSS is
not addressed with output escaping for the same reason: there are no Blade views, and
`Resource` classes control the exact response shape.

### Uniform error envelope

Every API failure — validation, auth, 404, throttle, or an unhandled exception — responds
with the same shape:

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
3. **Verify the routes:** `php artisan route:list --path=api`

   ```
   GET|HEAD   api/v1/health
   POST       api/v1/login
   POST       api/v1/logout
   GET|HEAD   api/v1/users
   POST       api/v1/users
   GET|HEAD   api/v1/users/{user}
   PUT|PATCH  api/v1/users/{user}
   DELETE     api/v1/users/{user}
   ```
4. **Review `UserPolicy`.** The generated rules are ownership-based, which is all a stock
   `User` model can express. Tighten them to your roles before shipping — and tighten
   rather than loosen: `create` currently allows any authenticated user, which is right for
   an admin-only console and wrong for a public API.
5. **Review `config/cachewraith-template.php`**, especially the CSP if you ever serve HTML from the
   same application.

### Why `SecurityServiceProvider` and not `AppServiceProvider`?

`URL::forceScheme`, the policy registration, the rate limiters and Eloquent strict mode
live in a generated `App\Providers\SecurityServiceProvider` rather than being spliced into
your `AppServiceProvider`. The installer will not rewrite a file your application already
owns and may have filled with unrelated bootstrapping — and keeping the global security
defaults in one dedicated file means a reviewer can audit all of them at a glance. Move
them into `AppServiceProvider` if you prefer; nothing else depends on where they live.

## Requirements

- PHP 8.2+
- Laravel 10, 11, 12 or 13

> **Laravel 10 is end-of-life.** The fallback wiring for it is implemented and tested, but
> Composer now blocks every 10.x release because of published security advisories (OWASP
> A03). Treat Laravel 10 support here as a migration aid, not a destination.

Optional: `spatie/laravel-data` (the DTO is generated as a `Data` object when present),
`laravel/sanctum` (the auth routes register only when installed).

## License

MIT. See [LICENSE](LICENSE).
