<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ErpAccess;
use App\Services\SupplierCatalogue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ErpController extends Controller
{
    public function __construct(private ErpAccess $access) {}

    public function index(Request $request)
    {
        $isAdmin = (bool) $request->user()->is_admin;
        $role = $request->user()->role;
        $agentId = $request->user()->agent_id;
        $seesAllQuotes = $isAdmin || $role === 'pricing';
        $canSeeCost = $isAdmin || $role === 'pricing';
        $seesAllInvoices = $isAdmin || $role === 'accounts';

        $agents = DB::table('agents')->orderBy('name');
        $quotes = DB::table('quotes')->orderByDesc('number');
        $customers = DB::table('customers')->orderByDesc('updated');
        $activities = DB::table('customer_activities')->orderByDesc('created');
        $deliveryNotes = DB::table('delivery_notes')->orderByDesc('number');
        $invoices = DB::table('invoices')->orderByDesc('number');
        $salesGoals = DB::table('sales_goals');
        if (!$isAdmin) {
            $agents->where('id', $agentId);
            $customers->where('agent', $agentId);
            $activities->where('agent', $agentId);
            $deliveryNotes->where('agent', $agentId);
            $salesGoals->where('agent_id', $agentId);
        }
        if (!$seesAllQuotes) {
            $quotes->where('agent', $agentId);
        }
        if (!$seesAllInvoices) {
            $invoices->where('agent', $agentId);
        }

        $settings = DB::table('settings')->where('id', 1)->first();
        $companies = DB::table('companies')->orderBy('name')->get();
        $products = DB::table('products')->orderBy('name')->get();
        if (!$canSeeCost) {
            $products = $products->map(function ($p) {
                unset($p->cost_baisa, $p->supplier_aed);
                return $p;
            });
        }

        return response()->json([
            'products' => $products,
            'quotes' => $quotes->get()->map(function ($q) use ($canSeeCost) {
                $lines = json_decode($q->lines, true);
                if (!$canSeeCost) {
                    $lines = array_map(function ($l) {
                        unset($l['costBaisa']);
                        return $l;
                    }, $lines);
                }
                return [...(array) $q, 'rate' => (float) $q->rate, 'lines' => $lines];
            }),
            'agents' => $agents->get(),
            'companies' => $companies,
            'customers' => $customers->get(),
            'customerActivities' => $activities->get(),
            'deliveryNotes' => $deliveryNotes->get()->map(fn ($d) => [...(array) $d, 'lines' => json_decode($d->lines, true)]),
            'invoices' => $invoices->get()->map(fn ($i) => [...(array) $i, 'lines' => json_decode($i->lines, true)]),
            'salesGoals' => $salesGoals->get(),
            'settings' => $settings ? [...(array) $settings, 'rate' => (float) $settings->rate] : ['rate' => config('erp.default_rate'), 'company' => 'Cloud ERP', 'vat_number' => null, 'updated' => null],
            'vatRate' => config('erp.vat_rate'),
            'supplierConfigured' => (bool) (config('erp.supplier_username') && config('erp.supplier_password')),
            'aiConfigured' => (bool) config('erp.gemini_key'),
            'isAdmin' => $isAdmin,
            'userRole' => $role,
            'userName' => $request->user()->name,
            'userAgentId' => $agentId,
        ]);
    }

    public function store(Request $request, SupplierCatalogue $supplier)
    {
        $action = $request->validate([
            'action' => 'required|in:product,stock,agent,settings,sync,quote,quote_price,quote_unlock_price,status,quote_outcome,customer,customer_activity,delivery_note,delivery_note_status,invoice,invoice_status,invoice_payment,company_update,sales_goal',
        ])['action'];
        if (in_array($action, ['stock', 'agent', 'settings', 'sync'])) $this->access->admin($request);
        if ($action === 'sync') return response()->json(['count' => $supplier->sync()]);
        if ($action === 'product') {
            $v = $request->validate([
                'product.name' => 'required|string|max:200', 'product.sku' => 'required|string|max:191|unique:products,sku',
                'product.description' => 'nullable|string|max:5000', 'product.category' => 'nullable|string|max:1000',
                'product.image' => 'nullable|url:https|max:2000', 'product.warehouseStock' => 'required|integer|min:0|max:1000000',
                'product.saleBaisa' => 'nullable|integer|min:0|max:1000000000', 'product.costBaisa' => 'required|integer|min:0|max:1000000000',
            ])['product'];
            abort_if(!$request->user()->is_admin && $v['warehouseStock'] !== 0, 403, 'Only an administrator can set warehouse quantities.');
            abort_if(!$request->user()->is_admin && $v['costBaisa'] !== 0, 403, 'Only an administrator can set product cost.');
            $id = (string) Str::uuid();
            DB::table('products')->insert(['id' => $id, 'name' => $v['name'], 'sku' => $v['sku'], 'description' => $v['description'] ?? '', 'category' => $v['category'] ?? '', 'image' => $v['image'] ?? '', 'warehouse_stock' => $v['warehouseStock'], 'sale_baisa' => $v['saleBaisa'] ?? null, 'cost_baisa' => $v['costBaisa']]);
            return response()->json(['id' => $id]);
        }
        if ($action === 'stock') {
            $v = $request->validate(['id' => 'required|uuid|exists:products,id', 'warehouseStock' => 'required|integer|min:0|max:1000000', 'saleBaisa' => 'nullable|integer|min:0|max:1000000000']);
            DB::table('products')->where('id', $v['id'])->update(['warehouse_stock' => $v['warehouseStock'], 'sale_baisa' => $v['saleBaisa'] ?? null]);
        }
        if ($action === 'agent') {
            $v = $request->validate([
                'name' => 'required|string|max:200',
                'email' => 'nullable|email|max:254|unique:users,email',
                'password' => 'nullable|string|min:8|max:72',
                'role' => 'nullable|in:pricing,accounts',
            ]);
            abort_if(!empty($v['email']) && empty($v['password']), 422, 'Set a sign-in password for this agent.');
            abort_if(empty($v['email']) && !empty($v['password']), 422, 'Enter a sign-in email for this agent.');
            abort_if(!empty($v['role']) && empty($v['email']), 422, 'A role requires a sign-in email.');
            $id = DB::transaction(function () use ($v) {
                $id = (string) Str::uuid();
                DB::table('agents')->insert(['id' => $id, 'name' => $v['name']]);
                if (!empty($v['email'])) {
                    User::create(['name' => $v['name'], 'email' => $v['email'], 'password' => $v['password'], 'is_admin' => false, 'agent_id' => $id, 'role' => $v['role'] ?? null]);
                }
                return $id;
            });
            return response()->json(['id' => $id]);
        }
        if ($action === 'settings') {
            $v = $request->validate(['rate' => 'required|numeric|gt:0|max:100', 'company' => 'required|string|max:200', 'vatNumber' => 'nullable|string|max:50']);
            DB::table('settings')->updateOrInsert(['id' => 1], ['rate' => $v['rate'], 'company' => $v['company'], 'vat_number' => $v['vatNumber'] ?? null, 'updated' => now()->toIso8601String()]);
        }
        if ($action === 'company_update') {
            $this->access->admin($request);
            $v = $request->validate(['id' => 'required|uuid|exists:companies,id', 'vatNumber' => 'nullable|string|max:50']);
            DB::table('companies')->where('id', $v['id'])->update(['vat_number' => $v['vatNumber'] ?? null]);
        }
        if ($action === 'sales_goal') {
            $v = $request->validate([
                'agentId' => 'required|uuid|exists:agents,id',
                'period' => ['required', 'regex:/^\d{4}-\d{2}$/'],
                'targetBaisa' => 'required|integer|min:0|max:1000000000000',
            ]);
            $this->access->agent($request, $v['agentId']);
            $existing = DB::table('sales_goals')->where('agent_id', $v['agentId'])->where('period', $v['period'])->first();
            if ($existing) {
                DB::table('sales_goals')->where('id', $existing->id)->update(['target_baisa' => $v['targetBaisa'], 'updated' => now()->toIso8601String()]);
            } else {
                DB::table('sales_goals')->insert([
                    'id' => (string) Str::uuid(), 'agent_id' => $v['agentId'], 'period' => $v['period'],
                    'target_baisa' => $v['targetBaisa'], 'created' => now()->toIso8601String(), 'updated' => now()->toIso8601String(),
                ]);
            }
        }
        if ($action === 'quote') return $this->quote($request);
        if ($action === 'quote_price') return $this->quotePrice($request);
        if ($action === 'quote_unlock_price') {
            $this->access->admin($request);
            $v = $request->validate(['id' => 'required|uuid|exists:quotes,id']);
            DB::table('quotes')->where('id', $v['id'])->update(['price_unlocked_by_admin' => true, 'updated' => now()->toIso8601String()]);
        }
        if ($action === 'status') {
            $v = $request->validate(['id' => 'required|uuid|exists:quotes,id', 'revision' => 'required|integer|min:1', 'status' => 'required|in:Draft,Reviewed,Accepted,Declined']);
            $q = DB::table('quotes')->where('id', $v['id'])->first();
            $this->access->agent($request, $q->agent);
            abort_if(in_array($v['status'], ['Reviewed', 'Accepted'], true) && $q->pricing_status !== 'Priced', 422, 'This quotation is still awaiting pricing.');
            $changed = DB::table('quotes')->where('id', $v['id'])->where('revision', $v['revision'])->update(['status' => $v['status'], 'revision' => DB::raw('revision + 1'), 'updated' => now()->toIso8601String()]);
            abort_unless($changed, 409, 'Quotation changed. Refresh and try again.');
        }
        if ($action === 'quote_outcome') {
            $v = $request->validate([
                'id' => 'required|uuid|exists:quotes,id',
                'outcome' => 'nullable|in:Won,Lost,OnHold',
                'reason' => 'required_if:outcome,Lost,OnHold|nullable|string|max:1000',
            ]);
            $q = DB::table('quotes')->where('id', $v['id'])->first();
            $this->access->agent($request, $q->agent);
            $outcome = $v['outcome'] ?? null;
            DB::table('quotes')->where('id', $v['id'])->update([
                'outcome' => $outcome,
                'outcome_reason' => in_array($outcome, ['Lost', 'OnHold'], true) ? $v['reason'] : null,
                'outcome_at' => $outcome ? now()->toIso8601String() : null,
                'updated' => now()->toIso8601String(),
            ]);
        }
        if ($action === 'customer') return $this->customer($request);
        if ($action === 'customer_activity') return $this->customerActivity($request);
        if ($action === 'delivery_note') return $this->deliveryNote($request);
        if ($action === 'delivery_note_status') {
            $v = $request->validate(['id' => 'required|uuid|exists:delivery_notes,id', 'status' => 'required|in:Draft,Delivered']);
            $dn = DB::table('delivery_notes')->where('id', $v['id'])->first();
            $this->access->agent($request, $dn->agent);
            DB::table('delivery_notes')->where('id', $v['id'])->update(['status' => $v['status'], 'updated' => now()->toIso8601String()]);
            $invoiceId = null;
            if ($v['status'] === 'Delivered') {
                $existing = DB::table('invoices')->where('quote_id', $dn->quote_id)->first();
                if ($existing) {
                    $invoiceId = $existing->id;
                } else {
                    $quote = DB::table('quotes')->where('id', $dn->quote_id)->first();
                    $invoiceId = $this->generateInvoice($quote);
                }
            }
            return response()->json(['ok' => true, 'invoiceId' => $invoiceId]);
        }
        if ($action === 'invoice') return $this->invoice($request);
        if ($action === 'invoice_status') {
            $v = $request->validate(['id' => 'required|uuid|exists:invoices,id', 'status' => 'required|in:Draft,Sent,Cancelled']);
            $inv = DB::table('invoices')->where('id', $v['id'])->first();
            $this->access->agent($request, $inv->agent);
            DB::table('invoices')->where('id', $v['id'])->update(['status' => $v['status'], 'updated' => now()->toIso8601String()]);
        }
        if ($action === 'invoice_payment') {
            $this->access->accounts($request);
            $v = $request->validate([
                'id' => 'required|uuid|exists:invoices,id',
                'paid' => 'required|boolean',
                'paidAt' => 'required_if:paid,true|nullable|date',
            ]);
            DB::table('invoices')->where('id', $v['id'])->update([
                'status' => $v['paid'] ? 'Paid' : 'Sent',
                'paid_at' => $v['paid'] ? $v['paidAt'] : null,
                'marked_paid_by' => $v['paid'] ? $request->user()->agent_id : null,
                'updated' => now()->toIso8601String(),
            ]);
        }
        return response()->json(['ok' => true]);
    }

    private function quote(Request $request)
    {
        $q = $request->validate([
            'quote.id' => 'sometimes|uuid', 'quote.revision' => 'sometimes|integer|min:1',
            'quote.agent' => 'required|uuid', 'quote.customer' => 'required|string|max:200',
            'quote.companyId' => 'required|uuid|exists:companies,id',
            'quote.email' => 'nullable|email|max:254', 'quote.notes' => 'nullable|string|max:5000',
            'quote.rate' => 'required|numeric|gt:0|max:100', 'quote.lines' => 'required|array|min:1|max:200',
            'quote.lines.*.productId' => 'required|uuid|exists:products,id',
            'quote.lines.*.quantity' => 'required|integer|min:1|max:1000000',
            'quote.lines.*.unitBaisa' => 'sometimes|integer|min:0|max:1000000000',
            'quote.lines.*.branding' => 'nullable|string|max:1000',
        ])['quote'];
        $this->access->agent($request, $q['agent']);
        $id = DB::transaction(function () use ($q, $request) {
            $saved = isset($q['id']) ? DB::table('quotes')->where('id', $q['id'])->lockForUpdate()->first() : null;
            if (isset($q['id'])) {
                abort_unless($saved, 404, 'Quotation not found.');
                $this->access->agent($request, $saved->agent);
                abort_unless($saved->agent === $q['agent'] && $saved->status === 'Draft' && $saved->revision === ($q['revision'] ?? 0), 409, 'Quotation changed or is no longer a draft. Reopen it before editing.');
            }
            $rate = (float) ($saved->rate ?? $q['rate']);
            $old = collect($saved ? json_decode($saved->lines, true) : [])->keyBy('productId');
            $products = DB::table('products')->whereIn('id', array_column($q['lines'], 'productId'))->get()->keyBy('id');
            // Prices are set by the Pricing role, not the agent: a brand-new or
            // still-pending quote always prices its lines at zero, a previously
            // priced quote keeps its priced amounts, and only an admin-granted
            // unlock lets the agent's own submitted price through.
            $wasPriced = $saved && $saved->pricing_status === 'Priced';
            $unlocked = $saved && (bool) $saved->price_unlocked_by_admin;
            $anyUnpricedLine = false;
            $lines = []; $total = 0;
            foreach ($q['lines'] as $l) {
                $p = $products[$l['productId']]; $previous = $old->get($l['productId']);
                if ($unlocked) {
                    $unitBaisa = (int) ($l['unitBaisa'] ?? $previous['unitBaisa'] ?? 0);
                } elseif ($wasPriced && $previous) {
                    $unitBaisa = (int) $previous['unitBaisa'];
                } else {
                    $unitBaisa = 0;
                    if ($wasPriced) $anyUnpricedLine = true;
                }
                $line = ['productId' => $p->id, 'name' => $previous['name'] ?? $p->name, 'sku' => $previous['sku'] ?? $p->sku, 'quantity' => (int) $l['quantity'], 'unitBaisa' => $unitBaisa, 'branding' => $l['branding'] ?? '', 'costBaisa' => $previous['costBaisa'] ?? ($p->supplier_aed === null ? $p->cost_baisa : (int) round($p->supplier_aed * $rate * 10))];
                $total += $line['quantity'] * $line['unitBaisa']; $lines[] = $line;
            }
            abort_if($total > 1000000000000, 422, 'Quotation total exceeds the supported range.');
            $id = $saved->id ?? (string) Str::uuid();
            $pricingStatus = $unlocked ? 'Priced' : (($wasPriced && $anyUnpricedLine) ? 'Pending' : ($saved?->pricing_status ?? 'Pending'));
            $values = [
                'customer' => $q['customer'], 'email' => $q['email'] ?? '', 'notes' => $q['notes'] ?? '',
                'company_id' => $q['companyId'],
                'lines' => json_encode($lines, JSON_THROW_ON_ERROR), 'total' => $total, 'updated' => now()->toIso8601String(),
                'pricing_status' => $pricingStatus, 'price_unlocked_by_admin' => false,
            ];
            if ($saved) DB::table('quotes')->where('id', $id)->update([...$values, 'revision' => $saved->revision + 1]);
            else DB::table('quotes')->insert([...$values, 'id' => $id, 'agent' => $q['agent'], 'rate' => $rate, 'created' => now()->toIso8601String()]);
            return $id;
        });
        return response()->json(['id' => $id]);
    }

    private function quotePrice(Request $request)
    {
        $this->access->pricing($request);
        $v = $request->validate([
            'quote.id' => 'required|uuid|exists:quotes,id',
            'quote.lines' => 'required|array|min:1',
            'quote.lines.*.productId' => 'required|uuid',
            'quote.lines.*.unitBaisa' => 'required|integer|min:0|max:1000000000',
        ])['quote'];
        $id = DB::transaction(function () use ($v, $request) {
            $saved = DB::table('quotes')->where('id', $v['id'])->lockForUpdate()->first();
            abort_unless($saved, 404, 'Quotation not found.');
            abort_unless($saved->status === 'Draft', 422, 'Only a draft quotation can be priced.');
            $prices = collect($v['lines'])->keyBy('productId');
            $lines = collect(json_decode($saved->lines, true))->map(function ($l) use ($prices) {
                $l['unitBaisa'] = (int) ($prices->get($l['productId'])['unitBaisa'] ?? $l['unitBaisa']);
                return $l;
            })->all();
            $total = array_sum(array_map(fn ($l) => $l['quantity'] * $l['unitBaisa'], $lines));
            abort_if($total > 1000000000000, 422, 'Quotation total exceeds the supported range.');
            DB::table('quotes')->where('id', $v['id'])->update([
                'lines' => json_encode($lines, JSON_THROW_ON_ERROR), 'total' => $total,
                'pricing_status' => 'Priced', 'priced_by' => $request->user()->agent_id,
                'priced_at' => now()->toIso8601String(), 'price_unlocked_by_admin' => false,
                'updated' => now()->toIso8601String(), 'revision' => DB::raw('revision + 1'),
            ]);
            return $v['id'];
        });
        return response()->json(['id' => $id]);
    }

    private function customer(Request $request)
    {
        $v = $request->validate([
            'customer.id' => 'sometimes|uuid|exists:customers,id',
            'customer.agent' => 'required|uuid',
            'customer.company' => 'required|string|max:200',
            'customer.contactName' => 'required|string|max:200',
            'customer.email' => 'nullable|email|max:254',
            'customer.phone' => 'nullable|string|max:50',
            'customer.address' => 'nullable|string|max:2000',
            'customer.stage' => 'required|in:New Lead,Contacted,Qualified,Proposal Sent,Won,Lost',
            'customer.notes' => 'nullable|string|max:5000',
            'customer.followUpAt' => 'nullable|date',
        ])['customer'];
        $this->access->agent($request, $v['agent']);
        $values = [
            'agent' => $v['agent'], 'company' => $v['company'], 'contact_name' => $v['contactName'],
            'email' => $v['email'] ?? null, 'phone' => $v['phone'] ?? null, 'address' => $v['address'] ?? null,
            'stage' => $v['stage'], 'notes' => $v['notes'] ?? '', 'follow_up_at' => $v['followUpAt'] ?? null,
            'updated' => now()->toIso8601String(),
        ];
        if (isset($v['id'])) {
            $existing = DB::table('customers')->where('id', $v['id'])->first();
            $this->access->agent($request, $existing->agent);
            DB::table('customers')->where('id', $v['id'])->update($values);
            return response()->json(['id' => $v['id']]);
        }
        $id = (string) Str::uuid();
        DB::table('customers')->insert([...$values, 'id' => $id, 'created' => now()->toIso8601String()]);
        return response()->json(['id' => $id]);
    }

    private function customerActivity(Request $request)
    {
        $v = $request->validate([
            'activity.customerId' => 'required|uuid|exists:customers,id',
            'activity.type' => 'required|in:call,email,meeting,note',
            'activity.notes' => 'required|string|max:2000',
        ])['activity'];
        $customer = DB::table('customers')->where('id', $v['customerId'])->first();
        $this->access->agent($request, $customer->agent);
        $id = (string) Str::uuid();
        DB::table('customer_activities')->insert([
            'id' => $id, 'customer_id' => $v['customerId'], 'agent' => $customer->agent,
            'type' => $v['type'], 'notes' => $v['notes'], 'created' => now()->toIso8601String(),
        ]);
        DB::table('customers')->where('id', $v['customerId'])->update(['updated' => now()->toIso8601String()]);
        return response()->json(['id' => $id]);
    }

    private function deliveryNote(Request $request)
    {
        $v = $request->validate([
            'deliveryNote.quoteId' => 'required|uuid|exists:quotes,id',
            'deliveryNote.address' => 'nullable|string|max:2000',
            'deliveryNote.notes' => 'nullable|string|max:2000',
        ])['deliveryNote'];
        $quote = DB::table('quotes')->where('id', $v['quoteId'])->first();
        $this->access->agent($request, $quote->agent);
        abort_unless($quote->status === 'Accepted', 422, 'Only an accepted quotation can have a delivery note.');
        $lines = collect(json_decode($quote->lines, true))->map(fn ($l) => [
            'productId' => $l['productId'], 'name' => $l['name'], 'sku' => $l['sku'], 'quantity' => $l['quantity'],
        ])->all();
        $id = (string) Str::uuid();
        DB::table('delivery_notes')->insert([
            'id' => $id, 'quote_id' => $quote->id, 'agent' => $quote->agent, 'company_id' => $quote->company_id,
            'customer' => $quote->customer, 'address' => $v['address'] ?? '', 'lines' => json_encode($lines, JSON_THROW_ON_ERROR),
            'notes' => $v['notes'] ?? '', 'status' => 'Draft',
            'created' => now()->toIso8601String(), 'updated' => now()->toIso8601String(),
        ]);
        return response()->json(['id' => $id]);
    }

    private function invoice(Request $request)
    {
        $v = $request->validate([
            'invoice.quoteId' => 'required|uuid|exists:quotes,id',
            'invoice.dueDate' => 'nullable|date',
            'invoice.notes' => 'nullable|string|max:2000',
        ])['invoice'];
        $quote = DB::table('quotes')->where('id', $v['quoteId'])->first();
        $this->access->agent($request, $quote->agent);
        abort_unless($quote->status === 'Accepted', 422, 'Only an accepted quotation can be invoiced.');
        abort_unless(DB::table('delivery_notes')->where('quote_id', $quote->id)->exists(), 422, 'Create a delivery note before invoicing.');
        abort_if(DB::table('invoices')->where('quote_id', $quote->id)->exists(), 422, 'This quotation has already been invoiced.');
        $id = $this->generateInvoice($quote, $v['dueDate'] ?? null, $v['notes'] ?? '');
        return response()->json(['id' => $id]);
    }

    private function generateInvoice(object $quote, ?string $dueDate = null, string $notes = ''): string
    {
        $lines = collect(json_decode($quote->lines, true))->map(fn ($l) => [
            'productId' => $l['productId'], 'name' => $l['name'], 'sku' => $l['sku'],
            'quantity' => $l['quantity'], 'unitBaisa' => $l['unitBaisa'],
        ])->all();
        $subtotal = $quote->total;
        $vat = (int) round($subtotal * config('erp.vat_rate'));
        $total = $subtotal + $vat;
        $id = (string) Str::uuid();
        DB::table('invoices')->insert([
            'id' => $id, 'quote_id' => $quote->id, 'agent' => $quote->agent, 'company_id' => $quote->company_id,
            'customer' => $quote->customer, 'email' => $quote->email,
            'lines' => json_encode($lines, JSON_THROW_ON_ERROR),
            'subtotal' => $subtotal, 'vat_baisa' => $vat, 'total' => $total,
            'status' => 'Draft', 'notes' => $notes, 'due_date' => $dueDate,
            'created' => now()->toIso8601String(), 'updated' => now()->toIso8601String(),
        ]);
        return $id;
    }
}
