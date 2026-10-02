<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Guarded so a run that stopped part-way (MySQL DDL isn't transactional) can be repeated.
        if (!Schema::hasColumn('agents', 'takes_leads')) {
            Schema::table('agents', function (Blueprint $t) {
                $t->boolean('takes_leads')->default(true);
                $t->string('last_lead_at')->nullable();
            });
        }
        // The accountant never works leads.
        $accountants = DB::table('users')->where('role', 'accounts')->whereNotNull('agent_id')->pluck('agent_id');
        DB::table('agents')->whereIn('id', $accountants)->update(['takes_leads' => false]);

        Schema::dropIfExists('prospects');
        Schema::create('prospects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('agent')->nullable()->constrained('agents')->nullOnDelete();
            $t->string('name', 200)->default('');
            $t->string('title', 200)->default('');
            $t->string('company', 200)->default('');
            $t->string('email', 254)->default('');
            $t->string('phone', 50)->default('');
            $t->string('website', 500)->default('');
            $t->string('linkedin_url', 500)->default('');
            $t->string('location', 200)->default('');
            $t->string('source_url', 2000)->default('');
            $t->string('source', 20)->default('manual');
            $t->text('notes')->nullable();
            $t->string('status', 20)->default('new');
            $t->string('captured_by', 200)->default('');
            $t->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->string('assigned_at')->nullable();
            $t->string('created');
            $t->string('updated');
            $t->index(['agent', 'status']);
            // No index on email/linkedin_url: MariaDB 10.1 caps key length at 767 bytes
            // and the table is small enough to scan.
        });

        Schema::dropIfExists('capture_tokens');
        Schema::create('capture_tokens', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->primary();
            $t->string('token_hash', 64)->unique();
            $t->string('created');
            $t->string('last_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capture_tokens');
        Schema::dropIfExists('prospects');
        Schema::table('agents', function (Blueprint $t) {
            $t->dropColumn(['takes_leads', 'last_lead_at']);
        });
    }
};
