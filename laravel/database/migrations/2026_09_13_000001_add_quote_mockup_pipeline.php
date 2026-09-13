<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $t) {
            $t->foreignUuid('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
        });

        Schema::table('quotes', function (Blueprint $t) {
            $t->string('mockup_status', 20)->default('Pending');
            $t->foreignUuid('mockup_generation_id')->nullable()->constrained('generations')->nullOnDelete();
            $t->string('mockup_approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('mockup_generation_id');
            $t->dropColumn(['mockup_status', 'mockup_approved_at']);
        });
        Schema::table('generations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('quote_id');
        });
    }
};
