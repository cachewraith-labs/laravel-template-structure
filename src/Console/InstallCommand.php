<?php

declare(strict_types=1);

namespace Cachewraith\LaravelTemplateStructure\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Cachewraith\LaravelTemplateStructure\LaravelTemplateStructureServiceProvider as PackageProvider;

/**
 * Scaffolds the template structure into the host application.
 *
 * Template Method: handle() fixes the order of the installation — choose the
 * stack, copy, wire, report — while each step is a small private method that
 * knows one file format. Adding a step means adding a method and one line to
 * handle(), not restructuring the command.
 *
 * Two front doors, one set of layers. --stack decides which of them is
 * scaffolded: the versioned JSON API, the Blade UI, or both. The choice only
 * ever changes the transport — controllers, form requests, resources or
 * templates, routes and middleware. Models, services, repositories and
 * policies are copied either way, because they are the part that does not
 * care how the request arrived, and duplicating them per transport is how the
 * two halves start disagreeing about what the rules are.
 *
 * The filtering is a path allowlist (see stackOf()) rather than three stub
 * trees. One tree keeps the shared files literally shared — there is no
 * chance of the API's ItemService drifting from the web's — and keeps
 * --publish-stubs publishing one thing.
 *
 * Every step is idempotent and every step fails soft: when a file cannot be
 * patched automatically (because the application has already customised it),
 * the command records the exact snippet the developer should paste and carries
 * on, rather than aborting halfway through a half-scaffolded application.
 *
 * The stub tree is read straight out of the package. Nothing is published into
 * the application unless the developer asks for it with --publish-stubs, so a
 * freshly installed project has no stubs/ directory cluttering it.
 */
final class InstallCommand extends Command
{
    private const STACK_API = 'api';

    private const STACK_WEB = 'web';

    private const STACK_BOTH = 'both';

    /**
     * Stub paths, relative to the stub root, that belong to exactly one stack.
     * Anything not matched here is shared and is installed for both.
     *
     * Prefix match, so a directory covers everything under it.
     *
     * @var array<string, list<string>>
     */
    private const STACK_PATHS = [
        self::STACK_API => [
            'app/Http/Controllers/Api/',
            'app/Http/Requests/V1/',
            'app/Http/Requests/V2/',
            'app/Http/Resources/',
            'app/Http/Middleware/ForceJsonResponse.php',
            'app/Traits/ApiResponse.php',
            'routes/api_',
            'tests/Feature/V1/',
            'tests/Feature/V2/',
            'docs/API.md',
        ],
        self::STACK_WEB => [
            'app/Http/Controllers/Web/',
            'app/Http/Requests/Web/',
            'app/Support/Money.php',
            'resources/',
            'public/',
            'routes/web_ui.php',
            'tests/Feature/Web/',
            'tests/Unit/MoneyTest.php',
            'docs/WEB.md',
        ],
    ];

    /**
     * Packages and paths that mean the application already owns its
     * session-authentication flow. When one is present the web stack is
     * scaffolded without a login controller, request or view, and
     * routes/web_ui.php leaves the credential routes alone.
     *
     * @var list<string>
     */
    private const AUTH_SCAFFOLDING = [
        'laravel/ui',
        'laravel/breeze',
        'laravel/jetstream',
        'laravel/fortify',
    ];

    /**
     * The web stack's auth slice, skipped when the application has its own.
     *
     * @var list<string>
     */
    private const WEB_AUTH_PATHS = [
        'app/Http/Controllers/Web/Auth/',
        'app/Http/Requests/Web/LoginRequest.php',
        'resources/views/auth/',
        'tests/Feature/Web/LoginTest.php',
    ];

    protected $signature = 'cachewraith:install
                            {--stack= : Which front door to scaffold: api, web or both}
                            {--force : Overwrite scaffolded files that already exist}
                            {--publish-stubs : Also copy the raw stub tree to stubs/cachewraith-template/ for customisation}';

    protected $description = 'Scaffold the clean, OOAD-based, OWASP-hardened layered structure — versioned JSON API, Blade UI, or both';

    /** @var array<int, string> */
    private array $created = [];

    /** @var array<int, string> */
    private array $skipped = [];

    /** @var array<int, string> */
    private array $wired = [];

    /** @var array<int, array{0: string, 1: string}> */
    private array $manual = [];

    /** @var array<int, string> */
    private array $notes = [];

    private string $stack = self::STACK_API;

    private string $stubRoot = '';

    private bool $ownsAuthScaffolding = false;

    public function handle(Filesystem $files): int
    {
        $this->components->info('Installing cachewraith/laravel-template-structure...');

        $this->stack = $this->resolveStack();

        if ($this->installsWeb()) {
            $this->ownsAuthScaffolding = $this->detectAuthScaffolding($files);
        }

        if ($this->option('publish-stubs')) {
            $this->publishStubs();
        }

        $source = $this->stubRoot = $this->stubSource($files);
        $this->line('  <fg=gray>Stack: '.$this->stack.'  ·  stub source: '.$this->relative($source).'</>');

        $this->copyApplicationTree($files, $source);
        $this->copyTree($files, $source.'/config', config_path());
        $this->copyTree($files, $source.'/database', base_path('database'));
        $this->copyTree($files, $source.'/routes', base_path('routes'));
        $this->copyTree($files, $source.'/tests', base_path('tests'));
        $this->copyTree($files, $source.'/docker', base_path('docker'));
        $this->copyTree($files, $source.'/docs', base_path('docs'));
        $this->copyTree($files, $source.'/resources', resource_path());
        $this->copyTree($files, $source.'/public', public_path());

        // docker-compose*.yml, .dockerignore.
        $this->copyTree($files, $source.'/root', base_path(), ['CLAUDE.md', 'AGENTS.md']);
        $this->mergeAgentGuidance($files, $source);

        if ($this->isLaravel11OrAbove()) {
            $this->wireBootstrapApp($files);
        } else {
            $this->wireRouteServiceProvider($files);
            $this->wireHttpKernel($files);
            $this->wireLegacyExceptionHandler($files);
        }

        $this->registerProviders($files);
        $this->wireAppServiceProvider($files);
        $this->appendRepositoryBindings($files);
        $this->noteEnvExample($files);
        $this->checkConfigIsCurrent($files);
        $this->checkLayoutIsCompatible($files);

        $this->summary();

        return self::SUCCESS;
    }

