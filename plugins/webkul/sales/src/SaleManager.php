<?php

namespace Webkul\Sale;

use Exception;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Enums as AccountEnums;
use Webkul\Account\Enums\InvoicePolicy;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Facades\Tax;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\FiscalPosition;
use Webkul\Account\Models\Journal as AccountJournal;
use Webkul\Account\Models\Move as AccountMove;
use Webkul\Account\Models\Product as AccountProduct;
use Webkul\Account\Settings\DefaultAccountSettings;
use Webkul\Inventory\Enums as InventoryEnums;
use Webkul\Inventory\Facades\Inventory as InventoryFacade;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\Lot;
use Webkul\Inventory\Models\Move as InventoryMove;
use Webkul\Inventory\Models\Operation as InventoryOperation;
use Webkul\Inventory\Models\Product as InventoryProduct;
use Webkul\Inventory\Models\Rule;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Partner\Models\Partner;
use Webkul\PluginManager\Package;
use Webkul\Sale\Enums\AdvancedPayment;
use Webkul\Sale\Enums\InvoiceStatus;
use Webkul\Sale\Enums\OrderDeliveryStatus;
use Webkul\Sale\Enums\OrderState;
use Webkul\Sale\Enums\QtyDeliveredMethod;
use Webkul\Sale\Mail\SaleOrderCancelQuotation;
use Webkul\Sale\Mail\SaleOrderQuotation;
use Webkul\Sale\Models\AdvancedPaymentInvoice;
use Webkul\Sale\Models\Order;
use Webkul\Sale\Models\OrderLine;
use Webkul\Sale\Settings\InvoiceSettings;
use Webkul\Sale\Settings\QuotationAndOrderSettings;
use Webkul\Support\Services\EmailService;

class SaleManager
{
    public function __construct(
        protected QuotationAndOrderSettings $quotationAndOrderSettings,
        protected InvoiceSettings $invoiceSettings,
    ) {}

    public function sendQuotationOrOrderByEmail(Order $record, array $data = []): array
    {
        $result = $this->sendByEmail($record, $data);

        if (! empty($result['sent'])) {
            $record = $this->computeSaleOrder($record);
        }

        return $result;
    }

    public function lockAndUnlock(Order $record): Order
    {
        $record->update(['locked' => ! $record->locked]);

        $record = $this->computeSaleOrder($record);

        return $record;
    }

    public function confirmSaleOrder(Order $record): Order
    {
        $this->applyPullRules($record);

        $record->update([
            'state'          => OrderState::SALE,
            'invoice_status' => InvoiceStatus::TO_INVOICE,
            'locked'         => $this->quotationAndOrderSettings->enable_lock_confirm_sales,
        ]);

        $record = $this->computeSaleOrder($record);

        return $record;
    }

    public function backToQuotation(Order $record): Order
    {
        $record->update([
            'state'          => OrderState::DRAFT,
            'invoice_status' => InvoiceStatus::NO,
        ]);

        $record = $this->computeSaleOrder($record);

        return $record;
    }

    public function cancelSaleOrder(Order $record, array $data = []): Order
    {
        $record->update([
            'state'          => OrderState::CANCEL,
            'invoice_status' => InvoiceStatus::NO,
        ]);

        if (! empty($data)) {
            $this->cancelAndSendEmail($record, $data);
        }

        $record = $this->computeSaleOrder($record);

        $this->cancelInventoryOperation($record);

        return $record;
    }

    public function createInvoice(Order $record, array $data = [])
    {
        if ($data['advance_payment_method'] == AdvancedPayment::DELIVERED->value) {
            $this->createAccountMove($record);
        }

        $advancedPaymentInvoice = AdvancedPaymentInvoice::create([
            ...$data,
            'currency_id'          => $record->currency_id,
            'company_id'           => $record->company_id,
            'creator_id'           => Auth::id(),
            'deduct_down_payments' => true,
            'consolidated_billing' => true,
        ]);

        $advancedPaymentInvoice->orders()->attach($record->id);

        return $this->computeSaleOrder($record);
    }

    /**
     * Compute the sale order.
     */
    public function computeSaleOrder(Order $record): Order
    {
        $record->amount_untaxed = 0;
        $record->amount_tax = 0;
        $record->amount_total = 0;

        foreach ($record->lines as $line) {
            $line->state = $record->state;
            $line->salesman_id = $record->user_id;
            $line->order_partner_id = $record->partner_id;
            $line->invoice_status = $record->invoice_status;

            $line = $this->computeSaleOrderLine($line);

            $record->amount_untaxed += $line->price_subtotal;
            $record->amount_tax += $line->price_tax;
            $record->amount_total += $line->price_total;
        }

        $record = $this->computeWarehouseId($record);

        $record = $this->computeDeliveryStatus($record);

        $record = $this->computeInvoiceStatus($record);

        $record->save();

        $this->syncInventoryDelivery($record);

        $record->refresh();

        return $record;
    }

