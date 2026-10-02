<?php

namespace Tests\Feature;

use App\Mail\InvoicePaymentReminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentReminderTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(string $name, ?string $role = null): array
    {
        $agentId = (string) Str::uuid();
        DB::table('agents')->insert(['id' => $agentId, 'name' => $name]);
        $user = User::create([
            'name' => $name, 'email' => Str::slug($name).'@test.invalid', 'password' => 'password',
            'is_admin' => false, 'agent_id' => $agentId, 'role' => $role,
        ]);

        return [$user, $agentId];
    }

    private function makeQuote(string $agentId): string
    {
        $id = (string) Str::uuid();
        $user = User::where('agent_id', $agentId)->first();
        $productId = (string) Str::uuid();
        DB::table('products')->insert(['id' => $productId, 'sku' => 'S-'.$productId, 'name' => 'P', 'warehouse_stock' => 5, 'cost_baisa' => 100]);

        return $this->actingAs($user)->postJson('/api/erp', [
            'action' => 'quote',
            'quote' => ['agent' => $agentId, 'companyId' => (string) DB::table('companies')->value('id'), 'customer' => 'Acme', 'rate' => 0.1,
                'lines' => [['productId' => $productId, 'quantity' => 1, 'unitBaisa' => 1000]]],
        ])->json('id');
    }

    private function makeInvoice(string $agentId, ?string $dueDate, string $status = 'Sent', string $email = 'client@test.invalid'): string
    {
        $id = (string) Str::uuid();
        DB::table('invoices')->insert([
            'id' => $id, 'quote_id' => $this->makeQuote($agentId), 'customer_id' => null, 'agent' => $agentId,
            'company_id' => (string) DB::table('companies')->value('id'), 'customer' => 'Acme', 'email' => $email,
            'lines' => '[]', 'subtotal' => 1000, 'vat_baisa' => 50, 'total' => 1050,
            'status' => $status, 'notes' => '', 'due_date' => $dueDate,
            'created' => now()->toIso8601String(), 'updated' => now()->toIso8601String(),
        ]);

        return $id;
    }

    private function today(int $plusDays = 0): string
    {
        return now(config('erp.timezone'))->addDays($plusDays)->toDateString();
    }

    private function remind(): void
    {
        $this->artisan('erp:send-payment-reminders', ['--allow-log' => true])->assertSuccessful();
    }

    public function test_reminds_seven_days_then_one_day_before_due_and_never_twice(): void
    {
        Mail::fake();
        [, $agentId] = $this->makeAgent('Sam');
        $id = $this->makeInvoice($agentId, $this->today(7));

        $this->remind();
        $this->remind();
        Mail::assertSent(InvoicePaymentReminder::class, 1);
        $this->assertSame([7], DB::table('invoice_reminders')->where('invoice_id', $id)->pluck('days_before')->all());

        DB::table('invoices')->where('id', $id)->update(['due_date' => $this->today(1)]);
        $this->remind();
        $this->remind();
        Mail::assertSent(InvoicePaymentReminder::class, 2);
        $this->assertSame([1, 7], DB::table('invoice_reminders')->where('invoice_id', $id)->orderBy('days_before')->pluck('days_before')->all());
    }

    public function test_nothing_sent_too_early_overdue_unsent_or_without_email(): void
    {
        Mail::fake();
        [, $agentId] = $this->makeAgent('Sam');
        $this->makeInvoice($agentId, $this->today(10));
        $this->makeInvoice($agentId, $this->today(-1));
        $this->makeInvoice($agentId, $this->today(3), 'Draft');
        $this->makeInvoice($agentId, $this->today(3), 'Paid');
        $this->makeInvoice($agentId, $this->today(3), 'Sent', '');
        $this->makeInvoice($agentId, null);

        $this->remind();
        Mail::assertNothingSent();
    }

    public function test_late_marked_sent_invoice_gets_the_closest_missed_window_once(): void
    {
        Mail::fake();
        [, $agentId] = $this->makeAgent('Sam');
        $id = $this->makeInvoice($agentId, $this->today(3));

        $this->remind();
        $this->remind();
        Mail::assertSent(InvoicePaymentReminder::class, 1);
        $this->assertSame([7], DB::table('invoice_reminders')->where('invoice_id', $id)->pluck('days_before')->all());
    }

    public function test_refuses_to_run_when_mail_is_not_configured(): void
    {
        $this->artisan('erp:send-payment-reminders')->assertFailed();
    }

    public function test_invoice_update_saves_due_date_and_email(): void
    {
        [$agent, $agentId] = $this->makeAgent('Sam');
        $id = $this->makeInvoice($agentId, null, 'Draft', '');

        $this->actingAs($agent)->postJson('/api/erp', [
            'action' => 'invoice_update', 'id' => $id, 'poNumber' => 'PO-1', 'dueDate' => '2026-12-01', 'email' => 'ap@client.test',
        ])->assertOk();

        $row = DB::table('invoices')->where('id', $id)->first();
        $this->assertSame('ap@client.test', $row->email);
        $this->assertStringStartsWith('2026-12-01', $row->due_date);
        $this->assertSame('PO-1', $row->po_number);

        $this->actingAs($agent)->postJson('/api/erp', ['action' => 'invoice_update', 'id' => $id, 'email' => 'not-an-email'])->assertStatus(422);
    }

    public function test_new_invoices_default_to_thirty_day_terms(): void
    {
        [$agent, $agentId] = $this->makeAgent('Sam');
        $quoteId = $this->makeQuote($agentId);
        DB::table('quotes')->where('id', $quoteId)->update(['status' => 'Accepted']);
        $this->actingAs($agent)->postJson('/api/erp', ['action' => 'delivery_note', 'deliveryNote' => ['quoteId' => $quoteId]])->assertOk();
        $this->actingAs($agent)->postJson('/api/erp', ['action' => 'invoice', 'invoice' => ['quoteId' => $quoteId]])->assertOk();

        $due = DB::table('invoices')->where('quote_id', $quoteId)->value('due_date');
        $this->assertStringStartsWith($this->today(30), (string) $due);
    }

    public function test_reminder_email_renders_with_and_without_agent_and_po(): void
    {
        $invoice = (object) ['number' => 12, 'total' => 1050000, 'customer' => 'Acme LLC', 'po_number' => null];
        $html = (new InvoicePaymentReminder($invoice, 'Mugdi', 1, '9 Oct 2026', null, null))->render();
        $this->assertStringContainsString('INV-0012', $html);
        $this->assertStringContainsString('tomorrow', $html);
        $this->assertStringContainsString('1,050.000', $html);
        $this->assertStringNotContainsString('Your PO', $html);
    }
}
