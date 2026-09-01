# Architecture, security and design conventions

House rules for Claude Code working in this repository. Read `docs/ARCHITECTURE.md`
before making a structural change.

## What this project is

A versioned JSON API on Laravel, laid out in layers. The layout is not
decoration — each rule below has a specific failure it prevents.

## Where code goes

| The change | The file |
| --- | --- |
| A new endpoint | `routes/api_v{n}.php` → `app/Http/Controllers/Api/V{n}/` |
| What a valid payload looks like | `app/Http/Requests/V{n}/` |
| What the client receives | `app/Http/Resources/V{n}/` |
| "When X happens, do A then B, and log it" | `app/Services/` |
| A query | `app/Repositories/` (behind its `Contracts/` interface) |
| "May this caller do this?" | `app/Policies/` |
| A schema change | a **new** file in `database/migrations/` |
| A cross-cutting response concern | `app/Traits/ApiResponse.php` |

## Rules

**Controllers stay thin.** Authorise, hand validated input to a service, shape
the response. No query building, no business rules. If an `if` about business
meaning appears in a controller, it belongs in a service.

**Repositories touch the database and nothing else.** No authorisation, no
logging, no hashing. Every consumer type hints the interface in
`app/Repositories/Contracts`; `RepositoryServiceProvider` is the only file that
names a concrete class.

**A released API version is frozen.** Never edit `V1` to change behaviour. A
breaking change is a new route file plus new classes in `V{n+1}` — and only for
the classes whose contract actually changed. A `V2` directory that mirrors `V1`
file-for-file is duplication, not versioning.

**Never edit a migration that has run.** Write another one.

**Use the envelope.** Responses go through `App\Traits\ApiResponse`
(`respondSuccess`, `respondCreated`, `respondError`, `respondPaginated`).
Failures go through `App\Exceptions\Handler`. Do not hand-build a
`response()->json([...])` with a different shape.

## Security: follow the OWASP Top Ten

Before calling any work done that touches authentication, authorization,
sessions, input handling, data access, secrets, crypto, file uploads, outbound
requests, dependencies, or error/log output, check it against the categories
below. This is a checklist, not background reading.

Apply it in both directions:

- **Writing code** — pick the design that avoids the category up front
  (parameterized queries, server-side authorization checks, allowlists for
  outbound URLs) rather than bolting a mitigation on afterward.
- **Reviewing code** — name the categories actually at risk and cite the ID with
  a `file:line`, so the finding is checkable: "A01: this handler trusts a
  client-supplied `userId`", never a vague "this looks insecure". Do not pad a
  review with categories that do not apply, and do not report a real concern as
  generic advice when it maps to one.

### The categories (OWASP Top 10:2025)

| ID | Category | Recurring failure mode |
|---|---|---|
| A01 | Broken Access Control | Access authorized from client-supplied IDs, or checked only in the UI; also SSRF — server fetches a user-supplied URL without an allowlist |
| A02 | Security Misconfiguration | Debug mode on, permissive CORS, default credentials, missing headers, verbose errors |
| A03 | Software Supply Chain Failures | Known-CVE or unpinned dependencies, compromised build systems, untrusted distribution or plugin sources |
| A04 | Cryptographic Failures | Secrets or PII unprotected in transit/at rest; homegrown crypto; fast hashes for passwords |
| A05 | Injection | SQL/NoSQL/OS/LDAP built by string concatenation; unescaped output (XSS) |
| A06 | Insecure Design | No rate limits, no trust boundary, logic that assumes a cooperative client |
| A07 | Authentication Failures | Weak session/token handling, no lockout, tokens that never expire or rotate |
| A08 | Software or Data Integrity Failures | Unsigned updates, insecure deserialization, untrusted CI/CD |
| A09 | Security Logging & Alerting Failures | Security events unlogged or never alerted on; logs leaking secrets/PII/stack traces |
| A10 | Mishandling of Exceptional Conditions | Improper error handling, logic errors, failing open on an error path |

Moved since 2021, and worth restating because the old IDs are muscle memory:
SSRF is no longer its own category (now A01), Vulnerable and Outdated Components
grew into A03 Software Supply Chain Failures, and Injection dropped from A03 to
A05. Cite 2025 IDs.