    /**
     * Compute the sale order line.
     */
    public function computeSaleOrderLine(OrderLine $line): OrderLine
    {
        $line = $this->computeQtyInvoiced($line);

        $line = $this->computeQtyDelivered($line);

        $line->qty_to_invoice = $line->qty_delivered - $line->qty_invoiced;

        $subTotal = $line->price_unit * $line->product_qty;

        $discountAmount = 0;

        if ($line->discount > 0) {
            $discountAmount = $subTotal * ($line->discount / 100);

            $subTotal = $subTotal - $discountAmount;
        }

        $taxIds = $line->taxes->pluck('id')->toArray();

        [$subTotal, $taxAmount] = Tax::collect($taxIds, $subTotal, $line->product_qty);

        $line->price_subtotal = round($subTotal, 4);

        $line->price_tax = $taxAmount;

        $line->price_total = $subTotal + $taxAmount;

        $line->sort = $line->sort ?? OrderLine::max('sort') + 1;

        $line->technical_price_unit = $line->price_unit;

        $line->price_reduce_taxexcl = $line->product_uom_qty ? round($line->price_subtotal / $line->product_uom_qty, 4) : 0.0;

        $line->price_reduce_taxinc = $line->product_uom_qty ? round($line->price_total / $line->product_uom_qty, 4) : 0.0;

        $line->state = $line->order->state;

        $line = $this->computeOrderLineDeliveryMethod($line);

        $line = $this->computeOrderLineInvoiceStatus($line);

        $line = $this->computeQtyInvoiced($line);

        $line = $this->computeOrderLineUntaxedAmountToInvoice($line);

        $line = $this->untaxedOrderLineAmountToInvoiced($line);

        $line->save();

        return $line;
    }

    public function computeQtyInvoiced(OrderLine $line): OrderLine
    {
        $qtyInvoiced = 0.000;

        foreach ($line->accountMoveLines as $accountMoveLine) {
            if (
                $accountMoveLine->move->state !== AccountEnums\MoveState::CANCEL
                || $accountMoveLine->move->payment_state === AccountEnums\PaymentState::INVOICING_LEGACY->value
            ) {
                $convertedQty = $accountMoveLine->uom->computeQuantity($accountMoveLine->quantity, $line->uom);

                if ($accountMoveLine->move->move_type === AccountEnums\MoveType::OUT_INVOICE) {
                    $qtyInvoiced += $convertedQty;
                } elseif ($accountMoveLine->move->move_type === AccountEnums\MoveType::OUT_REFUND) {
                    $qtyInvoiced -= $convertedQty;
                }
            }
        }

        $line->qty_invoiced = $qtyInvoiced;

        return $line;
    }

    public function computeQtyDelivered(OrderLine $line): OrderLine
    {
        if ($line->qty_delivered_method == QtyDeliveredMethod::MANUAL) {
            $line->qty_delivered = $line->qty_delivered ?? 0.0;
        }

        if ($line->qty_delivered_method == QtyDeliveredMethod::STOCK_MOVE) {
            $qty = 0.0;

            [$outgoingMoves, $incomingMoves] = $this->getOutgoingIncomingMoves($line);

            foreach ($outgoingMoves as $move) {
                if ($move->state != InventoryEnums\MoveState::DONE) {
                    continue;
                }

                $qty += $move->uom->computeQuantity($move->quantity, $line->uom, true, 'HALF-UP');
            }

            foreach ($incomingMoves as $move) {
                if ($move->state != InventoryEnums\MoveState::DONE) {
                    continue;
                }

                $qty -= $move->uom->computeQuantity($move->quantity, $line->uom, true, 'HALF-UP');
            }

            $line->qty_delivered = $qty;
        }

        return $line;
    }

    public function computeWarehouseId(Order $order): Order
    {
        if (! Package::isPluginInstalled('inventories')) {
            return $order;
        }

        $order->warehouse_id = Warehouse::where('company_id', $order->company_id)->first()?->id;

        optional($order->lines)->each(function ($line) use ($order) {
            $line->warehouse_id = $order->warehouse_id;
            $line->save();
        });

        return $order;
    }

    public function computeDeliveryStatus(Order $order): Order
    {
        if (! Package::isPluginInstalled('inventories')) {
            $order->delivery_status = OrderDeliveryStatus::NO;

            return $order;
        }

        if ($order->operations->isEmpty() || $order->operations->every(function ($receipt) {
            return $receipt->state == InventoryEnums\OperationState::CANCELED;
        })) {
            $order->delivery_status = OrderDeliveryStatus::NO;
        } elseif ($order->operations->every(function ($receipt) {
            return in_array($receipt->state, [InventoryEnums\OperationState::DONE, InventoryEnums\OperationState::CANCELED]);
        })) {
            $order->delivery_status = OrderDeliveryStatus::FULL;
        } elseif ($order->operations->contains(function ($receipt) {
            return $receipt->state == InventoryEnums\OperationState::DONE;
        })) {
            $order->delivery_status = OrderDeliveryStatus::PARTIAL;
        } else {
            $order->delivery_status = OrderDeliveryStatus::PENDING;
        }

        return $order;
    }

    public function computeInvoiceStatus(Order $order): Order
    {
        if ($order->state != OrderState::SALE) {
            $order->invoice_status = InvoiceStatus::NO;

            return $order;
        }

        if ($order->lines->contains(function ($line) {
            return $line->invoice_status == InvoiceStatus::TO_INVOICE;
        })) {
            $order->invoice_status = InvoiceStatus::TO_INVOICE;
        } elseif ($order->lines->contains(function ($line) {
            return $line->invoice_status == InvoiceStatus::INVOICED;
        })) {
            $order->invoice_status = InvoiceStatus::INVOICED;
        } elseif ($order->lines->contains(function ($line) {
            return in_array($line->invoice_status, [InvoiceStatus::INVOICED, InvoiceStatus::UP_SELLING]);
        })) {
            $order->invoice_status = InvoiceStatus::UP_SELLING;
        } else {
            $order->invoice_status = InvoiceStatus::NO;
        }

        return $order;
    }

    public function computeOrderLineDeliveryMethod(OrderLine $line): OrderLine
    {
        if ($line->qty_delivered_method) {
            return $line;
        }

        if ($line->is_expense) {
            $line->qty_delivered_method = 'analytic';
        } else {
            $line->qty_delivered_method = $this->isStockMoveLine($line)
                ? QtyDeliveredMethod::STOCK_MOVE
                : QtyDeliveredMethod::MANUAL;
        }

        return $line;
    }

