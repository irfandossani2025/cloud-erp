<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $t) {
            $t->string('outcome', 20)->nullable();
            $t->text('outcome_reason')->nullable();
            $t->string('outcome_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $t) {
            $t->dropColumn(['outcome', 'outcome_reason', 'outcome_at']);
        });
    }
};
