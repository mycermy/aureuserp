# AureusERP — System Reference

> **Purpose:** Development reference for team members continuing work on the `zrm/workshop-demo` plugin and related AureusERP customisation.
> **Last updated:** April 12, 2026 (rev 4)

---

## Table of Contents

1. [Tech Stack](#tech-stack)
2. [Plugin Architecture](#plugin-architecture)
3. [Installed Webkul Plugins](#installed-webkul-plugins)
4. [Dashboard Navigation](#dashboard-navigation)
5. [Accounting Module — Key Concepts](#accounting-module--key-concepts)
6. [Reference IDs (Core Install)](#reference-ids-core-install)
7. [Workshop Demo Plugin](#workshop-demo-plugin)
8. [Seeded Demo Data](#seeded-demo-data)
9. [Known Limitations & Notes](#known-limitations--notes)

---

## Tech Stack

| Package | Version |
|---|---|
| PHP | 8.4 |
| Laravel | 11 |
| Filament | v4 |
| Livewire | v3 |
| Tailwind CSS | v4 |
| Pest | v4 |
| PHPUnit | v12 |
| Laravel Pint | v1 |

**Database:** MySQL (default schema: `aureuserp`)
**Local domain:** `aureuserp.test`
**Admin panel path:** `/admin`

---

## Plugin Architecture

Plugins live under `plugins/` in two vendor namespaces:

```
plugins/
  webkul/     ← core ERP plugins (packaged by Webkul / AureusERP team)
  zrm/        ← custom plugins (Zulfadli Resources)
    workshop-demo/ ← car workshop demo data plugin
```

Each plugin follows this structure:
```
plugins/{vendor}/{name}/
  composer.json
  src/
    Filament/
      Pages/
      Resources/
      Widgets/
      Clusters/
  database/
    migrations/
    seeders/
  resources/
    lang/
    views/
```

Plugins are registered via `composer.json` autoload and loaded through Laravel's service providers in `bootstrap/providers.php`.

Artisan install commands follow the pattern: `php artisan {plugin}:install`

---

## Installed Webkul Plugins

| Plugin Folder | Description |
|---|---|
| `accounting` | Accounting Overview dashboard, journal chart widgets |
| `accounts` | Core accounting models: journals, moves, payments, accounts |
| `analytics` | Analytics / reporting |
| `blogs` | Blog / CMS |
| `chatter` | Activity chatter sidebar (reused across resources) |
| `contacts` | Contact management |
| `employees` | Employee records |
| `fields` | Custom fields framework |
| `full-calendar` | Full Calendar integration |
| `inventories` | Inventory management, stock moves, warehouses, locations |
| `invoices` | Invoice-specific UI layer |
| `partners` | Partner (customer/supplier) model layer |
| `payments` | Payment processing layer |
| `plugin-manager` | Plugin install/uninstall UI |
| `products` | Product catalogue, categories, variants, UoM |
| `projects` | Project & task management + **Project Dashboard** |
| `purchases` | Purchase orders, vendor bills, receipts |
| `recruitments` | Recruitment pipeline + **Recruitment Dashboard** |
| `sales` | Sales orders, quotations, deliveries |
| `security` | User/role management (Filament Shield) |
| `support` | Shared base classes, traits, support utilities |
| `table-views` | Saved table view filters |
| `time-off` | Leave management: time-off requests, approvals, types |
| `timesheets` | Timesheet entries linked to projects/tasks |
| `website` | Website / landing page builder |

---

## Dashboard Navigation

The **"Dashboard"** main menu group (`/admin`) currently shows only two entries:

| Label | Plugin | Route | Class |
|---|---|---|---|
| **Project** | `webkul/projects` | `/admin/project` | `Webkul\Project\Filament\Pages\Dashboard` |
| **Recruitment** | `webkul/recruitments` | `/admin/recruitment` | `Webkul\Recruitment\Filament\Pages\Recruitments` |

> **Important:** Clicking the logo or the "Dashboard" menu item defaults to `/admin/project` (Project Dashboard) because it is the first registered entry in the navigation group.

### Dashboards NOT yet implemented

The following dashboards **do not exist** in the current codebase and would need to be created from scratch under a `Pages/Dashboard.php` in each plugin's `src/Filament/Pages/` folder:

- Sales Dashboard
- Inventory Dashboard
- Purchase Dashboard
- Accounting Dashboard (the Accounting Overview at `/admin/accounting/overview` exists but is NOT registered under the "Dashboard" navigation group)

### How to add a new Dashboard page

1. Create `src/Filament/Pages/Dashboard.php` extending `Filament\Pages\Dashboard`
2. Set `protected static string $routePath = 'your-slug';`
3. Set `getNavigationGroup()` to return `'Dashboard'`
4. Register widgets in `getWidgets(): array`
5. Add the page to the plugin's Filament panel provider

---

## Accounting Module — Key Concepts

The accounting layer is split across **two plugins**:

| Plugin | Role |
|---|---|
| `webkul/accounts` | Models, database tables, enums, resources |
| `webkul/accounting` | Dashboard UI, Overview page, Journal chart widgets |

### Key Tables

| Table | Purpose |
|---|---|
| `accounts_journals` | Journals (sale, purchase, bank, cash, general) |
| `accounts_accounts` | Chart of Accounts entries |
| `accounts_account_moves` | Journal entries (invoices, bills, entries) |
| `accounts_account_move_lines` | Double-entry lines for each move |
| `accounts_account_payments` | Payment records |
| `accounts_accounts_move_payment` | Pivot: links payments ↔ moves |

### Inventory Key Tables

| Table | Purpose |
|---|---|
| `inventories_operations` | Picking / delivery / receipt operations |
| `inventories_moves` | Stock moves (per product line within an operation) |
| `inventories_move_lines` | Detail lines for each stock move |
| `inventories_product_quantities` | On-hand quantity per product/location/lot/package |
| `inventories_locations` | Warehouse locations (stock, virtual, customer, vendor) |
| `inventories_operation_types` | Operation type config (receipts, deliveries, etc.) |

### Key Enums

**`MoveState`** (`accounts_account_moves.state`)
- `draft` — not yet posted
- `posted` — confirmed journal entry
- `cancel` — cancelled

**`PaymentState`** (`accounts_account_moves.payment_state`) — for invoice/bill moves
- `not_paid`
- `in_payment`
- `paid`
- `partial`
- `reversed`
- `blocked`
- `invoicing_legacy`

**`PaymentStatus`** (`accounts_account_payments.state`) — for payment records
- `draft`
- `in_process`
- `paid`
- `not_paid`
- `canceled`
- `rejected`

**`JournalType`** (`accounts_journals.type`)
- `sale` — Customer Invoices journal
- `purchase` — Vendor Bills journal
- `general` — Misc/adjustment journal
- `bank` — Bank Transactions journal
- `cash` — Cash Transactions journal

**`ProductTracking`** (`products_products.tracking`) — from `Webkul\Inventory\Enums\ProductTracking`
- `qty` — Track by quantity only (no serial/lot) — **use this as the default**
- `lot` — Track by lot number
- `serial` — Track by unique serial number

> ⚠️ **There is no `none` value.** Using `'none'` throws `ValueError: "none" is not a valid backing value for enum Webkul\Inventory\Enums\ProductTracking` when the Inventory module renders the product. Always use `'qty'` for untracked storable products.

### Accounting Overview Chart (Dashboard)

Located at: `plugins/webkul/accounting/src/Filament/Widgets/JournalChartsWidget.php`

The chart renders one card per journal where `show_on_dashboard = true`. Each card uses `JournalChartWidget.php`.

**For `bank`/`cash` journals** — line chart of running balance (last 5 weeks), data source: `amount_total` of all `posted` moves.

**For `sale`/`purchase` journals** — bar chart bucketed by due date. **Only shows moves where `payment_state IN ('not_paid', 'partial')` AND `amount_residual > 0`.** Fully paid invoices/bills do NOT appear on this chart.

Stats panels visibility rule:
```php
@if (($stat['value'] ?? 0) > 0 || ($stat['amount'] ?? null))
    // shown
@endif
```
All-zero stats are hidden, making the chart appear "blank" if all transactions are paid.

---

## Reference IDs (Core Install)

These IDs are stable after a fresh `php artisan migrate --seed` on a clean install.

### Company / User

| Resource | ID | Value |
|---|---|---|
| Company | 1 | DummyCorp LLC |
| Admin User | 1 | Admin |
| Currency (default from `APP_CURRENCY`) | 34 | MYR |

### Units of Measure

| UoM | ID |
|---|---|
| Units | 1 |
| Litres | 20 |

### Warehouse / Locations

| Location | ID |
|---|---|
| WH/Stock (main stock) | 12 |
| Vendors (virtual) | 4 |
| Customers (virtual) | 5 |

### Inventory Operation Types

| ID | Name | Direction |
|---|---|---|
| 1 | Receipts | inbound (Vendors → WH/Stock) |
| 2 | Delivery Orders | outbound (WH/Stock → Customers) |

### Journals

| ID | Name | Type | show_on_dashboard |
|---|---|---|---|
| 1 | Customer Invoices | sale | ✅ yes |
| 2 | Vendor Bills | purchase | ✅ yes |
| 3 | Miscellaneous Operations | general | ❌ no |
| 4 | Exchange Difference | general | ❌ no |
| 5 | Bank Transactions | bank | ✅ yes |
| 6 | Cash Transactions | cash | ✅ yes |

### Payment Method Lines (on Bank journal, id=5)

| ID | Direction |
|---|---|
| 1 | Inbound (customer receipts) |
| 2 | Outbound (vendor payments) |

### Chart of Accounts (key accounts)

| ID | Code | Name | Type |
|---|---|---|---|
| 2 | 110100 | Stock Valuation | asset_current |
| 3 | 110200 | Stock Interim (Received) | asset_current |
| 7 | 121000 | Account Receivable | asset_receivable |
| 16 | 211000 | Account Payable | liability_payable |
| 27 | 400000 | Product Sales | income |
| 32 | 500000 | Cost of Goods Sold | expense_direct_cost |
| 45 | 101401 | Bank | asset_cash |
| 46 | 101501 | Cash | asset_cash |

---

## Workshop Demo Plugin

**Package:** `zrm/workshop-demo`
**Namespace:** `Zrm\WorkshopDemo\`
**Path:** `plugins/zrm/workshop-demo/`
**Author:** Zulfadli Resources

### Purpose

Demonstrates a complete car workshop business flow in AureusERP:

1. Products (spare parts, engine oils, workshop labour services)
2. Partners (one supplier, two workshop customers)
3. Three Purchase Orders → warehouse receipts → stock updated
4. Two Sales Orders → deliveries → stock decremented
5. COGS tracking via purchase price on sale order lines
6. Accounting: vendor bills + customer invoices (posted, paid)
7. Accounting: COGS journal entries (misc, posted)
8. Payments: bank journal entries + payment records (paid)
9. Pending transactions: one unpaid vendor bill + one unpaid customer invoice with inventory consumption and COGS (for Accounting Overview chart data)

### Running the Seeder

```bash
php artisan db:seed --class="Zrm\\WorkshopDemo\\Database\\Seeders\\WorkshopDemoSeeder"
```

The seeder is **idempotent** — safe to run multiple times. Each method checks for existing records by name before inserting.

### Product Keys

Used internally in the seeder when referencing `$this->productIds[]`:

| Key | Product Name | Type |
|---|---|---|
| `oil_0w20` | Engine Oil 0W20 (per litre) | storable |
| `oil_10w40` | Engine Oil 10W40 (per litre) | storable |
| `brake_pads` | Brake Pads (Front Set) | storable |
| `spark_plugs` | Spark Plugs (per piece) | storable |
| `labor_oil_change` | Workshop Labour — Oil Change | service |
| `labor_brake_service` | Workshop Labour — Brake Service | service |

---

## Seeded Demo Data

### Partners

| ID | Name | Role |
|---|---|---|
| 13 | AutoParts Supplier Co. | Supplier |
| 14 | Ahmad's Fleet Services | Customer 1 |
| 15 | Nour Delivery Co. | Customer 2 |

### Purchase Orders

| Name | Supplier | State | Bill |
|---|---|---|---|
| PO/WKSHP/001 | AutoParts Supplier Co. | purchase (done) | WKSHP/BILL/001 — $314 |
| PO/WKSHP/002 | AutoParts Supplier Co. | purchase (done) | WKSHP/BILL/002 — $380 |
| PO/WKSHP/003 | AutoParts Supplier Co. | purchase (done) | WKSHP/BILL/003 — $315 |

### Sales Orders

| Name | Customer | State | Invoice |
|---|---|---|---|
| SO/WKSHP/001 | Ahmad's Fleet Services | sale (done) | WKSHP/INV/001 — $270 |
| SO/WKSHP/002 | Nour Delivery Co. | sale (done) | WKSHP/INV/002 — $287 |

### Delivery Operations (`inventories_operations`)

| ID | Name | Origin | State |
|---|---|---|---|
| 4 | WH/OUT/SO/WKSHP/001 | SO/WKSHP/001 | done |
| 5 | WH/OUT/SO/WKSHP/002 | SO/WKSHP/002 | done |
| 6 | WH/OUT/WKSHP/INV/PEND1 | WKSHP/INV/PEND1 | done |

### Closing Stock (WH/Stock, location id=12)

After all 3 purchase receipts, 2 sales deliveries, and 1 pending delivery:

| Product | UoM | Qty on Hand |
|---|---|---|
| Engine Oil 0W20 | L | 11.0 |
| Engine Oil 10W40 | L | 7.0 |
| Brake Pads (Front Set) | pc | 3.0 |
| Spark Plugs (per piece) | pc | 4.0 |

### Account Moves (Journal Entries)

| ID | Name | Type | Journal | State | Payment State | Amount | Residual |
|---|---|---|---|---|---|---|---|
| 1 | WKSHP/BILL/001 | in_invoice | Vendor Bills | posted | paid | $314 | $0 |
| 2 | WKSHP/BILL/002 | in_invoice | Vendor Bills | posted | paid | $380 | $0 |
| 3 | WKSHP/BILL/003 | in_invoice | Vendor Bills | posted | paid | $315 | $0 |
| 4 | WKSHP/INV/001 | out_invoice | Customer Invoices | posted | paid | $270 | $0 |
| 5 | WKSHP/COGS/001 | entry | Misc Operations | posted | not_paid | $132 | $0 |
| 6 | WKSHP/INV/002 | out_invoice | Customer Invoices | posted | paid | $287 | $0 |
| 7 | WKSHP/COGS/002 | entry | Misc Operations | posted | not_paid | $125 | $0 |
| 8 | WKSHP/BNK/PAY/001 | entry | Bank Transactions | posted | not_paid | $314 | $0 |
| 9 | WKSHP/BNK/PAY/002 | entry | Bank Transactions | posted | not_paid | $380 | $0 |
| 10 | WKSHP/BNK/PAY/003 | entry | Bank Transactions | posted | not_paid | $315 | $0 |
| 11 | WKSHP/BNK/REC/001 | entry | Bank Transactions | posted | not_paid | $270 | $0 |
| 12 | WKSHP/BNK/REC/002 | entry | Bank Transactions | posted | not_paid | $287 | $0 |
| 13 | WKSHP/BILL/PEND1 | in_invoice | Vendor Bills | posted | **not_paid** | $420 | **$420** |
| 15 | WKSHP/INV/PEND1 | out_invoice | Customer Invoices | posted | **not_paid** | $312 | **$312** |
| 16 | WKSHP/COGS/PEND1 | entry | Misc Operations | posted | not_paid | $170 | $0 |
| 17 | WKSHP/RECV/001 | entry | Misc Operations | posted | not_paid | $314 | $0 |
| 18 | WKSHP/RECV/002 | entry | Misc Operations | posted | not_paid | $380 | $0 |
| 19 | WKSHP/RECV/003 | entry | Misc Operations | posted | not_paid | $315 | $0 |

> Moves 17–19 are the **goods-received stock valuation entries** (`WKSHP/RECV/001–003`). They mirror what the inventory module auto-generates when a purchase receipt is confirmed (DR Stock Valuation / CR Stock Interim), and are created by `createGoodsReceivedEntry()` at the start of `ensurePurchaseAccounting()`.

> Moves 13, 15, and 16 are the **pending transactions**. They exist so the Accounting Overview bar charts for Vendor Bills and Customer Invoices show outstanding amounts by due date. Move 15 is backed by a real delivery (op id=6) consuming 2× Brake Pads + 4L Engine Oil 10W40, with COGS recognised in move 16.

### Payment Records (`accounts_account_payments`)

| ID | Name | Type | Partner | Amount | State |
|---|---|---|---|---|---|
| 1 | WKSHP/BNK/PAY/001 | outbound (vendor) | AutoParts Supplier | $314 | paid |
| 2 | WKSHP/BNK/PAY/002 | outbound (vendor) | AutoParts Supplier | $380 | paid |
| 3 | WKSHP/BNK/PAY/003 | outbound (vendor) | AutoParts Supplier | $315 | paid |
| 4 | WKSHP/BNK/REC/001 | inbound (customer) | Ahmad's Fleet Services | $270 | paid |
| 5 | WKSHP/BNK/REC/002 | inbound (customer) | Nour Delivery Co. | $287 | paid |

### Double-Entry Accounting Summary

The full purchasing cycle uses three separate journal entries:

**① Goods Received (misc entry — auto-generated on receipt confirmation):**
```
DR  Stock Valuation           [110100]  ← inventory asset increases
CR  Stock Interim (Received)  [110200]  ← clearing: goods in, bill not yet posted
```

**② Vendor Bill (in_invoice — clears the interim account):**
```
DR  Stock Interim (Received)  [110200]  ← clearing account settled
CR  Account Payable           [211000]  ← now formally owe the supplier
```

**Vendor Payment (bank entry):**
```
DR  Account Payable           [211000]  ← settle payable
CR  Bank / Cash               [101401 / 101501]  ← cash out
```

**Customer Invoice (out_invoice):**
```
DR  Account Receivable        [121000]  ← customer owes us
CR  Product Sales             [400000]  ← per line item
```

**COGS Entry (misc entry, per storable product line):**
```
DR  Cost of Goods Sold        [500000]  ← expense recognised
CR  Stock Valuation           [110100]  ← inventory reduced
```

**Customer Receipt (bank entry):**
```
DR  Bank / Cash               [101401 / 101501]  ← cash received  
CR  Account Receivable        [121000]  ← settle receivable
```

---

## Known Limitations & Notes

### Dashboard
- Clicking the logo or the "Dashboard" main menu item always navigates to `/admin/project` because Project Dashboard is the first entry registered under the "Dashboard" navigation group.
- There is no Sales, Inventory, or Purchase dashboard page yet. These would be new features to build.

### Accounting Overview Chart — Sale/Purchase Journals
- The bar chart only renders amounts for **unpaid/partial** invoices and bills (`payment_state = not_paid|partial` + `amount_residual > 0`).
- Fully paid transactions are correct and intentional — they do not appear on the chart.
- Moves 13 (`WKSHP/BILL/PEND1`) and 15 (`WKSHP/INV/PEND1`) exist solely to keep the chart non-blank. Their due dates shift relative to the seed date (computed with `now()->addDays(n)`), so re-running the seeder on a later date gives different bucket placement.

### Accounting Overview Chart — Bank Journal
- The bank chart uses a **line chart** of running balance over the last 5 weeks, sourced from `amount_total` (not `amount_residual`). Bank entries 8–12 provide this data.

### Enum Pitfalls
- `accounts_account_payments.state` uses **`PaymentStatus`** enum with values: `draft`, `in_process`, `paid`, `not_paid`, `canceled`, `rejected`. Do **not** use `posted` — it will throw a `ValueError` at runtime.
- `accounts_account_moves.state` uses **`MoveState`** enum with values: `draft`, `posted`, `cancel`.
- `accounts_account_moves.payment_state` uses **`PaymentState`** enum — separate from `PaymentStatus`.
- `products_products.tracking` uses **`ProductTracking`** enum with values: `qty`, `lot`, `serial`. Do **not** use `none` — it throws `ValueError: "none" is not a valid backing value for enum Webkul\Inventory\Enums\ProductTracking`. Use `qty` for all untracked storable products.

### Plugin Install / Timeout on macOS
- The `timeout` command (GNU coreutils) is not available on macOS by default.
- A fix was submitted upstream: PR `fix/plugin-install-timeout-macos` updates `buildTimeoutCommand()` to use `gtimeout` if available, otherwise skips the timeout wrapper.
- Workaround until merged: `brew install coreutils` or run plugin installs manually via Artisan.

### Idempotency
- The seeder uses name-based existence checks before inserting, so it is safe to run multiple times. Re-running will skip all existing records and only add genuinely missing ones.
- The pending transactions (moves 13 & 15) and the goods-received entries (moves 17–19) all have their own existence checks and will be skipped on re-runs.

### Stock Valuation vs Stock Interim (Received)
- **Stock Valuation [110100]** is a normal asset account — its DR balance represents the current monetary value of inventory physically on hand. It increases when goods arrive and decreases when goods are sold (COGS).
- **Stock Interim (Received) [110200]** is a temporary clearing account. It carries a balance only while goods have been physically received but the corresponding vendor bill has not yet been posted. Once the bill is posted, Stock Interim nets back to zero.
- A non-zero Stock Interim balance is expected and intentional when there are unbilled receipts. In this seeder, `WKSHP/BILL/PEND1` is a future parts order (ordered, not yet received), which is why Stock Interim carries a $420 DR balance.
- `createGoodsReceivedEntry()` — added to `ensurePurchaseAccounting()` — seeds the receipt-side entry (`WKSHP/RECV/001–003`) that the inventory module would normally auto-generate. Without it, Stock Valuation showed only credits (COGS outflows) with no matching debits, making the account appear artificially negative.

### Pending Invoice — Inventory Consumption
- `WKSHP/INV/PEND1` (move 15) is a **real sale** backed by delivery operation `WH/OUT/WKSHP/INV/PEND1` (op id=6).
- It consumes **2× Brake Pads** (COGS $90) and **4L Engine Oil 10W40** (COGS $80) from WH/Stock.
- A COGS entry `WKSHP/COGS/PEND1` (move 16) is also created: DR Cost of Goods Sold $170 / CR Stock Valuation $170.
- Invoice total: $312 revenue ($160 brake pads + $152 engine oil). Invoice is `not_paid` — shows on the Customer Invoices bar chart.


###
php artisan db:wipe --force && 
php artisan erp:install --force --no-interaction --admin-name="Admin" --admin-email="admin@example.com" --admin-password="password"

php artisan workshop-demo:install --no-interaction


###
php artisan tinker --execute="tap(app(\Webkul\Inventory\Settings\TraceabilitySettings::class), fn($s) => [$s->enable_lots_serial_numbers = true, $s->save()]);"