    protected function isStockMoveLine(OrderLine $line): bool
    {
        if (! Package::isPluginInstalled('inventories')) {
            return false;
        }

        $product = $line->product;

        if (! $product) {
            return false;
        }

        return (bool) ($product->is_storable ?? false);
    }

    public function computeOrderLineInvoiceStatus(OrderLine $line): OrderLine
    {
        if ($line->state !== OrderState::SALE) {
            $line->invoice_status = InvoiceStatus::NO;

            return $line;
        }

        $policy = $line->product?->invoice_policy ?? $line->product?->parent?->invoice_policy ?? $this->invoiceSettings->invoice_policy->value;

        if (
            $line->is_downpayment
            && $line->untaxed_amount_to_invoice == 0
        ) {
            $line->invoice_status = InvoiceStatus::INVOICED;
        } elseif ($policy === InvoicePolicy::ORDER->value) {
            if ($line->qty_invoiced >= $line->product_uom_qty) {
                $line->invoice_status = InvoiceStatus::INVOICED;
            } elseif ($line->qty_delivered > $line->product_uom_qty) {
                $line->invoice_status = InvoiceStatus::UP_SELLING;
            } else {
                $line->invoice_status = InvoiceStatus::TO_INVOICE;
            }
        } elseif ($policy === InvoicePolicy::DELIVERY->value) {
            if ($line->qty_invoiced >= $line->product_uom_qty) {
                $line->invoice_status = InvoiceStatus::INVOICED;
            } elseif ($line->qty_to_invoice != 0 || $line->qty_delivered == $line->product_uom_qty) {
                $line->invoice_status = InvoiceStatus::TO_INVOICE;
            } else {
                $line->invoice_status = InvoiceStatus::NO;
            }
        } else {
            $line->invoice_status = InvoiceStatus::NO;
        }

        return $line;
    }

    public function computeOrderLineUntaxedAmountToInvoice(OrderLine $line): OrderLine
    {
        if ($line->state !== OrderState::SALE) {
            $line->untaxed_amount_to_invoice = 0;

            return $line;
        }

        $priceSubtotal = 0;

        if ($line->product->invoice_policy === InvoicePolicy::DELIVERY->value) {
            $uomQtyToConsider = $line->qty_delivered;
        } else {
            $uomQtyToConsider = $line->product_uom_qty;
        }

        $discount = $line->discount ?? 0.0;
        $priceReduce = $line->price_unit * (1 - ($discount / 100.0));
        $priceSubtotal = $priceReduce * $uomQtyToConsider;

        $line->untaxed_amount_to_invoice = $priceSubtotal - $line->untaxed_amount_invoiced;

        return $line;
    }

    public function untaxedOrderLineAmountToInvoiced(OrderLine $line): OrderLine
    {
        $amountInvoiced = 0.0;

        foreach ($line->accountMoveLines as $accountMoveLine) {
            if (
                $accountMoveLine->move->state === AccountEnums\MoveState::POSTED
                || $accountMoveLine->move->payment_state === AccountEnums\PaymentState::INVOICING_LEGACY
            ) {
                if ($accountMoveLine->move->move_type === AccountEnums\MoveType::OUT_INVOICE) {
                    $amountInvoiced += $line->price_subtotal;
                } elseif ($accountMoveLine->move->move_type === AccountEnums\MoveType::OUT_REFUND) {
                    $amountInvoiced -= $line->price_subtotal;
                }
            }
        }

        $line->untaxed_amount_invoiced = $amountInvoiced;

        return $line;
    }

    public function sendByEmail(Order $record, array $data): array
    {
        $partners = Partner::whereIn('id', $data['partners'])->get();

        $sent = [];
        $failed = [];

        foreach ($partners as $partner) {
            if (empty($partner->email)) {
                $failed[$partner->name] = 'No email address';

                continue;
            }

            try {
                $payload = [
                    'record_name'    => $record->name,
                    'model_name'     => $record->state->getLabel(),
                    'subject'        => $data['subject'],
                    'description'    => $data['description'],
                    'to'             => [
                        'address' => $partner->email,
                        'name'    => $partner->name,
                    ],
                ];

                app(EmailService::class)->send(
                    mailClass: SaleOrderQuotation::class,
                    view: $viewName = 'sales::mails.sale-order-quotation',
                    payload: $payload,
                    attachments: [
                        [
                            'path' => $data['file'],
                            'name' => basename($data['file']),
                        ],
                    ]
                );

                $message = $record->addMessage([
                    'from' => [
                        'company' => Auth::user()->defaultCompany->toArray(),
                    ],
                    'body' => view($viewName, compact('payload'))->render(),
                    'type' => 'comment',
                ]);

                $record->addAttachments(
                    [$data['file']],
                    ['message_id' => $message->id],
                );

                $sent[] = $partner->name;
            } catch (Exception $e) {
                $failed[$partner->name] = 'Email service error: '.$e->getMessage();
            }
        }

        if (! empty($sent) && $record->state === OrderState::DRAFT) {
            $record->state = OrderState::SENT;
            $record->save();
        }

        return [
            'sent'   => $sent,
            'failed' => $failed,
        ];
    }

    public function cancelAndSendEmail(Order $record, array $data)
    {
        $partners = Partner::whereIn('id', $data['partners'])->get();

        foreach ($partners as $partner) {
            $payload = [
                'record_name'    => $record->name,
                'model_name'     => 'Quotation',
                'subject'        => $data['subject'],
                'description'    => $data['description'],
                'to'             => [
                    'address' => $partner?->email,
                    'name'    => $partner?->name,
                ],
            ];

            app(EmailService::class)->send(
                mailClass: SaleOrderCancelQuotation::class,
                view: $viewName = 'sales::mails.sale-order-cancel-quotation',
                payload: $payload,
            );

            $record->addMessage([
                'from' => [
                    'company' => Auth::user()->defaultCompany->toArray(),
                ],
                'body' => view($viewName, compact('payload'))->render(),
                'type' => 'comment',
            ]);
        }
    }

