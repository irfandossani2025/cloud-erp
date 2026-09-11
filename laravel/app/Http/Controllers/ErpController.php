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
        $agents = DB::table('agents')->orderBy('name');
        $quotes = DB::table('quotes')->orderByDesc('number');
        if (!$request->user()->is_admin) {
            $agents->where('id', $request->user()->agent_id);
            $quotes->where('agent', $request->user()->agent_id);
        }
        $settings = DB::table('settings')->where('id', 1)->first();
        return response()->json([
            'products' => DB::table('products')->orderBy('name')->get(),
            'quotes' => $quotes->get()->map(fn ($q) => [...(array) $q, 'rate' => (float) $q->rate, 'lines' => json_decode($q->lines, true)]),
            'agents' => $agents->get(),
            'settings' => $settings ? [...(array) $settings, 'rate' => (float) $settings->rate] : ['rate' => config('erp.default_rate'), 'company' => 'Cloud ERP', 'updated' => null],
            'supplierConfigured' => (bool) (config('erp.supplier_username') && config('erp.supplier_password')),
            'aiConfigured' => (bool) config('erp.gemini_key'),
            'isAdmin' => (bool) $request->user()->is_admin,
            'userName' => $request->user()->name,
        ]);
    }

    public function store(Request $request, SupplierCatalogue $supplier)
    {
        $action = $request->validate(['action' => 'required|in:product,stock,agent,settings,sync,quote,status'])['action'];
        if (in_array($action, ['stock', 'agent', 'settings', 'sync'])) $this->access->admin($request);
        if ($action === 'sync') return response()->json(['count' => $supplier->sync()]);
        if ($action === 'product') {
            $v = $request->validate([
                'product.name' => 'required|string|max:200', 'product.sku' => 'required|string|max:200|unique:products,sku',
                'product.description' => 'nullable|string|max:5000', 'product.category' => 'nullable|string|max:1000',
                'product.image' => 'nullable|url:https|max:2000', 'product.warehouseStock' => 'required|integer|min:0|max:1000000',
                'product.saleBaisa' => 'nullable|integer|min:0|max:1000000000', 'product.costBaisa' => 'required|integer|min:0|max:1000000000',
            ])['product'];
            abort_if(!$request->user()->is_admin && $v['warehouseStock'] !== 0, 403, 'Only an administrator can set warehouse quantities.');
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
            ]);
            abort_if(!empty($v['email']) && empty($v['password']), 422, 'Set a sign-in password for this agent.');
            abort_if(empty($v['email']) && !empty($v['password']), 422, 'Enter a sign-in email for this agent.');
            $id = DB::transaction(function () use ($v) {
                $id = (string) Str::uuid();
                DB::table('agents')->insert(['id' => $id, 'name' => $v['name']]);
                if (!empty($v['email'])) {
                    User::create(['name' => $v['name'], 'email' => $v['email'], 'password' => $v['password'], 'is_admin' => false, 'agent_id' => $id]);
                }
                return $id;
            });
            return response()->json(['id' => $id]);
        }
        if ($action === 'settings') {
            $v = $request->validate(['rate' => 'required|numeric|gt:0|max:100', 'company' => 'required|string|max:200']);
            DB::table('settings')->updateOrInsert(['id' => 1], [...$v, 'updated' => now()->toIso8601String()]);
        }
        if ($action === 'quote') return $this->quote($request);
        if ($action === 'status') {
            $v = $request->validate(['id' => 'required|uuid|exists:quotes,id', 'revision' => 'required|integer|min:1', 'status' => 'required|in:Draft,Reviewed,Accepted,Declined']);
            $q = DB::table('quotes')->where('id', $v['id'])->first();
            $this->access->agent($request, $q->agent);
            $changed = DB::table('quotes')->where('id', $v['id'])->where('revision', $v['revision'])->update(['status' => $v['status'], 'revision' => DB::raw('revision + 1'), 'updated' => now()->toIso8601String()]);
            abort_unless($changed, 409, 'Quotation changed. Refresh and try again.');
        }
        return response()->json(['ok' => true]);
    }

    private function quote(Request $request)
    {
        $q = $request->validate([
            'quote.id' => 'sometimes|uuid', 'quote.revision' => 'sometimes|integer|min:1',
            'quote.agent' => 'required|uuid', 'quote.customer' => 'required|string|max:200',
            'quote.email' => 'nullable|email|max:254', 'quote.notes' => 'nullable|string|max:5000',
            'quote.rate' => 'required|numeric|gt:0|max:100', 'quote.lines' => 'required|array|min:1|max:200',
            'quote.lines.*.productId' => 'required|uuid|exists:products,id',
            'quote.lines.*.quantity' => 'required|integer|min:1|max:1000000',
            'quote.lines.*.unitBaisa' => 'required|integer|min:0|max:1000000000',
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
            $lines = []; $total = 0;
            foreach ($q['lines'] as $l) {
                $p = $products[$l['productId']]; $previous = $old->get($l['productId']);
                $line = ['productId' => $p->id, 'name' => $previous['name'] ?? $p->name, 'sku' => $previous['sku'] ?? $p->sku, 'quantity' => (int) $l['quantity'], 'unitBaisa' => (int) $l['unitBaisa'], 'branding' => $l['branding'] ?? '', 'costBaisa' => $previous['costBaisa'] ?? ($p->supplier_aed === null ? $p->cost_baisa : (int) round($p->supplier_aed * $rate * 10))];
                $total += $line['quantity'] * $line['unitBaisa']; $lines[] = $line;
            }
            abort_if($total > 1000000000000, 422, 'Quotation total exceeds the supported range.');
            $id = $saved->id ?? (string) Str::uuid();
            $values = ['customer' => $q['customer'], 'email' => $q['email'] ?? '', 'notes' => $q['notes'] ?? '', 'lines' => json_encode($lines, JSON_THROW_ON_ERROR), 'total' => $total, 'updated' => now()->toIso8601String()];
            if ($saved) DB::table('quotes')->where('id', $id)->update([...$values, 'revision' => $saved->revision + 1]);
            else DB::table('quotes')->insert([...$values, 'id' => $id, 'agent' => $q['agent'], 'rate' => $rate, 'created' => now()->toIso8601String()]);
            return $id;
        });
        return response()->json(['id' => $id]);
    }
}
