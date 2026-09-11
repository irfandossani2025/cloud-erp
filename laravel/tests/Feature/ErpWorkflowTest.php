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

    private function makeAcceptedQuote(User $agent, string $agentId, string $productId, int $unitBaisa = 1000, int $quantity = 2): string
    {
        $id = $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'quote',
            'quote' => [
                'agent' => $agentId, 'customer' => 'Acme', 'rate' => 0.1,
                'lines' => [['productId' => $productId, 'quantity' => $quantity, 'unitBaisa' => $unitBaisa]],
            ],
        ])->json('id');
        DB::table('quotes')->where('id', $id)->update(['status' => 'Accepted']);

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

    public function test_agent_can_create_and_update_their_own_customer(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $id = $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'customer',
            'customer' => [
                'agent' => $agentId, 'company' => 'Acme LLC', 'contactName' => 'Jane Doe',
                'stage' => 'New Lead',
            ],
        ])->assertOk()->json('id');
        $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'customer',
            'customer' => [
                'id' => $id, 'agent' => $agentId, 'company' => 'Acme LLC', 'contactName' => 'Jane Doe',
                'stage' => 'Qualified',
            ],
        ])->assertOk();
        $this->assertDatabaseHas('customers', ['id' => $id, 'stage' => 'Qualified']);
    }

    public function test_agent_cannot_edit_another_agents_customer(): void
    {
        [$agentA, $agentAId] = $this->makeAgent('Agent A');
        [$agentB] = $this->makeAgent('Agent B');
        $id = $this->actingAs($agentA)->postJson('/api/erp', [
            'action' => 'customer',
            'customer' => ['agent' => $agentAId, 'company' => 'Acme LLC', 'contactName' => 'Jane Doe', 'stage' => 'New Lead'],
        ])->json('id');
        $this->actingAs($agentB)->postJson('/api/erp', [
            'action' => 'customer',
            'customer' => ['id' => $id, 'agent' => $agentAId, 'company' => 'Acme LLC', 'contactName' => 'Jane Doe', 'stage' => 'Won'],
        ])->assertForbidden();
    }

    public function test_customer_activity_can_be_logged_and_appears_in_index(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $customerId = $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'customer',
            'customer' => ['agent' => $agentId, 'company' => 'Acme LLC', 'contactName' => 'Jane Doe', 'stage' => 'New Lead'],
        ])->json('id');
        $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'customer_activity',
            'activity' => ['customerId' => $customerId, 'type' => 'call', 'notes' => 'Discussed pricing'],
        ])->assertOk();
        $view = $this->actingAs($agent)->getJson('/api/erp')->json();
        $this->assertCount(1, $view['customerActivities']);
        $this->assertSame('Discussed pricing', $view['customerActivities'][0]['notes']);
    }

    public function test_delivery_note_requires_an_accepted_quote(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $productId = $this->makeProduct();
        $draftId = $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'quote',
            'quote' => ['agent' => $agentId, 'customer' => 'Acme', 'rate' => 0.1, 'lines' => [['productId' => $productId, 'quantity' => 1, 'unitBaisa' => 1000]]],
        ])->json('id');
        $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'delivery_note',
            'deliveryNote' => ['quoteId' => $draftId],
        ])->assertStatus(422);
    }

    public function test_delivery_note_and_invoice_can_be_created_from_an_accepted_quote(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $productId = $this->makeProduct();
        $quoteId = $this->makeAcceptedQuote($agent, $agentId, $productId, unitBaisa: 1000, quantity: 2);

        $dnId = $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'delivery_note',
            'deliveryNote' => ['quoteId' => $quoteId, 'address' => 'Muscat'],
        ])->assertOk()->json('id');
        $this->assertDatabaseHas('delivery_notes', ['id' => $dnId, 'quote_id' => $quoteId, 'status' => 'Draft']);

        $invId = $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'invoice',
            'invoice' => ['quoteId' => $quoteId],
        ])->assertOk()->json('id');
        // total = 2000 baisa, 5% VAT = 100 baisa, total = 2100 baisa
        $this->assertDatabaseHas('invoices', ['id' => $invId, 'subtotal' => 2000, 'vat_baisa' => 100, 'total' => 2100]);
    }

    public function test_agent_cannot_invoice_another_agents_quote(): void
    {
        [$agentA, $agentAId] = $this->makeAgent('Agent A');
        [$agentB] = $this->makeAgent('Agent B');
        $productId = $this->makeProduct();
        $quoteId = $this->makeAcceptedQuote($agentA, $agentAId, $productId);
        $this->actingAs($agentB)->postJson('/api/erp', [
            'action' => 'invoice',
            'invoice' => ['quoteId' => $quoteId],
        ])->assertForbidden();
    }

    public function test_invoice_status_can_be_updated_by_owning_agent(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $productId = $this->makeProduct();
        $quoteId = $this->makeAcceptedQuote($agent, $agentId, $productId);
        $invId = $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'invoice',
            'invoice' => ['quoteId' => $quoteId],
        ])->json('id');
        $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'invoice_status', 'id' => $invId, 'status' => 'Paid',
        ])->assertOk();
        $this->assertDatabaseHas('invoices', ['id' => $invId, 'status' => 'Paid']);
    }
}