    public function getOutgoingIncomingMoves(OrderLine $orderLine, bool $strict = true)
    {
        $outgoingMoveIds = [];

        $incomingMoveIds = [];

        $moves = $orderLine->inventoryMoves->filter(function ($inventoryMove) use ($orderLine) {
            return $inventoryMove->state != InventoryEnums\MoveState::CANCELED
                && ! $inventoryMove->is_scraped
                && $orderLine->product_id == $inventoryMove->product_id;
        });

        $triggeringRuleIds = [];

        if ($moves->isNotEmpty() && ! $strict) {
            $sortedMoves = $moves->sortBy('id');

            $seenWarehouseIds = [];

            foreach ($sortedMoves as $move) {
                if (! in_array($move->warehouse->id, $seenWarehouseIds)) {
                    $triggeringRuleIds[] = $move->rule_id;

                    $seenWarehouseIds[] = $move->warehouse_id;
                }
            }
        }

        foreach ($moves as $move) {
            $isOutgoingStrict = $strict && $move->destinationLocation->type == InventoryEnums\LocationType::CUSTOMER;

            $isOutgoingNonStrict = ! $strict
                && in_array($move->rule_id, $triggeringRuleIds)
                && ($move->finalLocation ?? $move->destinationLocation->type) == InventoryEnums\LocationType::CUSTOMER;

            if ($isOutgoingStrict || $isOutgoingNonStrict) {
                if (
                    ! $move->origin_returned_move_id
                    || (
                        $move->origin_returned_move_id
                        && $move->to_refund
                    )
                ) {
                    $outgoingMoveIds[] = $move->id;
                }
            } elseif ($move->sourceLocation == InventoryEnums\LocationType::CUSTOMER && $move->is_refund) {
                $incomingMoveIds[] = $move->id;
            }
        }

        return [
            $moves->whereIn('id', $outgoingMoveIds),
            $moves->whereIn('id', $incomingMoveIds),
        ];
    }

    private function createAccountMove(Order $record): AccountMove
    {
        $accountMove = AccountMove::create([
            'move_type'               => AccountEnums\MoveType::OUT_INVOICE,
            'invoice_origin'          => $record->name,
            'reference'               => $record->client_order_ref ?: $record->name,
            'payment_reference'       => $record->name,
            'date'                    => now(),
            'company_id'              => $record->company_id,
            'currency_id'             => $record->currency_id,
            'invoice_payment_term_id' => $record->payment_term_id,
            'partner_id'              => $record->partner_id,
            'fiscal_position_id'      => $record->fiscal_position_id,
        ]);

        $record->accountMoves()->attach($accountMove->id);

        foreach ($record->lines as $line) {
            $this->createAccountMoveLine($accountMove, $line);
        }

        $accountMove = AccountFacade::computeAccountMove($accountMove);

        $accountMove->checked = (bool) $accountMove->journal?->auto_check_on_post;
        $accountMove->save();
        $accountMove = AccountFacade::confirmMove($accountMove);
        $accountMove->refresh();

        if (! $accountMove->name && $accountMove->journal) {
            $accountMove->computeName();
            $accountMove->save();
            $accountMove->refresh();
        }

        $cogsMove = $this->createCogsMove($record, $accountMove);

        if ($cogsMove && $cogsMove->state === AccountEnums\MoveState::DRAFT) {
            $cogsMove->checked = (bool) $cogsMove->journal?->auto_check_on_post;
            $cogsMove->save();
            AccountFacade::confirmMove($cogsMove);
        }

        return $accountMove;
    }

    private function createAccountMoveLine(AccountMove $accountMove, OrderLine $orderLine): void
    {
        $quantity = $this->resolveInvoiceQuantity($orderLine);

        $accountMoveLine = $accountMove->lines()->create([
            'name'         => $orderLine->name,
            'date'         => $accountMove->date,
            'creator_id'   => $accountMove?->creator_id,
            'parent_state' => $accountMove->state,
            'quantity'     => $quantity,
            'price_unit'   => $orderLine->price_unit,
            'discount'     => $orderLine->discount,
            'currency_id'  => $accountMove->currency_id,
            'product_id'   => $orderLine->product_id,
            'uom_id'       => $orderLine->product_uom_id,
        ]);

        $orderLine->accountMoveLines()->sync($accountMoveLine->id);

        $accountMoveLine->taxes()->sync($orderLine->taxes->pluck('id'));
    }

