<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ErpAccess
{
    public function agent(Request $request, string $id): void
    {
        abort_unless(DB::table('agents')->where('id', $id)->exists(), 422, 'Select a valid sales agent.');
        abort_unless($request->user()->is_admin || $request->user()->agent_id === $id, 403, 'This record belongs to another sales agent.');
    }

    public function admin(Request $request): void
    {
        abort_unless($request->user()->is_admin, 403, 'Administrator access is required.');
    }

    public function pricing(Request $request): void
    {
        abort_unless($request->user()->is_admin || $request->user()->role === 'pricing', 403, 'Pricing access is required.');
    }
}
