<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierCatalogue
{
    public function sync(): int
    {
        if (!config('erp.supplier_username') || !config('erp.supplier_password')) {
            throw ValidationException::withMessages(['supplier' => 'Supplier credentials have not been configured.']);
        }
        $response = Http::acceptJson()->withBasicAuth(config('erp.supplier_username'), config('erp.supplier_password'))
            ->connectTimeout(10)->timeout(45)->get('https://luxurytrd.com/wp-json/api/v1/products');
        abort_unless($response->successful(), 502, 'Supplier connection failed. Existing products are unchanged.');
        $rows = $response->json();
        abort_unless(is_array($rows) && array_is_list($rows) && count($rows) > 0 && count($rows) <= 20000, 502, 'Supplier returned an invalid catalogue.');
        $ids = []; $skus = []; $normalised = [];
        foreach ($rows as $row) {
            Validator::make($row, [
                'id' => 'required|integer', 'name' => 'required|string|max:200', 'sku' => 'required|string|max:200',
                'price' => 'nullable|numeric|min:0|max:10000000', 'stock_quantity' => 'nullable|integer|min:0|max:1000000000',
                'categories' => 'sometimes|array', 'categories.*' => 'string', 'images' => 'sometimes|array', 'images.*' => 'string',
            ])->validate();
            $id = (string) $row['id']; $sku = trim($row['sku']);
            abort_if(isset($ids[$id]) || isset($skus[mb_strtolower($sku)]), 502, 'Supplier returned duplicate products.');
            $ids[$id] = true; $skus[mb_strtolower($sku)] = true;
            $image = $row['images'][0] ?? '';
            $normalised[] = [
                'supplier_id' => $id, 'sku' => $sku, 'name' => trim($row['name']),
                'description' => mb_substr(strip_tags((string) ($row['description'] ?? '')), 0, 5000),
                'category' => mb_substr(implode(', ', $row['categories'] ?? []), 0, 1000),
                'image' => str_starts_with($image, 'https://') ? $image : '',
                'supplier_aed' => isset($row['price']) && $row['price'] !== '' ? (int) round((float) $row['price'] * 100) : null,
                'supplier_stock' => isset($row['stock_quantity']) ? (int) $row['stock_quantity'] : null,
                'supplier_sync' => now()->toIso8601String(),
            ];
        }
        DB::transaction(function () use ($normalised) {
            foreach ($normalised as $row) {
                $existing = DB::table('products')->where('supplier_id', $row['supplier_id'])->first();
                $duplicate = DB::table('products')->where('sku', $row['sku'])->first();
                abort_if($duplicate && $duplicate->id !== ($existing->id ?? null), 409, 'A supplier SKU conflicts with an existing product. Resolve it before syncing.');
                if ($existing) {
                    DB::table('products')->where('id', $existing->id)->update($row);
                } else {
                    DB::table('products')->insert(['id' => (string) Str::uuid(), ...$row]);
                }
            }
        });
        return count($normalised);
    }
}
