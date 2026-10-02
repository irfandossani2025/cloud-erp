<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class LeadAssigner
{
    /**
     * Next agent in the rotation: signed-in agents who take leads, whoever has
     * waited longest (never-assigned first). Returns the agent id, or null when
     * nobody is eligible.
     */
    public function next(): ?string
    {
        return DB::transaction(function () {
            $agent = DB::table('agents')
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('users')->whereColumn('users.agent_id', 'agents.id'))
                ->where('takes_leads', true)
                ->orderByRaw('last_lead_at is null desc')
                ->orderBy('last_lead_at')
                ->orderBy('name')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if (!$agent) {
                return null;
            }
            DB::table('agents')->where('id', $agent->id)->update(['last_lead_at' => now()->format('Y-m-d\TH:i:s.uP')]);

            return $agent->id;
        });
    }
}
