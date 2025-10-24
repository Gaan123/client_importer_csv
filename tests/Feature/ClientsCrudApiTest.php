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
}
