<?php

declare(strict_types=1);

namespace Smn\LaravelTemplateStructure;

use Illuminate\Support\ServiceProvider;
use Smn\LaravelTemplateStructure\Console\InstallCommand;

/**
 * Package entry point.
 *
 * Facade pattern: this provider is the single, simple entry point into the
 * package for the host application. It wires up exactly three things — the
 * install command, the package config, and the publishable stub tree — and
 * deliberately registers no runtime behaviour of its own. Everything the
 * consuming application actually runs is scaffolded into that application by
 * the installer, so the package can be removed afterwards without breaking it.
 */
final class LaravelTemplateStructureServiceProvider extends ServiceProvider
{
    /**
     * Absolute path to the package root.
     */
    public static function packagePath(string $path = ''): string
    {
        return rtrim(dirname(__DIR__).($path !== '' ? DIRECTORY_SEPARATOR.ltrim($path, '/\\') : ''), DIRECTORY_SEPARATOR);
    }

    /**
     * Register bindings and merge the package configuration.
     */
    public function register(): void
    {
        // Single source of truth: the same file is merged here, published by
        // the "smn-template-config" tag, and copied into config/ on install.
        $this->mergeConfigFrom(static::packagePath('stubs/config/smn-template.php'), 'smn-template');
    }

    /**
     * Bootstrap console-only concerns.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
        ]);

        // Publish the raw stub tree so a team can customise the generated code
        // before (or after) running php artisan smn:install. The installer
        // prefers these published stubs over the packaged ones when present.
        $this->publishes([
            static::packagePath('stubs') => base_path('stubs/smn-template'),
        ], 'smn-template-stubs');

        $this->publishes([
            static::packagePath('stubs/config/smn-template.php') => config_path('smn-template.php'),
        ], 'smn-template-config');
    }

    /**
     * @return array<int, class-string>
     */
    public function provides(): array
    {
        return [InstallCommand::class];
    }
}
