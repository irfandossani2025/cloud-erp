<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('number');
            $t->uuid('id')->unique();
            $t->foreignUuid('quote_id')->unique()->constrained('quotes');
            $t->foreignUuid('agent')->constrained('agents');
            $t->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $t->string('customer', 200);
            $t->string('stage', 30)->default('sales_order');
            $t->string('po_number', 100)->nullable();
            $t->unsignedBigInteger('po_amount_baisa')->nullable();
            $t->string('created');
            $t->string('updated');
        });

        Schema::create('order_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained('orders');
            $t->string('kind', 10);
            $t->string('stage', 30);
            $t->text('note')->nullable();
            $t->string('actor', 200);
            $t->string('file_path')->nullable();
            $t->string('file_name', 255)->nullable();
            $t->string('file_mime', 100)->nullable();
            $t->unsignedInteger('file_size')->nullable();
            $t->string('created');
            $t->index('order_id');
        });

        // Quotations already marked Won before order tracking existed get an
        // order too, positioned from whatever documents they already have.
        foreach (DB::table('quotes')->where('outcome', 'Won')->orderBy('number')->get() as $quote) {
            $dn = DB::table('delivery_notes')->where('quote_id', $quote->id)->first();
            $stage = 'sales_order';
            if ($dn) {
                $stage = $dn->status === 'Delivered' ? 'completed' : 'delivery';
            }
            DB::table('orders')->insert([
                'id' => (string) Str::uuid(), 'quote_id' => $quote->id, 'agent' => $quote->agent,
                'company_id' => $quote->company_id, 'customer' => $quote->customer, 'stage' => $stage,
                'po_number' => $dn->po_number ?? null,
                'created' => $quote->outcome_at ?: $quote->updated, 'updated' => now()->toIso8601String(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('orders');
    }
};