    /*
    |----------------------------------------------------------------------
    | Stack selection
    |----------------------------------------------------------------------
    */

    /**
     * --stack wins; otherwise ask, defaulting to "api".
     *
     * The default is "api" rather than "both" on purpose: it is what every
     * release before the web stack existed produced, so an unattended re-run
     * — a deploy script, a CI step, --no-interaction — keeps doing exactly
     * what it did yesterday and never drops a resources/views tree into a
     * project that has no use for one.
     */
    private function resolveStack(): string
    {
        $given = $this->option('stack');

        if (is_string($given) && $given !== '') {
            $given = strtolower(trim($given));

            if (in_array($given, [self::STACK_API, self::STACK_WEB, self::STACK_BOTH], true)) {
                return $given;
            }

            $this->components->warn(
                'Unknown --stack='.$given.'. Expected api, web or both — falling back to api.'
            );

            return self::STACK_API;
        }

        if (! $this->input->isInteractive()) {
            return self::STACK_API;
        }

        return $this->choice(
            'Which front door should be scaffolded?',
            [
                self::STACK_API => 'api   — versioned JSON API (Sanctum tokens, resources, envelope)',
                self::STACK_WEB => 'web   — Blade UI (session auth, forms, views)',
                self::STACK_BOTH => 'both  — one set of services, policies and repositories behind both',
            ],
            self::STACK_API,
        );
    }

    private function installsApi(): bool
    {
        return $this->stack === self::STACK_API || $this->stack === self::STACK_BOTH;
    }

    private function installsWeb(): bool
    {
        return $this->stack === self::STACK_WEB || $this->stack === self::STACK_BOTH;
    }

    /**
     * Does this application already own a sign-in flow?
     *
     * Overwriting Breeze's routes, controllers and views — or racing them for
     * the "login" route name — is a worse outcome than shipping no auth at
     * all, so the scaffold defers. routes/web_ui.php guards its credential
     * routes on class_exists, so skipping the controller is all it takes.
     */
    private function detectAuthScaffolding(Filesystem $files): bool
    {
        if ($files->isDirectory(app_path('Http/Controllers/Auth'))) {
            $this->notes[] = 'app/Http/Controllers/Auth exists — the sign-in slice was not generated.';

            return true;
        }

        $manifest = base_path('composer.json');

        if (! $files->exists($manifest)) {
            return false;
        }

        /** @var array{require?: array<string, string>, require-dev?: array<string, string>}|null $composer */
        $composer = json_decode($files->get($manifest), true);

        if (! is_array($composer)) {
            return false;
        }

        $required = array_merge(
            array_keys((array) ($composer['require'] ?? [])),
            array_keys((array) ($composer['require-dev'] ?? [])),
        );

        foreach (self::AUTH_SCAFFOLDING as $package) {
            if (in_array($package, $required, true)) {
                $this->notes[] = $package.' is installed — the sign-in slice was not generated; its routes stay yours.';

                return true;
            }
        }

        return false;
    }

