<?php

namespace Zrm\WorkshopDemo;

use Filament\Panel;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;

class WorkshopDemoServiceProvider extends PackageServiceProvider
{
    public static string $name = 'workshop-demo';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasSeeder('Zrm\\WorkshopDemo\\Database\\Seeders\\DatabaseSeeder')
            ->hasDependencies([
                'accounting',
                'inventories',
                'sales',
                'purchases',
                'contacts',
            ])
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->installDependencies()
                    ->runsSeeders();
            });
    }

    public function packageRegistered(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(WorkshopDemoPlugin::make());
        });
    }

    public function packageBooted(): void
    {
        //
    }
}
