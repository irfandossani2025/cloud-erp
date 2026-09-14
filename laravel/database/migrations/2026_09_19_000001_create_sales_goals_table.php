<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_goals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('agent_id')->constrained('agents');
            $t->string('period', 7);
            $t->unsignedBigInteger('target_baisa');
            $t->string('created');
            $t->string('updated');
            $t->unique(['agent_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_goals');
    }
};