    /**
     * Is this stub path part of the selected installation?
     *
     * The auth slice is checked first because it is a subset of the web
     * stack with an extra condition, and a later, broader web rule would
     * otherwise claim it.
     *
     * Anything matching no rule is shared — models, services, repositories,
     * policies, config, migrations, Docker. That default is deliberate: a new
     * shared file is installed for every stack without anyone remembering to
     * list it, and only transport-specific files need an entry.
     */
    private function isSelected(string $relative): bool
    {
        if ($this->matchesAny($relative, self::WEB_AUTH_PATHS)) {
            return $this->installsWeb() && ! $this->ownsAuthScaffolding;
        }

        if ($this->matchesAny($relative, self::STACK_PATHS[self::STACK_API])) {
            return $this->installsApi();
        }

        if ($this->matchesAny($relative, self::STACK_PATHS[self::STACK_WEB])) {
            return $this->installsWeb();
        }

        return true;
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function matchesAny(string $relative, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /*
    |----------------------------------------------------------------------
    | Steps
    |----------------------------------------------------------------------
    */

    /**
     * Opt-in only. The published tree is a customisation point, not part of a
     * normal install — a project that never edits the stubs should not carry a
     * copy of them.
     */
    private function publishStubs(): void
    {
        $this->callSilently('vendor:publish', array_filter([
            '--tag' => 'cachewraith-template-stubs',
            '--force' => $this->option('force') ? true : null,
        ]));

        $this->wired[] = 'stubs/cachewraith-template: raw stub tree published for customisation';
    }

    /**
     * Prefer stubs the team has published (and possibly customised) over the
     * packaged originals, so re-running the installer preserves local edits.
     */
    private function stubSource(Filesystem $files): string
    {
        $published = base_path('stubs/cachewraith-template');

        return $files->isDirectory($published.'/app')
            ? $published
            : PackageProvider::packagePath('stubs');
    }

    /**
     * The app/ tree, with one version-dependent exception.
     *
     * Laravel 11+ has no app/Exceptions/Handler.php, so the mapper lands there
     * under its own name and is wired through withExceptions(). Laravel 10
     * already owns that path, so the mapper is installed alongside it as
     * ApiExceptionHandler and the application's handler delegates to it.
     */
    private function copyApplicationTree(Filesystem $files, string $source): void
    {
        $handlerStub = $source.'/app/Exceptions/Handler.php';

        if ($this->isLaravel11OrAbove()) {
            $this->copyTree($files, $source.'/app', app_path());

            return;
        }

        $this->copyTree($files, $source.'/app', app_path(), ['Exceptions/Handler.php']);

        $target = app_path('Exceptions/ApiExceptionHandler.php');

        if ($files->exists($target) && ! $this->option('force')) {
            $this->skipped[] = $this->relative($target);

            return;
        }

        if (! $files->exists($handlerStub)) {
            return;
        }

        $files->ensureDirectoryExists(dirname($target));
        $files->put($target, str_replace(
            ['final class Handler', '{@see Handler}'],
            ['final class ApiExceptionHandler', '{@see ApiExceptionHandler}'],
            $files->get($handlerStub),
        ));

        $this->created[] = $this->relative($target);
    }

    /**
     * CLAUDE.md and AGENTS.md, appended rather than replaced.
     *
     * Laravel 12+ ships both files with its own Boost guidelines, and other
     * tooling adds to them too. Skipping them (the copyTree default) would
     * leave an agent with no idea about the layer rules; overwriting them
     * would throw away instructions the application already relies on. So the
     * guidance is appended inside a fenced marker block, which also makes a
     * re-run idempotent and the block easy to delete.
     */
    private function mergeAgentGuidance(Filesystem $files, string $source): void
    {
        $marker = '<!-- cachewraith-template:begin -->';

        foreach (['CLAUDE.md', 'AGENTS.md'] as $name) {
            $stub = $source.'/root/'.$name;

            if (! $files->exists($stub)) {
                continue;
            }

            $target = base_path($name);

            if (! $files->exists($target)) {
                $files->put($target, $marker."\n\n".$files->get($stub)."\n\n<!-- cachewraith-template:end -->\n");
                $this->created[] = $this->relative($target);

                continue;
            }

            if (str_contains($files->get($target), $marker)) {
                $this->wired[] = $name.' already carries the cachewraith-template guidance';

                continue;
            }

            $files->append($target, "\n\n".$marker."\n\n".$files->get($stub)."\n\n<!-- cachewraith-template:end -->\n");
            $this->wired[] = $name.': architecture, OWASP and design-pattern guidance appended';
        }
    }

    /**
     * Copy a directory tree, never clobbering existing application files
     * unless --force was given, and never copying a file that belongs to a
     * stack this install did not ask for.
     *
     * @param  array<int, string>  $except  Paths, relative to $from, to leave behind.
     */
    private function copyTree(Filesystem $files, string $from, string $to, array $except = []): void
    {
        if (! $files->isDirectory($from)) {
            return;
        }

        $files->ensureDirectoryExists($to);

        foreach ($files->allFiles($from, true) as $file) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());

            if (in_array($relative, $except, true)) {
                continue;
            }

            // isSelected() reasons about paths relative to the stub root, not
            // to the directory being copied, so that one table can describe
            // "app/Http/Controllers/Web/" and "resources/" in the same terms.
            if (! $this->isSelected($this->stubRelative($from, $relative))) {
                continue;
            }

            $target = $to.DIRECTORY_SEPARATOR.$file->getRelativePathname();

            if ($files->exists($target) && ! $this->option('force')) {
                $this->skipped[] = $this->relative($target);

                continue;
            }

            $files->ensureDirectoryExists(dirname($target));
            $files->copy($file->getPathname(), $target);
            $this->created[] = $this->relative($target);
        }
    }

    /**
     * Re-root a path that is relative to $from onto the stub tree's root.
     */
    private function stubRelative(string $from, string $relative): string
    {
        $prefix = trim(str_replace(
            str_replace(DIRECTORY_SEPARATOR, '/', $this->stubRoot),
            '',
            str_replace(DIRECTORY_SEPARATOR, '/', $from),
        ), '/');

        return $prefix === '' ? $relative : $prefix.'/'.$relative;
    }

    /*
    |----------------------------------------------------------------------
    | Laravel 11+ wiring — bootstrap/app.php
    |----------------------------------------------------------------------
    */

    private function wireBootstrapApp(Filesystem $files): void
    {
        $path = base_path('bootstrap/app.php');

        if (! $files->exists($path)) {
            $this->manual[] = ['bootstrap/app.php', $this->routingSnippet()];

            return;
        }

        $contents = $files->get($path);
        $contents = $this->injectRouting($contents);
        $contents = $this->injectMiddleware($contents);
        $contents = $this->injectExceptions($contents);

        $files->put($path, $contents);
    }

