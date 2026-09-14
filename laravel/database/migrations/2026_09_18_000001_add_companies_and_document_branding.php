<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('key', 30)->unique();
            $t->string('name', 200);
            $t->string('trading_name', 200)->nullable();
            $t->string('vat_number', 50)->nullable();
            $t->string('logo_path', 200);
        });

        $lustrousId = (string) Str::uuid();
        $mugdiId = (string) Str::uuid();
        DB::table('companies')->insert([
            ['id' => $lustrousId, 'key' => 'lustrous', 'name' => 'Lustrous Glory International', 'trading_name' => 'Hadaya Muscat', 'vat_number' => null, 'logo_path' => '/mais-logo.png'],
            ['id' => $mugdiId, 'key' => 'mugdi', 'name' => 'Mugdi Investments LLC', 'trading_name' => null, 'vat_number' => null, 'logo_path' => '/mugdi-logo.png'],
        ]);

        foreach (['quotes', 'delivery_notes', 'invoices'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            });
        }

        // Every document created before the branding switch was issued under
        // Lustrous Glory's Hadaya Muscat trading name (the sole logo the app
        // used until now), so grandfather them all in as that company.
        foreach (['quotes', 'delivery_notes', 'invoices'] as $table) {
            DB::table($table)->update(['company_id' => $lustrousId]);
        }
    }

    public function down(): void
    {
        foreach (['quotes', 'delivery_notes', 'invoices'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('company_id');
            });
        }
        Schema::dropIfExists('companies');
    }
};
