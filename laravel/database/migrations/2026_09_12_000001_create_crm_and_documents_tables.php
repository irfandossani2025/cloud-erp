<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $t) {
            $t->string('vat_number')->nullable();
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('agent')->constrained('agents');
            $t->string('company', 200);
            $t->string('contact_name', 200);
            $t->string('email')->nullable();
            $t->string('phone', 50)->nullable();
            $t->text('address')->nullable();
            $t->string('stage', 30)->default('New Lead');
            $t->text('notes')->nullable();
            $t->string('follow_up_at')->nullable();
            $t->string('created');
            $t->string('updated');
            $t->index(['agent', 'stage']);
        });

        Schema::create('customer_activities', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('customer_id')->constrained('customers');
            $t->foreignUuid('agent')->constrained('agents');
            $t->string('type', 30);
            $t->text('notes');
            $t->string('created');
            $t->index('customer_id');
        });

        Schema::create('delivery_notes', function (Blueprint $t) {
            $t->bigIncrements('number');
            $t->uuid('id')->unique();
            $t->foreignUuid('quote_id')->constrained('quotes');
            $t->foreignUuid('agent')->constrained('agents');
            $t->string('customer', 200);
            $t->text('address')->nullable();
            // longText, not json: see create_erp_tables migration note (MariaDB
            // 10.1 on the production host predates native JSON columns).
            $t->longText('lines');
            $t->text('notes')->nullable();
            $t->string('status', 30)->default('Draft');
            $t->string('created');
            $t->string('updated');
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->bigIncrements('number');
            $t->uuid('id')->unique();
            $t->foreignUuid('quote_id')->constrained('quotes');
            $t->foreignUuid('agent')->constrained('agents');
            $t->string('customer', 200);
            $t->string('email')->default('');
            $t->longText('lines');
            $t->unsignedBigInteger('subtotal');
            $t->unsignedBigInteger('vat_baisa');
            $t->unsignedBigInteger('total');
            $t->string('status', 30)->default('Draft');
            $t->text('notes')->nullable();
            $t->string('due_date')->nullable();
            $t->string('created');
            $t->string('updated');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('delivery_notes');
        Schema::dropIfExists('customer_activities');
        Schema::dropIfExists('customers');
        Schema::table('settings', function (Blueprint $t) {
            $t->dropColumn('vat_number');
        });
    }
};