    private function injectRouting(string $contents): string
    {
        $api = $this->installsApi() && ! str_contains($contents, 'routes/api_');
        $web = $this->installsWeb() && ! $this->mentionsWebRoutes($contents);

        if (! $api && ! $web) {
            $this->wired[] = 'bootstrap/app.php already loads the scaffolded route files';

            return $contents;
        }

        $open = strpos($contents, '->withRouting(');

        if ($open === false) {
            $this->manual[] = ['bootstrap/app.php (inside ->withRouting())', $this->routingSnippet($api, $web)];

            return $contents;
        }

        $parenAt = $open + strlen('->withRouting');
        $close = $this->matchingParen($contents, $parenAt);

        if ($close === null) {
            $this->manual[] = ['bootstrap/app.php (inside ->withRouting())', $this->routingSnippet($api, $web)];

            return $contents;
        }

        // An existing then: closure is the application's own — or one this
        // installer wrote on an earlier run for the other stack. Merging into
        // it automatically would be guesswork, so hand it back to the
        // developer rather than producing a file that does not parse.
        if (str_contains(substr($contents, $open, $close - $open), 'then:')) {
            $this->manual[] = ['bootstrap/app.php (inside the existing then: closure)', $this->routingGroupSnippet($api, $web)];

            return $contents;
        }

        $before = rtrim(substr($contents, 0, $close));

        // ->withRouting() may already end with a comma, or have no arguments
        // at all; both must stay syntactically valid after the injection.
        if (! str_ends_with($before, ',') && ! str_ends_with($before, '(')) {
            $before .= ',';
        }

        $injected = $before."\n".$this->routingSnippet($api, $web)."\n    ".substr($contents, $close);

        if ($api) {
            $this->wired[] = 'bootstrap/app.php: routes/api_{version}.php loaded under the api group, name-prefixed per version';
        }

        if ($web) {
            $this->wired[] = 'bootstrap/app.php: routes/web_ui.php loaded under the web group';
        }

        return $injected;
    }

    private function mentionsWebRoutes(string $contents): bool
    {
        return str_contains($contents, 'web.routes_file') || str_contains($contents, 'routes/web_ui');
    }

    private function injectMiddleware(string $contents): string
    {
        // Guarded piece by piece, not all-or-nothing: installing the API
        // today after installing the web stack yesterday must still register
        // ForceJsonResponse, and a single "is SecurityHeaders here?" check
        // would silently decide the file was already done.
        $json = $this->installsApi() && ! str_contains($contents, 'Middleware\\ForceJsonResponse');
        $headers = ! str_contains($contents, 'Middleware\\SecurityHeaders');
        $alias = ! str_contains($contents, "'ratelimit.api'");

        if (! $json && ! $headers && ! $alias) {
            $this->wired[] = 'bootstrap/app.php already registers the scaffolded middleware';

            return $contents;
        }

        $pattern = '/->withMiddleware\(\s*function\s*\(\s*[\\\\\w]*Middleware\s+\$middleware\s*\)\s*(?::\s*void\s*)?\{/';

        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            $this->manual[] = ['bootstrap/app.php (inside ->withMiddleware())', $this->middlewareSnippet($json, $headers, $alias)];

            return $contents;
        }

        $at = $matches[0][1] + strlen($matches[0][0]);

        $registered = array_filter([
            $json ? 'ForceJsonResponse prepended to the api group' : null,
            $headers ? 'SecurityHeaders appended globally' : null,
            $alias ? 'ratelimit.api aliased' : null,
        ]);

        $this->wired[] = 'bootstrap/app.php: '.implode(', ', $registered);

