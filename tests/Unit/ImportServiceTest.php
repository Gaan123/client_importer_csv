<?php

namespace Tests\Unit;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ImportService $importService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importService = new ImportService();
        Storage::fake('local');
    }

    public function test_validates_csv_file_type_correctly()
    {
        $csvContent = "company,email,phone\nTest Company,test@example.com,555-1234";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $errors = $this->importService->validateFile($file);

        $this->assertEmpty($errors);
    }

    public function test_rejects_binary_files()
    {
        $binaryContent = "\x00\x01\x02\x03\x04\x05\x06\x07";
        $file = UploadedFile::fake()->createWithContent('fake.csv', $binaryContent);

        $errors = $this->importService->validateFile($file);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('does not appear to be a valid CSV', $errors[0]);
    }

    public function test_rejects_file_without_csv_delimiters()
    {
        $textContent = "This is just plain text without any delimiters";
        $file = UploadedFile::fake()->createWithContent('test.csv', $textContent);

        $errors = $this->importService->validateFile($file);

        $this->assertNotEmpty($errors);
    }

    public function test_generates_unique_file_signature()
    {
        $csvContent = "company,email,phone\nTest,test@test.com,555-1234";
        $file1 = UploadedFile::fake()->createWithContent('test1.csv', $csvContent);
        $file2 = UploadedFile::fake()->createWithContent('test2.csv', $csvContent);

        $signature1 = $this->importService->generateFileSignature($file1);
        $signature2 = $this->importService->generateFileSignature($file2);

        $this->assertEquals($signature1, $signature2);
        $this->assertEquals(64, strlen($signature1));
    }

    public function test_different_content_generates_different_signature()
    {
        $file1 = UploadedFile::fake()->createWithContent('test1.csv', 'content1');
        $file2 = UploadedFile::fake()->createWithContent('test2.csv', 'content2');

        $signature1 = $this->importService->generateFileSignature($file1);
        $signature2 = $this->importService->generateFileSignature($file2);

        $this->assertNotEquals($signature1, $signature2);
    }

    public function test_detects_duplicate_file_by_signature()
    {
        $signature = 'test_signature_12345';

        Import::create([
            'importable_type' => 'clients',
            'file_signature' => $signature,
            'file_path' => 'test/path.csv',
            'status' => 'completed',
            'total_rows' => 10,
            'metadata' => [],
            'data' => [],
        ]);

        $duplicate = $this->importService->checkDuplicate($signature);

        $this->assertNotNull($duplicate);
        $this->assertEquals($signature, $duplicate->file_signature);
    }

    public function test_returns_null_for_non_duplicate_file()
    {
        $signature = 'non_existing_signature';

        $result = $this->importService->checkDuplicate($signature);

        $this->assertNull($result);
    }

    public function test_stores_file_correctly()
    {
        $csvContent = "company,email,phone\nTest,test@test.com,555-1234";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);
        $signature = 'test_signature';

        $path = $this->importService->storeFile($file, $signature);

        $this->assertStringContainsString('imports/', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_saves_import_record_with_metadata()
    {
        $csvContent = "company,email,phone\nTest,test@test.com,555-1234";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);
        $signature = 'test_signature_123';
        $filePath = 'imports/test.csv';

        $import = $this->importService->saveImportRecord(
            $file,
            $signature,
            $filePath,
            'clients',
            ['custom_key' => 'custom_value']
        );

        $this->assertInstanceOf(Import::class, $import);
        $this->assertEquals('clients', $import->importable_type);
        $this->assertEquals($signature, $import->file_signature);
        $this->assertEquals(ImportStatus::PENDING, $import->status);
        $this->assertArrayHasKey('original_filename', $import->metadata);
        $this->assertArrayHasKey('custom_key', $import->metadata);
    }

    public function test_validates_csv_structure_with_inconsistent_columns()
    {
        $csvContent = "company,email,phone\n";
        $csvContent .= "Company1,email1@test.com,555-1234\n";
        $csvContent .= "Company2,email2@test.com\n";
        $csvContent .= "Company3,email3@test.com,555-5678\n";

        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $errors = $this->importService->validateFile($file);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('inconsistent', $errors[0]);
    }
}
