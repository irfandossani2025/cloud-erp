<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ErpWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(string $name, bool $admin = false): array
    {
        $agentId = (string) Str::uuid();
        DB::table('agents')->insert(['id' => $agentId, 'name' => $name]);
        $user = User::create([
            'name' => $name,
            'email' => Str::slug($name).'@test.invalid',
            'password' => 'password',
            'is_admin' => $admin,
            'agent_id' => $agentId,
        ]);

        return [$user, $agentId];
    }

    private function makeProduct(): string
    {
        $id = (string) Str::uuid();
        DB::table('products')->insert([
            'id' => $id,
            'sku' => 'SKU-'.$id,
            'name' => 'Test Product',
            'warehouse_stock' => 10,
            'cost_baisa' => 500,
        ]);

        return $id;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_admin_can_reach_workspace(): void
    {
        [$admin] = $this->makeAgent('Admin User', true);
        $this->actingAs($admin)->get('/')->assertOk();
    }

    public function test_agent_can_save_and_read_back_a_quote_total(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $productId = $this->makeProduct();
        $response = $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'quote',
            'quote' => [
                'agent' => $agentId,
                'customer' => 'Acme',
                'rate' => 0.1,
                'lines' => [['productId' => $productId, 'quantity' => 2, 'unitBaisa' => 1000]],
            ],
        ])->assertOk();
        $this->assertDatabaseHas('quotes', ['id' => $response->json('id'), 'total' => 2000]);
    }

    public function test_agent_cannot_create_a_quote_for_another_agent(): void
    {
        [$agentA] = $this->makeAgent('Agent A');
        [, $agentBId] = $this->makeAgent('Agent B');
        $productId = $this->makeProduct();
        $this->actingAs($agentA)->postJson('/api/erp', [
            'action' => 'quote',
            'quote' => [
                'agent' => $agentBId,
                'customer' => 'Acme',
                'rate' => 0.1,
                'lines' => [['productId' => $productId, 'quantity' => 1, 'unitBaisa' => 1000]],
            ],
        ])->assertForbidden();
    }

    public function test_non_admin_cannot_set_nonzero_warehouse_stock_on_a_new_product(): void
    {
        [$agent] = $this->makeAgent('Agent One');
        $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'product',
            'product' => ['name' => 'Mug', 'sku' => 'MUG-1', 'warehouseStock' => 5, 'costBaisa' => 100],
        ])->assertForbidden();
    }

    public function test_non_admin_cannot_sync_supplier_or_change_settings(): void
    {
        [$agent] = $this->makeAgent('Agent One');
        $this->actingAs($agent)->postJson('/api/erp', ['action' => 'sync'])->assertForbidden();
        $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'settings', 'company' => 'X', 'rate' => 0.1,
        ])->assertForbidden();
    }

    public function test_editing_a_quote_with_a_stale_revision_is_rejected(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $productId = $this->makeProduct();
        $id = $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'quote',
            'quote' => [
                'agent' => $agentId, 'customer' => 'Acme', 'rate' => 0.1,
                'lines' => [['productId' => $productId, 'quantity' => 1, 'unitBaisa' => 1000]],
            ],
        ])->json('id');
        $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'quote',
            'quote' => [
                'id' => $id, 'revision' => 1, 'agent' => $agentId, 'customer' => 'Acme', 'rate' => 0.1,
                'lines' => [['productId' => $productId, 'quantity' => 2, 'unitBaisa' => 1000]],
            ],
        ])->assertOk();
        $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'quote',
            'quote' => [
                'id' => $id, 'revision' => 1, 'agent' => $agentId, 'customer' => 'Acme', 'rate' => 0.1,
                'lines' => [['productId' => $productId, 'quantity' => 3, 'unitBaisa' => 1000]],
            ],
        ])->assertStatus(409);
    }

    public function test_non_admin_does_not_receive_cost_data(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        [$admin] = $this->makeAgent('Admin User', true);
        $productId = $this->makeProduct();
        DB::table('products')->where('id', $productId)->update(['supplier_aed' => 200]);
        $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'quote',
            'quote' => [
                'agent' => $agentId, 'customer' => 'Acme', 'rate' => 0.1,
                'lines' => [['productId' => $productId, 'quantity' => 1, 'unitBaisa' => 1000]],
            ],
        ])->assertOk();

        $agentView = $this->actingAs($agent)->getJson('/api/erp')->json();
        $this->assertArrayNotHasKey('cost_baisa', $agentView['products'][0]);
        $this->assertArrayNotHasKey('supplier_aed', $agentView['products'][0]);
        $this->assertArrayNotHasKey('costBaisa', $agentView['quotes'][0]['lines'][0]);

        $adminView = $this->actingAs($admin)->getJson('/api/erp')->json();
        $this->assertArrayHasKey('cost_baisa', $adminView['products'][0]);
        $this->assertArrayHasKey('supplier_aed', $adminView['products'][0]);
        $this->assertArrayHasKey('costBaisa', $adminView['quotes'][0]['lines'][0]);
    }

    public function test_non_admin_cannot_set_nonzero_cost_on_a_new_product(): void
    {
        [$agent] = $this->makeAgent('Agent One');
        $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'product',
            'product' => ['name' => 'Mug', 'sku' => 'MUG-2', 'warehouseStock' => 0, 'costBaisa' => 100],
        ])->assertForbidden();
    }

    public function test_agent_creation_with_email_grants_a_working_login(): void
    {
        [$admin] = $this->makeAgent('Admin User', true);
        $this->actingAs($admin)->postJson('/api/erp', [
            'action' => 'agent',
            'name' => 'New Agent',
            'email' => 'new.agent@test.invalid',
            'password' => 'a-strong-password',
        ])->assertOk();
        $this->assertDatabaseHas('users', ['email' => 'new.agent@test.invalid', 'is_admin' => false]);
    }
}
