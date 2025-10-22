<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateTestCsvFiles extends Command
{
    protected $signature = 'generate:test-csv {--path=storage/test_csvs : Directory to save CSV files} {--skip-large : Skip generating large file}';

    protected $description = 'Generate 3 test CSV files: 100 rows (valid), 10 rows (with errors), ~100MB file';

    public function handle()
    {
        $path = $this->option('path');
        $directory = base_path($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
            $this->info("Created directory: {$directory}");
        }

        $this->info('Generating test CSV files...');
        $this->newLine();

        // File 1: Small dataset with 100 valid rows
        $this->generateSmallDataset($directory);

        // File 2: 10 rows with validation errors and duplicate constraints
        $this->generateErrorDataset($directory);

        // File 3: Large file (optional, can be skipped)
        if (!$this->option('skip-large')) {
            if ($this->confirm('Generate large test file (~100MB)? This will take a minute.', true)) {
                $this->generateLargeFile($directory);
            } else {
                $this->warn('Skipped large file generation.');
            }
        } else {
            $this->warn('Skipped large file generation (--skip-large option).');
        }

        $this->newLine();
        $this->info('Test CSV files generated successfully!');
        $this->info("Location: {$directory}");
    }

    /**
     * Generate small dataset with 100 valid rows
     */
    protected function generateSmallDataset(string $directory): void
    {
        $filePath = "{$directory}/clients_100_valid.csv";
        $handle = fopen($filePath, 'w');

        fputcsv($handle, ['company', 'email', 'phone']);

        $bar = $this->output->createProgressBar(100);
        $bar->setFormat('File 1 (100 valid rows): %current%/%max% [%bar%] %percent:3s%%');
        $bar->start();

        for ($i = 1; $i <= 100; $i++) {
            fputcsv($handle, [
                "Company {$i}",
                "contact{$i}@company{$i}.com",
                sprintf('+1-%03d-%03d-%04d', rand(200, 999), rand(100, 999), rand(1000, 9999))
            ]);
            $bar->advance();
        }

        $bar->finish();
        fclose($handle);

        $fileSize = $this->formatBytes(filesize($filePath));
        $this->newLine();
        $this->info("✓ Generated: clients_100_valid.csv ({$fileSize})");
        $this->newLine();
    }

    /**
     * Generate dataset with validation errors and unique constraint violations
     */
    protected function generateErrorDataset(string $directory): void
    {
        $filePath = "{$directory}/clients_10_errors.csv";
        $handle = fopen($filePath, 'w');

        fputcsv($handle, ['company', 'email', 'phone']);

        $rows = [
            // Row 1: Valid
            ['Error Test Company 1', 'valid1@test.com', '+1-555-0001'],

            // Row 2: Invalid email format
            ['Error Test Company 2', 'invalid-email', '+1-555-0002'],

            // Row 3: Missing company (required field)
            ['', 'valid3@test.com', '+1-555-0003'],

            // Row 4: Valid
            ['Error Test Company 4', 'valid4@test.com', '+1-555-0004'],

            // Row 5: Email exceeds max length (55 chars)
            ['Error Test Company 5', 'this.is.a.very.long.email.address.that.exceeds.the.maximum.length@test.com', '+1-555-0005'],

            // Row 6: Missing email (required field)
            ['Error Test Company 6', '', '+1-555-0006'],

            // Row 7: Phone exceeds max length (22 chars)
            ['Error Test Company 7', 'valid7@test.com', '+1-555-0007-this-is-way-too-long-for-phone'],

            // Row 8: Duplicate of row 1 (unique constraint violation)
            ['Error Test Company 1', 'valid1@test.com', '+1-555-0001'],

            // Row 9: Missing phone (required field)
            ['Error Test Company 9', 'valid9@test.com', ''],

            // Row 10: Duplicate of row 4 (unique constraint violation)
            ['Error Test Company 4', 'valid4@test.com', '+1-555-0004'],
        ];

        $bar = $this->output->createProgressBar(count($rows));
        $bar->setFormat('File 2 (10 rows with errors): %current%/%max% [%bar%] %percent:3s%%');
        $bar->start();

        foreach ($rows as $row) {
            fputcsv($handle, $row);
            $bar->advance();
        }

        $bar->finish();
        fclose($handle);

        $fileSize = $this->formatBytes(filesize($filePath));
        $this->newLine();
        $this->info("✓ Generated: clients_10_errors.csv ({$fileSize})");
        $this->line('  - 3 valid rows');
        $this->line('  - 2 invalid email format/length');
        $this->line('  - 3 missing required fields');
        $this->line('  - 2 unique constraint violations');
        $this->newLine();
    }

    /**
     * Generate large file (approximately 100MB with 900,000 rows)
     */
    protected function generateLargeFile(string $directory): void
    {
        $filePath = "{$directory}/clients_large.csv";
        $handle = fopen($filePath, 'w');

        fputcsv($handle, ['company', 'email', 'phone']);

        // Calculate rows needed for ~100MB file
        // Average row size: ~120 bytes (company + email + phone + delimiters + newline)
        // 100MB = 104,857,600 bytes
        // Rows needed: 104,857,600 / 120 ≈ 873,813 rows
        // Let's use 900,000 rows for ~108MB
        $totalRows = 900_000;

        $bar = $this->output->createProgressBar($totalRows);
        $bar->setFormat('File 3 (Large file): %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% Memory: %memory:6s%');
        $bar->start();

        // Add some duplicates scattered throughout (every 10,000 rows, duplicate the previous row)
        for ($i = 1; $i <= $totalRows; $i++) {
            $isDuplicate = ($i > 1 && $i % 10_000 === 0);

            if ($isDuplicate) {
                // Duplicate the previous row to trigger unique constraint
                fputcsv($handle, [
                    "Large Test Company " . ($i - 1),
                    "contact" . ($i - 1) . "@largetest.com",
                    sprintf('+1-%03d-%03d-%04d',
                        (($i - 1) % 900) + 100,
                        (($i - 1) % 900) + 100,
                        ($i - 1) % 10000
                    )
                ]);
            } else {
                fputcsv($handle, [
                    "Large Test Company {$i}",
                    "contact{$i}@largetest.com",
                    sprintf('+1-%03d-%03d-%04d',
                        ($i % 900) + 100,
                        ($i % 900) + 100,
                        $i % 10000
                    )
                ]);
            }

            if ($i % 10000 === 0) {
                $bar->advance(10000);
            }
        }

        $bar->finish();
        fclose($handle);

        $fileSize = $this->formatBytes(filesize($filePath));
        $duplicateCount = floor($totalRows / 10_000);

        $this->newLine();
        $this->info("✓ Generated: clients_large.csv ({$fileSize})");
        $this->line("  - {$totalRows} total rows");
        $this->line("  - {$duplicateCount} duplicate rows (unique constraint violations)");
        $this->newLine();
    }

    /**
     * Format bytes to human-readable size
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
