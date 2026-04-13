<?php

namespace Zrm\WorkshopDemo\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Webkul\Inventory\Settings\TraceabilitySettings;

/**
 * WorkshopDemoSeeder
 *
 * Demonstrates a full car workshop automation flow in AureusERP:
 *
 * 1. Products (spare parts + engine oils + workshop labor services)
 * 2. Partners  (supplier + two workshop customers)
 * 3. Three Purchase Orders → Receipts into WH/Stock
 * 4. Inventory quantities updated after each receipt
 * 5. Two Sales Orders for vehicle service jobs → Deliveries out of WH/Stock
 * 6. COGS tracked via purchase_price on each sale order line
 * 7. Closing stock and inventory valuation visible in Inventory module
 * 8. Accounting: Vendor Bills (in_invoice) for each PO + COGS stock-valuation entries
 * 9. Accounting: Customer Invoices (out_invoice) for each SO + COGS recognition entries
 *
 * ─────────────────────────────────────────────────────────────────
 *  Reference IDs (seeded by core install):
 *  - company_id          : 1  (DummyCorp LLC)
 *  - user_id             : 1  (Admin)
 *  - currency_id         : 34  (env: app.currency, e.g. MYR)
 *  - uom Units           : 1  / uom Litres : 20
 *  - WH/Stock loc        : 12 / Vendors : 4 / Customers : 5
 *  - Receipt op type     : 1  / Delivery op type : 2
 *  - Journal Vendor Bills: 2  / Customer Invoices : 1 / Misc : 3
 *  - Acct Receivable     : 7  (121000)
 *  - Acct Payable        : 16 (211000)
 *  - Stock Interim Recv  : 3  (110200)
 *  - Stock Valuation     : 2  (110100)
 *  - COGS                : 32 (500000)
 *  - Product Sales       : 27 (400000)
 * ─────────────────────────────────────────────────────────────────
 */
class WorkshopDemoSeeder extends Seeder
{
    // ── Core reference IDs ──────────────────────────────────────────

    private int $companyId = 1;

    private int $userId = 1;

    private int $currencyId = 34;

    private int $uomUnits = 1;   // "Units"

    private int $uomLitres = 20;  // "L"

    private int $categoryId = 1;   // "All"

    private int $stockLocId = 12; // WH/Stock

    private int $vendorLocId = 4;  // Partners/Vendors

    private int $customerLocId = 5;  // Partners/Customers

    private int $receiptOpTypeId = 1; // Receipts (incoming)

    private int $deliveryOpTypeId = 2; // Delivery Orders (outgoing)

    private int $paymentTermId = 4; // 30 Days

    // ── Accounting reference IDs ────────────────────────────────────

    private int $journalSale = 1;  // Customer Invoices  (out_invoice)

    private int $journalPurchase = 2;  // Vendor Bills       (in_invoice)

    private int $journalMisc = 3;  // Miscellaneous Operations (entry)

    private int $journalBank = 5;  // Bank Transactions  (bank)

    private int $pmLineInbound = 1;  // Bank journal — inbound (customer receipts)

    private int $pmLineOutbound = 2;  // Bank journal — outbound (vendor payments)

    private int $acctBank = 45;  // 101401 Bank (default account of Bank journal)

    private int $acctReceivable = 7;  // 121000 Account Receivable

    private int $acctStockVal = 2;  // 110100 Stock Valuation

    private int $acctStockIntRec = 3;  // 110200 Stock Interim (Received)

    private int $acctPayable = 16; // 211000 Account Payable

    private int $acctSales = 27; // 400000 Product Sales

    private int $acctCogs = 32; // 500000 Cost of Goods Sold

    /** @var array<int, string> */
    private array $lotTrackedProductKeys = [
        'oil_0w20',
        'oil_10w40',
        'brake_pads',
    ];

    // Populated during seeding
    /** @var array<string, int> */
    private array $productIds = [];

    /** @var array<string, float> */
    private array $lastLotPurchaseUnitPrices = [];

    /** @var array<string, int> */
    private array $storableProductKeys = [
        'oil_0w20',
        'oil_10w40',
        'brake_pads',
        'spark_plugs',
    ];

    private int $supplierPartnerId;

    private int $customer1Id;

    private int $customer2Id;

    public function run(): void
    {
        $this->command->info('');
        $this->command->info('🔧  Workshop Automation Demo Seeder');
        $this->command->info('═══════════════════════════════════════');

        $this->enableInventoryTraceability();
        $this->resolveCurrencyFromCompany();

        $this->seedProductCategory();
        $this->seedProducts();
        $this->seedPartners();
        $this->seedPurchaseOrder1();
        $this->seedPurchaseOrder2();
        $this->seedPurchaseOrder3();
        $this->syncProductCostsFromInventory();
        $this->seedSaleOrder1();
        $this->seedSaleOrder2();
        $this->seedPendingTransactions();
        $this->syncProductCostsFromInventory();
        $this->printStockSummary();
        $this->printLotCostControlSummary();

        $this->command->info('');
        $this->command->info('✅  Workshop demo data created successfully!');
        $this->command->info('');
    }

    private function enableInventoryTraceability(): void
    {
        $traceabilitySettings = app(TraceabilitySettings::class);

        if ($traceabilitySettings->enable_lots_serial_numbers) {
            return;
        }

        $traceabilitySettings->enable_lots_serial_numbers = true;
        $traceabilitySettings->save();

        $this->command->info('▸ Enabled Inventory traceability setting: Lots & Serial Numbers');
    }

    private function resolveCurrencyFromCompany(): void
    {
        $configuredCurrencyCode = strtoupper((string) config('app.currency', 'USD'));

        $configuredCurrencyId = DB::table('currencies')
            ->where('name', $configuredCurrencyCode)
            ->value('id');

        if ($configuredCurrencyId) {
            DB::table('currencies')
                ->where('id', $configuredCurrencyId)
                ->update([
                    'active'     => true,
                    'updated_at' => now(),
                ]);
        }

        $companyCurrencyId = DB::table('companies')
            ->where('id', $this->companyId)
            ->value('currency_id');

        if ($companyCurrencyId) {
            $this->currencyId = (int) $companyCurrencyId;

            return;
        }

        $fallbackCurrencyId = DB::table('currencies')
            ->where('name', $configuredCurrencyCode)
            ->value('id')
            ?? DB::table('currencies')
                ->where('active', true)
                ->value('id')
            ?? DB::table('currencies')->value('id');

        if ($fallbackCurrencyId) {
            $this->currencyId = (int) $fallbackCurrencyId;
        }
    }

    // ── 1. Product Category ─────────────────────────────────────────