    private function createCogsMove(Order $record, AccountMove $invoiceMove): ?AccountMove
    {
        if (! Package::isPluginInstalled('inventories')) {
            return null;
        }

        $generalJournalId = $this->resolveGeneralJournalId($record->company_id);

        if (! $generalJournalId) {
            return null;
        }

        $allocations = [];

        foreach ($record->lines as $line) {
            $qtyToCost = $this->resolveInvoiceQuantity($line);

            if ($qtyToCost <= 0) {
                continue;
            }

            $expenseAccountId = $this->resolveExpenseAccountId($line->product_id, $record->fiscal_position_id);

            if (! $expenseAccountId) {
                continue;
            }

            $stockValuationAccountId = $this->resolveStockValuationAccountId($record->company_id, $expenseAccountId);

            if (! $stockValuationAccountId) {
                continue;
            }

            $lineAllocations = $this->resolveLineCostAllocations($line, $qtyToCost);

            foreach ($lineAllocations as $allocation) {
                $allocation['expense_account_id'] = $expenseAccountId;
                $allocation['stock_valuation_account_id'] = $stockValuationAccountId;
                $allocations[] = $allocation;
            }
        }

        if (empty($allocations)) {
            return null;
        }

        $cogsMove = AccountMove::create([
            'move_type'      => AccountEnums\MoveType::ENTRY,
            'invoice_origin' => $record->name,
            'reference'      => $invoiceMove->name,
            'date'           => $invoiceMove->date,
            'company_id'     => $record->company_id,
            'currency_id'    => $record->currency_id,
            'partner_id'     => $record->partner_id,
            'journal_id'     => $generalJournalId,
        ]);

        $record->accountMoves()->attach($cogsMove->id);

        foreach ($allocations as $allocation) {
            $amount = round($allocation['quantity'] * $allocation['unit_cost'], 4);

            if ($amount <= 0) {
                continue;
            }

            $reference = 'SO-LINE-'.$allocation['order_line_id'].'-COGS';
            $lotSuffix = $allocation['lot_name'] ? ' ['.$allocation['lot_name'].']' : '';
            $lineName = $allocation['name'].$lotSuffix;

            $cogsMove->lines()->create([
                'name'            => $lineName.' COGS',
                'reference'       => $reference,
                'date'            => $cogsMove->date,
                'parent_state'    => $cogsMove->state,
                'company_id'      => $cogsMove->company_id,
                'journal_id'      => $cogsMove->journal_id,
                'currency_id'     => $cogsMove->currency_id,
                'partner_id'      => $cogsMove->partner_id,
                'account_id'      => $allocation['expense_account_id'],
                'display_type'    => AccountEnums\DisplayType::COGS,
                'product_id'      => $allocation['product_id'],
                'uom_id'          => $allocation['uom_id'],
                'quantity'        => $allocation['quantity'],
                'price_unit'      => $allocation['unit_cost'],
                'price_subtotal'  => $amount,
                'price_total'     => $amount,
                'debit'           => $amount,
                'credit'          => 0,
                'balance'         => $amount,
                'amount_currency' => $amount,
            ]);

            $cogsMove->lines()->create([
                'name'            => $lineName.' Stock Valuation',
                'reference'       => $reference,
                'date'            => $cogsMove->date,
                'parent_state'    => $cogsMove->state,
                'company_id'      => $cogsMove->company_id,
                'journal_id'      => $cogsMove->journal_id,
                'currency_id'     => $cogsMove->currency_id,
                'partner_id'      => $cogsMove->partner_id,
                'account_id'      => $allocation['stock_valuation_account_id'],
                'display_type'    => AccountEnums\DisplayType::COGS,
                'product_id'      => $allocation['product_id'],
                'uom_id'          => $allocation['uom_id'],
                'quantity'        => $allocation['quantity'],
                'price_unit'      => $allocation['unit_cost'],
                'price_subtotal'  => -$amount,
                'price_total'     => -$amount,
                'debit'           => 0,
                'credit'          => $amount,
                'balance'         => -$amount,
                'amount_currency' => -$amount,
            ]);
        }

        return AccountFacade::computeAccountMove($cogsMove);
    }

    private function resolveInvoiceQuantity(OrderLine $orderLine): float
    {
        $productInvoicePolicy = $orderLine->product?->invoice_policy;
        $invoiceSetting = $this->invoiceSettings->invoice_policy->value;

        return (float) (($productInvoicePolicy ?? $invoiceSetting) === InvoicePolicy::ORDER->value
            ? $orderLine->product_uom_qty
            : $orderLine->qty_to_invoice);
    }

    private function resolveLineCostAllocations(OrderLine $line, float $targetQty): array
    {
        $allocations = [];

        [$outgoingMoves] = $this->getOutgoingIncomingMoves($line);

        foreach ($outgoingMoves->sortBy('id') as $move) {
            if ($move->state !== InventoryEnums\MoveState::DONE || $targetQty <= 0) {
                continue;
            }

            $move->loadMissing('lines.lot', 'lines.uom');

            foreach ($move->lines->sortBy('id') as $moveLine) {
                if ($targetQty <= 0) {
                    break;
                }

                $lineUom = $moveLine->uom ?? $line->uom;
                $lineQty = (float) $moveLine->qty;

                if ($lineQty <= 0) {
                    continue;
                }

                $qtyInOrderUom = (float) $lineUom->computeQuantity($lineQty, $line->uom, true, 'HALF-UP');
                $allocatedQty = min($targetQty, $qtyInOrderUom);

                if ($allocatedQty <= 0) {
                    continue;
                }

                $allocations[] = [
                    'order_line_id' => $line->id,
                    'name'          => $line->name,
                    'product_id'    => $line->product_id,
                    'uom_id'        => $line->product_uom_id,
                    'quantity'      => $allocatedQty,
                    'unit_cost'     => $this->resolveLotUnitCost($moveLine, $line),
                    'lot_name'      => $moveLine->lot?->name ?? $moveLine->lot_name,
                ];

                $targetQty -= $allocatedQty;
            }
        }

        if ($targetQty > 0) {
            $allocations[] = [
                'order_line_id' => $line->id,
                'name'          => $line->name,
                'product_id'    => $line->product_id,
                'uom_id'        => $line->product_uom_id,
                'quantity'      => $targetQty,
                'unit_cost'     => (float) ($line->purchase_price ?? $line->product?->cost ?? 0),
                'lot_name'      => null,
            ];
        }

        return $allocations;
    }

