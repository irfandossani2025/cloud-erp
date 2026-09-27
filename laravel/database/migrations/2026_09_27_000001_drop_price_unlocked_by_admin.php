<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $t) {
            $t->dropColumn('price_unlocked_by_admin');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $t) {
            $t->boolean('price_unlocked_by_admin')->default(false);
        });
    }
};
