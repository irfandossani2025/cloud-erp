<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->string('vat_number', 50)->nullable();
        });
        Schema::table('quotes', function (Blueprint $t) {
            $t->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('customer_id');
        });
        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn('vat_number');
        });
    }
};