    private function resolveLotUnitCost($moveLine, OrderLine $line): float
    {
        $purchasePrice = $moveLine->lot?->properties['purchase_price'] ?? null;

        if (is_numeric($purchasePrice)) {
            return (float) $purchasePrice;
        }

        if ($moveLine->lot_id) {
            $lot = Lot::query()->find($moveLine->lot_id);

            $purchasePrice = $lot?->properties['purchase_price'] ?? null;

            if (is_numeric($purchasePrice)) {
                return (float) $purchasePrice;
            }
        }

        return (float) ($line->purchase_price ?? $line->product?->cost ?? 0);
    }

    private function resolveExpenseAccountId(int $productId, ?int $fiscalPositionId): ?int
    {
        $product = AccountProduct::query()->with('category')->find($productId);

        if (! $product) {
            return null;
        }

        $fiscalPosition = $fiscalPositionId ? FiscalPosition::query()->find($fiscalPositionId) : null;

        $accounts = $product->getAccountsFromFiscalPosition($fiscalPosition);

        if (! empty($accounts['expense']?->id)) {
            return $accounts['expense']->id;
        }

        return app(DefaultAccountSettings::class)->expense_account_id;
    }

    private function resolveStockValuationAccountId(int $companyId, int $fallbackExpenseAccountId): ?int
    {
        $query = Account::query()
            ->where('deprecated', false)
            ->where(function ($accountQuery) use ($companyId) {
                $accountQuery
                    ->whereDoesntHave('companies')
                    ->orWhereHas('companies', function ($companyQuery) use ($companyId) {
                        $companyQuery->where('companies.id', $companyId);
                    });
            });

        $stockValuationAccount = (clone $query)
            ->where(function ($accountQuery) {
                $accountQuery
                    ->where('code', '110100')
                    ->orWhereRaw('LOWER(name) like ?', ['%stock valuation%']);
            })
            ->first();

        if ($stockValuationAccount?->id) {
            return $stockValuationAccount->id;
        }

        $assetAccount = (clone $query)
            ->where('account_type', AccountEnums\AccountType::ASSET_CURRENT)
            ->where('id', '!=', $fallbackExpenseAccountId)
            ->orderBy('id')
            ->first();

        return $assetAccount?->id;
    }

    private function resolveGeneralJournalId(int $companyId): ?int
    {
        return AccountJournal::query()
            ->where('company_id', $companyId)
            ->where('type', AccountEnums\JournalType::GENERAL)
            ->value('id');
    }

    protected function syncInventoryDelivery(Order $record): void
    {
        if ($record->state !== OrderState::SALE) {
            return;
        }

        if (! Package::isPluginInstalled('inventories')) {
            return;
        }

        $operations = $record->operations()->get();

        $draftOperations = $operations->filter(function ($operation) {
            return in_array($operation->state, [
                InventoryEnums\OperationState::DRAFT,
                InventoryEnums\OperationState::CONFIRMED,
                InventoryEnums\OperationState::ASSIGNED,
            ]);
        });

        $validatedOperations = $operations->filter(function ($operation) {
            return $operation->state === InventoryEnums\OperationState::DONE;
        });

        foreach ($record->lines as $line) {
            $validatedQty = $this->getValidatedDeliveryQtyForLine($line, $validatedOperations);

            $pendingQty = $this->getPendingDeliveryQtyForLine($line, $draftOperations);

            $totalScheduledQty = $validatedQty + $pendingQty;

            $requiredQty = $line->product_qty;

            $diffQty = $requiredQty - $totalScheduledQty;

            if ($diffQty > 0) {
                if ($pendingQty > 0) {
                    $this->updateDraftDeliveryMoves($line, $draftOperations, $pendingQty + $diffQty);
                } else {
                    $this->createNewDeliveryForLine($record, $line, $diffQty);
                }
            } elseif ($diffQty < 0) {
                $qtyToReduce = min(abs($diffQty), $pendingQty);

                if ($qtyToReduce > 0) {
                    $newPendingQty = $pendingQty - $qtyToReduce;

                    $this->updateOrCancelDeliveryMoves($line, $draftOperations, $newPendingQty);
                }
            } elseif ($pendingQty > 0 && $pendingQty != ($requiredQty - $validatedQty)) {
                $expectedPendingQty = max(0, $requiredQty - $validatedQty);

                $this->updateOrCancelDeliveryMoves($line, $draftOperations, $expectedPendingQty);
            }
        }

        $this->cleanupEmptyDeliveryOperations($record);

        foreach ($record->operations()->get() as $operation) {
            if (! in_array($operation->state, [InventoryEnums\OperationState::DONE, InventoryEnums\OperationState::CANCELED])) {
                $operation->refresh();

                InventoryFacade::computeTransfer($operation);
            }
        }
    }

    protected function getValidatedDeliveryQtyForLine(OrderLine $line, $validatedOperations): float
    {
        $quantity = 0.0;

        foreach ($validatedOperations as $operation) {
            foreach ($operation->moves as $move) {
                if ($move->sale_order_line_id === $line->id && $move->state === InventoryEnums\MoveState::DONE) {
                    $quantity += $move->quantity;
                }
            }
        }

        return $quantity;
    }

    protected function getPendingDeliveryQtyForLine(OrderLine $line, $draftOperations): float
    {
        $quantity = 0.0;

        foreach ($draftOperations as $operation) {
            foreach ($operation->moves as $move) {
                if (
                    $move->sale_order_line_id === $line->id
                    && $move->state !== InventoryEnums\MoveState::CANCELED
                ) {
                    $quantity += $move->product_uom_qty;
                }
            }
        }

        return $quantity;
    }

