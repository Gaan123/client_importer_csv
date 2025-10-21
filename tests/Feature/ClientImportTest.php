<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Jobs\ProcessClientsImport;
use App\Models\Clients;
use App\Models\Import;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );
    }

    public function test_successfully_imports_valid_csv_file()
    {
        Bus::fake();

        $csvContent = "company,email,phone\n";
        $csvContent .= "Company 1,contact1@company1.com,+1-555-123-4567\n";
        $csvContent .= "Company 2,contact2@company2.com,+1-555-123-4568\n";
        $csvContent .= "Company 3,contact3@company3.com,+1-555-123-4569";

        $file = UploadedFile::fake()->createWithContent('clients.csv', $csvContent);

        $response = $this->postJson('/api/clients/import', [
            'file' => $file,
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'importable_type',
                'status',
                'total_rows',
                'file_signature',
                'metadata',
                'created_at',
                'updated_at',
            ],
            'message',
        ]);

        Bus::assertBatched(function ($batch) {
            return $batch->name === 'Import Clients - 1';
        });

        $this->assertDatabaseCount('imports', 1);
        $this->assertDatabaseHas('imports', ['status' => 'queued']);
    }

    public function test_rejects_duplicate_file_upload()
    {
        $csvContent = "company,email,phone\nCompany,test@test.com,555-1234";
        $file = UploadedFile::fake()->createWithContent('clients.csv', $csvContent);

        $this->postJson('/api/clients/import', ['file' => $file]);

        $file2 = UploadedFile::fake()->createWithContent('clients.csv', $csvContent);
        $response = $this->postJson('/api/clients/import', ['file' => $file2]);

        $response->assertStatus(409);
        $response->assertJson([
            'message' => 'This file has already been imported.',
        ]);
        $response->assertJsonStructure(['data']);
    }

    public function test_handles_duplicate_entries_in_csv()
    {
        $csvContent = "company,email,phone\n";
        $csvContent .= "Company 1,contact1@company1.com,+1-555-123-4567\n";
        $csvContent .= "Company 2,contact2@company2.com,+1-555-123-4568\n";
        $csvContent .= "Company 1,contact1@company1.com,+1-555-123-4567";

        $file = UploadedFile::fake()->createWithContent('clients.csv', $csvContent);

        $response = $this->postJson('/api/clients/import', ['file' => $file]);
        $response->assertStatus(202);

        $import = Import::first();

        $job = new ProcessClientsImport($import);
        $job->handle(app(\App\Services\ImportService::class));

        $import->refresh();


        $duplicateRow = collect($import->data['rows'])->firstWhere('is_duplicate', true);

        $this->assertNotNull($duplicateRow);
        $this->assertEquals('failed', $duplicateRow['status']);
    }

    public function test_handles_validation_errors()
    {
        $csvContent = "company,email,phone\n";
        $csvContent .= "Company 1,contact1@company1.com,+1-555-123-4567\n";
        $csvContent .= ",missing_company@test.com,+1-555-123-4568\n";
        $csvContent .= "Company 3,invalid-email,+1-555-123-4569\n";
        $csvContent .= "Company 4,contact4@company4.com,";

        $file = UploadedFile::fake()->createWithContent('clients.csv', $csvContent);

        $response = $this->postJson('/api/clients/import', ['file' => $file]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['data', 'message']);


        $import = Import::first();
        $failedRows = collect($import->data['rows'])->where('status', 'failed');

        $this->assertCount(3, $failedRows);
    }

    public function test_rejects_invalid_file_type()
    {
        $binaryContent = "\x00\x01\x02\x03\x04\x05";
        $file = UploadedFile::fake()->createWithContent('fake.csv', $binaryContent);

        $response = $this->postJson('/api/clients/import', ['file' => $file]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'File validation failed.',
        ]);
    }

    public function test_requires_file_upload()
    {
        $response = $this->postJson('/api/clients/import', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file']);
    }

    public function test_rejects_non_csv_mime_type()
    {
        $file = UploadedFile::fake()->image('image.jpg');

        $response = $this->postJson('/api/clients/import', ['file' => $file]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file']);
    }

    public function test_stores_all_rows_in_import_data()
    {
        $csvContent = "company,email,phone\n";
        $csvContent .= "Company 1,contact1@company1.com,+1-555-123-4567\n";
        $csvContent .= "Company 2,contact2@company2.com,+1-555-123-4568";

        $file = UploadedFile::fake()->createWithContent('clients.csv', $csvContent);

        $response = $this->postJson('/api/clients/import', ['file' => $file]);

        $response->assertStatus(202);

        $import = Import::first();

        $this->assertArrayHasKey('rows', $import->data);
        $this->assertArrayHasKey('summary', $import->data);
        $this->assertCount(2, $import->data['rows']);

        foreach ($import->data['rows'] as $row) {
            $this->assertArrayHasKey('row_number', $row);
            $this->assertArrayHasKey('data', $row);
            $this->assertArrayHasKey('is_duplicate', $row);
            $this->assertArrayHasKey('status', $row);
            $this->assertArrayHasKey('error', $row);
        }
    }

    public function test_handles_large_csv_file()
    {
        $csvContent = "company,email,phone\n";

        for ($i = 1; $i <= 1000; $i++) {
            $csvContent .= "Company {$i},contact{$i}@company{$i}.com,+1-555-{$i}\n";
        }

        $file = UploadedFile::fake()->createWithContent('large.csv', $csvContent);

        $response = $this->postJson('/api/clients/import', ['file' => $file]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['data', 'message']);

    }

    public function test_import_creates_proper_metadata()
    {
        $csvContent = "company,email,phone\nCompany,test@test.com,555-1234";
        $file = UploadedFile::fake()->createWithContent('test-file.csv', $csvContent);

        $response = $this->postJson('/api/clients/import', ['file' => $file]);

        $response->assertStatus(202);

        $import = Import::first();

        $this->assertArrayHasKey('original_filename', $import->metadata);
        $this->assertArrayHasKey('mime_type', $import->metadata);
        $this->assertArrayHasKey('file_size', $import->metadata);
        $this->assertArrayHasKey('uploaded_at', $import->metadata);
        $this->assertEquals('test-file.csv', $import->metadata['original_filename']);
    }

    public function test_validates_email_format()
    {
        $csvContent = "company,email,phone\n";
        $csvContent .= "Company 1,invalid-email-format,+1-555-123-4567";

        $file = UploadedFile::fake()->createWithContent('clients.csv', $csvContent);

        $response = $this->postJson('/api/clients/import', ['file' => $file]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['data', 'message']);

    }

    public function test_validates_string_length_limits()
    {
        $longEmail = str_repeat('a', 50) . '@test.com';

        $csvContent = "company,email,phone\n";
        $csvContent .= "Company 1,{$longEmail},+1-555-123-4567";

        $file = UploadedFile::fake()->createWithContent('clients.csv', $csvContent);

        $response = $this->postJson('/api/clients/import', ['file' => $file]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['data', 'message']);
    }

    public function test_job_processes_import_successfully()
    {
        Bus::fake();

        $csvContent = "company,email,phone\n";
        $csvContent .= "Test Company 1,testcontact1@testcompany1.com,+1-999-123-4567\n";
        $csvContent .= "Test Company 2,testcontact2@testcompany2.com,+1-999-123-4568";

        $file = UploadedFile::fake()->createWithContent('clients.csv', $csvContent);

        $response = $this->postJson('/api/clients/import', ['file' => $file]);
        $response->assertStatus(202);

        $import = Import::first();

        $job = new ProcessClientsImport($import);
        $job->handle(app(\App\Services\ImportService::class));

        $import->refresh();

        $this->assertEquals(ImportStatus::COMPLETED, $import->status);
        $this->assertEquals(2, $import->total_rows);
        $this->assertDatabaseCount('clients', 2);
    }
}
