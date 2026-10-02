<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderTrackerTest extends TestCase
{
    use RefreshDatabase;

    private const JSON = ['Accept' => 'application/json'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makeAgent(string $name, bool $admin = false, ?string $role = null): array
    {
        $agentId = (string) Str::uuid();
        DB::table('agents')->insert(['id' => $agentId, 'name' => $name]);
        $user = User::create([
            'name' => $name, 'email' => Str::slug($name).'@test.invalid', 'password' => 'password',
            'is_admin' => $admin, 'agent_id' => $agentId, 'role' => $role,
        ]);

        return [$user, $agentId];
    }

    private function makeProduct(): string
    {
        $id = (string) Str::uuid();
        DB::table('products')->insert(['id' => $id, 'sku' => 'SKU-'.$id, 'name' => 'Test Product', 'warehouse_stock' => 10, 'cost_baisa' => 500]);

        return $id;
    }

    private function makeWonQuote(User $agent, string $agentId, int $unitBaisa = 1000): string
    {
        $quoteId = $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'quote',
            'quote' => [
                'agent' => $agentId, 'companyId' => (string) DB::table('companies')->value('id'), 'customer' => 'Acme', 'rate' => 0.1,
                'lines' => [['productId' => $this->makeProduct(), 'quantity' => 2, 'unitBaisa' => $unitBaisa]],
            ],
        ])->json('id');
        $this->actingAs($agent)->postJson('/api/erp', ['action' => 'quote_outcome', 'id' => $quoteId, 'outcome' => 'Won'])->assertOk();

        return $quoteId;
    }

    private function order(string $quoteId): object
    {
        return DB::table('orders')->where('quote_id', $quoteId)->first();
    }

    private function advance(User $user, string $orderId, string $stage, array $extra = [])
    {
        return $this->actingAs($user)->post("/api/orders/{$orderId}/advance", ['stage' => $stage, ...$extra], self::JSON);
    }

    private function poFile(): UploadedFile
    {
        return UploadedFile::fake()->create('po.pdf', 20, 'application/pdf');
    }

    private function walkTo(User $user, string $orderId, string $target): void
    {
        $keys = array_keys(\App\Services\OrderWorkflow::STAGES);
        foreach ($keys as $stage) {
            if ($stage === $target) {
                return;
            }
            $extra = match ($stage) {
                'sales_order' => ['file' => $this->poFile(), 'poNumber' => 'PO-77'],
                'sample_photo' => ['file' => UploadedFile::fake()->image('sample.png')],
                default => [],
            };
            $this->advance($user, $orderId, $stage, $extra)->assertOk();
        }
    }

    public function test_marking_a_quote_won_creates_one_order_at_the_sales_order_step(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $quoteId = $this->makeWonQuote($agent, $agentId);
        $this->assertSame('sales_order', $this->order($quoteId)->stage);

        $this->actingAs($agent)->postJson('/api/erp', ['action' => 'quote_outcome', 'id' => $quoteId, 'outcome' => 'Won'])->assertOk();
        $this->assertSame(1, DB::table('orders')->where('quote_id', $quoteId)->count());
    }

    public function test_a_lost_quote_does_not_create_an_order(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $quoteId = $this->makeWonQuote($agent, $agentId);
        DB::table('orders')->delete();
        $this->actingAs($agent)->postJson('/api/erp', ['action' => 'quote_outcome', 'id' => $quoteId, 'outcome' => 'Lost', 'reason' => 'Price'])->assertOk();
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_the_sales_order_step_needs_an_uploaded_po(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $order = $this->order($this->makeWonQuote($agent, $agentId));

        $this->advance($agent, $order->id, 'sales_order')->assertStatus(422);
        $this->assertSame('sales_order', DB::table('orders')->where('id', $order->id)->value('stage'));

        $this->advance($agent, $order->id, 'sales_order', [
            'file' => $this->poFile(), 'poNumber' => 'PO-123', 'poAmountBaisa' => 2100,
        ])->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'stage' => 'order_received', 'po_number' => 'PO-123', 'po_amount_baisa' => 2100]);
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order->id)->whereNotNull('file_path')->count());
    }

    public function test_advancing_from_a_stale_stage_is_rejected(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $order = $this->order($this->makeWonQuote($agent, $agentId));
        $this->walkTo($agent, $order->id, 'order_received');

        $this->advance($agent, $order->id, 'sales_order', ['file' => $this->poFile()])->assertStatus(409);
    }

    public function test_the_sample_step_only_accepts_a_photo(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $order = $this->order($this->makeWonQuote($agent, $agentId));
        $this->walkTo($agent, $order->id, 'sample_photo');

        $this->advance($agent, $order->id, 'sample_photo')->assertStatus(422);
        $this->advance($agent, $order->id, 'sample_photo', ['file' => $this->poFile()])->assertStatus(422);
        $this->advance($agent, $order->id, 'sample_photo', ['file' => UploadedFile::fake()->image('sample.png')])->assertOk();
        $this->assertSame('material_approval', DB::table('orders')->where('id', $order->id)->value('stage'));
    }

    public function test_only_the_owner_or_an_admin_can_advance_an_order(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        [$other] = $this->makeAgent('Agent Two');
        [$accounts] = $this->makeAgent('Accounts User', false, 'accounts');
        [$admin] = $this->makeAgent('Admin User', true);
        $order = $this->order($this->makeWonQuote($agent, $agentId));

        $this->advance($other, $order->id, 'sales_order', ['file' => $this->poFile()])->assertForbidden();
        $this->advance($accounts, $order->id, 'sales_order', ['file' => $this->poFile()])->assertForbidden();
        $this->advance($admin, $order->id, 'sales_order', ['file' => $this->poFile()])->assertOk();
    }

    public function test_passing_qc_prepares_a_draft_delivery_note_and_invoice(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $quoteId = $this->makeWonQuote($agent, $agentId, 5000);
        $order = $this->order($quoteId);
        $this->walkTo($agent, $order->id, 'qc');
        $this->assertSame(0, DB::table('delivery_notes')->count());

        $this->advance($agent, $order->id, 'qc')->assertOk();

        $this->assertDatabaseHas('quotes', ['id' => $quoteId, 'status' => 'Accepted']);
        $this->assertDatabaseHas('delivery_notes', ['quote_id' => $quoteId, 'status' => 'Draft', 'po_number' => 'PO-77']);
        $this->assertDatabaseHas('invoices', ['quote_id' => $quoteId, 'status' => 'Draft', 'subtotal' => 10000, 'vat_baisa' => 500, 'po_number' => 'PO-77']);
        $this->assertSame('delivery', DB::table('orders')->where('id', $order->id)->value('stage'));
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order->id)->where('actor', 'System')->count());
    }

    public function test_passing_qc_reuses_documents_that_already_exist(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $quoteId = $this->makeWonQuote($agent, $agentId);
        DB::table('quotes')->where('id', $quoteId)->update(['status' => 'Accepted']);
        $this->actingAs($agent)->postJson('/api/erp', ['action' => 'delivery_note', 'deliveryNote' => ['quoteId' => $quoteId]])->assertOk();
        $order = $this->order($quoteId);
        $this->walkTo($agent, $order->id, 'qc');

        $this->advance($agent, $order->id, 'qc')->assertOk();
        $this->assertSame(1, DB::table('delivery_notes')->where('quote_id', $quoteId)->count());
        $this->assertSame(1, DB::table('invoices')->where('quote_id', $quoteId)->count());
        $this->assertDatabaseHas('delivery_notes', ['quote_id' => $quoteId, 'po_number' => 'PO-77']);
    }

    public function test_passing_qc_adds_the_po_number_to_an_invoice_made_earlier(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $quoteId = $this->makeWonQuote($agent, $agentId);
        DB::table('quotes')->where('id', $quoteId)->update(['status' => 'Accepted']);
        $this->actingAs($agent)->postJson('/api/erp', ['action' => 'delivery_note', 'deliveryNote' => ['quoteId' => $quoteId]])->assertOk();
        $this->actingAs($agent)->postJson('/api/erp', ['action' => 'invoice', 'invoice' => ['quoteId' => $quoteId]])->assertOk();
        $order = $this->order($quoteId);
        $this->walkTo($agent, $order->id, 'qc');

        $this->advance($agent, $order->id, 'qc')->assertOk();
        $this->assertDatabaseHas('invoices', ['quote_id' => $quoteId, 'po_number' => 'PO-77']);
    }

    public function test_qc_is_blocked_while_the_quotation_is_unpriced(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $quoteId = $this->makeWonQuote($agent, $agentId, 0);
        $order = $this->order($quoteId);
        $this->walkTo($agent, $order->id, 'qc');

        $this->advance($agent, $order->id, 'qc')->assertStatus(422);
        $this->assertSame('qc', DB::table('orders')->where('id', $order->id)->value('stage'));
        $this->assertSame(0, DB::table('invoices')->count());
    }

    public function test_confirming_delivery_completes_the_order_and_marks_the_note_delivered(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $quoteId = $this->makeWonQuote($agent, $agentId);
        $order = $this->order($quoteId);
        $this->walkTo($agent, $order->id, 'delivery');

        $this->advance($agent, $order->id, 'delivery')->assertOk();
        $this->assertSame('completed', DB::table('orders')->where('id', $order->id)->value('stage'));
        $this->assertDatabaseHas('delivery_notes', ['quote_id' => $quoteId, 'status' => 'Delivered']);
        $this->assertSame(1, DB::table('invoices')->where('quote_id', $quoteId)->count());

        $this->advance($agent, $order->id, 'completed')->assertStatus(422);
    }

    public function test_a_note_can_be_added_without_moving_the_order(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $order = $this->order($this->makeWonQuote($agent, $agentId));

        $this->actingAs($agent)->post("/api/orders/{$order->id}/note", [], self::JSON)->assertStatus(422);
        $this->actingAs($agent)->post("/api/orders/{$order->id}/note", ['note' => 'Customer called'], self::JSON)->assertOk();
        $this->assertSame('sales_order', DB::table('orders')->where('id', $order->id)->value('stage'));
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'kind' => 'note', 'note' => 'Customer called']);
    }

    public function test_agents_see_their_own_orders_and_accounts_sees_all(): void
    {
        [$agentA, $agentAId] = $this->makeAgent('Agent A');
        [$agentB, $agentBId] = $this->makeAgent('Agent B');
        [$accounts] = $this->makeAgent('Accounts User', false, 'accounts');
        $this->makeWonQuote($agentA, $agentAId);
        $this->makeWonQuote($agentB, $agentBId);

        $this->assertCount(1, $this->actingAs($agentA)->getJson('/api/erp')->json('orders'));
        $this->assertCount(2, $this->actingAs($accounts)->getJson('/api/erp')->json('orders'));
    }

    public function test_uploaded_files_are_only_served_to_the_owner_accounts_or_admin(): void
    {
        [$agent, $agentId] = $this->makeAgent('Agent One');
        [$other] = $this->makeAgent('Agent Two');
        [$accounts] = $this->makeAgent('Accounts User', false, 'accounts');
        $order = $this->order($this->makeWonQuote($agent, $agentId));
        $this->advance($agent, $order->id, 'sales_order', ['file' => $this->poFile()])->assertOk();
        $eventId = DB::table('order_events')->where('order_id', $order->id)->whereNotNull('file_path')->value('id');

        $this->actingAs($agent)->get("/api/order-files/{$eventId}")->assertOk();
        $this->actingAs($accounts)->get("/api/order-files/{$eventId}")->assertOk();
        $this->actingAs($other)->get("/api/order-files/{$eventId}")->assertForbidden();
    }

    public function test_deleting_a_quote_removes_its_order_timeline_and_files(): void
    {
        [$admin] = $this->makeAgent('Admin User', true);
        [$agent, $agentId] = $this->makeAgent('Agent One');
        $quoteId = $this->makeWonQuote($agent, $agentId);
        $order = $this->order($quoteId);
        $this->advance($agent, $order->id, 'sales_order', ['file' => $this->poFile()])->assertOk();
        $path = DB::table('order_events')->where('order_id', $order->id)->value('file_path');
        Storage::disk('local')->assertExists($path);

        $this->actingAs($admin)->postJson('/api/erp', ['action' => 'quote_delete', 'id' => $quoteId])->assertOk();
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('order_events')->count());
        Storage::disk('local')->assertMissing($path);
    }
}