    private function seedProductCategory(): void
    {
        $this->command->info('');
        $this->command->info('▸ Creating product category: Workshop Spare Parts');

        $existing = DB::table('products_categories')
            ->where('name', 'Workshop Spare Parts')
            ->first();

        if ($existing) {
            $this->categoryId = $existing->id;
            $this->command->info('  (already exists, id='.$this->categoryId.')');

            return;
        }

        $this->categoryId = DB::table('products_categories')->insertGetId([
            'name'        => 'Workshop Spare Parts',
            'full_name'   => 'All / Workshop Spare Parts',
            'parent_path' => '/1/',
            'parent_id'   => 1,
            'creator_id'  => $this->userId,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->command->info('  → id='.$this->categoryId);
    }

    // ── 2. Products ─────────────────────────────────────────────────

    private function seedProducts(): void
    {
        $this->command->info('');
        $this->command->info('▸ Creating spare-part products');

        $products = [
            [
                'key'         => 'oil_0w20',
                'name'        => 'Engine Oil 0W20 (per litre)',
                'reference'   => 'OIL-0W20',
                'type'        => 'goods',
                'cost'        => 25.00,
                'price'       => 45.00,
                'uom_id'      => $this->uomLitres,
                'uom_po_id'   => $this->uomLitres,
                'description' => 'Fully synthetic engine oil 0W20, suitable for modern petrol/diesel engines.',
            ],
            [
                'key'         => 'oil_10w40',
                'name'        => 'Engine Oil 10W40 (per litre)',
                'reference'   => 'OIL-10W40',
                'type'        => 'goods',
                'cost'        => 20.00,
                'price'       => 38.00,
                'uom_id'      => $this->uomLitres,
                'uom_po_id'   => $this->uomLitres,
                'description' => 'Semi-synthetic engine oil 10W40, versatile multi-grade formula.',
            ],
            [
                'key'         => 'brake_pads',
                'name'        => 'Brake Pads (Front Set)',
                'reference'   => 'BRK-PAD-F',
                'type'        => 'goods',
                'cost'        => 45.00,
                'price'       => 75.00,
                'uom_id'      => $this->uomUnits,
                'uom_po_id'   => $this->uomUnits,
                'description' => 'High-performance ceramic front brake pads, per two-piece set.',
            ],
            [
                'key'         => 'spark_plugs',
                'name'        => 'Spark Plugs (per piece)',
                'reference'   => 'SPK-PLUG',
                'type'        => 'goods',
                'cost'        => 8.00,
                'price'       => 15.00,
                'uom_id'      => $this->uomUnits,
                'uom_po_id'   => $this->uomUnits,
                'description' => 'Iridium long-life spark plug, universal fitment for 4-cylinder engines.',
            ],
            [
                'key'         => 'labor_oil_change',
                'name'        => 'Workshop Labor – Oil Change',
                'reference'   => 'SVC-OIL',
                'type'        => 'service',
                'cost'        => 0.00,
                'price'       => 30.00,
                'uom_id'      => $this->uomUnits,
                'uom_po_id'   => $this->uomUnits,
                'description' => 'Technician labor charge for engine oil & filter change service.',
            ],
            [
                'key'         => 'labor_brake_service',
                'name'        => 'Workshop Labor – Brake Service',
                'reference'   => 'SVC-BRK',
                'type'        => 'service',
                'cost'        => 0.00,
                'price'       => 60.00,
                'uom_id'      => $this->uomUnits,
                'uom_po_id'   => $this->uomUnits,
                'description' => 'Technician labor charge for front brake pad replacement & disc inspection.',
            ],
        ];

        foreach ($products as $data) {
            $isInventoryItem = $data['type'] === 'goods';
            $existing = DB::table('products_products')
                ->where('reference', $data['reference'])
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                $this->productIds[$data['key']] = $existing->id;

                $expectedTracking = $this->usesLotTracking($data['key']) ? 'lot' : 'qty';
                $updatePayload = [];

                if (($existing->tracking ?? null) !== $expectedTracking) {
                    $updatePayload['tracking'] = $expectedTracking;
                }

                if ($isInventoryItem) {
                    if ((int) ($existing->property_account_income_id ?? 0) !== $this->acctSales) {
                        $updatePayload['property_account_income_id'] = $this->acctSales;
                    }

                    if ((int) ($existing->property_account_expense_id ?? 0) !== $this->acctCogs) {
                        $updatePayload['property_account_expense_id'] = $this->acctCogs;
                    }
                }

                if ($updatePayload !== []) {
                    $updatePayload['updated_at'] = now();

                    DB::table('products_products')
                        ->where('id', $existing->id)
                        ->update($updatePayload);
                }

                $this->command->info('  (skip) '.$data['name'].' — already exists id='.$existing->id);

                continue;
            }

            $id = DB::table('products_products')->insertGetId([
                'type'                        => $data['type'],
                'name'                        => $data['name'],
                'reference'                   => $data['reference'],
                'price'                       => $data['price'],
                'cost'                        => $data['cost'],
                'description'                 => $data['description'],
                'enable_sales'                => 1,
                'enable_purchase'             => $data['type'] === 'goods' ? 1 : 0,
                'is_storable'                 => $data['type'] === 'goods' ? 1 : 0,
                'uom_id'                      => $data['uom_id'],
                'uom_po_id'                   => $data['uom_po_id'],
                'category_id'                 => $this->categoryId,
                'company_id'                  => $this->companyId,
                'creator_id'                  => $this->userId,
                'property_account_income_id'  => $isInventoryItem ? $this->acctSales : null,
                'property_account_expense_id' => $isInventoryItem ? $this->acctCogs : null,
                'tracking'                    => $this->usesLotTracking($data['key']) ? 'lot' : 'qty',
                'created_at'                  => now(),
                'updated_at'                  => now(),
            ]);

            $this->productIds[$data['key']] = $id;
            $this->command->info('  + '.$data['name'].' (id='.$id.')');
        }
    }

    // ── 3. Partners ─────────────────────────────────────────────────

    private function seedPartners(): void
    {
        $this->command->info('');
        $this->command->info('▸ Creating supplier and customer partners');

        $this->supplierPartnerId = $this->upsertPartner([
            'name'          => 'AutoParts Supplier Co.',
            'email'         => 'orders@autoparts-supplier.example',
            'phone'         => '+1-555-0100',
            'account_type'  => 'company',
            'sub_type'      => 'supplier',
            'supplier_rank' => 10,
            'customer_rank' => 0,
        ]);

        $this->customer1Id = $this->upsertPartner([
            'name'          => "Ahmad's Fleet Services",
            'email'         => 'service@ahmadfleet.example',
            'phone'         => '+1-555-0201',
            'account_type'  => 'company',
            'sub_type'      => 'customer',
            'supplier_rank' => 0,
            'customer_rank' => 5,
        ]);

        $this->customer2Id = $this->upsertPartner([
            'name'          => 'Nour Delivery Co.',
            'email'         => 'fleet@nourdelivery.example',
            'phone'         => '+1-555-0202',
            'account_type'  => 'company',
            'sub_type'      => 'customer',
            'supplier_rank' => 0,
            'customer_rank' => 5,
        ]);
    }

    private function upsertPartner(array $data): int
    {
        $existing = DB::table('partners_partners')
            ->where('name', $data['name'])
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            $this->command->info('  (skip) '.$data['name'].' — already exists id='.$existing->id);

            return $existing->id;
        }

        $id = DB::table('partners_partners')->insertGetId([
            'account_type'  => $data['account_type'],
            'sub_type'      => $data['sub_type'],
            'name'          => $data['name'],
            'email'         => $data['email'],
            'phone'         => $data['phone'],
            'supplier_rank' => $data['supplier_rank'],
            'customer_rank' => $data['customer_rank'],
            'company_id'    => $this->companyId,
            'creator_id'    => $this->userId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->command->info('  + '.$data['name'].' (id='.$id.')');

        return $id;
    }

    // ── 4. Purchase Order 1 ─────────────────────────────────────────
    //   AutoParts Supplier → WH/Stock
    //   Engine Oil 0W20: 10 L  @  25.00
    //   Spark Plugs:      8 pc @   8.00

    private function seedPurchaseOrder1(): void
    {
        $this->command->info('');
        $this->command->info('▸ Purchase Order 1 — Engine Oil 0W20 (10 L) + Spark Plugs (8 pc)');

        $orderedAt = Carbon::now()->subDays(20);

        $lines = [
            [
                'product_key' => 'oil_0w20',
                'qty'         => 10,
                'price_unit'  => 25.00,
                'uom_id'      => $this->uomLitres,
                'name'        => 'Engine Oil 0W20 (per litre)',
            ],
            [
                'product_key' => 'spark_plugs',
                'qty'         => 8,
                'price_unit'  => 8.00,
                'uom_id'      => $this->uomUnits,
                'name'        => 'Spark Plugs (per piece)',
            ],
        ];

        $this->createPurchaseOrder(
            reference: 'PO/WKSHP/001',
            lines: $lines,
            orderedAt: $orderedAt,
            receivedAt: $orderedAt->copy()->addDays(3),
        );
    }

    // ── 5. Purchase Order 2 ─────────────────────────────────────────
    //   Engine Oil 10W40: 10 L  @  20.00
    //   Brake Pads:        4 set @ 45.00

    private function seedPurchaseOrder2(): void
    {
        $this->command->info('');
        $this->command->info('▸ Purchase Order 2 — Engine Oil 10W40 (10 L) + Brake Pads (4 set)');

        $orderedAt = Carbon::now()->subDays(15);

        $lines = [
            [
                'product_key' => 'oil_10w40',
                'qty'         => 10,
                'price_unit'  => 20.00,
                'uom_id'      => $this->uomLitres,
                'name'        => 'Engine Oil 10W40 (per litre)',
            ],
            [
                'product_key' => 'brake_pads',
                'qty'         => 4,
                'price_unit'  => 45.00,
                'uom_id'      => $this->uomUnits,
                'name'        => 'Brake Pads (Front Set)',
            ],
        ];

        $this->createPurchaseOrder(
            reference: 'PO/WKSHP/002',
            lines: $lines,
            orderedAt: $orderedAt,
            receivedAt: $orderedAt->copy()->addDays(2),
        );
    }

    // ── 6. Purchase Order 3 ─────────────────────────────────────────
    //   Engine Oil 0W20:  5 L  @  25.00
    //   Engine Oil 10W40: 5 L  @  20.00
    //   Brake Pads:       2 set @ 45.00

    private function seedPurchaseOrder3(): void
    {
        $this->command->info('');
        $this->command->info('▸ Purchase Order 3 — Mixed stock top-up (0W20 x5, 10W40 x5, Brake Pads x2)');

        $orderedAt = Carbon::now()->subDays(8);

        $lines = [
            [
                'product_key' => 'oil_0w20',
                'qty'         => 5,
                'price_unit'  => 25.00,
                'uom_id'      => $this->uomLitres,
                'name'        => 'Engine Oil 0W20 (per litre)',
            ],
            [
                'product_key' => 'oil_10w40',
                'qty'         => 5,
                'price_unit'  => 20.00,
                'uom_id'      => $this->uomLitres,
                'name'        => 'Engine Oil 10W40 (per litre)',
            ],
            [
                'product_key' => 'brake_pads',
                'qty'         => 2,
                'price_unit'  => 45.00,
                'uom_id'      => $this->uomUnits,
                'name'        => 'Brake Pads (Front Set)',
            ],
        ];

        $this->createPurchaseOrder(
            reference: 'PO/WKSHP/003',
            lines: $lines,
            orderedAt: $orderedAt,
            receivedAt: $orderedAt->copy()->addDays(2),
        );
    }

    // ── 7. Sale Order 1 ─────────────────────────────────────────────
    //   Customer: Ahmad's Fleet Services
    //   Vehicle: Car A — Full Oil Change Service
    //   4 L Engine Oil 0W20  @ 45.00  (COGS: 25.00)
    //   4 pc Spark Plugs     @ 15.00  (COGS:  8.00)
    //   1 x Labor Oil Change @ 30.00  (COGS:  0.00)

    private function seedSaleOrder1(): void
    {
        $this->command->info('');
        $this->command->info('▸ Sale Order 1 — Ahmad\'s Fleet Services: Car A Oil Change');

        $orderDate = Carbon::now()->subDays(5);

        $lines = [
            [
                'product_key'    => 'oil_0w20',
                'qty'            => 4,
                'price_unit'     => 45.00,
                'purchase_price' => $this->getCurrentProductUnitCost('oil_0w20', 25.00),
                'uom_id'         => $this->uomLitres,
                'name'           => 'Engine Oil 0W20 — 4L oil change',
            ],
            [
                'product_key'    => 'spark_plugs',
                'qty'            => 4,
                'price_unit'     => 15.00,
                'purchase_price' => $this->getCurrentProductUnitCost('spark_plugs', 8.00),
                'uom_id'         => $this->uomUnits,
                'name'           => 'Spark Plugs — set of 4',
            ],
            [
                'product_key'    => 'labor_oil_change',
                'qty'            => 1,
                'price_unit'     => 30.00,
                'purchase_price' => 0.00,
                'uom_id'         => $this->uomUnits,
                'name'           => 'Workshop Labor — Oil Change',
            ],
        ];

        $this->createSaleOrder(
            reference: 'SO/WKSHP/001',
            customerId: $this->customer1Id,
            clientRef: 'Work Order WO-201 — Vehicle: Toyota Camry (Plate: AHM-001)',
            lines: $lines,
            orderDate: $orderDate,
            deliveredAt: $orderDate->copy()->addHours(4),
        );
    }

    // ── 8. Sale Order 2 ─────────────────────────────────────────────
    //   Customer: Nour Delivery Co.
    //   Vehicle: Car B — Brake & Oil Service
    //   1 set  Brake Pads      @ 75.00  (COGS: 45.00)
    //   4 L    Engine Oil 10W40@ 38.00  (COGS: 20.00)
    //   1 x    Labor Brake Svc @ 60.00  (COGS:  0.00)

    private function seedSaleOrder2(): void
    {
        $this->command->info('');
        $this->command->info('▸ Sale Order 2 — Nour Delivery Co.: Car B Brake & Oil Service');

        $orderDate = Carbon::now()->subDays(2);

        $lines = [
            [
                'product_key'    => 'brake_pads',
                'qty'            => 1,
                'price_unit'     => 75.00,
                'purchase_price' => $this->getCurrentProductUnitCost('brake_pads', 45.00),
                'uom_id'         => $this->uomUnits,
                'name'           => 'Brake Pads (Front Set) — replacement',
            ],
            [
                'product_key'    => 'oil_10w40',
                'qty'            => 4,
                'price_unit'     => 38.00,
                'purchase_price' => $this->getCurrentProductUnitCost('oil_10w40', 20.00),
                'uom_id'         => $this->uomLitres,
                'name'           => 'Engine Oil 10W40 — 4L top-up after brake service',
            ],
            [
                'product_key'    => 'labor_brake_service',
                'qty'            => 1,
                'price_unit'     => 60.00,
                'purchase_price' => 0.00,
                'uom_id'         => $this->uomUnits,
                'name'           => 'Workshop Labor — Brake Service',
            ],
        ];

        $this->createSaleOrder(
            reference: 'SO/WKSHP/002',
            customerId: $this->customer2Id,
            clientRef: 'Work Order WO-202 — Vehicle: Nissan Patrol (Plate: NOU-002)',
            lines: $lines,
            orderDate: $orderDate,
            deliveredAt: $orderDate->copy()->addHours(6),
        );
    }

    // ── Purchase Order builder ──────────────────────────────────────

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function createPurchaseOrder(
        string $reference,
        array $lines,
        Carbon $orderedAt,
        Carbon $receivedAt,
    ): void {
        $expandedLines = $this->expandPurchaseLinesWithLotPrices($lines);

        $existing = DB::table('purchases_orders')->where('name', $reference)->first();

        if ($existing) {
            $this->command->info('  (skip) '.$reference.' — already exists');
            $this->ensurePurchaseAccounting($existing->id, $reference, $expandedLines, $receivedAt);

            return;
        }

        // ── Compute totals ──────────────────────────────────────────
        $untaxedAmount = array_sum(array_map(
            fn ($l) => $l['qty'] * $l['price_unit'],
            $expandedLines
        ));

        $orderId = DB::table('purchases_orders')->insertGetId([
            'name'                     => $reference,
            'state'                    => 'purchase',
            'invoice_status'           => 'no',
            'receipt_status'           => 'full',
            'untaxed_amount'           => $untaxedAmount,
            'tax_amount'               => 0.00,
            'total_amount'             => $untaxedAmount,
            'total_cc_amount'          => $untaxedAmount,
            'currency_rate'            => 1.0,
            'invoice_count'            => 0,
            'mail_reminder_confirmed'  => 0,
            'mail_reception_confirmed' => 1,
            'mail_reception_declined'  => 0,
            'report_grids'             => 0,
            'ordered_at'               => $orderedAt,
            'approved_at'              => $orderedAt,
            'planned_at'               => $receivedAt,
            'effective_date'           => $receivedAt,
            'partner_id'               => $this->supplierPartnerId,
            'currency_id'              => $this->currencyId,
            'payment_term_id'          => $this->paymentTermId,
            'user_id'                  => $this->userId,
            'company_id'               => $this->companyId,
            'creator_id'               => $this->userId,
            'operation_type_id'        => $this->receiptOpTypeId,
            'created_at'               => $orderedAt,
            'updated_at'               => $receivedAt,
        ]);

        // ── Create receipt operation (inventory incoming) ───────────
        $operationId = DB::table('inventories_operations')->insertGetId([
            'name'                    => 'WH/IN/'.$reference,
            'origin'                  => $reference,
            'move_type'               => 'direct',
            'state'                   => 'done',
            'is_favorite'             => 0,
            'has_deadline_issue'      => 0,
            'is_printed'              => 0,
            'is_locked'               => 1,
            'scheduled_at'            => $receivedAt,
            'closed_at'               => $receivedAt,
            'operation_type_id'       => $this->receiptOpTypeId,
            'source_location_id'      => $this->vendorLocId,
            'destination_location_id' => $this->stockLocId,
            'partner_id'              => $this->supplierPartnerId,
            'company_id'              => $this->companyId,
            'creator_id'              => $this->userId,
            'user_id'                 => $this->userId,
            'created_at'              => $receivedAt,
            'updated_at'              => $receivedAt,
        ]);

        // Link operation ↔ purchase order
        DB::table('purchases_order_operations')->insert([
            'purchase_order_id'      => $orderId,
            'inventory_operation_id' => $operationId,
        ]);

        // ── Create order lines + inventory moves + update stock ─────
        foreach ($expandedLines as $line) {
            $productId = $this->productIds[$line['product_key']];
            $subtotal = $line['qty'] * $line['price_unit'];

            $orderLineId = DB::table('purchases_order_lines')->insertGetId([
                'name'                => $line['name'],
                'state'               => 'purchase',
                'qty_received_method' => 'manual',
                'product_qty'         => $line['qty'],
                'product_uom_qty'     => $line['qty'],
                'price_unit'          => $line['price_unit'],
                'price_subtotal'      => $subtotal,
                'price_total'         => $subtotal,
                'price_tax'           => 0.00,
                'price_total_cc'      => $subtotal,
                'discount'            => 0.00,
                'qty_received'        => $line['qty'],
                'qty_received_manual' => $line['qty'],
                'qty_invoiced'        => 0,
                'qty_to_invoice'      => $line['qty'],
                'is_downpayment'      => 0,
                'propagate_cancel'    => 0,
                'uom_id'              => $line['uom_id'],
                'product_id'          => $productId,
                'order_id'            => $orderId,
                'partner_id'          => $this->supplierPartnerId,
                'currency_id'         => $this->currencyId,
                'company_id'          => $this->companyId,
                'creator_id'          => $this->userId,
                'planned_at'          => $receivedAt,
                'created_at'          => $orderedAt,
                'updated_at'          => $receivedAt,
            ]);

            // Inventory move (vendor → stock, state=done)
            $moveId = DB::table('inventories_moves')->insertGetId([
                'name'                    => $line['name'],
                'state'                   => 'done',
                'origin'                  => $reference,
                'procure_method'          => 'make_to_stock',
                'product_qty'             => $line['qty'],
                'product_uom_qty'         => $line['qty'],
                'quantity'                => $line['qty'],
                'is_favorite'             => 0,
                'is_picked'               => 1,
                'is_scraped'              => 0,
                'is_inventory'            => 0,
                'is_refund'               => 0,
                'scheduled_at'            => $receivedAt,
                'operation_id'            => $operationId,
                'product_id'              => $productId,
                'uom_id'                  => $line['uom_id'],
                'source_location_id'      => $this->vendorLocId,
                'destination_location_id' => $this->stockLocId,
                'operation_type_id'       => $this->receiptOpTypeId,
                'warehouse_id'            => 1,
                'company_id'              => $this->companyId,
                'creator_id'              => $this->userId,
                'purchase_order_line_id'  => $orderLineId,
                'created_at'              => $receivedAt,
                'updated_at'              => $receivedAt,
            ]);

            if ($this->usesLotTracking($line['product_key'])) {
                $lotId = $this->createLotForReceipt(
                    $reference,
                    $line,
                    $productId,
                    $receivedAt,
                    (int) ($line['lot_index'] ?? 1)
                );

                DB::table('inventories_move_lines')->insert([
                    'lot_name'                => DB::table('inventories_lots')->where('id', $lotId)->value('name'),
                    'lot_id'                  => $lotId,
                    'state'                   => 'done',
                    'reference'               => 'WH/IN/'.$reference,
                    'qty'                     => $line['qty'],
                    'uom_qty'                 => $line['qty'],
                    'is_picked'               => 1,
                    'scheduled_at'            => $receivedAt,
                    'move_id'                 => $moveId,
                    'operation_id'            => $operationId,
                    'product_id'              => $productId,
                    'uom_id'                  => $line['uom_id'],
                    'source_location_id'      => $this->vendorLocId,
                    'destination_location_id' => $this->stockLocId,
                    'company_id'              => $this->companyId,
                    'creator_id'              => $this->userId,
                    'created_at'              => $receivedAt,
                    'updated_at'              => $receivedAt,
                ]);

                $this->adjustStock($productId, $line['qty'], $lotId, $receivedAt);
            } else {
                DB::table('inventories_move_lines')->insert([
                    'state'                   => 'done',
                    'reference'               => 'WH/IN/'.$reference,
                    'qty'                     => $line['qty'],
                    'uom_qty'                 => $line['qty'],
                    'is_picked'               => 1,
                    'scheduled_at'            => $receivedAt,
                    'move_id'                 => $moveId,
                    'operation_id'            => $operationId,
                    'product_id'              => $productId,
                    'uom_id'                  => $line['uom_id'],
                    'source_location_id'      => $this->vendorLocId,
                    'destination_location_id' => $this->stockLocId,
                    'company_id'              => $this->companyId,
                    'creator_id'              => $this->userId,
                    'created_at'              => $receivedAt,
                    'updated_at'              => $receivedAt,
                ]);

                $this->adjustStock($productId, $line['qty'], null, $receivedAt);
            }
        }

        $this->command->info(sprintf(
            '  ✓ %s | Total: $%.2f | Receipt: WH/IN/%s (done, id=%d)',
            $reference,
            $untaxedAmount,
            $reference,
            $operationId,
        ));

        $this->ensurePurchaseAccounting($orderId, $reference, $expandedLines, $receivedAt);
    }

    // ── Sale Order builder ──────────────────────────────────────────

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function createSaleOrder(
        string $reference,
        int $customerId,
        string $clientRef,
        array $lines,
        Carbon $orderDate,
        Carbon $deliveredAt,
    ): void {
        $existing = DB::table('sales_orders')->where('name', $reference)->first();

        if ($existing) {
            $this->command->info('  (skip) '.$reference.' — already exists');
            $this->ensureSaleAccounting($existing->id, $reference, $customerId, $lines, $deliveredAt);

            return;
        }

        // Totals
        $amountUntaxed = array_sum(array_map(
            fn ($l) => $l['qty'] * $l['price_unit'],
            $lines
        ));

        $cogsTotal = 0.0;
        $accountingLines = [];

        $orderId = DB::table('sales_orders')->insertGetId([
            'name'                => $reference,
            'state'               => 'sale',
            'invoice_status'      => 'to_invoice',
            'delivery_status'     => 'full',
            'client_order_ref'    => $clientRef,
            'date_order'          => $orderDate,
            'commitment_date'     => $deliveredAt,
            'amount_untaxed'      => $amountUntaxed,
            'amount_tax'          => 0.00,
            'amount_total'        => $amountUntaxed,
            'currency_rate'       => 1.0,
            'locked'              => 0,
            'require_signature'   => 0,
            'require_payment'     => 0,
            'prepayment_percent'  => 0,
            'partner_id'          => $customerId,
            'partner_invoice_id'  => $customerId,
            'partner_shipping_id' => $customerId,
            'currency_id'         => $this->currencyId,
            'user_id'             => $this->userId,
            'team_id'             => 1,
            'company_id'          => $this->companyId,
            'creator_id'          => $this->userId,
            'warehouse_id'        => 1,
            'payment_term_id'     => $this->paymentTermId,
            'created_at'          => $orderDate,
            'updated_at'          => $deliveredAt,
        ]);

        // Delivery operation (stock → customer)
        $operationId = DB::table('inventories_operations')->insertGetId([
            'name'                    => 'WH/OUT/'.$reference,
            'origin'                  => $reference,
            'move_type'               => 'direct',
            'state'                   => 'done',
            'is_favorite'             => 0,
            'has_deadline_issue'      => 0,
            'is_printed'              => 0,
            'is_locked'               => 1,
            'scheduled_at'            => $deliveredAt,
            'closed_at'               => $deliveredAt,
            'operation_type_id'       => $this->deliveryOpTypeId,
            'source_location_id'      => $this->stockLocId,
            'destination_location_id' => $this->customerLocId,
            'partner_id'              => $customerId,
            'company_id'              => $this->companyId,
            'creator_id'              => $this->userId,
            'user_id'                 => $this->userId,
            'sale_order_id'           => $orderId,
            'created_at'              => $deliveredAt,
            'updated_at'              => $deliveredAt,
        ]);

        foreach ($lines as $sort => $line) {
            $productId = $this->productIds[$line['product_key']];
            $isStockableLine = $line['purchase_price'] >= 0 && ! str_starts_with($line['product_key'], 'labor');
            $lotAllocations = [];
            $effectivePurchasePrice = (float) $line['purchase_price'];

            if ($isStockableLine && $this->usesLotTracking($line['product_key'])) {
                $lotAllocations = $this->consumeStockFromLots(
                    $productId,
                    (float) $line['qty'],
                    (float) $line['purchase_price']
                );

                $lineCogsTotal = (float) array_sum(array_map(
                    fn (array $allocation): float => $allocation['qty'] * $allocation['unit_cost'],
                    $lotAllocations
                ));

                $effectivePurchasePrice = $line['qty'] > 0
                    ? round($lineCogsTotal / $line['qty'], 4)
                    : 0.0;
            } else {
                $lineCogsTotal = (float) ($line['qty'] * $effectivePurchasePrice);
            }

            $subtotal = $line['qty'] * $line['price_unit'];
            $margin = ($line['price_unit'] - $effectivePurchasePrice) * $line['qty'];
            $marginPct = $line['price_unit'] > 0
                ? round(($line['price_unit'] - $effectivePurchasePrice) / $line['price_unit'] * 100, 2)
                : 0;

            $saleLineId = DB::table('sales_order_lines')->insertGetId([
                'sort'                       => $sort + 1,
                'order_id'                   => $orderId,
                'company_id'                 => $this->companyId,
                'currency_id'                => $this->currencyId,
                'order_partner_id'           => $customerId,
                'salesman_id'                => $this->userId,
                'product_id'                 => $productId,
                'product_uom_id'             => $line['uom_id'],
                'creator_id'                 => $this->userId,
                'state'                      => 'sale',
                'name'                       => $line['name'],
                'product_uom_qty'            => $line['qty'],
                'product_qty'                => $line['qty'],
                'price_unit'                 => $line['price_unit'],
                'discount'                   => 0.00,
                'price_subtotal'             => $subtotal,
                'price_total'                => $subtotal,
                'price_reduce_taxexcl'       => $line['price_unit'],
                'price_reduce_taxinc'        => $line['price_unit'],
                'price_tax'                  => 0.00,
                'technical_price_unit'       => $line['price_unit'],
                'purchase_price'             => $effectivePurchasePrice,
                'margin'                     => $margin,
                'margin_percent'             => $marginPct,
                'qty_delivered_method'       => $effectivePurchasePrice > 0 ? 'stock_move' : 'manual',
                'qty_delivered'              => $line['qty'],
                'qty_invoiced'               => 0,
                'qty_to_invoice'             => $line['qty'],
                'invoice_status'             => 'to_invoice',
                'untaxed_amount_invoiced'    => 0,
                'untaxed_amount_to_invoice'  => $subtotal,
                'is_downpayment'             => 0,
                'is_expense'                 => 0,
                'created_at'                 => $orderDate,
                'updated_at'                 => $deliveredAt,
            ]);

            $accountingLine = array_merge($line, [
                'purchase_price' => $effectivePurchasePrice,
            ]);

            if ($lotAllocations !== []) {
                $accountingLine['cogs_allocations'] = $lotAllocations;
            }

            $accountingLines[] = $accountingLine;

            $cogsTotal += $lineCogsTotal;

            // Only create stock moves for storable products (not services)
            if ($isStockableLine) {
                $moveId = DB::table('inventories_moves')->insertGetId([
                    'name'                    => $line['name'],
                    'state'                   => 'done',
                    'origin'                  => $reference,
                    'procure_method'          => 'make_to_stock',
                    'product_qty'             => $line['qty'],
                    'product_uom_qty'         => $line['qty'],
                    'quantity'                => $line['qty'],
                    'is_favorite'             => 0,
                    'is_picked'               => 1,
                    'is_scraped'              => 0,
                    'is_inventory'            => 0,
                    'is_refund'               => 0,
                    'scheduled_at'            => $deliveredAt,
                    'operation_id'            => $operationId,
                    'product_id'              => $productId,
                    'uom_id'                  => $line['uom_id'],
                    'source_location_id'      => $this->stockLocId,
                    'destination_location_id' => $this->customerLocId,
                    'operation_type_id'       => $this->deliveryOpTypeId,
                    'warehouse_id'            => 1,
                    'company_id'              => $this->companyId,
                    'creator_id'              => $this->userId,
                    'sale_order_line_id'      => $saleLineId,
                    'created_at'              => $deliveredAt,
                    'updated_at'              => $deliveredAt,
                ]);

                if ($this->usesLotTracking($line['product_key'])) {
                    foreach ($lotAllocations as $allocation) {
                        DB::table('inventories_move_lines')->insert([
                            'lot_name'                => $allocation['lot_name'] ?: null,
                            'lot_id'                  => $allocation['lot_id'] > 0 ? $allocation['lot_id'] : null,
                            'state'                   => 'done',
                            'reference'               => 'WH/OUT/'.$reference,
                            'qty'                     => $allocation['qty'],
                            'uom_qty'                 => $allocation['qty'],
                            'is_picked'               => 1,
                            'scheduled_at'            => $deliveredAt,
                            'move_id'                 => $moveId,
                            'operation_id'            => $operationId,
                            'product_id'              => $productId,
                            'uom_id'                  => $line['uom_id'],
                            'source_location_id'      => $this->stockLocId,
                            'destination_location_id' => $this->customerLocId,
                            'company_id'              => $this->companyId,
                            'creator_id'              => $this->userId,
                            'created_at'              => $deliveredAt,
                            'updated_at'              => $deliveredAt,
                        ]);
                    }
                } else {
                    DB::table('inventories_move_lines')->insert([
                        'state'                   => 'done',
                        'reference'               => 'WH/OUT/'.$reference,
                        'qty'                     => $line['qty'],
                        'uom_qty'                 => $line['qty'],
                        'is_picked'               => 1,
                        'scheduled_at'            => $deliveredAt,
                        'move_id'                 => $moveId,
                        'operation_id'            => $operationId,
                        'product_id'              => $productId,
                        'uom_id'                  => $line['uom_id'],
                        'source_location_id'      => $this->stockLocId,
                        'destination_location_id' => $this->customerLocId,
                        'company_id'              => $this->companyId,
                        'creator_id'              => $this->userId,
                        'created_at'              => $deliveredAt,
                        'updated_at'              => $deliveredAt,
                    ]);

                    // Deduct stock
                    $this->adjustStock($productId, -$line['qty']);
                }
            }
        }

        $this->command->info(sprintf(
            '  ✓ %s | Total: $%.2f | COGS: $%.2f | Margin: $%.2f | Delivery: done (id=%d)',
            $reference,
            $amountUntaxed,
            $cogsTotal,
            $amountUntaxed - $cogsTotal,
            $operationId,
        ));

        $this->syncProductCostsFromInventory();

        $this->ensureSaleAccounting($orderId, $reference, $customerId, $accountingLines, $deliveredAt);
    }

    // ── Accounting: Vendor Bill (in_invoice) ─────────────────────────

    // ── Accounting: Goods-Received Stock Valuation Entry ─────────────

    /**
     * Create the stock-valuation journal entry that is auto-generated by
     * the inventory module when a purchase receipt is confirmed.
     *
     * Double-entry per product line:
     *   DR  Stock Valuation          [110100]  (inventory asset up)
     *   CR  Stock Interim (Received) [110200]  (clearing account)
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function createGoodsReceivedEntry(string $reference, array $lines, Carbon $receivedAt): void
    {
        $suffix = substr($reference, strrpos($reference, '/') + 1);
        $entryName = 'WKSHP/RECV/'.$suffix;

        if (DB::table('accounts_account_moves')->where('name', $entryName)->exists()) {
            $this->command->info('  (skip) Goods-received entry '.$entryName.' — already exists');

            return;
        }

        $total = (float) array_sum(array_map(
            fn ($l) => $l['qty'] * $l['price_unit'],
            $lines
        ));

        $moveId = DB::table('accounts_account_moves')->insertGetId([
            'name'                              => $entryName,
            'move_type'                         => 'entry',
            'state'                             => 'posted',
            'payment_state'                     => 'not_paid',
            'auto_post'                         => 'no',
            'sequence_prefix'                   => 'WKSHP/RECV/',
            'journal_id'                        => $this->journalMisc,
            'partner_id'                        => $this->supplierPartnerId,
            'commercial_partner_id'             => $this->supplierPartnerId,
            'currency_id'                       => $this->currencyId,
            'company_id'                        => $this->companyId,
            'invoice_user_id'                   => $this->userId,
            'creator_id'                        => $this->userId,
            'invoice_origin'                    => $reference,
            'invoice_date'                      => $receivedAt->toDateString(),
            'invoice_date_due'                  => $receivedAt->toDateString(),
            'date'                              => $receivedAt->toDateString(),
            'amount_untaxed'                    => $total,
            'amount_tax'                        => 0.00,
            'amount_total'                      => $total,
            'amount_residual'                   => 0.00,
            'amount_untaxed_signed'             => $total,
            'amount_tax_signed'                 => 0.00,
            'amount_total_signed'               => $total,
            'amount_residual_signed'            => 0.00,
            'amount_untaxed_in_currency_signed' => $total,
            'amount_total_in_currency_signed'   => $total,
            'invoice_currency_rate'             => 1.0,
            'created_at'                        => $receivedAt,
            'updated_at'                        => $receivedAt,
        ]);

        $sort = 1;

        foreach ($lines as $line) {
            $subtotal = round($line['qty'] * $line['price_unit'], 2);
            $productId = $this->productIds[$line['product_key']];

            // DR Stock Valuation [110100]
            DB::table('accounts_account_move_lines')->insert([
                'sort'                     => $sort++,
                'move_id'                  => $moveId,
                'move_name'                => $entryName,
                'parent_state'             => 'posted',
                'journal_id'               => $this->journalMisc,
                'company_id'               => $this->companyId,
                'company_currency_id'      => $this->currencyId,
                'currency_id'              => $this->currencyId,
                'account_id'               => $this->acctStockVal,
                'partner_id'               => $this->supplierPartnerId,
                'product_id'               => $productId,
                'uom_id'                   => $line['uom_id'],
                'creator_id'               => $this->userId,
                'name'                     => $line['name'].' — Goods Received',
                'display_type'             => 'product',
                'date'                     => $receivedAt->toDateString(),
                'quantity'                 => $line['qty'],
                'price_unit'               => $line['price_unit'],
                'price_subtotal'           => $subtotal,
                'price_total'              => $subtotal,
                'discount'                 => 0.00,
                'debit'                    => $subtotal,
                'credit'                   => 0.00,
                'balance'                  => $subtotal,
                'amount_currency'          => $subtotal,
                'amount_residual'          => 0.00,
                'amount_residual_currency' => 0.00,
                'tax_base_amount'          => 0.00,
                'reconciled'               => 0,
                'is_downpayment'           => 0,
                'created_at'               => $receivedAt,
                'updated_at'               => $receivedAt,
            ]);

            // CR Stock Interim (Received) [110200]
            DB::table('accounts_account_move_lines')->insert([
                'sort'                     => $sort++,
                'move_id'                  => $moveId,
                'move_name'                => $entryName,
                'parent_state'             => 'posted',
                'journal_id'               => $this->journalMisc,
                'company_id'               => $this->companyId,
                'company_currency_id'      => $this->currencyId,
                'currency_id'              => $this->currencyId,
                'account_id'               => $this->acctStockIntRec,
                'partner_id'               => $this->supplierPartnerId,
                'product_id'               => $productId,
                'uom_id'                   => $line['uom_id'],
                'creator_id'               => $this->userId,
                'name'                     => $line['name'].' — Interim Clearing',
                'display_type'             => 'product',
                'date'                     => $receivedAt->toDateString(),
                'quantity'                 => $line['qty'],
                'price_unit'               => $line['price_unit'],
                'price_subtotal'           => $subtotal,
                'price_total'              => $subtotal,
                'discount'                 => 0.00,
                'debit'                    => 0.00,
                'credit'                   => $subtotal,
                'balance'                  => -$subtotal,
                'amount_currency'          => -$subtotal,
                'amount_residual'          => 0.00,
                'amount_residual_currency' => 0.00,
                'tax_base_amount'          => 0.00,
                'reconciled'               => 0,
                'is_downpayment'           => 0,
                'created_at'               => $receivedAt,
                'updated_at'               => $receivedAt,
            ]);
        }

        $this->command->info(sprintf(
            '  ✓ Goods-received entry %s (posted) — $%.2f  [move id=%d]',
            $entryName,
            $total,
            $moveId,
        ));
    }

    // ── Accounting: Vendor Bill (in_invoice) ─────────────────────────

    /**
     * Create an accounting vendor bill for a purchase order if it doesn't already exist.
     *
     * The goods-received entry (DR Stock Valuation / CR Stock Interim) is always
     * created first via createGoodsReceivedEntry(), which mirrors what the inventory
     * module would auto-generate on receipt confirmation.
     *
     * Vendor bill double-entry per product line:
     *   DR  Stock Interim (Received)  [110200]  (clearing account settled)
     *   CR  Account Payable           [211000]
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function ensurePurchaseAccounting(int $orderId, string $reference, array $lines, Carbon $billDate): void
    {
        $billSuffix = substr($reference, strrpos($reference, '/') + 1);
        $billName = 'WKSHP/BILL/'.$billSuffix;

        // Always create the goods-received stock valuation entry (has its own idempotency).
        $this->createGoodsReceivedEntry($reference, $lines, $billDate);

        // Idempotency: skip if vendor bill already linked to this PO
        $alreadyLinked = DB::table('purchases_order_account_moves')
            ->where('order_id', $orderId)
            ->exists();

        if ($alreadyLinked) {
            $this->command->info('  (skip accounting) Vendor bill already exists for '.$reference);
            $existingMove = DB::table('accounts_account_moves')->where('name', $billName)->first();

            if ($existingMove) {
                $this->ensureVendorPayment($existingMove->id, (float) $existingMove->amount_total, $billDate, $billSuffix);
            }

            return;
        }

        $total = (float) array_sum(array_map(
            fn ($l) => $l['qty'] * $l['price_unit'],
            $lines
        ));

        $moveId = DB::table('accounts_account_moves')->insertGetId([
            'name'                               => $billName,
            'move_type'                          => 'in_invoice',
            'state'                              => 'posted',
            'payment_state'                      => 'paid',
            'auto_post'                          => 'no',
            'sequence_prefix'                    => 'WKSHP/BILL/',
            'journal_id'                         => $this->journalPurchase,
            'partner_id'                         => $this->supplierPartnerId,
            'commercial_partner_id'              => $this->supplierPartnerId,
            'currency_id'                        => $this->currencyId,
            'company_id'                         => $this->companyId,
            'invoice_user_id'                    => $this->userId,
            'creator_id'                         => $this->userId,
            'invoice_origin'                     => $reference,
            'invoice_date'                       => $billDate->toDateString(),
            'invoice_date_due'                   => $billDate->copy()->addDays(30)->toDateString(),
            'date'                               => $billDate->toDateString(),
            'amount_untaxed'                     => $total,
            'amount_tax'                         => 0.00,
            'amount_total'                       => $total,
            'amount_residual'                    => 0.00,
            'amount_untaxed_signed'              => -$total,
            'amount_tax_signed'                  => 0.00,
            'amount_total_signed'                => -$total,
            'amount_residual_signed'             => 0.00,
            'amount_untaxed_in_currency_signed'  => -$total,
            'amount_total_in_currency_signed'    => -$total,
            'invoice_currency_rate'              => 1.0,
            'created_at'                         => $billDate,
            'updated_at'                         => $billDate,
        ]);

        // Product lines — DR Stock Interim (Received)
        foreach ($lines as $sort => $line) {
            $subtotal = round($line['qty'] * $line['price_unit'], 2);

            DB::table('accounts_account_move_lines')->insert([
                'sort'                     => $sort + 1,
                'move_id'                  => $moveId,
                'move_name'                => $billName,
                'parent_state'             => 'posted',
                'journal_id'               => $this->journalPurchase,
                'company_id'               => $this->companyId,
                'company_currency_id'      => $this->currencyId,
                'currency_id'              => $this->currencyId,
                'account_id'               => $this->acctStockIntRec,
                'partner_id'               => $this->supplierPartnerId,
                'product_id'               => $this->productIds[$line['product_key']],
                'uom_id'                   => $line['uom_id'],
                'creator_id'               => $this->userId,
                'name'                     => $line['name'],
                'display_type'             => 'product',
                'date'                     => $billDate->toDateString(),
                'invoice_date'             => $billDate->toDateString(),
                'quantity'                 => $line['qty'],
                'price_unit'               => $line['price_unit'],
                'price_subtotal'           => $subtotal,
                'price_total'              => $subtotal,
                'discount'                 => 0.00,
                'debit'                    => $subtotal,
                'credit'                   => 0.00,
                'balance'                  => $subtotal,
                'amount_currency'          => $subtotal,
                'amount_residual'          => 0.00,
                'amount_residual_currency' => 0.00,
                'tax_base_amount'          => 0.00,
                'reconciled'               => 0,
                'is_downpayment'           => 0,
                'created_at'               => $billDate,
                'updated_at'               => $billDate,
            ]);
        }

        // Payable line — CR Account Payable
        DB::table('accounts_account_move_lines')->insert([
            'sort'                     => count($lines) + 1,
            'move_id'                  => $moveId,
            'move_name'                => $billName,
            'parent_state'             => 'posted',
            'journal_id'               => $this->journalPurchase,
            'company_id'               => $this->companyId,
            'company_currency_id'      => $this->currencyId,
            'currency_id'              => $this->currencyId,
            'account_id'               => $this->acctPayable,
            'partner_id'               => $this->supplierPartnerId,
            'creator_id'               => $this->userId,
            'name'                     => $reference.' — Payable',
            'display_type'             => 'payment_term',
            'date'                     => $billDate->toDateString(),
            'invoice_date'             => $billDate->toDateString(),
            'date_maturity'            => $billDate->copy()->addDays(30)->toDateString(),
            'quantity'                 => 1,
            'price_unit'               => $total,
            'price_subtotal'           => $total,
            'price_total'              => $total,
            'discount'                 => 0.00,
            'debit'                    => 0.00,
            'credit'                   => $total,
            'balance'                  => -$total,
            'amount_currency'          => -$total,
            'amount_residual'          => 0.00,
            'amount_residual_currency' => 0.00,
            'tax_base_amount'          => 0.00,
            'reconciled'               => 1,
            'is_downpayment'           => 0,
            'created_at'               => $billDate,
            'updated_at'               => $billDate,
        ]);

        // Link bill → purchase order
        DB::table('purchases_order_account_moves')->insert([
            'order_id' => $orderId,
            'move_id'  => $moveId,
        ]);

        // Mark PO as invoiced
        DB::table('purchases_orders')->where('id', $orderId)->update([
            'invoice_status' => 'invoiced',
            'invoice_count'  => 1,
        ]);

        $this->command->info(sprintf(
            '  ✓ Vendor bill %s (posted, paid) — $%.2f  [move id=%d]',
            $billName,
            $total,
            $moveId,
        ));

        $this->ensureVendorPayment($moveId, $total, $billDate, $billSuffix);
    }

    // ── Accounting: Customer Invoice (out_invoice) ────────────────────

    /**
     * Create an accounting customer invoice for a sale order if it doesn't already exist.
     *
     * Double-entry:
     *   DR  Account Receivable  [121000]
     *   CR  Product Sales       [400000]  (per product/service line)
     *
     * COGS recognition (per storable product line, as a separate misc entry):
     *   DR  Cost of Goods Sold  [500000]
     *   CR  Stock Valuation     [110100]
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function ensureSaleAccounting(int $orderId, string $reference, int $customerId, array $lines, Carbon $invoiceDate): void
    {
        $invoiceSuffix = substr($reference, strrpos($reference, '/') + 1);
        $invoiceName = 'WKSHP/INV/'.$invoiceSuffix;

        $alreadyLinked = DB::table('accounts_account_moves')
            ->where('invoice_origin', $reference)
            ->where('move_type', 'out_invoice')
            ->exists();

        if ($alreadyLinked) {
            $this->command->info('  (skip accounting) Customer invoice already exists for '.$reference);
            $existingMove = DB::table('accounts_account_moves')->where('name', $invoiceName)->first();

            if ($existingMove) {
                $this->ensureCustomerPayment($existingMove->id, (float) $existingMove->amount_total, $customerId, $invoiceDate, $invoiceSuffix);
            }

            return;
        }

        $total = (float) array_sum(array_map(
            fn ($l) => $l['qty'] * $l['price_unit'],
            $lines
        ));

        $cogsTotal = (float) array_sum(array_map(function ($line): float {
            if (str_starts_with($line['product_key'], 'labor')) {
                return 0.0;
            }

            if (isset($line['cogs_allocations']) && is_array($line['cogs_allocations'])) {
                return (float) array_sum(array_map(
                    fn (array $allocation): float => $allocation['qty'] * $allocation['unit_cost'],
                    $line['cogs_allocations']
                ));
            }

            return (float) ($line['qty'] * $line['purchase_price']);
        }, $lines));

        $moveId = DB::table('accounts_account_moves')->insertGetId([
            'name'                               => $invoiceName,
            'move_type'                          => 'out_invoice',
            'state'                              => 'posted',
            'payment_state'                      => 'paid',
            'auto_post'                          => 'no',
            'sequence_prefix'                    => 'WKSHP/INV/',
            'journal_id'                         => $this->journalSale,
            'partner_id'                         => $customerId,
            'commercial_partner_id'              => $customerId,
            'currency_id'                        => $this->currencyId,
            'company_id'                         => $this->companyId,
            'invoice_user_id'                    => $this->userId,
            'creator_id'                         => $this->userId,
            'invoice_origin'                     => $reference,
            'invoice_date'                       => $invoiceDate->toDateString(),
            'invoice_date_due'                   => $invoiceDate->copy()->addDays(30)->toDateString(),
            'date'                               => $invoiceDate->toDateString(),
            'amount_untaxed'                     => $total,
            'amount_tax'                         => 0.00,
            'amount_total'                       => $total,
            'amount_residual'                    => 0.00,
            'amount_untaxed_signed'              => $total,
            'amount_tax_signed'                  => 0.00,
            'amount_total_signed'                => $total,
            'amount_residual_signed'             => 0.00,
            'amount_untaxed_in_currency_signed'  => $total,
            'amount_total_in_currency_signed'    => $total,
            'invoice_currency_rate'              => 1.0,
            'created_at'                         => $invoiceDate,
            'updated_at'                         => $invoiceDate,
        ]);

        foreach ($lines as $sort => $line) {
            $subtotal = round($line['qty'] * $line['price_unit'], 2);

            DB::table('accounts_account_move_lines')->insert([
                'sort'                     => $sort + 1,
                'move_id'                  => $moveId,
                'move_name'                => $invoiceName,
                'parent_state'             => 'posted',
                'journal_id'               => $this->journalSale,
                'company_id'               => $this->companyId,
                'company_currency_id'      => $this->currencyId,
                'currency_id'              => $this->currencyId,
                'account_id'               => $this->acctSales,
                'partner_id'               => $customerId,
                'product_id'               => $this->productIds[$line['product_key']],
                'uom_id'                   => $line['uom_id'],
                'creator_id'               => $this->userId,
                'name'                     => $line['name'],
                'display_type'             => 'product',
                'date'                     => $invoiceDate->toDateString(),
                'invoice_date'             => $invoiceDate->toDateString(),
                'quantity'                 => $line['qty'],
                'price_unit'               => $line['price_unit'],
                'price_subtotal'           => $subtotal,
                'price_total'              => $subtotal,
                'discount'                 => 0.00,
                'debit'                    => 0.00,
                'credit'                   => $subtotal,
                'balance'                  => -$subtotal,
                'amount_currency'          => -$subtotal,
                'amount_residual'          => 0.00,
                'amount_residual_currency' => 0.00,
                'tax_base_amount'          => 0.00,
                'reconciled'               => 0,
                'is_downpayment'           => 0,
                'created_at'               => $invoiceDate,
                'updated_at'               => $invoiceDate,
            ]);
        }

        DB::table('accounts_account_move_lines')->insert([
            'sort'                     => count($lines) + 1,
            'move_id'                  => $moveId,
            'move_name'                => $invoiceName,
            'parent_state'             => 'posted',
            'journal_id'               => $this->journalSale,
            'company_id'               => $this->companyId,
            'company_currency_id'      => $this->currencyId,
            'currency_id'              => $this->currencyId,
            'account_id'               => $this->acctReceivable,
            'partner_id'               => $customerId,
            'creator_id'               => $this->userId,
            'name'                     => $reference.' — Receivable',
            'display_type'             => 'payment_term',
            'date'                     => $invoiceDate->toDateString(),
            'invoice_date'             => $invoiceDate->toDateString(),
            'date_maturity'            => $invoiceDate->copy()->addDays(30)->toDateString(),
            'quantity'                 => 1,
            'price_unit'               => $total,
            'price_subtotal'           => $total,
            'price_total'              => $total,
            'discount'                 => 0.00,
            'debit'                    => $total,
            'credit'                   => 0.00,
            'balance'                  => $total,
            'amount_currency'          => $total,
            'amount_residual'          => 0.00,
            'amount_residual_currency' => 0.00,
            'tax_base_amount'          => 0.00,
            'reconciled'               => 1,
            'is_downpayment'           => 0,
            'created_at'               => $invoiceDate,
            'updated_at'               => $invoiceDate,
        ]);

        DB::table('sales_orders')->where('id', $orderId)->update([
            'invoice_status' => 'invoiced',
        ]);

        DB::table('sales_order_lines')->where('order_id', $orderId)->update([
            'qty_invoiced'              => DB::raw('product_uom_qty'),
            'invoice_status'            => 'invoiced',
            'untaxed_amount_invoiced'   => DB::raw('price_subtotal'),
            'untaxed_amount_to_invoice' => 0,
        ]);

        $this->command->info(sprintf(
            '  ✓ Customer invoice %s (posted, paid) — $%.2f  [move id=%d]',
            $invoiceName,
            $total,
            $moveId,
        ));

        $this->ensureCustomerPayment($moveId, $total, $customerId, $invoiceDate, $invoiceSuffix);

        if ($cogsTotal > 0) {
            $this->createCogsEntry($reference, $invoiceDate, $lines, $cogsTotal);
        }
    }

    // ── Accounting: Vendor Payment (outbound bank) ───────────────────

    /**
     * Create a bank payment record to settle a vendor bill.
     *
     *   DR  Account Payable  [211000]  (clearing the liability)
     *   CR  Bank             [101501]  (money leaves)
     */
    private function ensureVendorPayment(int $billMoveId, float $total, Carbon $paymentDate, string $suffix): void
    {
        if (DB::table('accounts_accounts_move_payment')->where('invoice_id', $billMoveId)->exists()) {
            return;
        }

        $paymentName = 'WKSHP/BNK/PAY/'.$suffix;

        $paymentMoveId = DB::table('accounts_account_moves')->insertGetId([
            'name'                               => $paymentName,
            'move_type'                          => 'entry',
            'state'                              => 'posted',
            'payment_state'                      => 'not_paid',
            'auto_post'                          => 'no',
            'sequence_prefix'                    => 'WKSHP/BNK/',
            'journal_id'                         => $this->journalBank,
            'partner_id'                         => $this->supplierPartnerId,
            'currency_id'                        => $this->currencyId,
            'company_id'                         => $this->companyId,
            'creator_id'                         => $this->userId,
            'date'                               => $paymentDate->toDateString(),
            'amount_untaxed'                     => $total,
            'amount_tax'                         => 0.00,
            'amount_total'                       => $total,
            'amount_residual'                    => 0.00,
            'amount_untaxed_signed'              => -$total,
            'amount_tax_signed'                  => 0.00,
            'amount_total_signed'                => -$total,
            'amount_residual_signed'             => 0.00,
            'amount_untaxed_in_currency_signed'  => -$total,
            'amount_total_in_currency_signed'    => -$total,
            'invoice_currency_rate'              => 1.0,
            'created_at'                         => $paymentDate,
            'updated_at'                         => $paymentDate,
        ]);

        // DR Account Payable
        DB::table('accounts_account_move_lines')->insert([
            'sort'                     => 1,
            'move_id'                  => $paymentMoveId,
            'move_name'                => $paymentName,
            'parent_state'             => 'posted',
            'journal_id'               => $this->journalBank,
            'company_id'               => $this->companyId,
            'company_currency_id'      => $this->currencyId,
            'currency_id'              => $this->currencyId,
            'account_id'               => $this->acctPayable,
            'partner_id'               => $this->supplierPartnerId,
            'creator_id'               => $this->userId,
            'name'                     => 'Vendor Payment — '.$suffix,
            'display_type'             => 'payment_term',
            'date'                     => $paymentDate->toDateString(),
            'quantity'                 => 1,
            'price_unit'               => $total,
            'price_subtotal'           => $total,
            'price_total'              => $total,
            'discount'                 => 0.00,
            'debit'                    => $total,
            'credit'                   => 0.00,
            'balance'                  => $total,
            'amount_currency'          => $total,
            'amount_residual'          => 0.00,
            'amount_residual_currency' => 0.00,
            'tax_base_amount'          => 0.00,
            'reconciled'               => 1,
            'is_downpayment'           => 0,
            'created_at'               => $paymentDate,
            'updated_at'               => $paymentDate,
        ]);

        // CR Bank
        DB::table('accounts_account_move_lines')->insert([
            'sort'                     => 2,
            'move_id'                  => $paymentMoveId,
            'move_name'                => $paymentName,
            'parent_state'             => 'posted',
            'journal_id'               => $this->journalBank,
            'company_id'               => $this->companyId,
            'company_currency_id'      => $this->currencyId,
            'currency_id'              => $this->currencyId,
            'account_id'               => $this->acctBank,
            'partner_id'               => $this->supplierPartnerId,
            'creator_id'               => $this->userId,
            'name'                     => 'Bank — '.$suffix,
            'display_type'             => 'product',
            'date'                     => $paymentDate->toDateString(),
            'quantity'                 => 1,
            'price_unit'               => $total,
            'price_subtotal'           => $total,
            'price_total'              => $total,
            'discount'                 => 0.00,
            'debit'                    => 0.00,
            'credit'                   => $total,
            'balance'                  => -$total,
            'amount_currency'          => -$total,
            'amount_residual'          => 0.00,
            'amount_residual_currency' => 0.00,
            'tax_base_amount'          => 0.00,
            'reconciled'               => 0,
            'is_downpayment'           => 0,
            'created_at'               => $paymentDate,
            'updated_at'               => $paymentDate,
        ]);

        $paymentId = DB::table('accounts_account_payments')->insertGetId([
            'name'                           => $paymentName,
            'state'                          => 'paid',
            'payment_type'                   => 'outbound',
            'partner_type'                   => 'supplier',
            'memo'                           => 'Payment for PO/'.$suffix,
            'date'                           => $paymentDate->toDateString(),
            'amount'                         => $total,
            'amount_company_currency_signed' => -$total,
            'is_reconciled'                  => 1,
            'is_matched'                     => 1,
            'is_sent'                        => 0,
            'journal_id'                     => $this->journalBank,
            'currency_id'                    => $this->currencyId,
            'company_id'                     => $this->companyId,
            'partner_id'                     => $this->supplierPartnerId,
            'payment_method_line_id'         => $this->pmLineOutbound,
            'payment_method_id'              => 2,
            'destination_account_id'         => $this->acctPayable,
            'outstanding_account_id'         => $this->acctBank,
            'move_id'                        => $paymentMoveId,
            'creator_id'                     => $this->userId,
            'created_at'                     => $paymentDate,
            'updated_at'                     => $paymentDate,
        ]);

        DB::table('accounts_accounts_move_payment')->insert([
            'invoice_id' => $billMoveId,
            'payment_id' => $paymentId,
        ]);

        $this->command->info(sprintf(
            '  ✓ Bank payment %s (outbound) — $%.2f  [payment id=%d]',
            $paymentName,
            $total,
            $paymentId,
        ));
    }

    // ── Accounting: Customer Receipt (inbound bank) ──────────────────

    /**
     * Create a bank payment record to settle a customer invoice.
     *
     *   DR  Bank                [101501]  (money arrives)
     *   CR  Account Receivable  [121000]  (clearing the asset)
     */
    private function ensureCustomerPayment(int $invoiceMoveId, float $total, int $customerId, Carbon $paymentDate, string $suffix): void
    {
        if (DB::table('accounts_accounts_move_payment')->where('invoice_id', $invoiceMoveId)->exists()) {
            return;
        }

        $paymentName = 'WKSHP/BNK/REC/'.$suffix;

        $paymentMoveId = DB::table('accounts_account_moves')->insertGetId([
            'name'                               => $paymentName,
            'move_type'                          => 'entry',
            'state'                              => 'posted',
            'payment_state'                      => 'not_paid',
            'auto_post'                          => 'no',
            'sequence_prefix'                    => 'WKSHP/BNK/',
            'journal_id'                         => $this->journalBank,
            'partner_id'                         => $customerId,
            'currency_id'                        => $this->currencyId,
            'company_id'                         => $this->companyId,
            'creator_id'                         => $this->userId,
            'date'                               => $paymentDate->toDateString(),
            'amount_untaxed'                     => $total,
            'amount_tax'                         => 0.00,
            'amount_total'                       => $total,
            'amount_residual'                    => 0.00,
            'amount_untaxed_signed'              => $total,
            'amount_tax_signed'                  => 0.00,
            'amount_total_signed'                => $total,
            'amount_residual_signed'             => 0.00,
            'amount_untaxed_in_currency_signed'  => $total,
            'amount_total_in_currency_signed'    => $total,
            'invoice_currency_rate'              => 1.0,
            'created_at'                         => $paymentDate,
            'updated_at'                         => $paymentDate,
        ]);

        // DR Bank
        DB::table('accounts_account_move_lines')->insert([
            'sort'                     => 1,
            'move_id'                  => $paymentMoveId,
            'move_name'                => $paymentName,
            'parent_state'             => 'posted',
            'journal_id'               => $this->journalBank,
            'company_id'               => $this->companyId,
            'company_currency_id'      => $this->currencyId,
            'currency_id'              => $this->currencyId,
            'account_id'               => $this->acctBank,
            'partner_id'               => $customerId,
            'creator_id'               => $this->userId,
            'name'                     => 'Bank — '.$suffix,
            'display_type'             => 'product',
            'date'                     => $paymentDate->toDateString(),
            'quantity'                 => 1,
            'price_unit'               => $total,
            'price_subtotal'           => $total,
            'price_total'              => $total,
            'discount'                 => 0.00,
            'debit'                    => $total,
            'credit'                   => 0.00,
            'balance'                  => $total,
            'amount_currency'          => $total,
            'amount_residual'          => 0.00,
            'amount_residual_currency' => 0.00,
            'tax_base_amount'          => 0.00,
            'reconciled'               => 0,
            'is_downpayment'           => 0,
            'created_at'               => $paymentDate,
            'updated_at'               => $paymentDate,
        ]);

        // CR Account Receivable
        DB::table('accounts_account_move_lines')->insert([
            'sort'                     => 2,
            'move_id'                  => $paymentMoveId,
            'move_name'                => $paymentName,
            'parent_state'             => 'posted',
            'journal_id'               => $this->journalBank,
            'company_id'               => $this->companyId,
            'company_currency_id'      => $this->currencyId,
            'currency_id'              => $this->currencyId,
            'account_id'               => $this->acctReceivable,
            'partner_id'               => $customerId,
            'creator_id'               => $this->userId,
            'name'                     => 'Customer Receipt — '.$suffix,
            'display_type'             => 'payment_term',
            'date'                     => $paymentDate->toDateString(),
            'quantity'                 => 1,
            'price_unit'               => $total,
            'price_subtotal'           => $total,
            'price_total'              => $total,
            'discount'                 => 0.00,
            'debit'                    => 0.00,
            'credit'                   => $total,
            'balance'                  => -$total,
            'amount_currency'          => -$total,
            'amount_residual'          => 0.00,
            'amount_residual_currency' => 0.00,
            'tax_base_amount'          => 0.00,
            'reconciled'               => 1,
            'is_downpayment'           => 0,
            'created_at'               => $paymentDate,
            'updated_at'               => $paymentDate,
        ]);

        $paymentId = DB::table('accounts_account_payments')->insertGetId([
            'name'                           => $paymentName,
            'state'                          => 'paid',
            'payment_type'                   => 'inbound',
            'partner_type'                   => 'customer',
            'memo'                           => 'Receipt for SO/'.$suffix,
            'date'                           => $paymentDate->toDateString(),
            'amount'                         => $total,
            'amount_company_currency_signed' => $total,
            'is_reconciled'                  => 1,
            'is_matched'                     => 1,
            'is_sent'                        => 0,
            'journal_id'                     => $this->journalBank,
            'currency_id'                    => $this->currencyId,
            'company_id'                     => $this->companyId,
            'partner_id'                     => $customerId,
            'payment_method_line_id'         => $this->pmLineInbound,
            'payment_method_id'              => 1,
            'destination_account_id'         => $this->acctReceivable,
            'outstanding_account_id'         => $this->acctBank,
            'move_id'                        => $paymentMoveId,
            'creator_id'                     => $this->userId,
            'created_at'                     => $paymentDate,
            'updated_at'                     => $paymentDate,
        ]);

        DB::table('accounts_accounts_move_payment')->insert([
            'invoice_id' => $invoiceMoveId,
            'payment_id' => $paymentId,
        ]);

        $this->command->info(sprintf(
            '  ✓ Bank receipt %s (inbound) — $%.2f  [payment id=%d]',
            $paymentName,
            $total,
            $paymentId,
        ));
    }

    // ── Accounting: COGS stock-valuation entry ───────────────────────

    /**
     * Create the COGS recognition journal entry that transfers stock value
     * out of inventory into the income statement:
     *
     *   DR  Cost of Goods Sold  [500000]   (expense recognised)
     *   CR  Stock Valuation     [110100]   (asset reduced)
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function createCogsEntry(string $soRef, Carbon $entryDate, array $lines, float $cogsTotal): void
    {
        $entryName = 'WKSHP/COGS/'.substr($soRef, strrpos($soRef, '/') + 1);

        // Idempotency
        if (DB::table('accounts_account_moves')->where('name', $entryName)->exists()) {
            return;
        }

        $moveId = DB::table('accounts_account_moves')->insertGetId([
            'name'                               => $entryName,
            'move_type'                          => 'entry',
            'state'                              => 'posted',
            'payment_state'                      => 'not_paid',
            'auto_post'                          => 'no',
            'sequence_prefix'                    => 'WKSHP/COGS/',
            'journal_id'                         => $this->journalMisc,
            'currency_id'                        => $this->currencyId,
            'company_id'                         => $this->companyId,
            'creator_id'                         => $this->userId,
            'invoice_origin'                     => $soRef,
            'date'                               => $entryDate->toDateString(),
            'amount_untaxed'                     => $cogsTotal,
            'amount_tax'                         => 0.00,
            'amount_total'                       => $cogsTotal,
            'amount_residual'                    => 0.00,
            'amount_untaxed_signed'              => $cogsTotal,
            'amount_tax_signed'                  => 0.00,
            'amount_total_signed'                => $cogsTotal,
            'amount_residual_signed'             => 0.00,
            'amount_untaxed_in_currency_signed'  => $cogsTotal,
            'amount_total_in_currency_signed'    => $cogsTotal,
            'invoice_currency_rate'              => 1.0,
            'created_at'                         => $entryDate,
            'updated_at'                         => $entryDate,
        ]);

        $sort = 1;

        $insertCogsPair = function (array $lineData, float $quantity, float $unitCost, ?string $lotName = null) use (&$sort, $moveId, $entryName, $entryDate): void {
            $cogsAmount = round($quantity * $unitCost, 2);

            if ($cogsAmount <= 0) {
                return;
            }

            $lineNameSuffix = $lotName ? ' ['.$lotName.']' : '';

            DB::table('accounts_account_move_lines')->insert([
                'sort'                     => $sort++,
                'move_id'                  => $moveId,
                'move_name'                => $entryName,
                'parent_state'             => 'posted',
                'journal_id'               => $this->journalMisc,
                'company_id'               => $this->companyId,
                'company_currency_id'      => $this->currencyId,
                'currency_id'              => $this->currencyId,
                'account_id'               => $this->acctCogs,
                'product_id'               => $this->productIds[$lineData['product_key']],
                'uom_id'                   => $lineData['uom_id'],
                'creator_id'               => $this->userId,
                'name'                     => 'COGS — '.$lineData['name'].$lineNameSuffix,
                'display_type'             => 'product',
                'date'                     => $entryDate->toDateString(),
                'quantity'                 => $quantity,
                'price_unit'               => $unitCost,
                'price_subtotal'           => $cogsAmount,
                'price_total'              => $cogsAmount,
                'discount'                 => 0.00,
                'debit'                    => $cogsAmount,
                'credit'                   => 0.00,
                'balance'                  => $cogsAmount,
                'amount_currency'          => $cogsAmount,
                'amount_residual'          => 0.00,
                'amount_residual_currency' => 0.00,
                'tax_base_amount'          => 0.00,
                'reconciled'               => 0,
                'is_downpayment'           => 0,
                'created_at'               => $entryDate,
                'updated_at'               => $entryDate,
            ]);

            DB::table('accounts_account_move_lines')->insert([
                'sort'                     => $sort++,
                'move_id'                  => $moveId,
                'move_name'                => $entryName,
                'parent_state'             => 'posted',
                'journal_id'               => $this->journalMisc,
                'company_id'               => $this->companyId,
                'company_currency_id'      => $this->currencyId,
                'currency_id'              => $this->currencyId,
                'account_id'               => $this->acctStockVal,
                'product_id'               => $this->productIds[$lineData['product_key']],
                'uom_id'                   => $lineData['uom_id'],
                'creator_id'               => $this->userId,
                'name'                     => 'Stock — '.$lineData['name'].$lineNameSuffix,
                'display_type'             => 'product',
                'date'                     => $entryDate->toDateString(),
                'quantity'                 => $quantity,
                'price_unit'               => $unitCost,
                'price_subtotal'           => $cogsAmount,
                'price_total'              => $cogsAmount,
                'discount'                 => 0.00,
                'debit'                    => 0.00,
                'credit'                   => $cogsAmount,
                'balance'                  => -$cogsAmount,
                'amount_currency'          => -$cogsAmount,
                'amount_residual'          => 0.00,
                'amount_residual_currency' => 0.00,
                'tax_base_amount'          => 0.00,
                'reconciled'               => 0,
                'is_downpayment'           => 0,
                'created_at'               => $entryDate,
                'updated_at'               => $entryDate,
            ]);
        };

        foreach ($lines as $line) {
            if (str_starts_with($line['product_key'], 'labor')) {
                continue;
            }

            if (isset($line['cogs_allocations']) && is_array($line['cogs_allocations'])) {
                foreach ($line['cogs_allocations'] as $allocation) {
                    $insertCogsPair(
                        $line,
                        (float) $allocation['qty'],
                        (float) $allocation['unit_cost'],
                        isset($allocation['lot_name']) ? (string) $allocation['lot_name'] : null
                    );
                }

                continue;
            }

            $insertCogsPair(
                $line,
                (float) $line['qty'],
                (float) $line['purchase_price']
            );
        }

        $this->command->info(sprintf(
            '  ✓ COGS entry %s (posted) — $%.2f  [move id=%d]',
            $entryName,
            $cogsTotal,
            $moveId,
        ));
    }

    private function adjustStock(int $productId, float $delta, ?int $lotId = null, ?Carbon $incomingAt = null): void
    {
        $query = DB::table('inventories_product_quantities')
            ->where('product_id', $productId)
            ->where('location_id', $this->stockLocId)
            ->whereNull('package_id');

        if ($lotId) {
            $query->where('lot_id', $lotId);
        } else {
            $query->whereNull('lot_id');
        }

        $existing = $query->first();

        if ($existing) {
            DB::table('inventories_product_quantities')
                ->where('id', $existing->id)
                ->update([
                    'quantity'   => max(0, $existing->quantity + $delta),
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('inventories_product_quantities')->insert([
                'quantity'                => max(0, $delta),
                'reserved_quantity'       => 0,
                'counted_quantity'        => 0,
                'difference_quantity'     => 0,
                'inventory_diff_quantity' => 0,
                'inventory_quantity_set'  => 0,
                'incoming_at'             => ($incomingAt ?? now()),
                'product_id'              => $productId,
                'location_id'             => $this->stockLocId,
                'lot_id'                  => $lotId,
                'company_id'              => $this->companyId,
                'creator_id'              => $this->userId,
                'created_at'              => now(),
                'updated_at'              => now(),
            ]);
        }
    }

    private function usesLotTracking(string $productKey): bool
    {
        return in_array($productKey, $this->lotTrackedProductKeys, true);
    }

    /**
     * @return array<int, float>
     */
    private function splitQuantityIntoRandomLots(float $quantity): array
    {
        $quantity = round($quantity, 4);

        if ($quantity <= 0) {
            return [];
        }

        $intQuantity = (int) round($quantity);

        if (abs($quantity - $intQuantity) > 0.0001 || $intQuantity <= 1) {
            return [$quantity];
        }

        $lotCount = random_int(1, min(3, $intQuantity));

        if ($lotCount === 1) {
            return [$quantity];
        }

        $remaining = $intQuantity;
        $splits = [];

        for ($index = 1; $index < $lotCount; $index++) {
            $maxForCurrent = $remaining - ($lotCount - $index);
            $chunk = random_int(1, $maxForCurrent);
            $splits[] = (float) $chunk;
            $remaining -= $chunk;
        }

        $splits[] = (float) $remaining;

        shuffle($splits);

        return $splits;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function expandPurchaseLinesWithLotPrices(array $lines): array
    {
        $expandedLines = [];

        foreach ($lines as $line) {
            if (! $this->usesLotTracking((string) $line['product_key'])) {
                $expandedLines[] = $line;

                continue;
            }

            $lotQuantities = $this->splitQuantityIntoRandomLots((float) $line['qty']);

            foreach ($lotQuantities as $lotIndex => $lotQty) {
                $lotPriceUnit = $this->nextLotPurchaseUnitPrice(
                    (string) $line['product_key'],
                    (float) $line['price_unit']
                );

                $expandedLines[] = array_merge($line, [
                    'qty'        => $lotQty,
                    'price_unit' => $lotPriceUnit,
                    'lot_index'  => $lotIndex + 1,
                ]);
            }
        }

        return $expandedLines;
    }

    private function nextLotPurchaseUnitPrice(string $productKey, float $fallbackPrice): float
    {
        if (! isset($this->lastLotPurchaseUnitPrices[$productKey])) {
            $basePrice = round($fallbackPrice, 2);
            $this->lastLotPurchaseUnitPrices[$productKey] = $basePrice;

            return $basePrice;
        }

        $previousPrice = $this->lastLotPurchaseUnitPrices[$productKey];
        $difference = (float) random_int(1, 5);
        $direction = random_int(0, 1) === 0 ? -1 : 1;
        $candidatePrice = $previousPrice + ($direction * $difference);

        if ($candidatePrice <= 0) {
            $candidatePrice = $previousPrice + $difference;
        }

        $nextPrice = round($candidatePrice, 2);
        $this->lastLotPurchaseUnitPrices[$productKey] = $nextPrice;

        return $nextPrice;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function createLotForReceipt(string $reference, array $line, int $productId, Carbon $receivedAt, int $index): int
    {
        $lotName = str_replace('/', '-', $reference)
            .'-'
            .strtoupper((string) $line['product_key'])
            .'-LOT'
            .$index;

        $existingLot = DB::table('inventories_lots')
            ->where('name', $lotName)
            ->where('product_id', $productId)
            ->first();

        if ($existingLot) {
            $existingProperties = is_string($existingLot->properties ?? null)
                ? json_decode((string) $existingLot->properties, true)
                : (is_array($existingLot->properties ?? null) ? $existingLot->properties : []);

            if (! is_array($existingProperties)) {
                $existingProperties = [];
            }

            if (! array_key_exists('purchase_price', $existingProperties)) {
                $existingProperties['purchase_price'] = round((float) $line['price_unit'], 2);

                DB::table('inventories_lots')
                    ->where('id', $existingLot->id)
                    ->update([
                        'properties' => json_encode($existingProperties),
                        'updated_at' => $receivedAt,
                    ]);
            }

            return $existingLot->id;
        }

        return DB::table('inventories_lots')->insertGetId([
            'name'        => $lotName,
            'reference'   => $reference,
            'description' => 'Workshop demo lot for '.$line['name'],
            'properties'  => json_encode([
                'purchase_price' => round((float) $line['price_unit'], 2),
            ]),
            'product_id'  => $productId,
            'uom_id'      => $line['uom_id'],
            'location_id' => $this->stockLocId,
            'company_id'  => $this->companyId,
            'creator_id'  => $this->userId,
            'created_at'  => $receivedAt,
            'updated_at'  => $receivedAt,
        ]);
    }

    /**
     * @return array<int, array{lot_id:int, lot_name:string, qty:float, unit_cost:float}>
     */
    private function consumeStockFromLots(int $productId, float $requestedQty, ?float $fallbackUnitCost = null): array
    {
        $remainingQty = round($requestedQty, 4);

        if ($remainingQty <= 0) {
            return [];
        }

        $resolvedFallbackUnitCost = $fallbackUnitCost ?? (float) (
            DB::table('products_products')->where('id', $productId)->value('cost') ?? 0
        );

        $allocations = [];

        $lotQuantities = DB::table('inventories_product_quantities as pq')
            ->join('inventories_lots as lot', 'lot.id', '=', 'pq.lot_id')
            ->where('pq.product_id', $productId)
            ->where('pq.location_id', $this->stockLocId)
            ->whereNull('pq.package_id')
            ->whereNotNull('pq.lot_id')
            ->where('pq.quantity', '>', 0)
            ->orderBy('pq.incoming_at')
            ->orderBy('pq.id')
            ->select('pq.id', 'pq.quantity', 'pq.lot_id', 'lot.name as lot_name', 'lot.properties as lot_properties')
            ->get();

        foreach ($lotQuantities as $quantityRow) {
            if ($remainingQty <= 0) {
                break;
            }

            $availableQty = (float) $quantityRow->quantity;

            if ($availableQty <= 0) {
                continue;
            }

            $pickedQty = round(min($availableQty, $remainingQty), 4);

            if ($pickedQty <= 0) {
                continue;
            }

            DB::table('inventories_product_quantities')
                ->where('id', $quantityRow->id)
                ->update([
                    'quantity'   => max(0, $availableQty - $pickedQty),
                    'updated_at' => now(),
                ]);

            $allocations[] = [
                'lot_id'    => (int) $quantityRow->lot_id,
                'lot_name'  => (string) $quantityRow->lot_name,
                'qty'       => $pickedQty,
                'unit_cost' => $this->resolveLotUnitCost(
                    (string) $quantityRow->lot_name,
                    $productId,
                    $quantityRow->lot_properties,
                    $resolvedFallbackUnitCost
                ),
            ];

            $remainingQty = round($remainingQty - $pickedQty, 4);
        }

        if ($remainingQty > 0) {
            $this->adjustStock($productId, -$remainingQty);
            $allocations[] = [
                'lot_id'    => 0,
                'lot_name'  => '',
                'qty'       => $remainingQty,
                'unit_cost' => $resolvedFallbackUnitCost,
            ];
        }

        return array_values(array_filter(
            $allocations,
            fn (array $allocation): bool => $allocation['qty'] > 0
        ));
    }

    private function resolveLotUnitCost(string $lotName, int $productId, mixed $lotProperties, float $fallbackUnitCost): float
    {
        $properties = [];

        if (is_string($lotProperties) && $lotProperties !== '') {
            $decoded = json_decode($lotProperties, true);
            $properties = is_array($decoded) ? $decoded : [];
        } elseif (is_array($lotProperties)) {
            $properties = $lotProperties;
        }

        $propertyCost = isset($properties['purchase_price'])
            ? (float) $properties['purchase_price']
            : 0.0;

        if ($propertyCost > 0) {
            return $propertyCost;
        }

        if (preg_match('/^PO-WKSHP-(\d+)-[A-Z0-9_]+-LOT(\d+)$/', $lotName, $matches) === 1) {
            $poReference = sprintf('PO/WKSHP/%03d', (int) $matches[1]);
            $lotIndex = (int) $matches[2];

            if ($lotIndex > 0) {
                $purchaseLine = DB::table('purchases_order_lines as pol')
                    ->join('purchases_orders as po', 'po.id', '=', 'pol.order_id')
                    ->where('po.name', $poReference)
                    ->where('pol.product_id', $productId)
                    ->orderBy('pol.id')
                    ->skip($lotIndex - 1)
                    ->first(['pol.price_unit']);

                if ($purchaseLine && (float) $purchaseLine->price_unit > 0) {
                    return (float) $purchaseLine->price_unit;
                }
            }
        }

        return max(0.0, $fallbackUnitCost);
    }

    private function getCurrentProductUnitCost(string $productKey, float $fallbackUnitCost): float
    {
        $productId = $this->productIds[$productKey] ?? null;

        if (! $productId) {
            return round($fallbackUnitCost, 4);
        }

        return $this->getWeightedOnHandUnitCost($productId, $fallbackUnitCost);
    }

    private function getWeightedOnHandUnitCost(int $productId, float $fallbackUnitCost): float
    {
        $lotRows = DB::table('inventories_product_quantities as pq')
            ->join('inventories_lots as lot', 'lot.id', '=', 'pq.lot_id')
            ->where('pq.product_id', $productId)
            ->where('pq.location_id', $this->stockLocId)
            ->whereNull('pq.package_id')
            ->whereNotNull('pq.lot_id')
            ->where('pq.quantity', '>', 0)
            ->select('pq.quantity', 'lot.name as lot_name', 'lot.properties as lot_properties')
            ->get();

        if ($lotRows->isNotEmpty()) {
            $quantityTotal = 0.0;
            $valueTotal = 0.0;

            foreach ($lotRows as $lotRow) {
                $qty = (float) $lotRow->quantity;

                if ($qty <= 0) {
                    continue;
                }

                $unitCost = $this->resolveLotUnitCost(
                    (string) $lotRow->lot_name,
                    $productId,
                    $lotRow->lot_properties,
                    $fallbackUnitCost
                );

                $quantityTotal += $qty;
                $valueTotal += $qty * $unitCost;
            }

            if ($quantityTotal > 0) {
                return round($valueTotal / $quantityTotal, 4);
            }
        }

        $nonLotQty = (float) (DB::table('inventories_product_quantities')
            ->where('product_id', $productId)
            ->where('location_id', $this->stockLocId)
            ->whereNull('package_id')
            ->whereNull('lot_id')
            ->sum('quantity'));

        if ($nonLotQty > 0) {
            $productCost = (float) (DB::table('products_products')->where('id', $productId)->value('cost') ?? 0);

            return $productCost > 0 ? round($productCost, 4) : round($fallbackUnitCost, 4);
        }

        return round($fallbackUnitCost, 4);
    }

    private function syncProductCostsFromInventory(): void
    {
        foreach ($this->storableProductKeys as $productKey) {
            $productId = $this->productIds[$productKey] ?? null;

            if (! $productId) {
                continue;
            }

            $currentCost = (float) (DB::table('products_products')->where('id', $productId)->value('cost') ?? 0);
            $computedCost = $this->getWeightedOnHandUnitCost($productId, $currentCost);

            if ($computedCost <= 0) {
                continue;
            }

            DB::table('products_products')
                ->where('id', $productId)
                ->update([
                    'cost'       => $computedCost,
                    'updated_at' => now(),
                ]);
        }
    }

    // ── Stock Summary ────────────────────────────────────────────────

    private function printStockSummary(): void
    {
        $this->command->info('');
        $this->command->info('─────────────────────────────────────────────────────────────');
        $this->command->info('  📦  CLOSING STOCK & INVENTORY VALUATION (WH/Stock)');
        $this->command->info('─────────────────────────────────────────────────────────────');
        $this->command->info(sprintf('  %-34s %6s %8s %12s', 'Product', 'UOM', 'Qty', 'Value'));
        $this->command->info('  '.str_repeat('-', 65));

        $totalValue = 0;

        foreach ($this->storableProductKeys as $key) {
            if (! isset($this->productIds[$key])) {
                continue;
            }

            $productId = $this->productIds[$key];

            $row = DB::table('inventories_product_quantities')
                ->where('product_id', $productId)
                ->where('location_id', $this->stockLocId)
                ->whereNull('package_id')
                ->selectRaw('COALESCE(SUM(quantity), 0) as qty')
                ->first();

            $qty = (float) ($row?->qty ?? 0);

            $product = DB::table('products_products')->find($productId);
            $cost = $product ? $this->getWeightedOnHandUnitCost($productId, (float) $product->cost) : 0;
            $value = $qty * $cost;
            $totalValue += $value;

            $uomName = match ($product?->uom_id) {
                $this->uomLitres => 'L',
                default          => 'pc',
            };

            $this->command->info(sprintf(
                '  %-34s %6s %8.1f %12.2f',
                $product?->name ?? 'Unknown',
                $uomName,
                $qty,
                $value,
            ));
        }

        $this->command->info('  '.str_repeat('-', 65));
        $this->command->info(sprintf('  %-34s %6s %8s %12.2f', 'TOTAL INVENTORY VALUE', '', '', $totalValue));
        $this->command->info('─────────────────────────────────────────────────────────────');

        $this->command->info('');
        $this->command->info('  💰  SALES MARGIN SUMMARY');
        $this->command->info('─────────────────────────────────────────────────────────────');
        $this->command->info(sprintf('  %-20s %12s %12s %12s', 'Order', 'Revenue', 'COGS', 'Gross Margin'));
        $this->command->info('  '.str_repeat('-', 60));

        $orders = DB::table('sales_orders')
            ->where('name', 'like', 'SO/WKSHP/%')
            ->get();

        $totalRevenue = 0;
        $totalCogs = 0;

        foreach ($orders as $order) {
            $lines = DB::table('sales_order_lines')
                ->where('order_id', $order->id)
                ->get();

            $revenue = 0;
            $cogs = 0;

            foreach ($lines as $line) {
                $revenue += $line->price_subtotal;
                $cogs += $line->purchase_price * $line->product_qty;
            }

            $margin = $revenue - $cogs;
            $totalRevenue += $revenue;
            $totalCogs += $cogs;

            $this->command->info(sprintf(
                '  %-20s %12.2f %12.2f %12.2f',
                $order->name,
                $revenue,
                $cogs,
                $margin,
            ));
        }

        $this->command->info('  '.str_repeat('-', 60));
        $this->command->info(sprintf(
            '  %-20s %12.2f %12.2f %12.2f',
            'TOTAL',
            $totalRevenue,
            $totalCogs,
            $totalRevenue - $totalCogs,
        ));
        $this->command->info('─────────────────────────────────────────────────────────────');
    }

    private function printLotCostControlSummary(): void
    {
        $this->command->info('');
        $this->command->info('  🧪  LOT COST CONTROL SUMMARY (PO LOT-TRACKED LINES)');
        $this->command->info('─────────────────────────────────────────────────────────────');
        $this->command->info(sprintf('  %-12s %-11s %8s %10s %8s', 'PO', 'Product', 'Qty', 'Unit', 'Δ Prev'));
        $this->command->info('  '.str_repeat('-', 60));

        $rows = DB::table('purchases_order_lines as pol')
            ->join('purchases_orders as po', 'po.id', '=', 'pol.order_id')
            ->join('products_products as p', 'p.id', '=', 'pol.product_id')
            ->where('po.name', 'like', 'PO/WKSHP/%')
            ->whereIn('p.tracking', ['lot', 'serial'])
            ->select(
                'po.name as purchase_order',
                'p.reference as product_reference',
                'pol.product_qty as quantity',
                'pol.price_unit as unit_price',
                'pol.id as line_id'
            )
            ->orderBy('p.reference')
            ->orderBy('po.name')
            ->orderBy('pol.id')
            ->get();

        if ($rows->isEmpty()) {
            $this->command->info('  (no lot-tracked purchase lines found)');
            $this->command->info('─────────────────────────────────────────────────────────────');

            return;
        }

        $previousPriceByProduct = [];

        foreach ($rows as $row) {
            $productRef = (string) $row->product_reference;
            $unitPrice = (float) $row->unit_price;
            $previousPrice = $previousPriceByProduct[$productRef] ?? null;
            $deltaText = $previousPrice === null
                ? 'base'
                : sprintf('%+.2f', $unitPrice - $previousPrice);

            $this->command->info(sprintf(
                '  %-12s %-11s %8.2f %10.2f %8s',
                $row->purchase_order,
                $productRef,
                (float) $row->quantity,
                $unitPrice,
                $deltaText,
            ));

            $previousPriceByProduct[$productRef] = $unitPrice;
        }

        $this->command->info('  '.str_repeat('-', 60));

        $lotCountRows = DB::table('inventories_lots as lot')
            ->join('products_products as p', 'p.id', '=', 'lot.product_id')
            ->where('lot.reference', 'like', 'PO/WKSHP/%')
            ->whereIn('p.tracking', ['lot', 'serial'])
            ->select(
                'lot.reference as purchase_order',
                'p.reference as product_reference',
                DB::raw('COUNT(*) as lot_count')
            )
            ->groupBy('lot.reference', 'p.reference')
            ->orderBy('p.reference')
            ->orderBy('lot.reference')
            ->get();

        $this->command->info('  Lots per PO/Product:');

        foreach ($lotCountRows as $lotCountRow) {
            $this->command->info(sprintf(
                '  - %s | %s | lots=%d',
                $lotCountRow->purchase_order,
                $lotCountRow->product_reference,
                (int) $lotCountRow->lot_count,
            ));
        }

        $this->command->info('─────────────────────────────────────────────────────────────');
    }

    // ── Pending Transactions: populate the Accounting Overview bar chart ────

    /**
     * Seed a few unpaid invoices/bills so the Accounting Overview dashboard
     * bar chart shows outstanding amounts by due date.  These represent
     * real-world in-progress work: one upcoming vendor parts order and one
     * outstanding customer invoice.
     */
    private function seedPendingTransactions(): void
    {
        $this->command->info('');
        $this->command->info('▸ Seeding pending invoices / bills (for Accounting Overview chart)');

        $this->seedPendingVendorBill();
        $this->seedPendingCustomerInvoice();
    }

    private function seedPendingVendorBill(): void
    {
        $billName = 'WKSHP/BILL/PEND1';

        if (DB::table('accounts_account_moves')->where('name', $billName)->exists()) {
            $this->command->info('  (skip) Pending vendor bill already exists');

            return;
        }

        $billDate = now()->subDays(5);
        $dueDate = now()->addDays(9);  // ~next week → "Next Week" bucket
        $total = 420.00;

        $moveId = DB::table('accounts_account_moves')->insertGetId([
            'name'                               => $billName,
            'move_type'                          => 'in_invoice',
            'state'                              => 'posted',
            'payment_state'                      => 'not_paid',
            'auto_post'                          => 'no',
            'sequence_prefix'                    => 'WKSHP/BILL/',
            'journal_id'                         => $this->journalPurchase,
            'partner_id'                         => $this->supplierPartnerId,
            'commercial_partner_id'              => $this->supplierPartnerId,
            'currency_id'                        => $this->currencyId,
            'company_id'                         => $this->companyId,
            'invoice_user_id'                    => $this->userId,
            'creator_id'                         => $this->userId,
            'invoice_origin'                     => 'Workshop Parts Order',
            'invoice_date'                       => $billDate->toDateString(),
            'invoice_date_due'                   => $dueDate->toDateString(),
            'date'                               => $billDate->toDateString(),
            'amount_untaxed'                     => $total,
            'amount_tax'                         => 0.00,
            'amount_total'                       => $total,
            'amount_residual'                    => $total,
            'amount_untaxed_signed'              => -$total,
            'amount_tax_signed'                  => 0.00,
            'amount_total_signed'                => -$total,
            'amount_residual_signed'             => -$total,
            'amount_untaxed_in_currency_signed'  => -$total,
            'amount_total_in_currency_signed'    => -$total,
            'invoice_currency_rate'              => 1.0,
            'created_at'                         => $billDate,
            'updated_at'                         => $billDate,
        ]);

        // Stock Interim (Received) line — DR
        DB::table('accounts_account_move_lines')->insert([
            'sort'                     => 1,
            'move_id'                  => $moveId,
            'move_name'                => $billName,
            'parent_state'             => 'posted',
            'journal_id'               => $this->journalPurchase,
            'company_id'               => $this->companyId,
            'company_currency_id'      => $this->currencyId,
            'currency_id'              => $this->currencyId,
            'account_id'               => $this->acctStockIntRec,
            'partner_id'               => $this->supplierPartnerId,
            'product_id'               => $this->productIds['brake_pads'],
            'uom_id'                   => 1,
            'creator_id'               => $this->userId,
            'name'                     => 'Brake Pads — Pending Order',
            'display_type'             => 'product',
            'date'                     => $billDate->toDateString(),
            'invoice_date'             => $billDate->toDateString(),
            'quantity'                 => 10,
            'price_unit'               => 42.00,
            'price_subtotal'           => $total,
            'price_total'              => $total,
            'discount'                 => 0.00,
            'debit'                    => $total,
            'credit'                   => 0.00,
            'balance'                  => $total,
            'amount_currency'          => $total,
            'amount_residual'          => 0.00,
            'amount_residual_currency' => 0.00,
            'tax_base_amount'          => 0.00,
            'reconciled'               => 0,
            'is_downpayment'           => 0,
            'created_at'               => $billDate,
            'updated_at'               => $billDate,
        ]);

        // Payable line — CR (unpaid, so amount_residual = total and reconciled = 0)
        DB::table('accounts_account_move_lines')->insert([
            'sort'                     => 2,
            'move_id'                  => $moveId,
            'move_name'                => $billName,
            'parent_state'             => 'posted',
            'journal_id'               => $this->journalPurchase,
            'company_id'               => $this->companyId,
            'company_currency_id'      => $this->currencyId,
            'currency_id'              => $this->currencyId,
            'account_id'               => $this->acctPayable,
            'partner_id'               => $this->supplierPartnerId,
            'creator_id'               => $this->userId,
            'name'                     => 'Workshop Parts Order — Payable',
            'display_type'             => 'payment_term',
            'date'                     => $billDate->toDateString(),
            'invoice_date'             => $billDate->toDateString(),
            'date_maturity'            => $dueDate->toDateString(),
            'quantity'                 => 1,
            'price_unit'               => $total,
            'price_subtotal'           => $total,
            'price_total'              => $total,
            'discount'                 => 0.00,
            'debit'                    => 0.00,
            'credit'                   => $total,
            'balance'                  => -$total,
            'amount_currency'          => -$total,
            'amount_residual'          => -$total,
            'amount_residual_currency' => -$total,
            'tax_base_amount'          => 0.00,
            'reconciled'               => 0,
            'is_downpayment'           => 0,
            'created_at'               => $billDate,
            'updated_at'               => $billDate,
        ]);

        $this->command->info(sprintf(
            '  ✓ Pending vendor bill %s (not_paid, due %s) — $%.2f  [move id=%d]',
            $billName,
            $dueDate->toDateString(),
            $total,
            $moveId,
        ));
    }

    private function seedPendingCustomerInvoice(): void
    {
        $invoiceName = 'WKSHP/INV/PEND1';

        if (DB::table('accounts_account_moves')->where('name', $invoiceName)->exists()) {
            $this->command->info('  (skip) Pending customer invoice already exists');

            return;
        }

        $invoiceDate = now()->subDays(3);
        $dueDate = now()->addDays(11);  // ~next week → "Next Week" bucket

        /**
         * Sale lines — storable products so we get:
         *   - inventory stock movement (delivery)
         *   - COGS journal entry
         *
         * @var array<int, array<string, mixed>> $lines
         */
        $lines = [
            [
                'product_key'    => 'brake_pads',
                'name'           => 'Brake Pads (Front Set)',
                'qty'            => 2,
                'price_unit'     => 80.00,     // selling price
                'purchase_price' => $this->getCurrentProductUnitCost('brake_pads', 45.00),
                'uom_id'         => 1,         // Units
            ],
            [
                'product_key'    => 'oil_10w40',
                'name'           => 'Engine Oil 10W40 (per litre)',
                'qty'            => 4,
                'price_unit'     => 38.00,     // selling price
                'purchase_price' => $this->getCurrentProductUnitCost('oil_10w40', 20.00),
                'uom_id'         => $this->uomLitres,
            ],
        ];

        $total = (float) array_sum(array_map(fn ($l) => $l['qty'] * $l['price_unit'], $lines));
        $cogsTotal = 0.0;
        $effectiveCogsLines = [];

        // ── Delivery operation (stock → customer) ──────────────────

        $operationId = DB::table('inventories_operations')->insertGetId([
            'name'                    => 'WH/OUT/'.$invoiceName,
            'state'                   => 'done',
            'origin'                  => $invoiceName,
            'move_type'               => 'direct',
            'is_locked'               => 1,
            'is_favorite'             => 0,
            'has_deadline_issue'      => 0,
            'is_printed'              => 0,
            'operation_type_id'       => $this->deliveryOpTypeId,
            'source_location_id'      => $this->stockLocId,
            'destination_location_id' => $this->customerLocId,
            'partner_id'              => $this->customer1Id,
            'user_id'                 => $this->userId,
            'company_id'              => $this->companyId,
            'creator_id'              => $this->userId,
            'scheduled_at'            => $invoiceDate,
            'closed_at'               => $invoiceDate,
            'created_at'              => $invoiceDate,
            'updated_at'              => $invoiceDate,
        ]);

        foreach ($lines as $line) {
            $productId = $this->productIds[$line['product_key']];
            $effectivePurchasePrice = (float) $line['purchase_price'];

            $stockMoveId = DB::table('inventories_moves')->insertGetId([
                'name'                    => $line['name'],
                'state'                   => 'done',
                'origin'                  => $invoiceName,
                'procure_method'          => 'make_to_stock',
                'product_qty'             => $line['qty'],
                'product_uom_qty'         => $line['qty'],
                'quantity'                => $line['qty'],
                'is_favorite'             => 0,
                'is_picked'               => 1,
                'is_scraped'              => 0,
                'is_inventory'            => 0,
                'is_refund'               => 0,
                'scheduled_at'            => $invoiceDate,
                'operation_id'            => $operationId,
                'product_id'              => $productId,
                'uom_id'                  => $line['uom_id'],
                'source_location_id'      => $this->stockLocId,
                'destination_location_id' => $this->customerLocId,
                'operation_type_id'       => $this->deliveryOpTypeId,
                'warehouse_id'            => 1,
                'company_id'              => $this->companyId,
                'creator_id'              => $this->userId,
                'created_at'              => $invoiceDate,
                'updated_at'              => $invoiceDate,
            ]);

            if ($this->usesLotTracking($line['product_key'])) {
                $lotAllocations = $this->consumeStockFromLots(
                    $productId,
                    (float) $line['qty'],
                    (float) $line['purchase_price']
                );

                $lineCogsTotal = (float) array_sum(array_map(
                    fn (array $allocation): float => $allocation['qty'] * $allocation['unit_cost'],
                    $lotAllocations
                ));

                $effectivePurchasePrice = $line['qty'] > 0
                    ? round($lineCogsTotal / $line['qty'], 4)
                    : 0.0;

                foreach ($lotAllocations as $allocation) {
                    DB::table('inventories_move_lines')->insert([
                        'lot_name'                => $allocation['lot_name'] ?: null,
                        'lot_id'                  => $allocation['lot_id'] > 0 ? $allocation['lot_id'] : null,
                        'state'                   => 'done',
                        'reference'               => 'WH/OUT/'.$invoiceName,
                        'qty'                     => $allocation['qty'],
                        'uom_qty'                 => $allocation['qty'],
                        'is_picked'               => 1,
                        'scheduled_at'            => $invoiceDate,
                        'move_id'                 => $stockMoveId,
                        'operation_id'            => $operationId,
                        'product_id'              => $productId,
                        'uom_id'                  => $line['uom_id'],
                        'source_location_id'      => $this->stockLocId,
                        'destination_location_id' => $this->customerLocId,
                        'company_id'              => $this->companyId,
                        'creator_id'              => $this->userId,
                        'created_at'              => $invoiceDate,
                        'updated_at'              => $invoiceDate,
                    ]);
                }
            } else {
                $lineCogsTotal = (float) ($line['qty'] * $effectivePurchasePrice);

                DB::table('inventories_move_lines')->insert([
                    'state'                   => 'done',
                    'reference'               => 'WH/OUT/'.$invoiceName,
                    'qty'                     => $line['qty'],
                    'uom_qty'                 => $line['qty'],
                    'is_picked'               => 1,
                    'scheduled_at'            => $invoiceDate,
                    'move_id'                 => $stockMoveId,
                    'operation_id'            => $operationId,
                    'product_id'              => $productId,
                    'uom_id'                  => $line['uom_id'],
                    'source_location_id'      => $this->stockLocId,
                    'destination_location_id' => $this->customerLocId,
                    'company_id'              => $this->companyId,
                    'creator_id'              => $this->userId,
                    'created_at'              => $invoiceDate,
                    'updated_at'              => $invoiceDate,
                ]);

                // Deduct on-hand stock
                $this->adjustStock($productId, -$line['qty']);
            }

            $cogsTotal += $lineCogsTotal;
            $effectiveCogsLine = array_merge($line, [
                'purchase_price' => $effectivePurchasePrice,
            ]);

            if (isset($lotAllocations) && is_array($lotAllocations) && $lotAllocations !== []) {
                $effectiveCogsLine['cogs_allocations'] = $lotAllocations;
            }

            $effectiveCogsLines[] = $effectiveCogsLine;
        }

        $this->command->info(sprintf(
            '  ✓ Delivery WH/OUT/%s (done) — %d lines, COGS: $%.2f  [op id=%d]',
            $invoiceName,
            count($lines),
            $cogsTotal,
            $operationId,
        ));

        // ── Customer Invoice (out_invoice) — unpaid ────────────────

        $moveId = DB::table('accounts_account_moves')->insertGetId([
            'name'                               => $invoiceName,
            'move_type'                          => 'out_invoice',
            'state'                              => 'posted',
            'payment_state'                      => 'not_paid',
            'auto_post'                          => 'no',
            'sequence_prefix'                    => 'WKSHP/INV/',
            'journal_id'                         => $this->journalSale,
            'partner_id'                         => $this->customer1Id,
            'commercial_partner_id'              => $this->customer1Id,
            'currency_id'                        => $this->currencyId,
            'company_id'                         => $this->companyId,
            'invoice_user_id'                    => $this->userId,
            'creator_id'                         => $this->userId,
            'invoice_origin'                     => $invoiceName,
            'invoice_date'                       => $invoiceDate->toDateString(),
            'invoice_date_due'                   => $dueDate->toDateString(),
            'date'                               => $invoiceDate->toDateString(),
            'amount_untaxed'                     => $total,
            'amount_tax'                         => 0.00,
            'amount_total'                       => $total,
            'amount_residual'                    => $total,
            'amount_untaxed_signed'              => $total,
            'amount_tax_signed'                  => 0.00,
            'amount_total_signed'                => $total,
            'amount_residual_signed'             => $total,
            'amount_untaxed_in_currency_signed'  => $total,
            'amount_total_in_currency_signed'    => $total,
            'invoice_currency_rate'              => 1.0,
            'created_at'                         => $invoiceDate,
            'updated_at'                         => $invoiceDate,
        ]);

        // Revenue lines — CR Product Sales (one line per product)
        foreach ($lines as $sort => $line) {
            $subtotal = round($line['qty'] * $line['price_unit'], 2);
            $productId = $this->productIds[$line['product_key']];

            DB::table('accounts_account_move_lines')->insert([
                'sort'                     => $sort + 1,
                'move_id'                  => $moveId,
                'move_name'                => $invoiceName,
                'parent_state'             => 'posted',
                'journal_id'               => $this->journalSale,
                'company_id'               => $this->companyId,
                'company_currency_id'      => $this->currencyId,
                'currency_id'              => $this->currencyId,
                'account_id'               => $this->acctSales,
                'partner_id'               => $this->customer1Id,
                'product_id'               => $productId,
                'uom_id'                   => $line['uom_id'],
                'creator_id'               => $this->userId,
                'name'                     => $line['name'],
                'display_type'             => 'product',
                'date'                     => $invoiceDate->toDateString(),
                'invoice_date'             => $invoiceDate->toDateString(),
                'quantity'                 => $line['qty'],
                'price_unit'               => $line['price_unit'],
                'price_subtotal'           => $subtotal,
                'price_total'              => $subtotal,
                'discount'                 => 0.00,
                'debit'                    => 0.00,
                'credit'                   => $subtotal,
                'balance'                  => -$subtotal,
                'amount_currency'          => -$subtotal,
                'amount_residual'          => 0.00,
                'amount_residual_currency' => 0.00,
                'tax_base_amount'          => 0.00,
                'reconciled'               => 0,
                'is_downpayment'           => 0,
                'created_at'               => $invoiceDate,
                'updated_at'               => $invoiceDate,
            ]);
        }

        // Receivable line — DR Account Receivable (unpaid: amount_residual = total, reconciled = 0)
        DB::table('accounts_account_move_lines')->insert([
            'sort'                     => count($lines) + 1,
            'move_id'                  => $moveId,
            'move_name'                => $invoiceName,
            'parent_state'             => 'posted',
            'journal_id'               => $this->journalSale,
            'company_id'               => $this->companyId,
            'company_currency_id'      => $this->currencyId,
            'currency_id'              => $this->currencyId,
            'account_id'               => $this->acctReceivable,
            'partner_id'               => $this->customer1Id,
            'creator_id'               => $this->userId,
            'name'                     => $invoiceName.' — Receivable',
            'display_type'             => 'payment_term',
            'date'                     => $invoiceDate->toDateString(),
            'invoice_date'             => $invoiceDate->toDateString(),
            'date_maturity'            => $dueDate->toDateString(),
            'quantity'                 => 1,
            'price_unit'               => $total,
            'price_subtotal'           => $total,
            'price_total'              => $total,
            'discount'                 => 0.00,
            'debit'                    => $total,
            'credit'                   => 0.00,
            'balance'                  => $total,
            'amount_currency'          => $total,
            'amount_residual'          => $total,
            'amount_residual_currency' => $total,
            'tax_base_amount'          => 0.00,
            'reconciled'               => 0,
            'is_downpayment'           => 0,
            'created_at'               => $invoiceDate,
            'updated_at'               => $invoiceDate,
        ]);

        $this->command->info(sprintf(
            '  ✓ Pending customer invoice %s (not_paid, due %s) — $%.2f  [move id=%d]',
            $invoiceName,
            $dueDate->toDateString(),
            $total,
            $moveId,
        ));

        // ── COGS recognition entry (DR COGS / CR Stock Valuation) ──

        $this->createCogsEntry($invoiceName, $invoiceDate, $effectiveCogsLines, $cogsTotal);
        $this->syncProductCostsFromInventory();
    }
}
