<?php

use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Product as AccountProduct;
use Webkul\Inventory\Models\Lot;
use Webkul\Inventory\Models\Move as InventoryMove;
use Webkul\Inventory\Models\MoveLine as InventoryMoveLine;
use Webkul\Sale\Enums\AdvancedPayment;
use Webkul\Sale\Enums\InvoiceStatus;
use Webkul\Sale\Models\Order;
use Webkul\Sale\Models\OrderLine;
use Webkul\Sale\SaleManager;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    TestBootstrapHelper::ensurePluginInstalled('sales');

    SecurityHelper::disableUserEvents();
    SecurityHelper::authenticateWithPermissions([]);
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

it('posts invoice and cogs moves when creating invoice from sales order', function () {
    $product = AccountProduct::factory()->withAccounts()->create([
        'cost' => 25,
    ]);

    $order = Order::factory()->sale()->create([
        'invoice_status' => InvoiceStatus::TO_INVOICE,
    ]);

    $line = OrderLine::factory()->sale()->create([
        'order_id'         => $order->id,
        'company_id'       => $order->company_id,
        'currency_id'      => $order->currency_id,
        'order_partner_id' => $order->partner_id,
        'product_id'       => $product->id,
        'product_uom_qty'  => 2,
        'qty_to_invoice'   => 2,
        'purchase_price'   => 25,
    ]);

    $lot = Lot::factory()->create([
        'product_id'  => $line->product_id,
        'uom_id'      => $line->product_uom_id,
        'company_id'  => $order->company_id,
        'properties'  => ['purchase_price' => 25],
    ]);

    $inventoryMove = InventoryMove::factory()->done()->create([
        'sale_order_line_id' => $line->id,
        'product_id'         => $line->product_id,
        'uom_id'             => $line->product_uom_id,
        'product_uom_qty'    => 2,
        'quantity'           => 2,
        'company_id'         => $order->company_id,
    ]);

    InventoryMoveLine::factory()->done()->create([
        'move_id'      => $inventoryMove->id,
        'operation_id' => $inventoryMove->operation_id,
        'product_id'   => $line->product_id,
        'uom_id'       => $line->product_uom_id,
        'qty'          => 2,
        'lot_id'       => $lot->id,
        'lot_name'     => $lot->name,
        'company_id'   => $order->company_id,
    ]);

    Account::factory()->receivable()->create();
    Account::factory()->payable()->create();
    Account::factory()->create([
        'account_type' => AccountType::ASSET_CURRENT,
        'code'         => '110100',
    ]);

    $defaultSalesAccount = Account::factory()->income()->create();
    $defaultGeneralAccount = Account::factory()->expense()->create();

    $saleJournal = Journal::query()
        ->where('company_id', $order->company_id)
        ->where('type', JournalType::SALE)
        ->first();

    if (! $saleJournal) {
        Journal::factory()->sale()->create([
            'company_id'         => $order->company_id,
            'currency_id'        => $order->currency_id,
            'default_account_id' => $defaultSalesAccount->id,
        ]);
    } else {
        $saleJournal->update(['default_account_id' => $defaultSalesAccount->id]);
    }

    $generalJournal = Journal::query()
        ->where('company_id', $order->company_id)
        ->where('type', JournalType::GENERAL)
        ->first();

    if (! $generalJournal) {
        Journal::factory()->create([
            'company_id'         => $order->company_id,
            'currency_id'        => $order->currency_id,
            'default_account_id' => $defaultGeneralAccount->id,
        ]);
    } else {
        $generalJournal->update(['default_account_id' => $defaultGeneralAccount->id]);
    }

    app(SaleManager::class)->createInvoice($order->fresh(['lines']), [
        'advance_payment_method' => AdvancedPayment::DELIVERED->value,
    ]);

    $freshOrder = $order->fresh();

    $invoiceMove = $freshOrder->accountMoves()
        ->where('move_type', MoveType::OUT_INVOICE)
        ->latest('id')
        ->first();

    $cogsMove = $freshOrder->accountMoves()
        ->where('move_type', MoveType::ENTRY)
        ->latest('id')
        ->first();

    expect($invoiceMove)->not->toBeNull();
    expect($cogsMove)->not->toBeNull();
    expect($invoiceMove->state)->toBe(MoveState::POSTED);
    expect($cogsMove->state)->toBe(MoveState::POSTED);

    $cogsLines = $cogsMove->lines()
        ->where('display_type', DisplayType::COGS)
        ->get();

    expect($cogsLines)->toHaveCount(2);
    expect((float) $cogsLines->sum('debit'))->toBe(50.0);
    expect((float) $cogsLines->sum('credit'))->toBe(50.0);
});
