<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoice_reminders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $t->unsignedSmallInteger('days_before');
            $t->string('sent_to', 254);
            $t->string('sent_at');
            $t->unique(['invoice_id', 'days_before']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_reminders');
    }
};
