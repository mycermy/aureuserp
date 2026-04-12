<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Account Transactions</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #111827;
        }

        h1 {
            margin: 0 0 4px;
            font-size: 18px;
        }

        p {
            margin: 0 0 12px;
            color: #4b5563;
        }

        .summary {
            width: 100%;
            margin-bottom: 16px;
        }

        .summary td {
            width: 25%;
            padding: 8px;
            border: 1px solid #d1d5db;
        }

        .summary-label {
            font-size: 10px;
            text-transform: uppercase;
            color: #6b7280;
        }

        .summary-value {
            margin-top: 4px;
            font-size: 14px;
            font-weight: bold;
            color: #111827;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 6px 8px;
            border: 1px solid #d1d5db;
            vertical-align: top;
        }

        th {
            background: #f3f4f6;
            text-align: left;
        }

        .text-right {
            text-align: right;
        }

        .opening-row {
            color: #4b5563;
            font-style: italic;
        }
    </style>
</head>
<body>
    @php
        $account = $data['account'];
        $runningBalance = $data['opening_balance'];
    @endphp

    <h1>Account Transactions - {{ $account->code }} {{ $account->name }}</h1>
    <p>From {{ $data['date_from']->format('M d, Y') }} to {{ $data['date_to']->format('M d, Y') }}</p>

    <table class="summary">
        <tr>
            <td>
                <div class="summary-label">Opening Balance</div>
                <div class="summary-value">{{ number_format($data['opening_balance'], 2) }}</div>
            </td>
            <td>
                <div class="summary-label">Debit</div>
                <div class="summary-value">{{ number_format($data['period_debit'], 2) }}</div>
            </td>
            <td>
                <div class="summary-label">Credit</div>
                <div class="summary-value">{{ number_format($data['period_credit'], 2) }}</div>
            </td>
            <td>
                <div class="summary-label">Ending Balance</div>
                <div class="summary-value">{{ number_format($data['ending_balance'], 2) }}</div>
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Journal</th>
                <th>Entry</th>
                <th>Communication</th>
                <th>Partner</th>
                <th class="text-right">Debit</th>
                <th class="text-right">Credit</th>
                <th class="text-right">Balance</th>
            </tr>
        </thead>
        <tbody>
            <tr class="opening-row">
                <td>{{ $data['date_from']->format('M d, Y') }}</td>
                <td></td>
                <td>Opening Balance</td>
                <td></td>
                <td></td>
                <td class="text-right">{{ $data['opening_balance'] > 0 ? number_format($data['opening_balance'], 2) : '' }}</td>
                <td class="text-right">{{ $data['opening_balance'] < 0 ? number_format(abs($data['opening_balance']), 2) : '' }}</td>
                <td class="text-right">{{ number_format($runningBalance, 2) }}</td>
            </tr>

            @foreach ($data['moves'] as $move)
                @php
                    $runningBalance += ($move['debit'] - $move['credit']);
                @endphp

                <tr>
                    <td>{{ \Carbon\Carbon::parse($move['date'])->format('M d, Y') }}</td>
                    <td>{{ $move['journal_name'] }}</td>
                    <td>
                        {{ $move['move_name'] }}
                        @if ($move['ref'])
                            ({{ $move['ref'] }})
                        @endif
                    </td>
                    <td>{{ $move['move_type'] === 'entry' ? $move['name'] : '' }}</td>
                    <td>{{ $move['partner_name'] }}</td>
                    <td class="text-right">{{ $move['debit'] > 0 ? number_format($move['debit'], 2) : '' }}</td>
                    <td class="text-right">{{ $move['credit'] > 0 ? number_format($move['credit'], 2) : '' }}</td>
                    <td class="text-right">{{ number_format($runningBalance, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
