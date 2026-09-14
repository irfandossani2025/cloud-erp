<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role', 20)->nullable()->after('is_admin');
        });

        Schema::table('quotes', function (Blueprint $t) {
            $t->string('pricing_status', 20)->default('Pending');
            $t->foreignUuid('priced_by')->nullable()->constrained('agents')->nullOnDelete();
            $t->string('priced_at')->nullable();
            $t->boolean('price_unlocked_by_admin')->default(false);
        });

        // Quotations created before this migration were priced directly by the
        // agent under the old flow -- grandfather them in as already priced so
        // they don't suddenly need a trip through the new Pricing role.
        DB::table('quotes')->update(['pricing_status' => 'Priced']);
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('priced_by');
            $t->dropColumn(['pricing_status', 'priced_at', 'price_unlocked_by_admin']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('role');
        });
    }
};