        return substr($contents, 0, $at)."\n".$this->middlewareSnippet($json, $headers, $alias)."\n".substr($contents, $at);
    }

    private function injectExceptions(string $contents): string
    {
        if (str_contains($contents, 'Exceptions\\Handler::register')) {
            $this->wired[] = 'bootstrap/app.php already registers App\Exceptions\Handler';

            return $contents;
        }

        $pattern = '/->withExceptions\(\s*function\s*\(\s*[\\\\\w]*Exceptions\s+\$exceptions\s*\)\s*(?::\s*void\s*)?\{/';

        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            $this->manual[] = ['bootstrap/app.php (inside ->withExceptions())', $this->exceptionsSnippet()];

            return $contents;
        }

        $at = $matches[0][1] + strlen($matches[0][0]);

        $this->wired[] = 'bootstrap/app.php: App\Exceptions\Handler renders the uniform JSON error envelope';

        return substr($contents, 0, $at)."\n".$this->exceptionsSnippet()."\n".substr($contents, $at);
    }

    /*
    |----------------------------------------------------------------------
    | Laravel 10 wiring
    |----------------------------------------------------------------------
    */

    private function wireRouteServiceProvider(Filesystem $files): void
    {
        $path = app_path('Providers/RouteServiceProvider.php');
        $api = $this->installsApi();
        $web = $this->installsWeb();

        if (! $files->exists($path)) {
            $this->manual[] = ['app/Providers/RouteServiceProvider.php', $this->routingGroupSnippet($api, $web)];

            return;
        }

        $contents = $files->get($path);
        $api = $api && ! str_contains($contents, 'routes/api_');
        $web = $web && ! $this->mentionsWebRoutes($contents);

        if (! $api && ! $web) {
            $this->wired[] = 'RouteServiceProvider already loads the scaffolded route files';

            return;
        }

        $pattern = '/\$this->routes\(\s*function\s*\(\s*\)\s*(?::\s*void\s*)?\{/';

        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            $this->manual[] = ['app/Providers/RouteServiceProvider.php (inside $this->routes())', $this->routingGroupSnippet($api, $web)];

            return;
        }

        $at = $matches[0][1] + strlen($matches[0][0]);
        $files->put($path, substr($contents, 0, $at)."\n".$this->routingGroupSnippet($api, $web)."\n".substr($contents, $at));

        if ($api) {
            $this->wired[] = 'RouteServiceProvider: routes/api_{version}.php loaded under the api middleware group';
        }

        if ($web) {
            $this->wired[] = 'RouteServiceProvider: routes/web_ui.php loaded under the web middleware group';
        }
    }

    private function wireHttpKernel(Filesystem $files): void
    {
        $path = app_path('Http/Kernel.php');

        if (! $files->exists($path)) {
            $this->manual[] = ['app/Http/Kernel.php', $this->kernelSnippet($this->installsApi(), true, true)];

            return;
        }

        $contents = $files->get($path);

        // Piece by piece, so installing the second stack later still lands
        // the middleware the first one did not need.
        $json = $this->installsApi() && ! str_contains($contents, 'Middleware\\ForceJsonResponse');
        $headers = ! str_contains($contents, 'Middleware\\SecurityHeaders');
        $alias = ! str_contains($contents, "'ratelimit.api'");

        if (! $json && ! $headers && ! $alias) {
            $this->wired[] = 'app/Http/Kernel.php already registers the scaffolded middleware';

            return;
        }

        $patched = $contents;

        $insertions = array_filter([
            $headers ? ['/protected \$middleware = \[/', "\n        \\App\\Http\\Middleware\\SecurityHeaders::class,"] : null,
            $json ? ["/'api' => \[/", "\n            \\App\\Http\\Middleware\\ForceJsonResponse::class,"] : null,
            $alias ? ['/protected \$middlewareAliases = \[/', "\n        'ratelimit.api' => \\App\\Http\\Middleware\\RateLimitApi::class,"] : null,
        ]);

        foreach ($insertions as [$pattern, $insertion]) {
            if (preg_match($pattern, $patched, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $at = $m[0][1] + strlen($m[0][0]);
            $patched = substr($patched, 0, $at).$insertion.substr($patched, $at);
        }

        if ($patched === $contents) {
            $this->manual[] = ['app/Http/Kernel.php', $this->kernelSnippet($json, $headers, $alias)];

            return;
        }

        $files->put($path, $patched);

        $registered = array_filter([
            $headers ? 'SecurityHeaders (global)' : null,
            $json ? 'ForceJsonResponse (api group)' : null,
            $alias ? 'ratelimit.api alias' : null,
        ]);

        $this->wired[] = 'app/Http/Kernel.php: '.implode(', ', $registered);
    }

    private function wireLegacyExceptionHandler(Filesystem $files): void
    {
        $path = app_path('Exceptions/Handler.php');

        if (! $files->exists($path)) {
            $this->manual[] = ['app/Exceptions/Handler.php', $this->legacyExceptionsSnippet()];

            return;
        }

        $contents = $files->get($path);

        if (str_contains($contents, 'ApiExceptionHandler')) {
            $this->wired[] = 'app/Exceptions/Handler.php already delegates to ApiExceptionHandler';

            return;
        }

        $pattern = '/public function register\(\)\s*(?::\s*void\s*)?\{/';

        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            $this->manual[] = ['app/Exceptions/Handler.php (inside register())', $this->legacyExceptionsSnippet()];

            return;
        }

        $at = $matches[0][1] + strlen($matches[0][0]);
        $files->put($path, substr($contents, 0, $at)."\n".$this->legacyExceptionsSnippet()."\n".substr($contents, $at));

        $this->wired[] = 'app/Exceptions/Handler.php: ApiExceptionHandler renders API failures';
    }

    /*
    |----------------------------------------------------------------------
    | Provider registration + repository bindings
    |----------------------------------------------------------------------
    */

    private function registerProviders(Filesystem $files): void
    {
        $provider = 'App\\Providers\\RepositoryServiceProvider';

        $path = base_path('bootstrap/providers.php');

        if ($files->exists($path)) {
            $contents = $files->get($path);

            if (str_contains($contents, $provider)) {
                return;
            }

            $close = strrpos($contents, '];');

            if ($close === false) {
                $this->manual[] = ['bootstrap/providers.php', '    '.$provider.'::class,'];

                return;
            }

            $files->put($path, substr($contents, 0, $close).'    '.$provider."::class,\n".substr($contents, $close));
            $this->wired[] = 'bootstrap/providers.php: registered '.$provider;

            return;
        }

        // Laravel 10 fallback.
        $path = config_path('app.php');

        if (! $files->exists($path)) {
            $this->manual[] = ['provider registration', $provider.'::class,'];

            return;
        }

        $contents = $files->get($path);

        if (str_contains($contents, $provider)) {
            return;
        }

        $anchor = 'App\\Providers\\RouteServiceProvider::class,';

        if (! str_contains($contents, $anchor)) {
            $this->manual[] = ["config/app.php ('providers' array)", '    '.$provider.'::class,'];

            return;
        }

        $files->put($path, str_replace(
            $anchor,
            $anchor."\n        ".$provider.'::class,',
            $contents,
        ));

        $this->wired[] = 'config/app.php: registered '.$provider;
    }

    /**
     * The global security defaults go into the application's own
     * AppServiceProvider rather than a provider of ours, because that is where
     * a reviewer looks for them. It is a file the application owns, so the
     * patch is guarded and falls back to printing the snippet.
     */
    private function wireAppServiceProvider(Filesystem $files): void
    {
        $path = app_path('Providers/AppServiceProvider.php');

        if (! $files->exists($path)) {
            $this->manual[] = ['app/Providers/AppServiceProvider.php (inside boot())', $this->securityDefaultsSnippet()];

            return;
        }

        $contents = $files->get($path);

        if (str_contains($contents, 'cachewraith-template: global security defaults')) {
            $this->wired[] = 'AppServiceProvider already applies the global security defaults';

            return;
        }

        $pattern = '/public function boot\(\)\s*(?::\s*void\s*)?\s*\{/';

        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            $this->manual[] = ['app/Providers/AppServiceProvider.php (inside boot())', $this->securityDefaultsSnippet()];

            return;
        }

        $at = $matches[0][1] + strlen($matches[0][0]);
        $files->put($path, substr($contents, 0, $at)."\n".$this->securityDefaultsSnippet()."\n".substr($contents, $at));

        $this->wired[] = 'AppServiceProvider: HTTPS scheme, strict models and destructive-command guard applied';
    }

    /**
     * Append contract => implementation pairs below the marker comment in the
     * generated RepositoryServiceProvider.
     *
     * @param  array<class-string|string, class-string|string>|null  $bindings
     */
    private function appendRepositoryBindings(Filesystem $files, ?array $bindings = null): void
    {
        $bindings ??= [
            'App\\Repositories\\Contracts\\UserRepositoryInterface' => 'App\\Repositories\\UserRepository',
            'App\\Repositories\\Contracts\\ItemRepositoryInterface' => 'App\\Repositories\\ItemRepository',
        ];

        $path = app_path('Providers/RepositoryServiceProvider.php');

        if (! $files->exists($path)) {
            return;
        }

        $contents = $files->get($path);
        $marker = '// cachewraith-template:bindings';

        if (! str_contains($contents, $marker)) {
            foreach ($bindings as $contract => $implementation) {
                if (! str_contains($contents, $contract)) {
                    $this->manual[] = [
                        'app/Providers/RepositoryServiceProvider.php ($bindings)',
                        '        \\'.$contract.'::class => \\'.$implementation.'::class,',
                    ];
                }
            }

            return;
        }

        $additions = '';

        foreach ($bindings as $contract => $implementation) {
            if (str_contains($contents, $contract)) {
                continue;
            }

            $additions .= "\n        \\".$contract.'::class => \\'.$implementation.'::class,';
            $this->wired[] = 'RepositoryServiceProvider: bound '.class_basename($contract);
        }

        if ($additions === '') {
            return;
        }

        $files->put($path, str_replace($marker, $marker.$additions, $contents));
    }

    /**
     * OWASP A02: leave the production reminder where it will actually be read.
     */
    private function noteEnvExample(Filesystem $files): void
    {
        $path = base_path('.env.example');

        if (! $files->exists($path)) {
            return;
        }

        $contents = $files->get($path);

        if (str_contains($contents, 'cachewraith-laravel-template-structure')) {
            return;
        }

        $files->append($path, <<<'ENV'

# --- cachewraith-laravel-template-structure -----------------------------
# APP_DEBUG must be false in production: a debug response leaks file paths,
# SQL fragments and environment values (OWASP A02).
# APP_DEBUG=false
# APP_ENV=production
#
# CACHEWRAITH_SECURITY_HEADERS=true
# CACHEWRAITH_HSTS=true
# CACHEWRAITH_FORCE_HTTPS=true
# CACHEWRAITH_RATE_LIMITING=true
# CACHEWRAITH_API_PREFIX=api
# CACHEWRAITH_CURRENCY=USD
#
# The Blade UI. CACHEWRAITH_WEB=false unregisters every scaffolded page in
# one switch; CACHEWRAITH_WEB_PREFIX moves them all under one path.
# CACHEWRAITH_WEB=true
# CACHEWRAITH_WEB_PREFIX=
#
# Sessions back the Blade sign-in flow (OWASP A07). In production the cookie
# must be TLS-only and unreadable from JavaScript, and it must not be sent on
# cross-site navigations.
# SESSION_DRIVER=database
# SESSION_SECURE_COOKIE=true
# SESSION_HTTP_ONLY=true
# SESSION_SAME_SITE=lax
#
# Explicit CORS allow-list. Empty means "no browser origin is granted access"
# (OWASP A02) — name the origins, never use a wildcard with credentials.
# CORS_ALLOWED_ORIGINS="https://app.example.com"
#
# Required by docker-compose.yml. Generate real values; never commit them.
# DB_PASSWORD=
# REDIS_PASSWORD=
# APP_PORT=8080
#
# Local demo data only (database/seeders/UserSeeder.php).
# SEED_USER_PASSWORD=
# ------------------------------------------------------------------------
ENV);

        $this->wired[] = '.env.example: production hardening notes appended';
    }

    /**
     * An existing config/cachewraith-template.php is never overwritten
     * without --force, so a project scaffolded before the web stack existed
     * keeps a config file with no "web" and no "web_security_headers" keys.
     *
     * That degrades safely — SecurityHeaders falls back to a built-in web
     * profile and the route loader to its defaults — but the developer can no
     * longer tune either from config, and would have no way of knowing why.
     * So: say so, precisely, rather than forcing a file the application owns.
     */
    private function checkConfigIsCurrent(Filesystem $files): void
    {
        if (! $this->installsWeb()) {
            return;
        }

        $path = config_path('cachewraith-template.php');

        if (! $files->exists($path)) {
            return;
        }

        $contents = $files->get($path);

        $missing = array_values(array_filter([
            str_contains($contents, "'web_security_headers'") ? null : 'web_security_headers',
            str_contains($contents, "'web' =>") ? null : 'web',
        ]));

        if ($missing === []) {
            return;
        }

        $this->notes[] = 'config/cachewraith-template.php predates the web stack and is missing: '
            .implode(', ', $missing)
            .'. Safe built-in defaults apply until you copy the new sections in (or re-run with --force).';
    }

    /**
     * The scaffolded pages are @extends('layouts.app') + @section('content'),
     * so they need a layout that yields that section.
     *
     * Breeze and Jetstream ship a resources/views/layouts/app.blade.php of
     * their own, and theirs is a *component* layout built around $slot with
     * no @yield at all. copyTree leaves an existing file alone, which is the
     * right instinct — but the failure it produces here is silent: the pages
     * render, the chrome appears, and the content is simply missing. So look,
     * and say so.
     */
    private function checkLayoutIsCompatible(Filesystem $files): void
    {
        if (! $this->installsWeb()) {
            return;
        }

        $path = resource_path('views/layouts/app.blade.php');

        if (! $files->exists($path) || str_contains($files->get($path), "@yield('content')")) {
            return;
        }

        $this->notes[] = 'resources/views/layouts/app.blade.php already exists and does not @yield(\'content\'). '
            .'The scaffolded pages extend it and would render empty. Either add @yield(\'content\') to your layout, '
            .'or point the scaffolded views at a layout of their own.';
    }

    /*
    |----------------------------------------------------------------------
    | Snippets
    |----------------------------------------------------------------------
    */

    private function routingSnippet(bool $api = true, bool $web = false): string
    {
        return "        then: function (): void {\n".$this->routingGroupSnippet($api, $web)."\n        },";
    }

    /**
     * The body only — used inside an existing then: closure, and inside
     * RouteServiceProvider::routes() on Laravel 10.
     */
    private function routingGroupSnippet(bool $api = true, bool $web = false): string
    {
        $parts = [];

        if ($api) {
            $parts[] = <<<'PHP'
            // cachewraith-template: every configured API version is loaded from
            // routes/api_{version}.php. Adding v2 never means editing v1
            // (Open/Closed).
            $apiPrefix = trim((string) config('cachewraith-template.api_version_prefix', 'api'), '/');

            foreach ((array) config('cachewraith-template.versions', ['v1']) as $version) {
                $file = base_path("routes/api_{$version}.php");

                if (file_exists($file)) {
                    \Illuminate\Support\Facades\Route::middleware('api')
                        ->prefix($apiPrefix.'/'.$version)
                        ->name($version.'.')
                        ->group($file);
                }
            }
PHP;
        }

        if ($web) {
            $parts[] = <<<'PHP'
            // cachewraith-template: the Blade UI, from routes/web_ui.php.
            // routes/web.php stays yours and is loaded as usual; delete
            // web_ui.php and the scaffolded pages are gone. The route names in
            // it are deliberately unprefixed — Laravel's own Authenticate
            // middleware redirects a guest to route('login').
            if (config('cachewraith-template.web.enabled', true)) {
                $webRoutes = base_path((string) config('cachewraith-template.web.routes_file', 'routes/web_ui.php'));

                if (file_exists($webRoutes)) {
                    \Illuminate\Support\Facades\Route::middleware('web')
                        ->prefix(trim((string) config('cachewraith-template.web.prefix', ''), '/'))
                        ->group($webRoutes);
                }
            }
PHP;
        }

        return implode("\n\n", $parts);
    }

    private function middlewareSnippet(bool $json = true, bool $headers = true, bool $alias = true): string
    {
        $parts = [];

        if ($json) {
            $parts[] = <<<'PHP'
        // Runs before auth and validation so API error paths stay JSON
        // (A02/A10). Scoped to the api group: it must never touch the web
        // group, where an HTML error page is the correct answer.
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);
PHP;
        }

        if ($headers) {
            $parts[] = <<<'PHP'
        // CSP, framing, MIME-sniffing, referrer and HSTS headers (A02).
        // Global, so routes added later inherit it. SecurityHeaders picks the
        // API or the web header profile per request — see
        // config/cachewraith-template.php.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
PHP;
        }

        if ($alias) {
            $parts[] = <<<'PHP'
        // Per-bucket ceiling, selected by each route (A06/A07):
        //     ->middleware('ratelimit.api')  /  ->middleware('ratelimit.api:login')
        // Used by the sign-in routes of both front doors — the "login" bucket
        // is keyed on email + IP whether the credentials arrived as JSON or
        // as a form post.
        $middleware->alias([
            'ratelimit.api' => \App\Http\Middleware\RateLimitApi::class,
        ]);
PHP;
        }

        return implode("\n\n", $parts);
    }

    private function exceptionsSnippet(): string
    {
        return <<<'PHP'
        // Uniform JSON error envelope: {success, message, errors, code}.
        \App\Exceptions\Handler::register($exceptions);
PHP;
    }

    private function legacyExceptionsSnippet(): string
    {
        return <<<'PHP'
        $this->renderable(function (\Throwable $e, $request) {
            return (new \App\Exceptions\ApiExceptionHandler())->render($request, $e);
        });
PHP;
    }

    private function kernelSnippet(bool $json = true, bool $headers = true, bool $alias = true): string
    {
        $parts = [];

        if ($headers) {
            $parts[] = <<<'PHP'
// protected $middleware = [
        \App\Http\Middleware\SecurityHeaders::class,
// ];
PHP;
        }

        if ($json) {
            $parts[] = <<<'PHP'
// 'api' => [
            \App\Http\Middleware\ForceJsonResponse::class,
// ],
PHP;
        }

        if ($alias) {
            $parts[] = <<<'PHP'
// protected $middlewareAliases = [
        'ratelimit.api' => \App\Http\Middleware\RateLimitApi::class,
// ];
PHP;
        }

        return implode("\n\n", $parts);
    }

    private function securityDefaultsSnippet(): string
    {
        return <<<'PHP'
        // --- cachewraith-template: global security defaults ----------------
        // A04: never generate an http:// URL outside local development. Set
        // CACHEWRAITH_FORCE_HTTPS explicitly if TLS terminates at a proxy and
        // TrustProxies is not configured yet.
        $forceHttps = config('cachewraith-template.force_https');

        if ($forceHttps ?? ! $this->app->environment(['local', 'testing'])) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        // A01/A06: fail loudly in development rather than silently in
        // production — a silently discarded attribute and an accidental
        // lazy-load become exceptions you can see.
        \Illuminate\Database\Eloquent\Model::shouldBeStrict(! $this->app->isProduction());

        // Refuse migrate:fresh / db:wipe against production (Laravel 11+).
        if ($this->app->isProduction()
            && method_exists(\Illuminate\Database\DatabaseManager::class, 'prohibitDestructiveCommands')) {
            \Illuminate\Support\Facades\DB::prohibitDestructiveCommands();
        }

        // Policies are discovered by name (App\Models\Item ->
        // App\Policies\ItemPolicy). If you move or rename either side, pair
        // them here with Gate::policy() — a policy that stops being found
        // fails *open*.
        // -------------------------------------------------------------------
PHP;
    }

    /*
    |----------------------------------------------------------------------
    | Helpers
    |----------------------------------------------------------------------
    */

    private function isLaravel11OrAbove(): bool
    {
        return version_compare($this->laravel->version(), '11.0.0', '>=');
    }

    /**
     * Offset of the parenthesis matching the one at $openAt, ignoring parens
     * inside strings and comments (bootstrap/app.php is full of both).
     */
    private function matchingParen(string $contents, int $openAt): ?int
    {
        $depth = 0;
        $length = strlen($contents);

        for ($i = $openAt; $i < $length; $i++) {
            $char = $contents[$i];

            if ($char === "'" || $char === '"') {
                $i = $this->skipString($contents, $i);

                continue;
            }

            if ($char === '/' && $i + 1 < $length && ($contents[$i + 1] === '/' || $contents[$i + 1] === '*')) {
                $i = $this->skipComment($contents, $i);

                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function skipString(string $contents, int $start): int
    {
        $quote = $contents[$start];
        $length = strlen($contents);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($contents[$i] === '\\') {
                $i++;

                continue;
            }

            if ($contents[$i] === $quote) {
                return $i;
            }
        }

        return $length;
    }

    private function skipComment(string $contents, int $start): int
    {
        if ($contents[$start + 1] === '/') {
            $end = strpos($contents, "\n", $start);

            return $end === false ? strlen($contents) : $end;
        }

        $end = strpos($contents, '*/', $start);

        return $end === false ? strlen($contents) : $end + 1;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }

    private function summary(): void
    {
        $this->newLine();

        if ($this->created !== []) {
            $this->components->info('Created '.count($this->created).' file(s):');
            foreach ($this->created as $file) {
                $this->line('    <fg=green>+</> '.$file);
            }
        }

        if ($this->skipped !== []) {
            $this->newLine();
            $this->components->warn('Left alone (already present — re-run with --force to overwrite):');
            foreach ($this->skipped as $file) {
                $this->line('    <fg=yellow>=</> '.$file);
            }
        }

        if ($this->wired !== []) {
            $this->newLine();
            $this->components->info('Wired up:');
            foreach ($this->wired as $note) {
                $this->line('    <fg=green>></> '.$note);
            }
        }

        if ($this->notes !== []) {
            $this->newLine();
            $this->components->warn('Worth knowing:');
            foreach ($this->notes as $note) {
                $this->line('    <fg=yellow>!</> '.$note);
            }
        }

        if ($this->manual !== []) {
            $this->newLine();
            $this->components->warn('Could not patch these automatically — add the snippet by hand:');
            foreach ($this->manual as [$where, $snippet]) {
                $this->newLine();
                $this->line('  <options=bold>'.$where.'</>');
                $this->line('<fg=gray>'.$snippet.'</>');
            }
        }

        $this->newLine();
        $this->components->info('Next steps');

        foreach ($this->nextSteps() as $index => [$command, $note]) {
            $this->line('    '.($index + 1).'. <options=bold>'.$command.'</>'.($note === '' ? '' : ' <fg=gray>'.$note.'</>'));
        }

        $this->newLine();
        $this->components->info($this->installsWeb()
            ? 'Read docs/ARCHITECTURE.md and docs/WEB.md, then copy the Item slice for your own aggregate and delete it.'
            : 'Read docs/ARCHITECTURE.md, then copy the Item slice for your own aggregate and delete it.');
        $this->newLine();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function nextSteps(): array
    {
        $steps = [['composer dump-autoload', '']];

        if ($this->installsApi()) {
            $steps[] = ['php artisan install:api', '(Laravel 11+ — installs Sanctum, needed by the API auth routes)'];
            $steps[] = ['Add Laravel\Sanctum\HasApiTokens to App\Models\User', ''];
        }

        $steps[] = ['php artisan migrate', '(creates the items table)'];

        if ($this->installsApi()) {
            $steps[] = ['php artisan route:list --path=api', '(you should see api/v1/items)'];
        }

        if ($this->installsWeb()) {
            $steps[] = ['php artisan db:seed --class=UserSeeder', '(a local account to sign in with — refuses to run in production)'];
            $steps[] = ['php artisan route:list --path=items', '(you should see the Blade item pages)'];

            if ($this->ownsAuthScaffolding) {
                $steps[] = [
                    'Point your existing sign-in flow at /dashboard',
                    '(the scaffold reuses your login routes; it defined none of its own)',
                ];
            } else {
                $steps[] = ['Visit /login', '(then /dashboard and /items)'];
            }

            $steps[] = [
                'Set SESSION_SECURE_COOKIE=true in production',
                '(A04/A07 — a session cookie sent over http is a session anyone on the path can take)',
            ];
        }

        $steps[] = ['php artisan test', '(the scaffolded feature and unit tests)'];
        $steps[] = ['composer audit --locked', '(OWASP A03 — wire this into CI)'];

        return $steps;
    }
}
