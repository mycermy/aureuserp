<?php

namespace Zrm\WorkshopDemo;

use Filament\Actions\Action;
use Filament\Panel;
use Filament\Tables\Table;
use Webkul\Account\Filament\Resources\AccountResource as BaseAccountResource;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\AccountResource as AccountingAccountResource;
use Webkul\Accounting\Models\Account;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;
use Zrm\WorkshopDemo\Filament\Pages\AccountTransactions;

class WorkshopDemoServiceProvider extends PackageServiceProvider
{
    public static string $name = 'workshop-demo';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasViews()
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
        Table::configureUsing(function (Table $table): void {
            $livewire = $table->getLivewire();

            if (! is_callable([$livewire, 'getResource'])) {
                return;
            }

            $resourceClass = call_user_func([$livewire, 'getResource']);

            if (! in_array($resourceClass, [
                AccountingAccountResource::class,
                BaseAccountResource::class,
            ], true)) {
                return;
            }

            $table->pushRecordActions([
                Action::make('accountTransactions')
                    ->label('Transactions')
                    ->icon('heroicon-o-book-open')
                    ->url(fn(Account $record): string => AccountTransactions::getUrl([
                        'selectedAccount' => $record->getKey(),
                    ])),
            ]);
        });
    }
}
