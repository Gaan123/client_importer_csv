<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;

class ImportDetailsExport implements FromArray, WithHeadings, WithStyles, WithColumnWidths
{
    protected $rows;
    protected $failedRowNumbers = [];

    public function __construct(array $rows)
    {
        $this->rows = $rows;

        // Track which row numbers have failed status
        foreach ($rows as $index => $row) {
            if ($row['status'] === 'failed') {
                // +2 because: +1 for header row, +1 for 0-based to 1-based index
                $this->failedRowNumbers[] = $index + 2;
            }
        }
    }

    public function array(): array
    {
        return array_map(function($row) {
            return [
                $row['row_number'],
                $row['company'],
                $row['email'],
                $row['phone'],
                $row['status'],
                $row['is_duplicate'] ? 'Yes' : 'No',
                $row['error'] ?? '',
            ];
        }, $this->rows);
    }

    public function headings(): array
    {
        return [
            'Row #',
            'Company',
            'Email',
            'Phone',
            'Status',
            'Is Duplicate',
            'Error',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $styles = [];

        // Style header row (row 1)
        $styles[1] = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E2E8F0'], // Light gray
            ],
        ];

        // Apply red background to failed rows
        foreach ($this->failedRowNumbers as $rowNumber) {
            $styles[$rowNumber] = [
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FEE2E2'], // Light red background
                ],
                'font' => [
                    'color' => ['rgb' => '991B1B'], // Dark red text
                ],
            ];
        }

        return $styles;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 10,  // Row #
            'B' => 25,  // Company
            'C' => 30,  // Email
            'D' => 20,  // Phone
            'E' => 12,  // Status
            'F' => 15,  // Is Duplicate
            'G' => 50,  // Error
        ];
    }
}
