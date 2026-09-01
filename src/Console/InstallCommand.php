<?php

declare(strict_types=1);

namespace Cachewraith\LaravelTemplateStructure\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Cachewraith\LaravelTemplateStructure\LaravelTemplateStructureServiceProvider as PackageProvider;

/**
 * Scaffolds the template structure into the host application.
 *
 * Template Method: handle() fixes the order of the installation — copy, wire,
 * report — while each step is a small private method that knows one file
 * format. Adding a step means adding a method and one line to handle(), not
 * restructuring the command.
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
    protected $signature = 'cachewraith:install
                            {--force : Overwrite scaffolded files that already exist}
                            {--publish-stubs : Also copy the raw stub tree to stubs/cachewraith-template/ for customisation}';

    protected $description = 'Scaffold the clean, OOAD-based, OWASP-hardened versioned-API structure into this application';

    /** @var array<int, string> */
    private array $created = [];

    /** @var array<int, string> */
    private array $skipped = [];

    /** @var array<int, string> */
    private array $wired = [];

    /** @var array<int, array{0: string, 1: string}> */
    private array $manual = [];

    public function handle(Filesystem $files): int
    {
        $this->components->info('Installing cachewraith/laravel-template-structure...');

        if ($this->option('publish-stubs')) {
            $this->publishStubs();
        }

        $source = $this->stubSource($files);
        $this->line('  <fg=gray>Stub source: '.$this->relative($source).'</>');

        $this->copyApplicationTree($files, $source);
        $this->copyTree($files, $source.'/config', config_path());
        $this->copyTree($files, $source.'/database', base_path('database'));
        $this->copyTree($files, $source.'/routes', base_path('routes'));
        $this->copyTree($files, $source.'/tests', base_path('tests'));
        $this->copyTree($files, $source.'/docker', base_path('docker'));
        $this->copyTree($files, $source.'/docs', base_path('docs'));

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

        $this->summary();

        return self::SUCCESS;
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
     * unless --force was given.
     *
     * @param  array<int, string>  $except  Relative paths to leave behind.
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
        if (str_contains($contents, 'routes/api_')) {
            $this->wired[] = 'bootstrap/app.php already loads routes/api_*.php';

            return $contents;
        }

        $open = strpos($contents, '->withRouting(');

        if ($open === false) {
            $this->manual[] = ['bootstrap/app.php (inside ->withRouting())', $this->routingSnippet()];

            return $contents;
        }

        $parenAt = $open + strlen('->withRouting');
        $close = $this->matchingParen($contents, $parenAt);

        if ($close === null) {
            $this->manual[] = ['bootstrap/app.php (inside ->withRouting())', $this->routingSnippet()];

            return $contents;
        }

        // An existing then: closure is the application's own; merging into it
        // automatically would be guesswork, so hand it back to the developer.
        if (str_contains(substr($contents, $open, $close - $open), 'then:')) {
            $this->manual[] = ['bootstrap/app.php (inside the existing then: closure)', $this->routingGroupSnippet()];

            return $contents;
        }

        $before = rtrim(substr($contents, 0, $close));

        // ->withRouting() may already end with a comma, or have no arguments
        // at all; both must stay syntactically valid after the injection.
        if (! str_ends_with($before, ',') && ! str_ends_with($before, '(')) {
            $before .= ',';
        }

        $injected = $before."\n".$this->routingSnippet()."\n    ".substr($contents, $close);

        $this->wired[] = 'bootstrap/app.php: routes/api_{version}.php loaded under the api group, name-prefixed per version';

        return $injected;
    }

    private function injectMiddleware(string $contents): string
    {
        if (str_contains($contents, 'Middleware\\SecurityHeaders')) {
            $this->wired[] = 'bootstrap/app.php already registers the security middleware';

            return $contents;
        }

        $pattern = '/->withMiddleware\(\s*function\s*\(\s*[\\\\\w]*Middleware\s+\$middleware\s*\)\s*(?::\s*void\s*)?\{/';

        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            $this->manual[] = ['bootstrap/app.php (inside ->withMiddleware())', $this->middlewareSnippet()];

            return $contents;
        }

        $at = $matches[0][1] + strlen($matches[0][0]);

        $this->wired[] = 'bootstrap/app.php: ForceJsonResponse prepended to the api group, SecurityHeaders appended globally, ratelimit.api aliased';

        return substr($contents, 0, $at)."\n".$this->middlewareSnippet()."\n".substr($contents, $at);
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

        if (! $files->exists($path)) {
            $this->manual[] = ['app/Providers/RouteServiceProvider.php', $this->routingGroupSnippet()];

            return;
        }

        $contents = $files->get($path);

        if (str_contains($contents, 'routes/api_')) {
            $this->wired[] = 'RouteServiceProvider already loads routes/api_*.php';

            return;
        }

        $pattern = '/\$this->routes\(\s*function\s*\(\s*\)\s*(?::\s*void\s*)?\{/';

        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            $this->manual[] = ['app/Providers/RouteServiceProvider.php (inside $this->routes())', $this->routingGroupSnippet()];

            return;
        }

        $at = $matches[0][1] + strlen($matches[0][0]);
        $files->put($path, substr($contents, 0, $at)."\n".$this->routingGroupSnippet()."\n".substr($contents, $at));

        $this->wired[] = 'RouteServiceProvider: routes/api_{version}.php loaded under the api middleware group';
    }

    private function wireHttpKernel(Filesystem $files): void
    {
        $path = app_path('Http/Kernel.php');

        if (! $files->exists($path)) {
            $this->manual[] = ['app/Http/Kernel.php', $this->kernelSnippet()];

            return;
        }

        $contents = $files->get($path);

        if (str_contains($contents, 'Middleware\\SecurityHeaders')) {
            $this->wired[] = 'app/Http/Kernel.php already registers the security middleware';

            return;
        }

        $patched = $contents;

        $insertions = [
            '/protected \$middleware = \[/' => "\n        \\App\\Http\\Middleware\\SecurityHeaders::class,",
            "/'api' => \[/" => "\n            \\App\\Http\\Middleware\\ForceJsonResponse::class,",
            '/protected \$middlewareAliases = \[/' => "\n        'ratelimit.api' => \\App\\Http\\Middleware\\RateLimitApi::class,",
        ];

        foreach ($insertions as $pattern => $insertion) {
            if (preg_match($pattern, $patched, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $at = $m[0][1] + strlen($m[0][0]);
            $patched = substr($patched, 0, $at).$insertion.substr($patched, $at);
        }

        if ($patched === $contents) {
            $this->manual[] = ['app/Http/Kernel.php', $this->kernelSnippet()];

            return;
        }

        $files->put($path, $patched);
        $this->wired[] = 'app/Http/Kernel.php: SecurityHeaders (global), ForceJsonResponse (api group), ratelimit.api alias';
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

    /*
    |----------------------------------------------------------------------
    | Snippets
    |----------------------------------------------------------------------
    */

    private function routingSnippet(): string
    {
        return <<<'PHP'
        then: function (): void {
            // Every configured API version is loaded from routes/api_{version}.php.
            // Adding v2 never means editing v1 (Open/Closed).
            $prefix = trim((string) config('cachewraith-template.api_version_prefix', 'api'), '/');

            foreach ((array) config('cachewraith-template.versions', ['v1']) as $version) {
                $file = base_path("routes/api_{$version}.php");

                if (file_exists($file)) {
                    \Illuminate\Support\Facades\Route::middleware('api')
                        ->prefix($prefix.'/'.$version)
                        ->name($version.'.')
                        ->group($file);
                }
            }
        },
PHP;
    }

    private function routingGroupSnippet(): string
    {
        return <<<'PHP'
            $prefix = trim((string) config('cachewraith-template.api_version_prefix', 'api'), '/');

            foreach ((array) config('cachewraith-template.versions', ['v1']) as $version) {
                $file = base_path("routes/api_{$version}.php");

                if (file_exists($file)) {
                    \Illuminate\Support\Facades\Route::middleware('api')
                        ->prefix($prefix.'/'.$version)
                        ->name($version.'.')
                        ->group($file);
                }
            }
PHP;
    }

    private function middlewareSnippet(): string
    {
        return <<<'PHP'
        // Runs before auth and validation so error paths stay JSON (A02/A10).
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);

        // CSP, framing, MIME-sniffing, referrer and HSTS headers (A02).
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Per-bucket ceiling, selected by each route (A06/A07):
        //     ->middleware('ratelimit.api')  /  ->middleware('ratelimit.api:login')
        $middleware->alias([
            'ratelimit.api' => \App\Http\Middleware\RateLimitApi::class,
        ]);
PHP;
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

    private function kernelSnippet(): string
    {
        return <<<'PHP'
// protected $middleware = [
        \App\Http\Middleware\SecurityHeaders::class,
// ];

// 'api' => [
            \App\Http\Middleware\ForceJsonResponse::class,
// ],

// protected $middlewareAliases = [
        'ratelimit.api' => \App\Http\Middleware\RateLimitApi::class,
// ];
PHP;
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
        $this->line('    1. <options=bold>composer dump-autoload</>');
        $this->line('    2. <options=bold>php artisan install:api</> <fg=gray>(Laravel 11+ — installs Sanctum, needed by the auth routes)</>');
        $this->line('    3. Add <options=bold>Laravel\Sanctum\HasApiTokens</> to App\Models\User');
        $this->line('    4. <options=bold>php artisan migrate</> <fg=gray>(creates the items table)</>');
        $this->line('    5. <options=bold>php artisan route:list --path=api</> <fg=gray>(you should see api/v1/items)</>');
        $this->line('    6. <options=bold>php artisan test</> <fg=gray>(the scaffolded Feature/V1 and Unit tests)</>');
        $this->line('    7. <options=bold>composer audit --locked</> <fg=gray>(OWASP A03 — wire this into CI)</>');
        $this->newLine();
        $this->components->info('Read docs/ARCHITECTURE.md, then copy the Item slice for your own aggregate and delete it.');
        $this->newLine();
    }
}