    protected function updateDraftDeliveryMoves(OrderLine $line, $draftOperations, float $targetQty): void
    {
        $firstMove = null;

        foreach ($draftOperations as $operation) {
            foreach ($operation->moves as $move) {
                if (
                    $move->sale_order_line_id === $line->id
                    && $move->state !== InventoryEnums\MoveState::CANCELED
                ) {
                    if (! $firstMove) {
                        $firstMove = $move;
                    }
                }
            }
        }

        if ($firstMove) {
            $uomQty = $line->uom->computeQuantity($targetQty, $line->product->uom, true, 'HALF-UP');

            $firstMove->update([
                'product_qty'     => $targetQty,
                'product_uom_qty' => $uomQty,
                'quantity'        => $uomQty,
            ]);

            $firstMove->lines()->delete();

            foreach ($draftOperations as $operation) {
                foreach ($operation->moves as $move) {
                    if (
                        $move->id !== $firstMove->id
                        && $move->sale_order_line_id === $line->id
                        && $move->state !== InventoryEnums\MoveState::CANCELED
                    ) {
                        $move->update([
                            'state'    => InventoryEnums\MoveState::CANCELED,
                            'quantity' => 0,
                        ]);

                        $move->lines()->delete();
                    }
                }
            }
        }
    }

    protected function updateOrCancelDeliveryMoves(OrderLine $line, $draftOperations, float $targetQty): void
    {
        if ($targetQty <= 0) {
            foreach ($draftOperations as $operation) {
                foreach ($operation->moves as $move) {
                    if (
                        $move->sale_order_line_id === $line->id
                        && $move->state !== InventoryEnums\MoveState::CANCELED
                    ) {
                        $move->update([
                            'state'    => InventoryEnums\MoveState::CANCELED,
                            'quantity' => 0,
                        ]);

                        $move->lines()->delete();
                    }
                }
            }

            return;
        }

        $firstMove = null;

        foreach ($draftOperations as $operation) {
            foreach ($operation->moves as $move) {
                if (
                    $move->sale_order_line_id === $line->id
                    && $move->state !== InventoryEnums\MoveState::CANCELED
                ) {
                    if (! $firstMove) {
                        $firstMove = $move;

                        $uomQty = $line->uom->computeQuantity($targetQty, $line->product->uom, true, 'HALF-UP');

                        $firstMove->update([
                            'product_qty'     => $targetQty,
                            'product_uom_qty' => $uomQty,
                            'quantity'        => $uomQty,
                        ]);

                        $firstMove->lines()->delete();
                    } else {
                        $move->update([
                            'state'    => InventoryEnums\MoveState::CANCELED,
                            'quantity' => 0,
                        ]);

                        $move->lines()->delete();
                    }
                }
            }
        }
    }

    protected function createNewDeliveryForLine(Order $record, OrderLine $line, float $qty): void
    {
        if (! $this->isStockMoveLine($line)) {
            return;
        }

        $existingDraftOperation = $record->operations()
            ->whereIn('state', [
                InventoryEnums\OperationState::DRAFT,
                InventoryEnums\OperationState::CONFIRMED,
                InventoryEnums\OperationState::ASSIGNED,
            ])
            ->first();

        $rule = $this->getPullRule($line);

        if (! $rule) {
            return;
        }

        $uomQty = $line->uom->computeQuantity($qty, $line->product->uom, true, 'HALF-UP');

        if ($existingDraftOperation) {
            $newMove = InventoryMove::create([
                'operation_id'            => $existingDraftOperation->id,
                'name'                    => $line->name,
                'reference'               => $existingDraftOperation->name,
                'state'                   => InventoryEnums\MoveState::DRAFT,
                'product_id'              => $line->product_id,
                'product_qty'             => $qty,
                'product_uom_qty'         => $uomQty,
                'quantity'                => $uomQty,
                'uom_id'                  => $line->product_uom_id,
                'origin'                  => $record->name,
                'scheduled_at'            => now()->addDays($rule->delay),
                'source_location_id'      => $rule->source_location_id,
                'destination_location_id' => $rule->destination_location_id,
                'final_location_id'       => $rule->destination_location_id,
                'product_packaging_id'    => $line->product_packaging_id,
                'rule_id'                 => $rule->id,
                'company_id'              => $rule->company_id,
                'operation_type_id'       => $rule->operation_type_id,
                'propagate_cancel'        => $rule->propagate_cancel,
                'warehouse_id'            => $rule->warehouse_id,
                'procure_method'          => InventoryEnums\ProcureMethod::MAKE_TO_ORDER,
                'sale_order_line_id'      => $line->id,
            ]);

            if ($newMove->shouldBypassReservation()) {
                $newMove->update([
                    'procure_method' => InventoryEnums\ProcureMethod::MAKE_TO_STOCK,
                ]);
            }
        } else {
            $newMove = $this->runPullRule($rule, $line);

            if ($newMove) {
                $newMove->update([
                    'product_qty'     => $qty,
                    'product_uom_qty' => $uomQty,
                    'quantity'        => $uomQty,
                ]);

                $this->createPullOperation($record, $rule, [$newMove]);
            }
        }
    }

    protected function cleanupEmptyDeliveryOperations(Order $record): void
    {
        $operations = $record->operations()->get();

        foreach ($operations as $operation) {
            if (in_array($operation->state, [InventoryEnums\OperationState::DONE, InventoryEnums\OperationState::CANCELED])) {
                continue;
            }

            $activeMoves = $operation->moves()->where('state', '!=', InventoryEnums\MoveState::CANCELED)->count();

            if ($activeMoves === 0) {
                $operation->update([
                    'state' => InventoryEnums\OperationState::CANCELED,
                ]);
            }
        }
    }

