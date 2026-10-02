<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentFactory
{
    public function deliveryNote(object $quote, string $address = '', string $notes = '', ?string $poNumber = null): string
    {
        $lines = collect(json_decode($quote->lines, true))->map(fn ($l) => [
            'productId' => $l['productId'], 'name' => $l['name'], 'description' => $l['description'] ?? '', 'sku' => $l['sku'], 'quantity' => $l['quantity'],
        ])->all();
        $id = (string) Str::uuid();
        DB::table('delivery_notes')->insert([
            'id' => $id, 'quote_id' => $quote->id, 'agent' => $quote->agent, 'company_id' => $quote->company_id,
            'customer' => $quote->customer, 'address' => $address, 'po_number' => $poNumber,
            'lines' => json_encode($lines, JSON_THROW_ON_ERROR),
            'notes' => $notes, 'status' => 'Draft',
            'created' => now()->toIso8601String(), 'updated' => now()->toIso8601String(),
        ]);

        return $id;
    }

    public function invoice(object $quote, ?string $dueDate = null, string $notes = ''): string
    {
        $lines = collect(json_decode($quote->lines, true))->map(fn ($l) => [
            'productId' => $l['productId'], 'name' => $l['name'], 'description' => $l['description'] ?? '', 'sku' => $l['sku'],
            'quantity' => $l['quantity'], 'unitBaisa' => $l['unitBaisa'],
        ])->all();
        $subtotal = $quote->total;
        $vat = (int) round($subtotal * config('erp.vat_rate'));
        $total = $subtotal + $vat;
        $id = (string) Str::uuid();
        $poNumber = DB::table('delivery_notes')->where('quote_id', $quote->id)->value('po_number');
        DB::table('invoices')->insert([
            'id' => $id, 'quote_id' => $quote->id, 'customer_id' => $quote->customer_id, 'agent' => $quote->agent, 'company_id' => $quote->company_id,
            'customer' => $quote->customer, 'email' => $quote->email, 'po_number' => $poNumber,
            'lines' => json_encode($lines, JSON_THROW_ON_ERROR),
            'subtotal' => $subtotal, 'vat_baisa' => $vat, 'total' => $total,
            'status' => 'Draft', 'notes' => $notes, 'due_date' => $dueDate,
            'created' => now()->toIso8601String(), 'updated' => now()->toIso8601String(),
        ]);

        return $id;
    }
}
