<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->foreignUuid('customer_id')->nullable()->after('quote_id')->constrained('customers')->nullOnDelete();
            $t->string('vendor_name', 200)->nullable();
            $t->unsignedBigInteger('purchase_cost_baisa')->nullable();
            $t->unsignedBigInteger('transport_cost_baisa')->nullable();
            $t->unsignedBigInteger('other_cost_baisa')->nullable();
            $t->string('other_cost_note', 500)->nullable();
        });

        // Backfill customer_id from each invoice's originating quote so the
        // accounts team can trace VAT collected back to a client's VAT
        // number without needing quote access of their own.
        DB::table('invoices')->orderBy('id')->chunkById(200, function ($invoices) {
            foreach ($invoices as $invoice) {
                $customerId = DB::table('quotes')->where('id', $invoice->quote_id)->value('customer_id');
                if ($customerId) {
                    DB::table('invoices')->where('id', $invoice->id)->update(['customer_id' => $customerId]);
                }
            }
        }, 'id');
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('customer_id');
            $t->dropColumn(['vendor_name', 'purchase_cost_baisa', 'transport_cost_baisa', 'other_cost_baisa', 'other_cost_note']);
        });
    }
};
