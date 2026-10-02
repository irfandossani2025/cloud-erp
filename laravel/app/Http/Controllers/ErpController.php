<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DocumentFactory;
use App\Services\ErpAccess;
use App\Services\OrderWorkflow;
use App\Services\SupplierCatalogue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ErpController extends Controller
{
    public function __construct(private ErpAccess $access, private DocumentFactory $documents, private OrderWorkflow $orders) {}

    public function index(Request $request)
    {
        $isAdmin = (bool) $request->user()->is_admin;
        $role = $request->user()->role;
        $agentId = $request->user()->agent_id;
        $seesAllQuotes = $isAdmin || $role === 'pricing';
        $canSeeCost = $isAdmin || $role === 'pricing';
        $seesAllInvoices = $isAdmin || $role === 'accounts';
        $seesAllDeliveryNotes = $isAdmin || $role === 'accounts';
        $seesAllCustomers = $isAdmin || $role === 'accounts';

        $agents = DB::table('agents')
            ->leftJoin('users', 'users.agent_id', '=', 'agents.id')
            ->select('agents.id', 'agents.name', 'users.email', 'users.role')
            ->orderBy('agents.name');
        $quotes = DB::table('quotes')->orderByDesc('number');
        $customers = DB::table('customers')->orderByDesc('updated');
        $activities = DB::table('customer_activities')->orderByDesc('created');
        $deliveryNotes = DB::table('delivery_notes')->orderByDesc('number');
        $invoices = DB::table('invoices')->orderByDesc('number');
        $salesGoals = DB::table('sales_goals');
        $orders = DB::table('orders')->orderByDesc('number');
        if (!$isAdmin && !$seesAllInvoices) {
            $orders->where('agent', $agentId);
        }
        if (!$isAdmin) {
            if (!$seesAllInvoices) {
                $agents->where('agents.id', $agentId);
            }
            if (!$seesAllCustomers) {
                $customers->where('agent', $agentId);
                $activities->where('agent', $agentId);
            }
            if (!$seesAllDeliveryNotes) {
                $deliveryNotes->where('agent', $agentId);
            }
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

        $invoiceRows = $invoices->get();
        $invoiceIds = $invoiceRows->pluck('id');
        $orders = $orders->get();
        $orderEvents = DB::table('order_events')->whereIn('order_id', $orders->pluck('id'))->orderBy('created')->get()
            ->map(fn ($e) => [
                'id' => $e->id, 'order_id' => $e->order_id, 'kind' => $e->kind, 'stage' => $e->stage,
                'note' => $e->note, 'actor' => $e->actor, 'created' => $e->created,
                'file_name' => $e->file_name, 'file_mime' => $e->file_mime, 'file_size' => $e->file_size,
            ]);

        return response()->json([
            'orders' => $orders,
            'orderEvents' => $orderEvents,
            'invoiceReminders' => DB::table('invoice_reminders')->whereIn('invoice_id', $invoiceIds)->orderBy('sent_at')->get(['invoice_id', 'days_before', 'sent_to', 'sent_at']),
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
            'invoices' => $invoiceRows->map(fn ($i) => [...(array) $i, 'lines' => json_decode($i->lines, true)]),
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
            'action' => 'required|in:product,stock,agent,agent_password,agent_role,settings,sync,quote,quote_price,quote_delete,status,quote_outcome,customer,customer_delete,customer_activity,delivery_note,delivery_note_status,delivery_note_update,invoice,invoice_delete,invoice_status,invoice_update,invoice_costs,invoice_payment,company_update,sales_goal',
        ])['action'];
        if (in_array($action, ['agent', 'settings', 'sync'])) $this->access->admin($request);
        if ($action === 'sync') return response()->json(['count' => $supplier->sync()]);
        if ($action === 'product') {
            $v = $request->validate([
                'product.name' => 'required|string|max:200', 'product.sku' => 'nullable|string|max:191|unique:products,sku',
                'product.description' => 'nullable|string|max:5000', 'product.category' => 'nullable|string|max:1000',
                'product.image' => 'nullable|url:https|max:2000', 'product.warehouseStock' => 'required|integer|min:0|max:1000000',
                'product.saleBaisa' => 'nullable|integer|min:0|max:1000000000', 'product.costBaisa' => 'required|integer|min:0|max:1000000000',
            ])['product'];
            abort_if(!$request->user()->is_admin && $v['warehouseStock'] !== 0, 403, 'Only an administrator can set warehouse quantities.');
            abort_if(!$request->user()->is_admin && $v['costBaisa'] !== 0, 403, 'Only an administrator can set product cost.');
            $id = (string) Str::uuid();
            $sku = ($v['sku'] ?? null) ?: $this->generateSku();
            DB::table('products')->insert(['id' => $id, 'name' => $v['name'], 'sku' => $sku, 'description' => $v['description'] ?? '', 'category' => $v['category'] ?? '', 'image' => $v['image'] ?? '', 'warehouse_stock' => $v['warehouseStock'], 'sale_baisa' => $v['saleBaisa'] ?? null, 'cost_baisa' => $v['costBaisa']]);
            return response()->json(['id' => $id, 'sku' => $sku]);
        }
        if ($action === 'stock') {
            $v = $request->validate(['id' => 'required|uuid|exists:products,id', 'warehouseStock' => 'required|integer|min:0|max:1000000', 'saleBaisa' => 'nullable|integer|min:0|max:1000000000']);
            $product = DB::table('products')->where('id', $v['id'])->first();
            abort_unless($product, 404, 'Product not found.');
            abort_if(!$request->user()->is_admin && $v['warehouseStock'] !== $product->warehouse_stock, 403, 'Only an administrator can change warehouse quantities.');
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
        if ($action === 'agent_password') {
            $this->access->admin($request);
            $v = $request->validate([
                'agentId' => 'required|uuid|exists:agents,id',
                'password' => 'required|string|min:8|max:72',
            ]);
            $user = User::where('agent_id', $v['agentId'])->first();
            abort_unless($user, 422, 'This sales agent does not have a sign-in login.');
            $user->password = $v['password'];
            $user->save();
        }
        if ($action === 'agent_role') {
            $this->access->admin($request);
            $v = $request->validate([
                'agentId' => 'required|uuid|exists:agents,id',
                'role' => 'nullable|in:pricing,accounts',
            ]);
            $user = User::where('agent_id', $v['agentId'])->first();
            abort_unless($user, 422, 'This sales agent does not have a sign-in login.');
            $user->role = $v['role'] ?? null;
            $user->save();
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
        if ($action === 'quote_delete') {
            $this->access->admin($request);
            $v = $request->validate(['id' => 'required|uuid|exists:quotes,id']);
            DB::transaction(function () use ($v) {
                $this->orders->purgeForQuote($v['id']);
                DB::table('invoices')->where('quote_id', $v['id'])->delete();
                DB::table('delivery_notes')->where('quote_id', $v['id'])->delete();
                DB::table('quotes')->where('id', $v['id'])->delete();
            });
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
            if ($outcome === 'Won') {
                $this->orders->ensureForQuote($q);
            }
        }
        if ($action === 'customer') return $this->customer($request);
        if ($action === 'customer_delete') {
            $this->access->admin($request);
            $v = $request->validate(['id' => 'required|uuid|exists:customers,id']);
            DB::transaction(function () use ($v) {
                DB::table('customer_activities')->where('customer_id', $v['id'])->delete();
                DB::table('customers')->where('id', $v['id'])->delete();
            });
        }
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
                    $invoiceId = $this->documents->invoice($quote);
                }
            }
            return response()->json(['ok' => true, 'invoiceId' => $invoiceId]);
        }
        if ($action === 'delivery_note_update') {
            $v = $request->validate(['id' => 'required|uuid|exists:delivery_notes,id', 'poNumber' => 'nullable|string|max:100']);
            $dn = DB::table('delivery_notes')->where('id', $v['id'])->first();
            $this->access->agent($request, $dn->agent);
            DB::table('delivery_notes')->where('id', $v['id'])->update(['po_number' => $v['poNumber'] ?? null, 'updated' => now()->toIso8601String()]);
        }
        if ($action === 'invoice') return $this->invoice($request);
        if ($action === 'invoice_update') {
            $v = $request->validate([
                'id' => 'required|uuid|exists:invoices,id',
                'poNumber' => 'nullable|string|max:100',
                'dueDate' => 'nullable|date_format:Y-m-d',
                'email' => 'nullable|email|max:254',
            ]);
            $inv = DB::table('invoices')->where('id', $v['id'])->first();
            if ($request->user()->role !== 'accounts') {
                $this->access->agent($request, $inv->agent);
            }
            DB::table('invoices')->where('id', $v['id'])->update([
                'po_number' => $v['poNumber'] ?? null,
                'due_date' => $v['dueDate'] ?? null,
                'email' => $v['email'] ?? '',
                'updated' => now()->toIso8601String(),
            ]);
        }
        if ($action === 'invoice_status') {
            $v = $request->validate(['id' => 'required|uuid|exists:invoices,id', 'status' => 'required|in:Draft,Sent,Cancelled']);
            $inv = DB::table('invoices')->where('id', $v['id'])->first();
            $this->access->agent($request, $inv->agent);
            DB::table('invoices')->where('id', $v['id'])->update(['status' => $v['status'], 'updated' => now()->toIso8601String()]);
        }
        if ($action === 'invoice_delete') {
            $this->access->admin($request);
            $v = $request->validate(['id' => 'required|uuid|exists:invoices,id']);
            DB::table('invoices')->where('id', $v['id'])->delete();
        }
        if ($action === 'invoice_costs') {
            $this->access->accounts($request);
            $v = $request->validate([
                'id' => 'required|uuid|exists:invoices,id',
                'vendorName' => 'nullable|string|max:200',
                'purchaseCostBaisa' => 'nullable|integer|min:0|max:1000000000',
                'transportCostBaisa' => 'nullable|integer|min:0|max:1000000000',
                'otherCostBaisa' => 'nullable|integer|min:0|max:1000000000',
                'otherCostNote' => 'nullable|string|max:500',
            ]);
            DB::table('invoices')->where('id', $v['id'])->update([
                'vendor_name' => $v['vendorName'] ?? null,
                'purchase_cost_baisa' => $v['purchaseCostBaisa'] ?? null,
                'transport_cost_baisa' => $v['transportCostBaisa'] ?? null,
                'other_cost_baisa' => $v['otherCostBaisa'] ?? null,
                'other_cost_note' => $v['otherCostNote'] ?? null,
                'updated' => now()->toIso8601String(),
            ]);
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
            'quote.customerId' => 'nullable|uuid|exists:customers,id',
            'quote.companyId' => 'required|uuid|exists:companies,id',
            'quote.email' => 'nullable|email|max:254', 'quote.notes' => 'nullable|string|max:5000',
            'quote.rate' => 'required|numeric|gt:0|max:100', 'quote.lines' => 'required|array|min:1|max:200',
            'quote.lines.*.id' => 'nullable|string|max:64',
            'quote.lines.*.productId' => 'required|uuid|exists:products,id',
            'quote.lines.*.quantity' => 'required|integer|min:1|max:1000000',
            'quote.lines.*.unitBaisa' => 'sometimes|integer|min:0|max:1000000000',
            'quote.lines.*.branding' => 'nullable|string|max:1000',
            'quote.lines.*.description' => 'nullable|string|max:2000',
        ])['quote'];
        $this->access->agent($request, $q['agent']);
        if (!empty($q['customerId'])) {
            $customer = DB::table('customers')->where('id', $q['customerId'])->first();
            abort_unless($customer && $customer->agent === $q['agent'], 422, 'Select a customer from your own book.');
        }
        $id = DB::transaction(function () use ($q, $request) {
            $saved = isset($q['id']) ? DB::table('quotes')->where('id', $q['id'])->lockForUpdate()->first() : null;
            if (isset($q['id'])) {
                abort_unless($saved, 404, 'Quotation not found.');
                $this->access->agent($request, $saved->agent);
                abort_unless($saved->agent === $q['agent'] && $saved->status === 'Draft' && $saved->revision === ($q['revision'] ?? 0), 409, 'Quotation changed or is no longer a draft. Reopen it before editing.');
            }
            $rate = (float) ($saved->rate ?? $q['rate']);
            $savedLines = $saved ? json_decode($saved->lines, true) : [];
            // Each line carries a stable client-generated id so a product can
            // appear on a quote more than once; older quotes saved before that
            // existed have no ids, so we fall back to matching by productId
            // (ambiguous only if such a legacy quote already had a duplicate,
            // which the app never allowed before this feature).
            $oldById = collect($savedLines)->filter(fn ($l) => !empty($l['id']))->keyBy('id');
            $oldByProduct = collect($savedLines)->keyBy('productId');
            $products = DB::table('products')->whereIn('id', array_column($q['lines'], 'productId'))->get()->keyBy('id');
            // Any agent can price their own quote directly: a submitted unit
            // price is always honoured, falling back to whatever this line was
            // last saved at (e.g. when only the quantity or branding changed).
            $lines = []; $total = 0;
            foreach ($q['lines'] as $l) {
                $p = $products[$l['productId']];
                $previous = (!empty($l['id']) ? $oldById->get($l['id']) : null) ?? $oldByProduct->get($l['productId']);
                $unitBaisa = (int) ($l['unitBaisa'] ?? $previous['unitBaisa'] ?? 0);
                $line = ['id' => $l['id'] ?? (string) Str::uuid(), 'productId' => $p->id, 'name' => $previous['name'] ?? $p->name, 'sku' => $previous['sku'] ?? $p->sku, 'quantity' => (int) $l['quantity'], 'unitBaisa' => $unitBaisa, 'branding' => $l['branding'] ?? '', 'description' => $l['description'] ?? ($previous['description'] ?? $p->description ?? ''), 'costBaisa' => $previous['costBaisa'] ?? ($p->supplier_aed === null ? $p->cost_baisa : (int) round($p->supplier_aed * $rate * 10))];
                $total += $line['quantity'] * $line['unitBaisa']; $lines[] = $line;
            }
            abort_if($total > 1000000000000, 422, 'Quotation total exceeds the supported range.');
            $id = $saved->id ?? (string) Str::uuid();
            $pricingStatus = collect($lines)->every(fn ($l) => $l['unitBaisa'] > 0) ? 'Priced' : 'Pending';
            $values = [
                'customer' => $q['customer'], 'email' => $q['email'] ?? '', 'notes' => $q['notes'] ?? '',
                'company_id' => $q['companyId'], 'customer_id' => $q['customerId'] ?? null,
                'lines' => json_encode($lines, JSON_THROW_ON_ERROR), 'total' => $total, 'updated' => now()->toIso8601String(),
                'pricing_status' => $pricingStatus,
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
            'quote.lines.*.id' => 'nullable|string|max:64',
            'quote.lines.*.productId' => 'required|uuid',
            'quote.lines.*.unitBaisa' => 'required|integer|min:0|max:1000000000',
        ])['quote'];
        $id = DB::transaction(function () use ($v, $request) {
            $saved = DB::table('quotes')->where('id', $v['id'])->lockForUpdate()->first();
            abort_unless($saved, 404, 'Quotation not found.');
            abort_unless($saved->status === 'Draft', 422, 'Only a draft quotation can be priced.');
            $pricesById = collect($v['lines'])->filter(fn ($l) => !empty($l['id']))->keyBy('id');
            $pricesByProduct = collect($v['lines'])->keyBy('productId');
            $lines = collect(json_decode($saved->lines, true))->map(function ($l) use ($pricesById, $pricesByProduct) {
                $match = (!empty($l['id']) ? $pricesById->get($l['id']) : null) ?? $pricesByProduct->get($l['productId']);
                $l['unitBaisa'] = (int) ($match['unitBaisa'] ?? $l['unitBaisa']);
                return $l;
            })->all();
            $total = array_sum(array_map(fn ($l) => $l['quantity'] * $l['unitBaisa'], $lines));
            abort_if($total > 1000000000000, 422, 'Quotation total exceeds the supported range.');
            DB::table('quotes')->where('id', $v['id'])->update([
                'lines' => json_encode($lines, JSON_THROW_ON_ERROR), 'total' => $total,
                'pricing_status' => 'Priced', 'priced_by' => $request->user()->agent_id,
                'priced_at' => now()->toIso8601String(),
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
            'customer.vatNumber' => 'nullable|string|max:50',
            'customer.stage' => 'required|in:New Lead,Contacted,Qualified,Proposal Sent,Won,Lost',
            'customer.notes' => 'nullable|string|max:5000',
            'customer.followUpAt' => 'nullable|date',
        ])['customer'];
        $this->access->agent($request, $v['agent']);
        $values = [
            'agent' => $v['agent'], 'company' => $v['company'], 'contact_name' => $v['contactName'],
            'email' => $v['email'] ?? null, 'phone' => $v['phone'] ?? null, 'address' => $v['address'] ?? null,
            'vat_number' => $v['vatNumber'] ?? null,
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
        $id = $this->documents->deliveryNote($quote, $v['address'] ?? '', $v['notes'] ?? '');
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
        $id = $this->documents->invoice($quote, $v['dueDate'] ?? null, $v['notes'] ?? '');
        return response()->json(['id' => $id]);
    }

    private function generateSku(): string
    {
        do {
            $sku = 'SKU-'.strtoupper(Str::random(6));
        } while (DB::table('products')->where('sku', $sku)->exists());

        return $sku;
    }
}
