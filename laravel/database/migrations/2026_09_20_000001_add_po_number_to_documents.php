<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('delivery_notes', function (Blueprint $t) {
            $t->string('po_number', 100)->nullable();
        });
        Schema::table('invoices', function (Blueprint $t) {
            $t->string('po_number', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_notes', function (Blueprint $t) {
            $t->dropColumn('po_number');
        });
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn('po_number');
        });
    }
};
