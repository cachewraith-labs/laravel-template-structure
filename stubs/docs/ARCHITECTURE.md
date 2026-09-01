# Architecture

A layered application with up to two front doors: a versioned JSON API and a
server-rendered Blade UI. Every rule below exists because breaking it has a
specific, nameable consequence.

Which front doors exist here depends on how the scaffold was installed
(`--stack=api`, `web` or `both`). What does *not* depend on it: the services,
repositories, policies and models below the controller. That is the point of
the layering — a second front door costs a controller, some form requests and
some templates, and no business logic at all. See `WEB.md` for the web side,
`API.md` for the API side.

## The request path

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

The web path is the same spine with a different transport at each end — the
`web` middleware group instead of `api`, a session instead of a bearer token,
a Blade view instead of a Resource. Four of the eight steps are literally the
same objects. `WEB.md` has the diagram side by side with this one.

## The layers

| Layer | Directory | Owns | Must never |
| --- | --- | --- | --- |
| Controller (API) | `app/Http/Controllers/Api/{V1,V2}` | authorise, delegate, respond | build a query, hold a business rule |
| Controller (web) | `app/Http/Controllers/Web` | authorise, delegate, render or redirect | build a query, hold a business rule |
| FormRequest | `app/Http/Requests/{V1,V2,Web}` | the shape of valid input, `authorize()` | `return true` |
| Resource | `app/Http/Resources/{V1,V2}` | the exact JSON response fields | return a model directly |
| View | `resources/views` | what the page shows | decide who may see it |
| Service | `app/Services` | the use case, ordering, logging | know about HTTP |
| Repository | `app/Repositories` | persistence | authorise, log, hash |
| Policy | `app/Policies` | "may this caller do this?" | read a request parameter |
| Model | `app/Models` | schema, relations, casts | contain a use case |
| Trait | `app/Traits` | the response envelope | grow into a junk drawer |
| Support | `app/Support` | a conversion both transports need | become a bag of helpers |

The two controller rows are the whole story. They differ in what they return
and in nothing else: a rule that holds over JSON but not over HTML is a rule
that does not hold.

### Choosing a layer

| Where does this code go? | Layer |
| --- | --- |
| "When X happens, do A then B, and record it" | Service |
| "Fetch/store this aggregate" | Repository |
| "May this caller do this?" | Policy (asked by FormRequest/Controller) |
| "What does the client receive?" | Resource (API) or a Blade view (web) |
| "What is a valid payload?" | FormRequest — one per transport |
| "What shape is every JSON response?" | `App\Traits\ApiResponse` |
| "How does a decimal become minor units?" | `App\Support\Money` |

## OOAD principles this enforces

**Single Responsibility.** A service sequences a use case and owns the audit
trail. A repository touches the database and nothing else — no authorisation,
no logging, no business rules. If a class needs the word "and" to describe it,
it is doing too much.

**Dependency Inversion.** Controllers and services depend on
`App\Repositories\Contracts\*Interface`, never on Eloquent.
`RepositoryServiceProvider` is the one file that names a concrete
implementation, which is what makes the persistence layer swappable and
trivially fakeable — see `tests/Unit/ItemServiceTest.php`, which tests the
status-transition rules with no database at all:

```php
$this->app->bind(ItemRepositoryInterface::class, InMemoryItemRepository::class);
```

**Interface Segregation.** One interface per aggregate root. Resist growing a
shared `CrudRepositoryInterface` — every consumer would then depend on methods
it never calls.

**Open/Closed.** A released API version is frozen. New behaviour is a new
version: a new route file and new controllers, never an edit to `v1`.

## API versioning

`config/cachewraith-template.php` drives it:

```php
'api_version_prefix' => env('CACHEWRAITH_API_PREFIX', 'api'),
'versions' => ['v1'],
```

Each entry loads `routes/api_{version}.php` under `{prefix}/{version}`, with
the `api` middleware group and the route-name prefix `{version}.` — so
`routes/api_v1.php` is served at `/api/v1/...`, its routes are named
`v1.items.index` and so on, and the file itself contains no `prefix()`, no
`middleware('api')` and no `name('v1.')`.

### Adding a version without breaking the last one

1. Write `routes/api_v2.php`.
2. Copy into `App\Http\{Controllers,Requests,Resources}\...\V2` **only** the
   classes whose contract actually changes. Point the new route file at them;
   anything unchanged keeps referencing `V1`. The shipped `V2\ItemController`
   is the worked example — it reuses the V1 FormRequests, because the accepted
   input did not change, and only the resource did.
3. Add `'v2'` to the `versions` array.

`/api/v1/*` keeps serving its existing clients, untouched. **Never edit a
released version in place** — that is Open/Closed with a deprecation policy
attached.

Versioning is a property of the HTTP boundary. The service, repository, model
and policy underneath have exactly one version.

## Two front doors, one domain

Versioning is a property of the HTTP boundary — and the *web* boundary is not
versioned at all. A version number is a contract with clients you cannot
redeploy; the only consumer of a Blade page is a browser you ship the HTML to,
so `routes/web_ui.php` has no `v1`. Change the page and ship it.

What the two doors share, and must go on sharing:

| Concern | Where it lives | Why not per transport |
| --- | --- | --- |
| "May this caller do this?" | `app/Policies` | Two copies diverge, and the half nobody tested is the half that leaks |
| The use case and its audit trail | `app/Services` | A rule enforced twice is a rule that will disagree with itself |
| Queries | `app/Repositories` | An unscoped listing added for one door is reachable from both |
| Ownership on write | `Item::$fillable` + `ItemService` | Mass assignment is closed at the model, not at each form |

What is legitimately per transport: the controller, the form request, the
output (Resource or view), the routes, and the security header profile.

