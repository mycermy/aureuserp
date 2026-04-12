<x-filament-panels::page>
    <style>
        .account-summary-grid {
            display: grid;
            gap: 1rem;
            grid-template-columns: 1fr;
        }

        @media (min-width: 640px) {
            .account-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .account-summary-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }
    </style>

    <div class="space-y-6">
        {{ $this->form }}

        @php
            $data = $this->accountTransactionsData;
            $account = $data['account'];
            $runningBalance = $data['opening_balance'];
        @endphp

        <x-filament::section>
            <x-slot name="heading">
                @if ($account)
                    Account Transactions - {{ $account->code }} {{ $account->name }}
                @else
                    Account Transactions
                @endif
            </x-slot>

            <x-slot name="description">
                From {{ $data['date_from']->format('M d, Y') }} to {{ $data['date_to']->format('M d, Y') }}
            </x-slot>

            @if (! $account)
                <div class="px-4 py-8 text-sm text-center text-gray-500 border border-gray-300 border-dashed rounded-lg dark:border-white/10 dark:text-gray-400">
                    Select an account to view its transactions.
                </div>
            @else
                <div>
                    <div class="account-summary-grid">
                        <div class="min-w-0 p-4 bg-white border border-gray-200 rounded-xl dark:border-white/10 dark:bg-white/5">
                            <div class="text-xs tracking-wide text-gray-500 uppercase dark:text-gray-400">Opening Balance</div>
                            <div class="mt-2 text-lg font-semibold text-gray-900 dark:text-white">{{ number_format($data['opening_balance'], 2) }}</div>
                        </div>

                        <div class="min-w-0 p-4 bg-white border border-gray-200 rounded-xl dark:border-white/10 dark:bg-white/5">
                            <div class="text-xs tracking-wide text-gray-500 uppercase dark:text-gray-400">Debit</div>
                            <div class="mt-2 text-lg font-semibold text-gray-900 dark:text-white">{{ number_format($data['period_debit'], 2) }}</div>
                        </div>

                        <div class="min-w-0 p-4 bg-white border border-gray-200 rounded-xl dark:border-white/10 dark:bg-white/5">
                            <div class="text-xs tracking-wide text-gray-500 uppercase dark:text-gray-400">Credit</div>
                            <div class="mt-2 text-lg font-semibold text-gray-900 dark:text-white">{{ number_format($data['period_credit'], 2) }}</div>
                        </div>

                        <div class="min-w-0 p-4 bg-white border border-gray-200 rounded-xl dark:border-white/10 dark:bg-white/5">
                            <div class="text-xs tracking-wide text-gray-500 uppercase dark:text-gray-400">Ending Balance</div>
                            <div class="mt-2 text-lg font-semibold text-gray-900 dark:text-white">{{ number_format($data['ending_balance'], 2) }}</div>
                        </div>
                    </div>
                </div>

                <div class="pt-8 mt-14">
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/5!">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5!">
                        <thead class="bg-gray-50/50 dark:bg-white/5">
                            <tr>
                                <th class="px-4 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase dark:text-gray-400">Date</th>
                                <th class="px-4 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase dark:text-gray-400">Journal</th>
                                <th class="px-4 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase dark:text-gray-400">Entry</th>
                                <th class="px-4 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase dark:text-gray-400">Communication</th>
                                <th class="px-4 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase dark:text-gray-400">Partner</th>
                                <th class="px-4 py-3 text-xs font-medium tracking-wider text-right text-gray-500 uppercase dark:text-gray-400">Debit</th>
                                <th class="px-4 py-3 text-xs font-medium tracking-wider text-right text-gray-500 uppercase dark:text-gray-400">Credit</th>
                                <th class="px-4 py-3 text-xs font-medium tracking-wider text-right text-gray-500 uppercase dark:text-gray-400">Balance</th>
                            </tr>
                        </thead>

                        <tbody class="bg-white divide-y divide-gray-200 dark:divide-white/5 dark:bg-gray-900">
                            <tr>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $data['date_from']->format('M d, Y') }}</td>
                                <td class="px-4 py-2"></td>
                                <td class="px-4 py-2 text-sm italic text-gray-600 dark:text-gray-400">Opening Balance</td>
                                <td class="px-4 py-2"></td>
                                <td class="px-4 py-2"></td>
                                <td class="px-4 py-2 text-sm text-right text-gray-600 dark:text-gray-400">{{ $data['opening_balance'] > 0 ? number_format($data['opening_balance'], 2) : '' }}</td>
                                <td class="px-4 py-2 text-sm text-right text-gray-600 dark:text-gray-400">{{ $data['opening_balance'] < 0 ? number_format(abs($data['opening_balance']), 2) : '' }}</td>
                                <td class="px-4 py-2 text-sm font-semibold text-right text-gray-600 dark:text-gray-400">{{ number_format($runningBalance, 2) }}</td>
                            </tr>

                            @forelse ($data['moves'] as $move)
                                @php
                                    $runningBalance += ($move['debit'] - $move['credit']);
                                @endphp

                                <tr>
                                    <td class="px-4 py-2 text-sm text-gray-600 whitespace-nowrap dark:text-gray-400">{{ \Carbon\Carbon::parse($move['date'])->format('M d, Y') }}</td>
                                    <td class="px-4 py-2 text-sm text-gray-600 whitespace-nowrap dark:text-gray-400">{{ $move['journal_name'] }}</td>
                                    <td class="px-4 py-2 text-sm text-gray-600 whitespace-nowrap dark:text-gray-400">
                                        {{ $move['move_name'] }}

                                        @if ($move['ref'])
                                            <span class="text-xs text-gray-500 dark:text-gray-500">({{ $move['ref'] }})</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-sm text-gray-600 whitespace-nowrap dark:text-gray-400">{{ $move['move_type'] === 'entry' ? $move['name'] : '' }}</td>
                                    <td class="px-4 py-2 text-sm text-gray-600 whitespace-nowrap dark:text-gray-400">{{ $move['partner_name'] }}</td>
                                    <td class="px-4 py-2 text-sm text-right text-gray-600 dark:text-gray-400">{{ $move['debit'] > 0 ? number_format($move['debit'], 2) : '' }}</td>
                                    <td class="px-4 py-2 text-sm text-right text-gray-600 dark:text-gray-400">{{ $move['credit'] > 0 ? number_format($move['credit'], 2) : '' }}</td>
                                    <td class="px-4 py-2 text-sm font-medium text-right text-gray-600 dark:text-gray-400">{{ number_format($runningBalance, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-sm text-center text-gray-500 dark:text-gray-400">
                                        No transactions found for this account in the selected period.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
