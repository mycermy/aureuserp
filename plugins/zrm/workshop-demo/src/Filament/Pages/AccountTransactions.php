<?php

namespace Zrm\WorkshopDemo\Filament\Pages;

use Barryvdh\DomPDF\Facade\Pdf;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Maatwebsite\Excel\Facades\Excel;
use Malzariey\FilamentDaterangepickerFilter\Fields\DateRangePicker;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Filament\Clusters\Reporting;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\Concerns\NormalizeDateFilter;
use Webkul\Accounting\Models\Account;
use Zrm\WorkshopDemo\Filament\Pages\Exports\AccountTransactionsExport;

class AccountTransactions extends Page implements HasForms
{
    use HasFiltersForm, HasPageShield, InteractsWithForms, NormalizeDateFilter;

    protected string $view = 'workshop-demo::filament.pages.account-transactions';

    protected static ?string $cluster = Reporting::class;

    protected static ?string $slug = 'account-transactions';

    protected static bool $shouldRegisterNavigation = true;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?int $navigationSort = 99;

    public ?array $data = [];

    protected static function getPagePermission(): ?string
    {
        return 'page_accounting_general_ledger';
    }

    public function getTitle(): string
    {
        return 'Account Transactions';
    }

    public static function getNavigationLabel(): string
    {
        return 'Account Transactions';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('accounting::filament/clusters/reporting.navigation.group');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('excel')
                ->label('Export Excel')
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->visible(fn(): bool => $this->accountTransactionsData['account'] !== null)
                ->action(function () {
                    $data = $this->accountTransactionsData;

                    return Excel::download(
                        new AccountTransactionsExport($data),
                        $this->getExportFilename('xlsx')
                    );
                }),
            Action::make('pdf')
                ->label('Export PDF')
                ->icon('heroicon-o-document-text')
                ->color('danger')
                ->visible(fn(): bool => $this->accountTransactionsData['account'] !== null)
                ->action(function () {
                    $data = $this->accountTransactionsData;

                    $pdf = Pdf::loadView('workshop-demo::filament.pages.pdfs.account-transactions', [
                        'data' => $data,
                    ])->setPaper('a4', 'landscape');

                    return response()->streamDownload(function () use ($pdf) {
                        echo $pdf->output();
                    }, $this->getExportFilename('pdf'));
                }),
        ];
    }

    public function mount(): void
    {
        $selectedAccount = request()->query('selectedAccount', request()->query('selected_account', request()->query('account', request()->query('accountCode'))));
        $startDate = request()->query('startDate', request()->query('date_from'));
        $endDate = request()->query('endDate', request()->query('date_to'));
        $journalIds = $this->normalizeJournalIds(request()->query('journals', []));
        $selectedAccountId = $this->resolveAccountId($selectedAccount);

        $formData = [];

        if ($startDate && $endDate) {
            $formData['date_range'] = [
                'startDate' => $startDate,
                'endDate'   => $endDate,
            ];
        }

        if ($selectedAccountId) {
            $formData['selected_account'] = $selectedAccountId;
        }

        if ($journalIds !== []) {
            $formData['journals'] = $journalIds;
        }

        $this->form->fill($formData);
    }

    protected function getFormSchema(): array
    {
        return [
            Section::make()
                ->columns([
                    'default' => 1,
                    'sm'      => 3,
                ])
                ->schema([
                    DateRangePicker::make('date_range')
                        ->label('Date Range')
                        ->suffixIcon('heroicon-o-calendar')
                        ->defaultThisMonth()
                        ->ranges([
                            'Today'        => [now()->startOfDay(), now()->endOfDay()],
                            'Yesterday'    => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
                            'This Month'   => [now()->startOfMonth(), now()->endOfMonth()],
                            'Last Month'   => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
                            'This Quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
                            'Last Quarter' => [now()->subQuarter()->startOfQuarter(), now()->subQuarter()->endOfQuarter()],
                            'This Year'    => [now()->startOfYear(), now()->endOfYear()],
                            'Last Year'    => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
                        ])
                        ->alwaysShowCalendar()
                        ->live(),

                    Select::make('selected_account')
                        ->label('Account')
                        ->options(fn(): array => $this->getAccountOptions())
                        ->searchable()
                        ->preload()
                        ->live(),

                    Select::make('journals')
                        ->label('Journals')
                        ->multiple()
                        ->options(Journal::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->preload()
                        ->live(),
                ])
                ->columnSpanFull(),
        ];
    }

    protected function getFormStatePath(): string
    {
        return 'data';
    }

    #[Computed]
    public function accountTransactionsData(): array
    {
        $dateRange = $this->parseDateRange();
        $dateFrom = $dateRange ? Carbon::parse($dateRange[0])->startOfDay() : now()->startOfMonth()->startOfDay();
        $dateTo = $dateRange ? Carbon::parse($dateRange[1])->endOfDay() : now()->endOfDay();
        $selectedAccountId = $this->form->getState()['selected_account'] ?? null;
        $journalIds = $this->form->getState()['journals'] ?? [];

        $account = $selectedAccountId ? Account::query()->find($selectedAccountId) : null;

        if (! $account) {
            return [
                'account'         => null,
                'date_from'       => $dateFrom,
                'date_to'         => $dateTo,
                'opening_balance' => 0.0,
                'period_debit'    => 0.0,
                'period_credit'   => 0.0,
                'ending_balance'  => 0.0,
                'moves'           => [],
            ];
        }

        $baseQuery = $this->baseMoveLineQuery($account->getKey(), $journalIds);

        $openingBalance = (clone $baseQuery)
            ->where('accounts_account_moves.date', '<', $dateFrom->toDateString())
            ->sum('accounts_account_move_lines.balance');

        $periodDebit = (clone $baseQuery)
            ->whereBetween('accounts_account_moves.date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->sum('accounts_account_move_lines.debit');

        $periodCredit = (clone $baseQuery)
            ->whereBetween('accounts_account_moves.date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->sum('accounts_account_move_lines.credit');

        $moves = (clone $baseQuery)
            ->select([
                'accounts_account_move_lines.id',
                'accounts_account_move_lines.name',
                'accounts_account_move_lines.debit',
                'accounts_account_move_lines.credit',
                'accounts_account_move_lines.balance',
                'accounts_account_moves.name as move_name',
                'accounts_account_moves.move_type',
                'accounts_account_moves.date',
                'accounts_account_moves.reference as ref',
                'accounts_journals.name as journal_name',
                'partners_partners.name as partner_name',
            ])
            ->whereBetween('accounts_account_moves.date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->orderBy('accounts_account_moves.date')
            ->orderBy('accounts_account_moves.id')
            ->get()
            ->toArray();

        return [
            'account'         => $account,
            'date_from'       => $dateFrom,
            'date_to'         => $dateTo,
            'opening_balance' => (float) $openingBalance,
            'period_debit'    => (float) $periodDebit,
            'period_credit'   => (float) $periodCredit,
            'ending_balance'  => (float) ($openingBalance + $periodDebit - $periodCredit),
            'moves'           => $moves,
        ];
    }

    protected function getAccountOptions(): array
    {
        return Account::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn(Account $account): array => [$account->id => trim($account->code . ' ' . $account->name)])
            ->all();
    }

    protected function baseMoveLineQuery(int $accountId, array $journalIds)
    {
        $companyId = Auth::user()->default_company_id;

        $query = MoveLine::query()
            ->join('accounts_account_moves', 'accounts_account_move_lines.move_id', '=', 'accounts_account_moves.id')
            ->leftJoin('accounts_journals', 'accounts_account_moves.journal_id', '=', 'accounts_journals.id')
            ->leftJoin('partners_partners', 'accounts_account_move_lines.partner_id', '=', 'partners_partners.id')
            ->where('accounts_account_move_lines.account_id', $accountId)
            ->where('accounts_account_moves.state', MoveState::POSTED)
            ->where('accounts_account_moves.company_id', $companyId);

        if ($journalIds !== []) {
            $query->whereIn('accounts_account_moves.journal_id', $journalIds);
        }

        return $query;
    }

    protected function resolveAccountId(mixed $selectedAccount): ?int
    {
        if (blank($selectedAccount)) {
            return null;
        }

        if (is_numeric($selectedAccount)) {
            $accountId = Account::query()->whereKey((int) $selectedAccount)->value('id');

            if ($accountId) {
                return (int) $accountId;
            }
        }

        $accountId = Account::query()->where('code', (string) $selectedAccount)->value('id');

        return $accountId ? (int) $accountId : null;
    }

    protected function normalizeJournalIds(mixed $journalIds): array
    {
        if (blank($journalIds)) {
            return [];
        }

        if (is_string($journalIds)) {
            $journalIds = str_contains($journalIds, ',')
                ? explode(',', $journalIds)
                : [$journalIds];
        }

        if (! is_array($journalIds)) {
            $journalIds = [$journalIds];
        }

        return collect($journalIds)
            ->filter(fn(mixed $journalId): bool => filled($journalId))
            ->map(fn(mixed $journalId): int => (int) $journalId)
            ->unique()
            ->values()
            ->all();
    }

    protected function getExportFilename(string $extension): string
    {
        $data = $this->accountTransactionsData;
        $account = $data['account'];
        $accountIdentifier = $account?->code ?: $account?->getKey() ?: 'account';

        return 'account-transactions-' . strtolower((string) $accountIdentifier) . '-' . $data['date_from']->format('Y-m-d') . '-' . $data['date_to']->format('Y-m-d') . '.' . $extension;
    }
}