## The reference slice

`Item` is a complete vertical slice — model, migration, factory, policy,
requests, resources in two API versions, service, repository, feature test,
unit test, and (on a `web` or `both` install) a Blade controller, templates and
their own feature test. It exists to be read and then copied for your own
domain. Delete it once you have your own aggregate; nothing else depends on it.

## OWASP Top Ten defaults

Numbering follows **OWASP Top 10:2025**.

| ID | Category | What is baked in |
| --- | --- | --- |
| **A01** | Broken Access Control | Every `FormRequest::authorize()` is a real policy call. Policies read the record's own columns, never a request parameter. Update/delete resolve the target from the **route binding**, never a body-supplied id (IDOR). Read endpoints call `Gate::authorize` explicitly. Listings are scoped by owner in the repository contract, so there is no unscoped method to reach for. `per_page` is clamped. |
| **A02** | Security Misconfiguration | `SecurityHeaders` is registered **globally**, so endpoints added later inherit it. `config/cors.php` is an explicit allow-list, empty by default. `expose_php=Off`, `display_errors=Off`, `server_tokens off`. `.env.example` documents `APP_DEBUG=false`. |
| **A03** | Software Supply Chain Failures | `composer audit --locked` runs in the production image build. Wire it into CI too. |
| **A04** | Cryptographic Failures | Passwords are hashed at the single write path in `UserService` via `Hash::make` (bcrypt/argon2id — never a fast hash). `URL::forceScheme('https')` outside local/testing. HSTS on secure connections. `.env` is in `.dockerignore`. Resources never emit a password, hash or token. |
| **A05** | Injection | Eloquent and the query builder only — no `DB::raw`, `whereRaw` or string-concatenated SQL anywhere. Validation rules are allowlists, so unknown keys never reach persistence. The nginx `location` blocks refuse to execute any path but `public/index.php`. |
| **A06** | Insecure Design | `RateLimitApi` gives every route a ceiling and fails closed on an unknown bucket. Pagination is bounded. Status transitions are a domain rule, not just a validation rule. `forceDelete` is closed by default. |
| **A07** | Authentication Failures | Sanctum tokens, named per device, revoked individually on logout. Login is limited per **email + IP**, returns an identical response for unknown-email and wrong-password, and equalises response timing so the endpoint cannot enumerate accounts. Password strength (12 chars, mixed case, numbers, symbols, HIBP-checked) is enforced on write, not on login. |
| **A08** | Software & Data Integrity Failures | No `unserialize()`, no `eval()`, no dynamic class instantiation from request input. The production image ships immutable code with only `storage/` and `bootstrap/cache` writable, and runs as `www-data`. |
| **A09** | Logging & Alerting Failures | Services log create/update/delete and `AuthController` logs login success, failure and misconfiguration — each with actor id and source IP, and **never** a password, hash, token or full payload. |
| **A10** | Mishandling of Exceptional Conditions | `App\Exceptions\Handler` maps every failure to one envelope and **fails closed**: an unrecognised exception is a generic 500, with class/file/line attached only when `APP_DEBUG` is on. `ForceJsonResponse` stops error paths from rendering HTML. |

### What the web front door adds

Installing the Blade stack widens three of those categories. The additions:

| ID | What is baked in |
| --- | --- |
| **A01** | Every state-changing action is a POST or DELETE carrying `@csrf` — no `GET /logout`, no delete link. `@can` in a template hides a control and is never the only check; the policy is asked again in the controller or the form request. |
| **A02** | `SecurityHeaders` serves a **second profile** for HTML: `default-src 'self'` with no `'unsafe-inline'` and no `'unsafe-eval'`. The API keeps `default-src 'none'`, which would block a page's own stylesheet and every form submission on it. Neither profile is loosened to accommodate the other. |
| **A04** | `Cache-Control: no-store, private` on any page rendered for a signed-in visitor: a cached page is still on disk after they sign out. Session cookie hardening is documented in `.env.example` and `WEB.md`. |
| **A05** | Output escaping — the reason it now matters. Every interpolation in every scaffolded template is `{{ }}`. There is not one `{!! !!}` in the scaffold, and `tests/Feature/Web/ItemPageTest` asserts that a stored `<script>` arrives as text. |
| **A07** | The session id is regenerated on sign-in (fixation) and the session invalidated *and* the CSRF token rotated on sign-out. Sign-in is throttled on email + IP through the same bucket the API uses, and the failure message is identical for an unknown email and a wrong password. |
| **A10** | `resources/views/errors/*` are standalone pages that touch no session and no data — a 404 for an unmatched URL never reaches `StartSession`, and a layout that read the session would turn a clean 404 into a 500. |

XXE is not addressed because the scaffolding parses no XML. On an API-only
install, output escaping does not arise either: there are no Blade views, and
`Resource` classes control the exact response shape.

## Migrations

`database/migrations` is this project's schema history — Laravel's equivalent of
Alembic. Every change is a new timestamped file. A migration that has run
anywhere but your own machine is never edited; write another one.

## Docker

| File | Role |
| --- | --- |
| `docker/Dockerfile` | Multi-stage production image. Composer never reaches the final layer. |
| `docker/Dockerfile.dev` | Development image: Xdebug, dev dependencies, opcache off. Never deploy it. |
| `docker/nginx/default.conf` | Front controller only; every other `.php` path is a 404. |
| `docker/php/php.ini` | `expose_php=Off`, errors to the log, bounded uploads. |
| `docker-compose.yml` | nginx → php-fpm → postgres + redis. No database port published. |
| `docker-compose.dev.yml` | Overlay: bind-mounted source, published ports. Opt in explicitly. |
