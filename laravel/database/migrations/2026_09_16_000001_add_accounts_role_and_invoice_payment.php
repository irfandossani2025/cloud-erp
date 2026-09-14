<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->string('paid_at')->nullable();
            $t->foreignUuid('marked_paid_by')->nullable()->constrained('agents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('marked_paid_by');
            $t->dropColumn('paid_at');
        });
    }
};
