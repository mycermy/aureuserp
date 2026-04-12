<?php

namespace Zrm\WorkshopDemo\Filament\Pages\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AccountTransactionsExport implements FromArray, WithColumnWidths, WithHeadings, WithStyles
{
    protected array $data;

    protected array $rowMetadata = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function headings(): array
    {
        $account = $this->data['account'];

        return [
            ['Account Transactions - ' . $account->code . ' ' . $account->name],
            ['From ' . $this->data['date_from']->format('M d, Y') . ' to ' . $this->data['date_to']->format('M d, Y')],
            [],
            ['Date', 'Journal', 'Entry', 'Communication', 'Partner', 'Debit', 'Credit', 'Balance'],
        ];
    }

    public function array(): array
    {
        $rows = [];
        $rowIndex = 5;
        $runningBalance = $this->data['opening_balance'];

        $rows[] = [
            $this->data['date_from']->format('M d, Y'),
            '',
            'Opening Balance',
            '',
            '',
            $this->data['opening_balance'] > 0 ? $this->data['opening_balance'] : '',
            $this->data['opening_balance'] < 0 ? abs($this->data['opening_balance']) : '',
            $runningBalance,
        ];
        $this->rowMetadata[$rowIndex++] = 'opening_balance';

        foreach ($this->data['moves'] as $move) {
            $runningBalance += $move['debit'] - $move['credit'];

            $rows[] = [
                Carbon::parse($move['date'])->format('M d, Y'),
                $move['journal_name'] ?? '',
                trim(($move['move_name'] ?? '') . ($move['ref'] ? ' (' . $move['ref'] . ')' : '')),
                ($move['move_type'] ?? null) === 'entry' ? ($move['name'] ?? '') : '',
                $move['partner_name'] ?? '',
                ($move['debit'] ?? 0) > 0 ? $move['debit'] : '',
                ($move['credit'] ?? 0) > 0 ? $move['credit'] : '',
                $runningBalance,
            ];
            $this->rowMetadata[$rowIndex++] = 'move_line';
        }

        $rows[] = [
            '',
            '',
            '',
            'Totals',
            '',
            $this->data['period_debit'],
            $this->data['period_credit'],
            $this->data['ending_balance'],
        ];
        $this->rowMetadata[$rowIndex] = 'totals';

        return $rows;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 16,
            'B' => 18,
            'C' => 28,
            'D' => 24,
            'E' => 24,
            'F' => 14,
            'G' => 14,
            'H' => 14,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->mergeCells('A1:H1');
        $sheet->mergeCells('A2:H2');

        $sheet->getStyle('A1:H1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);

        $sheet->getStyle('A2:H2')->applyFromArray([
            'font'      => ['size' => 11],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);

        $sheet->getStyle('A4:H4')->applyFromArray([
            'font'    => ['bold' => true],
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_THICK,
                    'color'       => ['rgb' => '666666'],
                ],
            ],
        ]);

        foreach ($this->rowMetadata as $rowNumber => $type) {
            $style = match ($type) {
                'opening_balance' => [
                    'font' => ['italic' => true, 'color' => ['rgb' => '666666']],
                ],
                'totals' => [
                    'font'    => ['bold' => true],
                    'borders' => [
                        'top' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color'       => ['rgb' => '000000'],
                        ],
                    ],
                ],
                default => [],
            };

            if ($style !== []) {
                $sheet->getStyle("A{$rowNumber}:H{$rowNumber}")->applyFromArray($style);
            }
        }

        $lastRow = count($this->rowMetadata) + 4;
        $sheet->getStyle("F5:H{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("F5:H{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        return [];
    }
}
