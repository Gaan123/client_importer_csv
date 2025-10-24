<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Jobs\ProcessClientsImport;
use App\Models\Import;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImportsApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_can_list_all_imports()
    {
        // Create multiple imports
        Import::factory()->count(3)->create();

        $response = $this->getJson('/api/imports');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'importable_type',
                    'status',
                    'total_rows',
                    'file_signature',
                    'metadata',
                    'created_at',
                    'updated_at',
                ]
            ],
            'links',
            'meta',
        ]);
        $response->assertJsonCount(3, 'data');
    }

    public function test_can_filter_imports_by_status()
    {
        Import::factory()->create(['status' => ImportStatus::COMPLETED]);
        Import::factory()->create(['status' => ImportStatus::FAILED]);
        Import::factory()->create(['status' => ImportStatus::PROCESSING]);

        $response = $this->getJson('/api/imports?status=completed');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.status', 'completed');
    }

    public function test_can_filter_imports_by_importable_type()
    {
        Import::factory()->create(['importable_type' => 'clients']);
        Import::factory()->create(['importable_type' => 'clients']);
        Import::factory()->create(['importable_type' => 'other']);

        $response = $this->getJson('/api/imports?importable_type=clients');

        $response->assertStatus(200);
        // Count only 'clients' type imports
        $clientsCount = count(array_filter($response->json('data'), fn($import) => $import['importable_type'] === 'clients'));
        $this->assertEquals(2, $clientsCount);
    }

    public function test_can_paginate_imports()
    {
        Import::factory()->count(20)->create();

        $response = $this->getJson('/api/imports?per_page=5');

        $response->assertStatus(200);
        $response->assertJsonCount(5, 'data');
        $response->assertJsonPath('meta.per_page', 5);
        $response->assertJsonPath('meta.total', 20);
    }

    public function test_can_show_import_with_client_logs()
    {
        $import = Import::factory()->create([
            'importable_type' => 'clients',
            'status' => ImportStatus::COMPLETED,
            'total_rows' => 3,
            'data' => [
                'summary' => [
                    'total' => 3,
                    'imported' => 2,
                    'failed' => 1,
                    'duplicates' => 0,
                ],
                'rows' => [
                    [
                        'row_number' => 1,
                        'data' => ['company' => 'Company 1', 'email' => 'test1@example.com', 'phone' => '+1-555-1111'],
                        'status' => 'success',
                        'is_duplicate' => false,
                        'error' => null,
                    ],
                    [
                        'row_number' => 2,
                        'data' => ['company' => 'Company 2', 'email' => 'test2@example.com', 'phone' => '+1-555-2222'],
                        'status' => 'success',
                        'is_duplicate' => false,
                        'error' => null,
                    ],
                    [
                        'row_number' => 3,
                        'data' => ['company' => 'Company 3', 'email' => 'invalid-email', 'phone' => '+1-555-3333'],
                        'status' => 'failed',
                        'is_duplicate' => false,
                        'error' => 'Validation failed',
                    ],
                ],
            ],
        ]);

        $response = $this->getJson("/api/imports/{$import->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'import_id',
            'status',
            'total_rows',
            'summary',
            'clients' => [
                'data' => [
                    '*' => ['row_number', 'company', 'email', 'phone', 'status', 'is_duplicate', 'error']
                ],
                'current_page',
                'per_page',
                'total',
                'last_page',
            ],
        ]);

        $response->assertJsonPath('summary.total', 3);
        $response->assertJsonPath('summary.imported', 2);
        $response->assertJsonPath('summary.failed', 1);
        $response->assertJsonCount(3, 'clients.data');
    }

    public function test_can_filter_import_clients_by_status()
    {
        $import = Import::factory()->create([
            'importable_type' => 'clients',
            'status' => ImportStatus::COMPLETED,
            'total_rows' => 3,
            'data' => [
                'summary' => [
                    'total' => 3,
                    'imported' => 1,
                    'failed' => 2,
                    'duplicates' => 0,
                ],
                'rows' => [
                    [
                        'row_number' => 1,
                        'data' => ['company' => 'Company 1', 'email' => 'test1@example.com', 'phone' => '+1-555-1111'],
                        'status' => 'success',
                        'is_duplicate' => false,
                        'error' => null,
                    ],
                    [
                        'row_number' => 2,
                        'data' => ['company' => 'Company 2', 'email' => 'test2@example.com', 'phone' => '+1-555-2222'],
                        'status' => 'failed',
                        'is_duplicate' => false,
                        'error' => 'Validation failed',
                    ],
                    [
                        'row_number' => 3,
                        'data' => ['company' => 'Company 3', 'email' => 'test3@example.com', 'phone' => '+1-555-3333'],
                        'status' => 'failed',
                        'is_duplicate' => false,
                        'error' => 'Validation failed',
                    ],
                ],
            ],
        ]);

        $response = $this->getJson("/api/imports/{$import->id}?status=failed");

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'clients.data');

        foreach ($response->json('clients.data') as $client) {
            $this->assertEquals('failed', $client['status']);
        }
    }

    public function test_can_filter_import_clients_by_is_duplicate()
    {
        $import = Import::factory()->create([
            'importable_type' => 'clients',
            'status' => ImportStatus::COMPLETED,
            'total_rows' => 3,
            'data' => [
                'summary' => [
                    'total' => 3,
                    'imported' => 2,
                    'failed' => 0,
                    'duplicates' => 1,
                ],
                'rows' => [
                    [
                        'row_number' => 1,
                        'data' => ['company' => 'Company 1', 'email' => 'test1@example.com', 'phone' => '+1-555-1111'],
                        'status' => 'success',
                        'is_duplicate' => false,
                        'error' => null,
                    ],
                    [
                        'row_number' => 2,
                        'data' => ['company' => 'Company 2', 'email' => 'test2@example.com', 'phone' => '+1-555-2222'],
                        'status' => 'success',
                        'is_duplicate' => true,
                        'error' => null,
                    ],
                    [
                        'row_number' => 3,
                        'data' => ['company' => 'Company 3', 'email' => 'test3@example.com', 'phone' => '+1-555-3333'],
                        'status' => 'success',
                        'is_duplicate' => false,
                        'error' => null,
                    ],
                ],
            ],
        ]);

        $response = $this->getJson("/api/imports/{$import->id}?is_duplicate=true");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'clients.data');
        $this->assertTrue($response->json('clients.data.0.is_duplicate'));
    }

    public function test_can_paginate_import_clients()
    {
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = [
                'row_number' => $i,
                'data' => ['company' => "Company $i", 'email' => "test$i@example.com", 'phone' => "+1-555-$i"],
                'status' => 'success',
                'is_duplicate' => false,
                'error' => null,
            ];
        }

        $import = Import::factory()->create([
            'importable_type' => 'clients',
            'status' => ImportStatus::COMPLETED,
            'total_rows' => 25,
            'data' => [
                'summary' => [
                    'total' => 25,
                    'imported' => 25,
                    'failed' => 0,
                    'duplicates' => 0,
                ],
                'rows' => $rows,
            ],
        ]);

        $response = $this->getJson("/api/imports/{$import->id}?per_page=10");

        $response->assertStatus(200);
        $response->assertJsonCount(10, 'clients.data');
        $response->assertJsonPath('clients.per_page', 10);
        $response->assertJsonPath('clients.total', 25);
    }

    public function test_can_delete_import()
    {
        $csvContent = "company,email,phone\nCompany,test@test.com,555-1234";
        $file = UploadedFile::fake()->createWithContent('clients.csv', $csvContent);

        $this->postJson('/api/clients/import', ['file' => $file]);

        $import = Import::first();

        $response = $this->deleteJson("/api/imports/{$import->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Import deleted successfully.',
        ]);

        $this->assertDatabaseMissing('imports', ['id' => $import->id]);
    }

    public function test_delete_import_also_deletes_file()
    {
        $csvContent = "company,email,phone\nCompany,test@test.com,555-1234";
        $file = UploadedFile::fake()->createWithContent('clients.csv', $csvContent);

        $this->postJson('/api/clients/import', ['file' => $file]);

        $import = Import::first();
        $filePath = $import->file_path;

        Storage::disk('local')->assertExists($filePath);

        $this->deleteJson("/api/imports/{$import->id}");

        Storage::disk('local')->assertMissing($filePath);
    }

    public function test_requires_authentication_for_imports_list()
    {
        // Create a fresh test case without authentication
        $this->refreshApplication();

        $response = $this->getJson('/api/imports');

        $response->assertStatus(401);
    }

    public function test_requires_authentication_for_import_delete()
    {
        $import = Import::factory()->create();

        // Create a fresh test case without authentication
        $this->refreshApplication();

        $response = $this->deleteJson("/api/imports/{$import->id}");

        $response->assertStatus(401);
    }
}
