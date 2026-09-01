# The web (Blade) front door

The scaffolded UI: server-rendered Blade, session authentication, no build
step. It sits on the same services, repositories and policies as the API — if
this project was installed with `--stack=web` there is no API, and if it was
installed with `--stack=both` the two front doors share every layer below the
controller.

## The request path

```
Request
  → web middleware group     session, CSRF, cookie encryption
  → ratelimit.api:login      on the sign-in route only (A06/A07)
  → auth                     is anyone signed in
  → FormRequest              authorize() via Policy + allowlist validation
  → Controller               authorise, delegate, redirect or render
  → Service                  the use case, the audit trail
  → Repository (interface)   persistence only
  → Eloquent
  ← Blade view               escaped output, one layout
  ← SecurityHeaders          web header profile, applied globally
```

Compare it with the API path in `ARCHITECTURE.md`. Four of the eight steps are
literally the same objects.

## What is scaffolded

```
app/
├── Http/
│   ├── Controllers/Web/
│   │   ├── Auth/LoginController.php   session auth (skipped if you have Breeze etc.)
│   │   ├── DashboardController.php
│   │   └── ItemController.php         the reference web slice
│   └── Requests/Web/
│       ├── LoginRequest.php
│       ├── StoreItemRequest.php
│       └── UpdateItemRequest.php
└── Support/
    └── Money.php                      decimals ↔ integer minor units, once

resources/views/
├── layouts/app.blade.php              the one layout
├── partials/{alerts,field-error,pagination}.blade.php
├── auth/login.blade.php
├── dashboard.blade.php
├── items/{index,create,edit,show,_form}.blade.php
└── errors/{403,404,419,429,500,503}.blade.php

public/vendor/cachewraith/app.css      no npm, no Vite, no CDN
routes/web_ui.php                      your routes/web.php is never touched
tests/Feature/Web/{LoginTest,ItemPageTest}.php
tests/Unit/MoneyTest.php
```

## Where code goes

| The change | The file |
| --- | --- |
| A new page | `routes/web_ui.php` → `app/Http/Controllers/Web/` |
| What a valid form submission looks like | `app/Http/Requests/Web/` |
| What the page shows | `resources/views/` |
| "When X happens, do A then B, and log it" | `app/Services/` — shared with the API |
| A query | `app/Repositories/` behind its `Contracts/` interface — shared |
| "May this caller do this?" | `app/Policies/` — shared |
| Chrome, navigation, flash messages | `resources/views/layouts/app.blade.php` |

## Rules

**Controllers stay thin.** Authorise, hand validated input to a service,
return a view or a redirect. The web `ItemController` is the same seven
decisions as the API one; if the two ever need different *rules*, the rule is
in the wrong layer.

**Web form requests are siblings of the API's, not reuses of them.**
`App\Http\Requests\Web\StoreItemRequest` duplicates the rules in
`App\Http\Requests\V1\StoreItemRequest` on purpose. V1 is a frozen API
contract; the day the web form gains a field, borrowing V1's request class
would force a change into a released version. Same rules today, different
lifecycles.

**Never `{!! !!}`.** Every interpolation in every scaffolded template is
`{{ }}`, which escapes. There is not one raw echo in this scaffold, and adding
one is a decision to review, not a formatting choice (OWASP A05).

**`@can` hides a button; it is not an access control.** Every `@can` in these
templates has a real `Gate::authorize` or `FormRequest::authorize()` behind it.
A control the visitor cannot see is still a URL they can type.

**Every state-changing action is a POST with `@csrf`.** No `GET /logout`, no
delete link. A GET that changes state is reachable from an `<img>` tag on any
other site and never sees CSRF protection.

**No inline JavaScript.** The CSP for web routes is `script-src 'self'` with
no `'unsafe-inline'`, which is what makes an injected `<script>` inert even if
one ever gets stored. An `onclick=` or an inline `<script>` would need
`'unsafe-inline'`, which disables that protection for the whole page. Put
scripts in a file under `public/`.

**Money crosses the boundary once.** The form posts a decimal; the column
stores integer minor units. `App\Support\Money` owns both directions. Never
`(int) round($value * 100)` — `1.15 * 100` is `114.99999999999999`.

## Authentication

If the application already has `laravel/ui`, Breeze, Jetstream, Fortify or its
own `app/Http/Controllers/Auth`, the installer generates **no** sign-in code
and `routes/web_ui.php` registers no credential routes — the guard is
`class_exists(LoginController::class)`, the same trick the API's Sanctum routes
use. Your existing flow keeps the `login` route name and the scaffolded pages
sit behind it.

Otherwise you get `LoginController`, which:

- regenerates the session id on sign-in (session fixation, A07);
- invalidates the session *and* rotates the CSRF token on sign-out;
- returns an identical error for an unknown email and a wrong password, and
  equalises response timing so the form cannot be used to enumerate accounts;
- throttles per email + IP through `ratelimit.api:login` — the same bucket the
  API's login endpoint uses;
- logs every attempt with the source address and never the password.

Route names are unprefixed (`login`, `dashboard`, `items.index`) because
Laravel's own `Authenticate` middleware redirects a guest to `route('login')`.
If one collides with a name in your `routes/web.php`, rename it in
`routes/web_ui.php` — that file is yours once it is generated.

### Before production

```dotenv
SESSION_DRIVER=database        # or redis; the file driver does not scale
SESSION_SECURE_COOKIE=true     # A04 — a cookie sent over http is a session anyone on the path can take
SESSION_HTTP_ONLY=true         # keeps the cookie out of reach of any script
SESSION_SAME_SITE=lax          # A01 — a cross-site POST arrives without it
```

Laravel's defaults are already `http_only=true` and `same_site=lax`; set them
explicitly anyway, because a default you never wrote down is one a future
config edit can quietly change.

## Security headers

`SecurityHeaders` runs globally and picks a profile per request. Web responses
get:

```
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self';
    img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none';
    frame-ancestors 'none'; base-uri 'self'; form-action 'self'
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: …
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-origin
```

Plus `Cache-Control: no-store, private` on any page rendered for a signed-in
visitor — a cached HTML page is still on disk after they sign out, and still
reachable with the back button on a shared machine (A04).

API responses keep the stricter `default-src 'none'` profile. Both live in
`config/cachewraith-template.php`, under `security_headers` and
`web_security_headers`. Adding a third front door means adding a third
profile, not loosening either of these.

If you add a font or a script from another origin, name that origin in the
policy. Do not reach for `'unsafe-inline'`.

## Styling

`public/vendor/cachewraith/app.css` is a single, self-hosted stylesheet with no
build step and no dependencies. It is scaffolding, not a design system.

To replace it with Tailwind, Bootstrap or your own pipeline: change the
`<link>` in `resources/views/layouts/app.blade.php` and delete the file.
Nothing in the PHP depends on its class names. If your replacement loads from
another origin, add that origin to the CSP.

## The Item slice

`Item` is a complete vertical: model, migration, factory, policy, requests,
service, repository, and — with `--stack=both` — a controller and a test on
*each* front door. Read both `ItemController`s side by side; that comparison is
the clearest statement of what belongs in a controller and what does not. Copy
the slice for your own aggregate, then delete it. Nothing else depends on it.
