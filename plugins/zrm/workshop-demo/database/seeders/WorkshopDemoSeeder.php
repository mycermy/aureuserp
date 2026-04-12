<?php

namespace Zrm\WorkshopDemo\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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
 *  - currency_id         : 1  (USD)
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

    private int $currencyId = 1;

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

    private int $acctBank = 46;  // 101501 Bank (default account of Bank journal)

    private int $acctReceivable = 7;  // 121000 Account Receivable

    private int $acctStockVal = 2;  // 110100 Stock Valuation

    private int $acctStockIntRec = 3;  // 110200 Stock Interim (Received)

    private int $acctPayable = 16; // 211000 Account Payable

    private int $acctSales = 27; // 400000 Product Sales

    private int $acctCogs = 32; // 500000 Cost of Goods Sold

    // Populated during seeding
    /** @var array<string, int> */
    private array $productIds = [];

    private int $supplierPartnerId;

    private int $customer1Id;

    private int $customer2Id;

    public function run(): void
    {
        $this->command->info('');
        $this->command->info('🔧  Workshop Automation Demo Seeder');
        $this->command->info('═══════════════════════════════════════');

        $this->seedProductCategory();
        $this->seedProducts();
        $this->seedPartners();
        $this->seedPurchaseOrder1();
        $this->seedPurchaseOrder2();
        $this->seedPurchaseOrder3();
        $this->seedSaleOrder1();
        $this->seedSaleOrder2();
        $this->seedPendingTransactions();
        $this->printStockSummary();

        $this->command->info('');
        $this->command->info('✅  Workshop demo data created successfully!');
        $this->command->info('');
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
            $this->command->info('  (already exists, id=' . $this->categoryId . ')');

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

        $this->command->info('  → id=' . $this->categoryId);
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
            $existing = DB::table('products_products')
                ->where('reference', $data['reference'])
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                $this->productIds[$data['key']] = $existing->id;
                $this->command->info('  (skip) ' . $data['name'] . ' — already exists id=' . $existing->id);

                continue;
            }

            $id = DB::table('products_products')->insertGetId([
                'type'              => $data['type'],
                'name'              => $data['name'],
                'reference'         => $data['reference'],
                'price'             => $data['price'],
                'cost'              => $data['cost'],
                'description'       => $data['description'],
                'enable_sales'      => 1,
                'enable_purchase'   => $data['type'] === 'goods' ? 1 : 0,
                'is_storable'       => $data['type'] === 'goods' ? 1 : 0,
                'uom_id'            => $data['uom_id'],
                'uom_po_id'         => $data['uom_po_id'],
                'category_id'       => $this->categoryId,
                'company_id'        => $this->companyId,
                'creator_id'        => $this->userId,
                'tracking'          => 'qty',
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            $this->productIds[$data['key']] = $id;
            $this->command->info('  + ' . $data['name'] . ' (id=' . $id . ')');
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
            $this->command->info('  (skip) ' . $data['name'] . ' — already exists id=' . $existing->id);

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

        $this->command->info('  + ' . $data['name'] . ' (id=' . $id . ')');

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
                'purchase_price' => 25.00,
                'uom_id'         => $this->uomLitres,
                'name'           => 'Engine Oil 0W20 — 4L oil change',
            ],
            [
                'product_key'    => 'spark_plugs',
                'qty'            => 4,
                'price_unit'     => 15.00,
                'purchase_price' => 8.00,
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
                'purchase_price' => 45.00,
                'uom_id'         => $this->uomUnits,
                'name'           => 'Brake Pads (Front Set) — replacement',
            ],
            [
                'product_key'    => 'oil_10w40',
                'qty'            => 4,
                'price_unit'     => 38.00,
                'purchase_price' => 20.00,
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
        $existing = DB::table('purchases_orders')->where('name', $reference)->first();

        if ($existing) {
            $this->command->info('  (skip) ' . $reference . ' — already exists');
            $this->ensurePurchaseAccounting($existing->id, $reference, $lines, $receivedAt);

            return;
        }

        // ── Compute totals ──────────────────────────────────────────
        $untaxedAmount = array_sum(array_map(
            fn($l) => $l['qty'] * $l['price_unit'],
            $lines
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
            'name'                    => 'WH/IN/' . $reference,
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
        foreach ($lines as $line) {
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

            // Inventory move line
            DB::table('inventories_move_lines')->insert([
                'state'                   => 'done',
                'reference'               => 'WH/IN/' . $reference,
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

            // Update (or create) product stock quantity
            $this->adjustStock($productId, $line['qty']);
        }

        $this->command->info(sprintf(
            '  ✓ %s | Total: $%.2f | Receipt: WH/IN/%s (done, id=%d)',
            $reference,
            $untaxedAmount,
            $reference,
            $operationId,
        ));

        $this->ensurePurchaseAccounting($orderId, $reference, $lines, $receivedAt);
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
            $this->command->info('  (skip) ' . $reference . ' — already exists');
            $this->ensureSaleAccounting($existing->id, $reference, $customerId, $lines, $deliveredAt);

            return;
        }

        // Totals
        $amountUntaxed = array_sum(array_map(
            fn($l) => $l['qty'] * $l['price_unit'],
            $lines
        ));

        $cogsTotal = array_sum(array_map(
            fn($l) => $l['qty'] * $l['purchase_price'],
            $lines
        ));

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
            'name'                    => 'WH/OUT/' . $reference,
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
            $subtotal = $line['qty'] * $line['price_unit'];
            $margin = ($line['price_unit'] - $line['purchase_price']) * $line['qty'];
            $marginPct = $line['price_unit'] > 0
                ? round(($line['price_unit'] - $line['purchase_price']) / $line['price_unit'] * 100, 2)
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
                'purchase_price'             => $line['purchase_price'],  // ← COGS
                'margin'                     => $margin,
                'margin_percent'             => $marginPct,
                'qty_delivered_method'       => $line['purchase_price'] > 0 ? 'stock_move' : 'manual',
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

            // Only create stock moves for storable products (not services)
            if ($line['purchase_price'] >= 0 && ! str_starts_with($line['product_key'], 'labor')) {
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

                DB::table('inventories_move_lines')->insert([
                    'state'                   => 'done',
                    'reference'               => 'WH/OUT/' . $reference,
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

        $this->command->info(sprintf(
            '  ✓ %s | Total: $%.2f | COGS: $%.2f | Margin: $%.2f | Delivery: done (id=%d)',
            $reference,
            $amountUntaxed,
            $cogsTotal,
            $amountUntaxed - $cogsTotal,
            $operationId,
        ));

        $this->ensureSaleAccounting($orderId, $reference, $customerId, $lines, $deliveredAt);
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
        $entryName = 'WKSHP/RECV/' . $suffix;

        if (DB::table('accounts_account_moves')->where('name', $entryName)->exists()) {
            $this->command->info('  (skip) Goods-received entry ' . $entryName . ' — already exists');

            return;
        }

        $total = (float) array_sum(array_map(
            fn($l) => $l['qty'] * $l['price_unit'],
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
                'name'                     => $line['name'] . ' — Goods Received',
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
                'name'                     => $line['name'] . ' — Interim Clearing',
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
        $billName = 'WKSHP/BILL/' . $billSuffix;

        // Always create the goods-received stock valuation entry (has its own idempotency).
        $this->createGoodsReceivedEntry($reference, $lines, $billDate);

        // Idempotency: skip if vendor bill already linked to this PO
        $alreadyLinked = DB::table('purchases_order_account_moves')
            ->where('order_id', $orderId)
            ->exists();

        if ($alreadyLinked) {
            $this->command->info('  (skip accounting) Vendor bill already exists for ' . $reference);
            $existingMove = DB::table('accounts_account_moves')->where('name', $billName)->first();

            if ($existingMove) {
                $this->ensureVendorPayment($existingMove->id, (float) $existingMove->amount_total, $billDate, $billSuffix);
            }

            return;
        }

        $total = (float) array_sum(array_map(
            fn($l) => $l['qty'] * $l['price_unit'],
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
            'name'                     => $reference . ' — Payable',
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
        $invoiceName = 'WKSHP/INV/' . $invoiceSuffix;

        // Idempotency: skip if invoice already linked to this SO via invoice_origin
        $alreadyLinked = DB::table('accounts_account_moves')
            ->where('invoice_origin', $reference)
            ->where('move_type', 'out_invoice')
            ->exists();

        if ($alreadyLinked) {
            $this->command->info('  (skip accounting) Customer invoice already exists for ' . $reference);
            $existingMove = DB::table('accounts_account_moves')->where('name', $invoiceName)->first();

            if ($existingMove) {
                $this->ensureCustomerPayment($existingMove->id, (float) $existingMove->amount_total, $customerId, $invoiceDate, $invoiceSuffix);
            }

            return;
        }

        $total = (float) array_sum(array_map(
            fn($l) => $l['qty'] * $l['price_unit'],
            $lines
        ));

        $cogsTotal = (float) array_sum(array_map(
            fn($l) => str_starts_with($l['product_key'], 'labor') ? 0.0 : $l['qty'] * $l['purchase_price'],
            $lines
        ));

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

        // Revenue lines — CR Product Sales
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

        // Receivable line — DR Account Receivable
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
            'name'                     => $reference . ' — Receivable',
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

        // Update SO: mark as invoiced
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

        // COGS recognition entry (DR COGS / CR Stock Valuation) for storable goods
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

        $paymentName = 'WKSHP/BNK/PAY/' . $suffix;

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
            'name'                     => 'Vendor Payment — ' . $suffix,
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
            'name'                     => 'Bank — ' . $suffix,
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
            'memo'                           => 'Payment for PO/' . $suffix,
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

        $paymentName = 'WKSHP/BNK/REC/' . $suffix;

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
            'name'                     => 'Bank — ' . $suffix,
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
            'name'                     => 'Customer Receipt — ' . $suffix,
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
            'memo'                           => 'Receipt for SO/' . $suffix,
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
        $entryName = 'WKSHP/COGS/' . substr($soRef, strrpos($soRef, '/') + 1);

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

        foreach ($lines as $line) {
            if (str_starts_with($line['product_key'], 'labor')) {
                continue;
            }

            $cogsAmount = round($line['qty'] * $line['purchase_price'], 2);

            if ($cogsAmount <= 0) {
                continue;
            }

            // DR Cost of Goods Sold
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
                'product_id'               => $this->productIds[$line['product_key']],
                'uom_id'                   => $line['uom_id'],
                'creator_id'               => $this->userId,
                'name'                     => 'COGS — ' . $line['name'],
                'display_type'             => 'product',
                'date'                     => $entryDate->toDateString(),
                'quantity'                 => $line['qty'],
                'price_unit'               => $line['purchase_price'],
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

            // CR Stock Valuation
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
                'product_id'               => $this->productIds[$line['product_key']],
                'uom_id'                   => $line['uom_id'],
                'creator_id'               => $this->userId,
                'name'                     => 'Stock — ' . $line['name'],
                'display_type'             => 'product',
                'date'                     => $entryDate->toDateString(),
                'quantity'                 => $line['qty'],
                'price_unit'               => $line['purchase_price'],
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
        }

        $this->command->info(sprintf(
            '  ✓ COGS entry %s (posted) — $%.2f  [move id=%d]',
            $entryName,
            $cogsTotal,
            $moveId,
        ));
    }

    private function adjustStock(int $productId, float $delta): void
    {
        $existing = DB::table('inventories_product_quantities')
            ->where('product_id', $productId)
            ->where('location_id', $this->stockLocId)
            ->whereNull('lot_id')
            ->whereNull('package_id')
            ->first();

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
                'product_id'              => $productId,
                'location_id'             => $this->stockLocId,
                'company_id'              => $this->companyId,
                'creator_id'              => $this->userId,
                'created_at'              => now(),
                'updated_at'              => now(),
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
        $this->command->info(sprintf('  %-34s %6s %8s %12s', 'Product', 'UOM', 'Qty', 'Value (USD)'));
        $this->command->info('  ' . str_repeat('-', 65));

        $storableKeys = ['oil_0w20', 'oil_10w40', 'brake_pads', 'spark_plugs'];

        $totalValue = 0;

        foreach ($storableKeys as $key) {
            if (! isset($this->productIds[$key])) {
                continue;
            }

            $productId = $this->productIds[$key];

            $row = DB::table('inventories_product_quantities')
                ->where('product_id', $productId)
                ->where('location_id', $this->stockLocId)
                ->whereNull('lot_id')
                ->whereNull('package_id')
                ->first();

            $qty = $row ? $row->quantity : 0;

            $product = DB::table('products_products')->find($productId);
            $cost = $product ? $product->cost : 0;
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

        $this->command->info('  ' . str_repeat('-', 65));
        $this->command->info(sprintf('  %-34s %6s %8s %12.2f', 'TOTAL INVENTORY VALUE', '', '', $totalValue));
        $this->command->info('─────────────────────────────────────────────────────────────');

        $this->command->info('');
        $this->command->info('  💰  SALES MARGIN SUMMARY');
        $this->command->info('─────────────────────────────────────────────────────────────');
        $this->command->info(sprintf('  %-20s %12s %12s %12s', 'Order', 'Revenue', 'COGS', 'Gross Margin'));
        $this->command->info('  ' . str_repeat('-', 60));

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

        $this->command->info('  ' . str_repeat('-', 60));
        $this->command->info(sprintf(
            '  %-20s %12.2f %12.2f %12.2f',
            'TOTAL',
            $totalRevenue,
            $totalCogs,
            $totalRevenue - $totalCogs,
        ));
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
                'purchase_price' => 45.00,     // COGS cost
                'uom_id'         => 1,         // Units
            ],
            [
                'product_key'    => 'oil_10w40',
                'name'           => 'Engine Oil 10W40 (per litre)',
                'qty'            => 4,
                'price_unit'     => 38.00,     // selling price
                'purchase_price' => 20.00,     // COGS cost
                'uom_id'         => $this->uomLitres,
            ],
        ];

        $total = (float) array_sum(array_map(fn($l) => $l['qty'] * $l['price_unit'], $lines));
        $cogsTotal = (float) array_sum(array_map(fn($l) => $l['qty'] * $l['purchase_price'], $lines));

        // ── Delivery operation (stock → customer) ──────────────────

        $operationId = DB::table('inventories_operations')->insertGetId([
            'name'                    => 'WH/OUT/' . $invoiceName,
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

            DB::table('inventories_move_lines')->insert([
                'state'                   => 'done',
                'reference'               => 'WH/OUT/' . $invoiceName,
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
            'name'                     => $invoiceName . ' — Receivable',
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

        $this->createCogsEntry($invoiceName, $invoiceDate, $lines, $cogsTotal);
    }
}
