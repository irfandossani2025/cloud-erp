<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 200);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_admin')->default(false);
            $t->foreignUuid('agent_id')->nullable()->constrained('agents');
        });
        Schema::create('products', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('sku', 191)->unique();
            $t->string('name', 200);
            $t->text('description')->nullable();
            $t->text('category')->nullable();
            $t->text('image')->nullable();
            $t->string('supplier_id')->nullable()->unique();
            $t->unsignedBigInteger('supplier_aed')->nullable();
            $t->unsignedInteger('supplier_stock')->nullable();
            $t->string('supplier_sync')->nullable();
            $t->unsignedInteger('warehouse_stock')->default(0);
            $t->unsignedBigInteger('sale_baisa')->nullable();
            $t->unsignedBigInteger('cost_baisa')->default(0);
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->decimal('rate', 12, 8);
            $t->string('company', 200);
            $t->string('updated');
        });
        Schema::create('quotes', function (Blueprint $t) {
            $t->bigIncrements('number');
            $t->uuid('id')->unique();
            $t->foreignUuid('agent')->constrained('agents');
            $t->string('customer', 200);
            $t->string('email')->default('');
            $t->text('notes')->nullable();
            $t->string('status')->default('Draft');
            $t->decimal('rate', 12, 8);
            // longText, not json: the production host runs MariaDB 10.1,
            // which predates native JSON columns (MariaDB 10.2.7+). The app
            // only ever reads/writes this as an opaque JSON string in PHP.
            $t->longText('lines');
            $t->unsignedBigInteger('total');
            $t->string('created');
            $t->string('updated');
            $t->unsignedInteger('revision')->default(1);
        });
        Schema::create('generations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('agent')->constrained('agents');
            $t->string('kind', 30);
            $t->text('prompt');
            $t->longText('result');
            $t->string('created');
            $t->index(['agent', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('products');
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('agent_id');
            $t->dropColumn('is_admin');
        });
        Schema::dropIfExists('agents');
    }
};
