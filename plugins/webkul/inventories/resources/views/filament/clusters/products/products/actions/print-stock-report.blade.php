<!DOCTYPE html>
<html>
<head>
    <style>
        @page {
            margin: 20px;
        }

        body {
            margin: 0;
            padding: 15px;
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 12px;
            color: #333;
            background: #ffffff;
        }

        .report-header {
            border-bottom: 2px solid #1a4587;
            margin-bottom: 20px;
            padding-bottom: 10px;
        }

        .report-title {
            font-size: 18px;
            font-weight: bold;
            color: #1a4587;
        }

        .report-date {
            font-size: 11px;
            color: #666;
            margin-top: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        thead tr {
            background-color: #1a4587;
            color: #ffffff;
        }

        thead th {
            padding: 8px 10px;
            text-align: left;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        tbody tr {
            border-bottom: 1px solid #e9ecef;
        }

        tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        tbody td {
            padding: 7px 10px;
            font-size: 11px;
            vertical-align: middle;
        }

        .qty-cell {
            text-align: right;
            font-weight: bold;
        }

        .qty-zero {
            color: #aaa;
        }

        .report-footer {
            margin-top: 20px;
            border-top: 1px solid #e9ecef;
            padding-top: 8px;
            font-size: 10px;
            color: #666;
            text-align: right;
        }
    </style>
</head>

<body>
    <div class="report-header">
        <div class="report-title">Product Stock Report</div>
        <div class="report-date">Generated: {{ now()->format('d M Y, H:i') }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 5%">#</th>
                <th style="width: 35%">Product Name</th>
                <th style="width: 15%">Reference</th>
                <th style="width: 20%">Category</th>
                <th style="width: 15%; text-align: right">On Hand Qty</th>
                <th style="width: 10%; text-align: right">UOM</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($records as $index => $record)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $record->name }}</td>
                    <td>{{ $record->reference ?? '—' }}</td>
                    <td>{{ $record->category?->full_name ?? '—' }}</td>
                    <td class="qty-cell {{ $record->on_hand_quantity == 0 ? 'qty-zero' : '' }}">
                        {{ number_format($record->on_hand_quantity, 2) }}
                    </td>
                    <td style="text-align: right">{{ $record->uom?->name ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="report-footer">
        Total products: {{ $records->count() }}
    </div>
</body>
</html>
