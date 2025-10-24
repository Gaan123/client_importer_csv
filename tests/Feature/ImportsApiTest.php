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
