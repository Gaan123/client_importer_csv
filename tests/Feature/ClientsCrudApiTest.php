<?php

namespace Tests\Feature;

use App\Models\Clients;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientsCrudApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_can_list_all_clients()
    {
        Clients::factory()->count(5)->create();

        $response = $this->getJson('/api/clients');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'company',
                    'email',
                    'phone',
                    'has_duplicates',
                    'extras',
                    'created_at',
                    'updated_at',
                ]
            ],
            'links',
            'meta',
        ]);
        $response->assertJsonCount(5, 'data');
    }

    public function test_can_paginate_clients()
    {
        Clients::factory()->count(20)->create();

        $response = $this->getJson('/api/clients?per_page=10');

        $response->assertStatus(200);
        $response->assertJsonCount(10, 'data');
        $response->assertJsonPath('meta.per_page', 10);
        $response->assertJsonPath('meta.total', 20);
    }

    public function test_can_search_clients_by_company()
    {
        Clients::factory()->create(['company' => 'Acme Corporation']);
        Clients::factory()->create(['company' => 'Other Company']);

        $response = $this->getJson('/api/clients?search=Acme');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
        $this->assertStringContainsString('Acme', $response->json('data.0.company'));
    }

    public function test_can_search_clients_by_email()
    {
        Clients::factory()->create(['email' => 'test@acme.com']);
        Clients::factory()->create(['email' => 'other@company.com']);

        $response = $this->getJson('/api/clients?search=acme');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    public function test_can_filter_clients_by_has_duplicates()
    {
        Clients::factory()->create(['has_duplicates' => true]);
        Clients::factory()->count(2)->create(['has_duplicates' => false]);

        $response = $this->getJson('/api/clients?has_duplicates=true');

        $response->assertStatus(200);
        $clients = $response->json('data');
        foreach ($clients as $client) {
            $this->assertTrue($client['has_duplicates']);
        }
    }

    public function test_can_create_client()
    {
        $clientData = [
            'company' => 'Test Company',
            'email' => 'test@example.com',
            'phone' => '+1-555-1234',
        ];

        $response = $this->postJson('/api/clients', $clientData);

        $response->assertStatus(201);
        $response->assertJson([
            'message' => 'Client created successfully.',
            'data' => [
                'company' => 'Test Company',
                'email' => 'test@example.com',
                'phone' => '+1-555-1234',
            ]
        ]);

        $this->assertDatabaseHas('clients', $clientData);
    }

    public function test_create_client_validates_required_fields()
    {
        $response = $this->postJson('/api/clients', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['company', 'email', 'phone']);
    }

    public function test_create_client_validates_email_format()
    {
        $response = $this->postJson('/api/clients', [
            'company' => 'Test Company',
            'email' => 'invalid-email',
            'phone' => '+1-555-1234',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_create_client_rejects_duplicate()
    {
        $clientData = [
            'company' => 'Test Company',
            'email' => 'test@example.com',
            'phone' => '+1-555-1234',
        ];

        Clients::create($clientData + ['has_duplicates' => false]);

        $response = $this->postJson('/api/clients', $clientData);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Client already exists.',
        ]);
    }

    public function test_can_show_single_client()
    {
        $client = Clients::factory()->create([
            'company' => 'Test Company',
            'email' => 'test@example.com',
        ]);

        $response = $this->getJson("/api/clients/{$client->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'id' => $client->id,
                'company' => 'Test Company',
                'email' => 'test@example.com',
            ]
        ]);
    }

    public function test_show_returns_404_for_nonexistent_client()
    {
        $response = $this->getJson('/api/clients/99999');

        $response->assertStatus(404);
    }

    public function test_can_update_client()
    {
        $client = Clients::factory()->create([
            'company' => 'Old Company',
            'email' => 'old@example.com',
        ]);

        $response = $this->putJson("/api/clients/{$client->id}", [
            'company' => 'New Company',
            'email' => 'new@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Client updated successfully.',
            'data' => [
                'company' => 'New Company',
                'email' => 'new@example.com',
            ]
        ]);

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'company' => 'New Company',
            'email' => 'new@example.com',
        ]);
    }

    public function test_can_partially_update_client_with_patch()
    {
        $client = Clients::factory()->create([
            'company' => 'Old Company',
            'email' => 'old@example.com',
            'phone' => '+1-555-1234',
        ]);

        $response = $this->patchJson("/api/clients/{$client->id}", [
            'company' => 'Updated Company',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'company' => 'Updated Company',
                'email' => 'old@example.com',
                'phone' => '+1-555-1234',
            ]
        ]);
    }

    public function test_update_validates_email_format()
    {
        $client = Clients::factory()->create();

        $response = $this->putJson("/api/clients/{$client->id}", [
            'email' => 'invalid-email',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_update_rejects_duplicate()
    {
        $client1 = Clients::factory()->create([
            'company' => 'Company 1',
            'email' => 'test1@example.com',
            'phone' => '+1-555-1111',
        ]);

        $client2 = Clients::factory()->create([
            'company' => 'Company 2',
            'email' => 'test2@example.com',
            'phone' => '+1-555-2222',
        ]);

        $response = $this->putJson("/api/clients/{$client2->id}", [
            'company' => 'Company 1',
            'email' => 'test1@example.com',
            'phone' => '+1-555-1111',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Client already exists.',
        ]);
    }

    public function test_can_delete_client()
    {
        $client = Clients::factory()->create();

        $response = $this->deleteJson("/api/clients/{$client->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Client deleted successfully.',
        ]);

        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    public function test_delete_returns_404_for_nonexistent_client()
    {
        $response = $this->deleteJson('/api/clients/99999');

        $response->assertStatus(404);
    }

    public function test_requires_authentication_for_list()
    {
        $this->refreshApplication();

        $response = $this->getJson('/api/clients');

        $response->assertStatus(401);
    }

    public function test_requires_authentication_for_create()
    {
        $this->refreshApplication();

        $response = $this->postJson('/api/clients', [
            'company' => 'Test Company',
            'email' => 'test@example.com',
            'phone' => '+1-555-1234',
        ]);

        $response->assertStatus(401);
    }

    public function test_requires_authentication_for_show()
    {
        $client = Clients::factory()->create();

        $this->refreshApplication();

        $response = $this->getJson("/api/clients/{$client->id}");

        $response->assertStatus(401);
    }

    public function test_requires_authentication_for_update()
    {
        $client = Clients::factory()->create();

        $this->refreshApplication();

        $response = $this->putJson("/api/clients/{$client->id}", [
            'company' => 'Updated Company',
        ]);

        $response->assertStatus(401);
    }

    public function test_requires_authentication_for_delete()
    {
        $client = Clients::factory()->create();

        $this->refreshApplication();

        $response = $this->deleteJson("/api/clients/{$client->id}");

        $response->assertStatus(401);
    }

    public function test_can_get_duplicates_for_client()
    {
        $client2 = Clients::factory()->create([
            'company' => 'Acme Corp',
            'email' => 'other@company.com',
            'phone' => '+1-555-9999',
        ]);

        $client3 = Clients::factory()->create([
            'company' => 'Acme Corp',
            'email' => 'another@company.com',
            'phone' => '+1-555-8888',
        ]);

        $client4 = Clients::factory()->create([
            'company' => 'Other Corp',
            'email' => 'test@acme.com',
            'phone' => '+1-555-7777',
        ]);

        $client1 = Clients::factory()->create([
            'company' => 'Acme Corp',
            'email' => 'test@acme.com',
            'phone' => '+1-555-1234',
            'has_duplicates' => true,
            'extras' => [
                'duplicate_ids' => [
                    'company' => [$client2->id, $client3->id],
                    'email' => [$client4->id],
                ]
            ],
        ]);

        $response = $this->getJson("/api/clients/{$client1->id}/duplicates");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'original_client' => ['id', 'company', 'email', 'phone', 'has_duplicates', 'extras'],
            'data' => [
                '*' => ['id', 'company', 'email', 'phone', 'has_duplicates', 'extras']
            ],
        ]);

        $response->assertJsonPath('original_client.id', $client1->id);
        $response->assertJsonCount(3, 'data');

        $duplicateIds = array_column($response->json('data'), 'id');
        $this->assertContains($client2->id, $duplicateIds);
        $this->assertContains($client3->id, $duplicateIds);
        $this->assertContains($client4->id, $duplicateIds);
    }

    public function test_duplicates_endpoint_returns_empty_for_client_without_duplicates()
    {
        $client = Clients::factory()->create([
            'company' => 'Unique Corp',
            'email' => 'unique@company.com',
            'phone' => '+1-555-0000',
            'has_duplicates' => false,
            'extras' => null,
        ]);

        $response = $this->getJson("/api/clients/{$client->id}/duplicates");

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'This client has no duplicates.',
            'data' => []
        ]);
    }

    public function test_duplicate_detection_finds_company_duplicates()
    {
        $client1 = Clients::factory()->create([
            'company' => 'Test Company',
            'email' => 'test1@example.com',
            'phone' => '+1-555-1111',
        ]);

        $client2 = Clients::factory()->create([
            'company' => 'Test Company',
            'email' => 'test2@example.com',
            'phone' => '+1-555-2222',
        ]);

        $job = new \App\Jobs\DetectSingleClientDuplicate($client1->id);
        $job->handle();

        $client1->refresh();

        $this->assertTrue($client1->has_duplicates);
        $this->assertNotNull($client1->extras);
        $this->assertArrayHasKey('duplicate_ids', $client1->extras);
        $this->assertArrayHasKey('company', $client1->extras['duplicate_ids']);
        $this->assertContains($client2->id, $client1->extras['duplicate_ids']['company']);
    }

    public function test_duplicate_detection_finds_email_duplicates()
    {
        $client1 = Clients::factory()->create([
            'company' => 'Company A',
            'email' => 'shared@example.com',
            'phone' => '+1-555-1111',
        ]);

        $client2 = Clients::factory()->create([
            'company' => 'Company B',
            'email' => 'shared@example.com',
            'phone' => '+1-555-2222',
        ]);

        $job = new \App\Jobs\DetectSingleClientDuplicate($client1->id);
        $job->handle();

        $client1->refresh();

        $this->assertTrue($client1->has_duplicates);
        $this->assertArrayHasKey('email', $client1->extras['duplicate_ids']);
        $this->assertContains($client2->id, $client1->extras['duplicate_ids']['email']);
    }

    public function test_duplicate_detection_finds_phone_duplicates()
    {
        $client1 = Clients::factory()->create([
            'company' => 'Company A',
            'email' => 'test1@example.com',
            'phone' => '+1-555-9999',
        ]);

        $client2 = Clients::factory()->create([
            'company' => 'Company B',
            'email' => 'test2@example.com',
            'phone' => '+1-555-9999',
        ]);

        $job = new \App\Jobs\DetectSingleClientDuplicate($client1->id);
        $job->handle();

        $client1->refresh();

        $this->assertTrue($client1->has_duplicates);
        $this->assertArrayHasKey('phone', $client1->extras['duplicate_ids']);
        $this->assertContains($client2->id, $client1->extras['duplicate_ids']['phone']);
    }

    public function test_duplicate_detection_updates_related_clients()
    {
        $client1 = Clients::factory()->create([
            'company' => 'Shared Company',
            'email' => 'test1@example.com',
            'phone' => '+1-555-1111',
        ]);

        $client2 = Clients::factory()->create([
            'company' => 'Shared Company',
            'email' => 'test2@example.com',
            'phone' => '+1-555-2222',
        ]);

        $job = new \App\Jobs\DetectSingleClientDuplicate($client1->id);
        $job->handle();

        $client2->refresh();

        $this->assertTrue($client2->has_duplicates);
        $this->assertNotNull($client2->extras);
        $this->assertArrayHasKey('duplicate_ids', $client2->extras);
        $this->assertArrayHasKey('company', $client2->extras['duplicate_ids']);
        $this->assertContains($client1->id, $client2->extras['duplicate_ids']['company']);
    }

    public function test_duplicate_detection_handles_no_duplicates()
    {
        $client = Clients::factory()->create([
            'company' => 'Unique Company',
            'email' => 'unique@example.com',
            'phone' => '+1-555-0000',
        ]);

        $job = new \App\Jobs\DetectSingleClientDuplicate($client->id);
        $job->handle();

        $client->refresh();

        $this->assertFalse($client->has_duplicates);
        $this->assertNull($client->extras);
    }
}
