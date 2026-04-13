<?php

namespace Webkul\Inventory\Filament\Clusters\Products\Resources\ProductResource\Pages;

use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Inventory\Filament\Clusters\Products\Resources\ProductResource;
use Webkul\Product\Filament\Resources\ProductResource\Pages\ListProducts as BaseListProducts;
use Webkul\TableViews\Filament\Components\PresetView;

class ListProducts extends BaseListProducts
{
    protected static string $resource = ProductResource::class;

    public function getPresetTableViews(): array
    {
        return array_merge(parent::getPresetTableViews(), [
            'storable_products' => PresetView::make(__('inventories::filament/clusters/products/resources/product/pages/list-products.tabs.inventory-management'))
                ->icon('heroicon-s-clipboard-document-list')
                ->favorite()
                ->modifyQueryUsing(fn(Builder $query) => $query->where('is_storable', true)),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return array_merge(parent::getHeaderActions(), [
            Action::make('print_stock_report')
                ->label(__('inventories::filament/clusters/products/resources/product.table.header-actions.print-stock-report.label'))
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function (): mixed {
                    $records = $this->getFilteredTableQuery()
                        ->where('is_storable', true)
                        ->with(['category', 'uom', 'quantities.location'])
                        ->get();

                    $pdf = Pdf::loadView('inventories::filament.clusters.products.products.actions.print-stock-report', [
                        'records' => $records,
                    ]);

                    $pdf->setPaper('a4', 'portrait');

                    return response()->streamDownload(function () use ($pdf): void {
                        echo $pdf->output();
                    }, 'Product-Stock-Report-' . now()->format('Y-m-d') . '.pdf');
                }),
        ]);
    }
}