    public function applyPullRules(Order $record): void
    {
        if (! Package::isPluginInstalled('inventories')) {
            return;
        }

        $rulesToRun = [];

        foreach ($record->lines as $line) {
            if (! $this->isStockMoveLine($line)) {
                continue;
            }

            $rule = $this->getPullRule($line);

            if (! $rule) {
                throw new Exception("No pull rule has been found to replenish \"{$line->name}\".\nVerify the routes configuration on the product.");
            }

            $rulesToRun[$line->id] = $rule;
        }

        $rules = [];

        foreach ($record->lines as $line) {
            if (! $this->isStockMoveLine($line)) {
                continue;
            }

            $rule = $rulesToRun[$line->id];

            $pulledMove = $this->runPullRule($rule, $line);

            if (! isset($rules[$rule->id])) {
                $rules[$rule->id] = [
                    'rule'  => $rule,
                    'moves' => [$pulledMove],
                ];
            } else {
                $rules[$rule->id]['moves'][] = $pulledMove;
            }
        }

        foreach ($rules as $ruleData) {
            $this->createPullOperation($record, $ruleData['rule'], $ruleData['moves']);
        }
    }

    protected function cancelInventoryOperation(Order $record): void
    {
        if (! Package::isPluginInstalled('inventories')) {
            return;
        }

        if (! $record->operation) {
            return;
        }

        foreach ($record->operation->moves as $move) {
            $move->update([
                'state'    => InventoryEnums\MoveState::CANCELED,
                'quantity' => 0,
            ]);

            $move->lines()->delete();
        }

        InventoryFacade::computeTransferState($record->operation);
    }

    /**
     * Create a new operation based on a push rule and assign moves to it.
     */
    private function createPullOperation(Order $record, Rule $rule, array $moves): void
    {
        $newOperation = InventoryOperation::create([
            'state'                   => InventoryEnums\OperationState::DRAFT,
            'origin'                  => $record->name,
            'partner_id'              => $record->partner_id,
            'operation_type_id'       => $rule->operation_type_id,
            'source_location_id'      => $rule->source_location_id,
            'destination_location_id' => $rule->destination_location_id,
            'scheduled_at'            => now()->addDays($rule->delay),
            'company_id'              => $rule->company_id,
            'sale_order_id'           => $record->id,
            'user_id'                 => Auth::id(),
            'creator_id'              => Auth::id(),
        ]);

        foreach ($moves as $move) {
            $move->update([
                'operation_id' => $newOperation->id,
                'reference'    => $newOperation->name,
                'scheduled_at' => $newOperation->scheduled_at,
            ]);
        }

        $newOperation->refresh();

        InventoryFacade::computeTransfer($newOperation);
    }

    /**
     * Run a pull rule on a line.
     */
    public function runPullRule(Rule $rule, OrderLine $line)
    {
        if ($rule->auto !== InventoryEnums\RuleAuto::MANUAL) {
            return;
        }

        $newMove = InventoryMove::create([
            'state'                   => InventoryEnums\MoveState::DRAFT,
            'reference'               => null,
            'name'                    => $line->name,
            'product_id'              => $line->product_id,
            'product_qty'             => $line->product_qty,
            'product_uom_qty'         => $line->product_uom_qty,
            'quantity'                => $line->product_qty,
            'uom_id'                  => $line->product_uom_id,
            'origin'                  => $line->origin,
            'scheduled_at'            => now()->addDays($rule->delay),
            'source_location_id'      => $rule->source_location_id,
            'destination_location_id' => $rule->destination_location_id,
            'final_location_id'       => $rule->destination_location_id,
            'product_packaging_id'    => $line->product_packaging_id,
            'rule_id'                 => $rule->id,
            'company_id'              => $rule->company_id,
            'operation_type_id'       => $rule->operation_type_id,
            'propagate_cancel'        => $rule->propagate_cancel,
            'warehouse_id'            => $rule->warehouse_id,
            'procure_method'          => InventoryEnums\ProcureMethod::MAKE_TO_ORDER,
            'sale_order_line_id'      => $line->id,
        ]);

        $newMove->save();

        if ($newMove->shouldBypassReservation()) {
            $newMove->update([
                'procure_method' => InventoryEnums\ProcureMethod::MAKE_TO_STOCK,
            ]);
        }

        return $newMove;
    }

    /**
     * Traverse up the location tree to find a matching pull rule.
     */
    public function getPullRule(OrderLine $line, array $filters = [])
    {
        $foundRule = null;

        $location = Location::where('type', InventoryEnums\LocationType::CUSTOMER)->first();

        $filters['action'] = [InventoryEnums\RuleAction::PULL, InventoryEnums\RuleAction::PULL_PUSH];

        while (! $foundRule && $location) {
            $filters['destination_location_id'] = $location->id;

            $foundRule = $this->searchPullRule(
                $line->productPackaging,
                InventoryProduct::find($line->product_id),
                $line->warehouse,
                $filters
            );

            $location = $location->parent;
        }

        return $foundRule;
    }

    /**
     * Search for a pull rule based on the provided filters.
     */
    public function searchPullRule($productPackaging, $product, $warehouse, array $filters)
    {
        if ($warehouse) {
            $filters['warehouse_id'] = $warehouse->id;
        }

        $routeSources = [
            [$productPackaging, 'routes'],
            [$product, 'routes'],
            [$product?->category, 'routes'],
            [$warehouse, 'routes'],
        ];

        foreach ($routeSources as [$source, $relationName]) {
            if (! $source || ! $source->{$relationName}) {
                continue;
            }

            $routeIds = $source->{$relationName}->pluck('id');

            if ($routeIds->isEmpty()) {
                continue;
            }

            $foundRule = Rule::whereIn('route_id', $routeIds)
                ->where($filters)
                ->orderBy('route_sort', 'asc')
                ->orderBy('sort', 'asc')
                ->first();

            if ($foundRule) {
                return $foundRule;
            }
        }

        return null;
    }
}