The list is versioned and periodically revised. If it has been updated past
2025, verify the current categories before leaning on the table above — the
process holds either way, but the IDs and names may shift.

This checklist is the floor, not the ceiling: a threat specific to the system at
hand still matters even when it maps to none of the ten.

### How it lands in this codebase

- **A01** — `authorize()` in a FormRequest must be a real policy call, never
  `return true`. Resolve the target from the route binding, never from a body
  id. A listing is scoped by owner in the repository.
- **A04** — passwords are hashed in `UserService`, the single write path. Never
  hash somewhere else, and never log a hash.
- **A05** — Eloquent and the query builder only. No `DB::raw`, `whereRaw` or
  concatenated SQL.
- **A06** — every route carries a `ratelimit.api` bucket; pagination is clamped
  by `ApiResponse::perPage`.
- **A09** — log identifiers, actor id and IP. Never a password, token, hash or
  full payload.
- **A10** — errors go through `App\Exceptions\Handler` and fail closed.

## Design patterns: choose deliberately

Before writing any non-trivial unit — a new class, module, or a branch point
that will grow — state in one or two lines: the shape of the problem, the
candidate patterns, the one chosen, and why. Not an essay, and not silence
either.

Match the problem shape, not the pattern name:

| The problem | Candidates |
|---|---|
| Construction is conditional, or the concrete type varies | Factory Method, Abstract Factory |
| An object needs many optional parts, or must be built step by step | Builder |
| One instance must be shared | container-scoped singleton — never a static global |
| Two incompatible interfaces must meet | Adapter, Bridge |
| Behavior must be added without touching the original | Decorator, Proxy |
| A subsystem needs one simple entry point | Facade |
| Part and whole must be treated alike | Composite |
| One algorithm, several interchangeable variants | Strategy |
| The steps are fixed, the details vary | Template Method |
| Something must react to change elsewhere | Observer, Mediator |
| An action must be queued, logged, or undone | Command, Memento |
| Behavior depends on which state the object is in | State |
| An input passes through ordered, optional handlers | Chain of Responsibility |
| Persistence must be swappable or testable | Repository, Unit of Work |
| A failure path must be explicit, not thrown | Result / Either, Null Object |
| A remote dependency can fail or stall | Circuit Breaker, Retry with backoff |
| Reads and writes have diverging models | CQRS |

Rules

- **The simplest construct that works, wins.** A function, a plain class, a
  language feature, or a `match` beats a pattern. A pattern earns its place when
  there are already two real variants, or a known axis of change — never on one
  hypothetical future one.
- **Write the language's idiom, not the 1994 diagram.** A first-class function
  is Strategy in most languages; a decorator, a context manager, an enum with
  behavior, or a discriminated union may be the local spelling. Do not build an
  interface hierarchy the language does not need.
- **Match the vocabulary already in this codebase** over introducing a new one.
  Consistency beats a marginally better fit.
- **Name it where it lands** — class or module name, or one line of doc — so the
  next reader sees the pattern without inferring it.
- **Do not retrofit** patterns into working code that nobody asked you to
  change.
- Say when a pattern was considered and rejected, and why. That is a design
  decision worth one line in the commit body or the PR.

### Patterns already in play here

Repository (`app/Repositories`), Strategy selected by route parameter
(`RateLimitApi` buckets), Decorator (`SecurityHeaders` middleware), Chain of
Responsibility collapsed into a `match` (`App\Exceptions\Handler`), and a mixin
for the response envelope (`App\Traits\ApiResponse`). Match this vocabulary
before introducing a new one.

## Commands

```bash
php artisan migrate                     # apply schema changes
php artisan db:seed --class=UserSeeder  # local demo data (refuses in production)
php artisan route:list --path=api       # verify the versioned routes
php artisan test                        # the suite
composer audit --locked                 # A03 — dependency advisories
```

## The Item slice

`Item` is a complete reference vertical — model, migration, factory, policy,
requests, resources in two versions, service, repository, feature test, unit
test. Copy it for a new aggregate, then delete it. Nothing else depends on it.
