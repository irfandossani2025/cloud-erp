<?php

namespace App\Http\Controllers;

use App\Services\LeadAssigner;
use App\Services\ProspectIntake;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProspectController extends Controller
{
    public function __construct(private ProspectIntake $intake, private LeadAssigner $assigner) {}

    private function rules(): array
    {
        return [
            'name' => 'nullable|string|max:200', 'title' => 'nullable|string|max:200', 'company' => 'nullable|string|max:200',
            'email' => 'nullable|email|max:254', 'phone' => 'nullable|string|max:50',
            'website' => 'nullable|url:http,https|max:500', 'linkedinUrl' => 'nullable|url:http,https|max:500',
            'location' => 'nullable|string|max:200', 'sourceUrl' => 'nullable|url:http,https|max:2000', 'notes' => 'nullable|string|max:5000',
        ];
    }

    /** People type "example.com"; store it as a usable link. */
    private function withSchemes(Request $request): void
    {
        foreach (['website', 'linkedinUrl', 'sourceUrl'] as $f) {
            $v = trim((string) $request->input($f, ''));
            if ($v !== '' && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $v)) {
                $request->merge([$f => 'https://'.$v]);
            }
        }
    }

    private function fields(array $v): array
    {
        return [
            'name' => $v['name'] ?? '', 'title' => $v['title'] ?? '', 'company' => $v['company'] ?? '', 'email' => $v['email'] ?? '',
            'phone' => $v['phone'] ?? '', 'website' => $v['website'] ?? '', 'linkedin_url' => $v['linkedinUrl'] ?? '',
            'location' => $v['location'] ?? '', 'source_url' => $v['sourceUrl'] ?? '', 'notes' => $v['notes'] ?? '',
        ];
    }

    private function sellers(Request $request): void
    {
        abort_if($request->user()->role === 'accounts' && !$request->user()->is_admin, 403, 'Prospects are handled by the sales team.');
    }

    private function owned(Request $request, string $id): object
    {
        $this->sellers($request);
        $p = DB::table('prospects')->where('id', $id)->first();
        abort_unless($p, 404, 'Prospect not found.');
        abort_unless($request->user()->is_admin || ($p->agent && $p->agent === $request->user()->agent_id), 403, 'This prospect is assigned to another sales agent.');

        return $p;
    }

    private function assignment(Request $request): ?string
    {
        $user = $request->user();
        if ($user->is_admin) {
            $choice = $request->input('agentId');
            if ($choice && $choice !== 'auto') {
                abort_unless(DB::table('agents')->where('id', $choice)->exists(), 422, 'Select a valid sales agent.');

                return $choice;
            }

            return null;
        }

        return $request->boolean('assignToMe', true) ? $user->agent_id : null;
    }

    private function attempt(Request $request, array $fields, string $source, ?string $assignTo, bool $autoAssign): \Illuminate\Http\JsonResponse
    {
        abort_if(trim($fields['name']) === '' && trim($fields['company']) === '', 422, 'Enter at least a name or a company.');
        $user = $request->user();
        $r = $this->intake->add($fields, $source, $user->name, $autoAssign ? null : $assignTo);

        return response()->json([
            'id' => $r['id'], 'duplicate' => $r['duplicate'], 'created' => $r['created'],
            'assignedTo' => $r['agent'] ? DB::table('agents')->where('id', $r['agent'])->value('name') : null,
            'mine' => $r['agent'] !== null && $r['agent'] === $user->agent_id,
        ]);
    }

    public function store(Request $request)
    {
        $this->sellers($request);
        $this->withSchemes($request);
        $v = $request->validate($this->rules() + ['agentId' => 'nullable|string', 'assignToMe' => 'nullable|boolean']);
        $to = $this->assignment($request);

        return $this->attempt($request, $this->fields($v), 'manual', $to, $to === null);
    }

    public function update(Request $request, string $id)
    {
        $p = $this->owned($request, $id);
        $this->withSchemes($request);
        $v = $request->validate($this->rules() + ['status' => 'nullable|in:new,contacted,not_a_fit']);
        $f = $this->fields($v);
        abort_if($f['name'] === '' && $f['company'] === '', 422, 'Enter at least a name or a company.');
        $f['email'] = strtolower($f['email']);
        $f['linkedin_url'] = ProspectIntake::linkedin($f['linkedin_url']);
        unset($f['source_url']);
        $values = [...$f, 'updated' => now()->toIso8601String()];
        if (!empty($v['status']) && $p->status !== 'converted') {
            $values['status'] = $v['status'];
        }
        DB::table('prospects')->where('id', $id)->update($values);

        return response()->json(['id' => $id]);
    }

    public function assign(Request $request, string $id)
    {
        abort_unless($request->user()->is_admin, 403, 'Administrator access is required.');
        $p = DB::table('prospects')->where('id', $id)->first();
        abort_unless($p, 404, 'Prospect not found.');
        $v = $request->validate(['agentId' => 'required|string']);
        $agent = $v['agentId'] === 'auto' ? $this->assigner->next() : $v['agentId'];
        abort_unless($agent, 422, 'No sales agent is set up to receive leads. Turn on "Receives leads" for an agent in Settings.');
        abort_unless(DB::table('agents')->where('id', $agent)->exists(), 422, 'Select a valid sales agent.');
        DB::table('prospects')->where('id', $id)->update(['agent' => $agent, 'assigned_at' => now()->toIso8601String(), 'updated' => now()->toIso8601String()]);

        return response()->json(['agent' => $agent]);
    }

    public function convert(Request $request, string $id)
    {
        $p = $this->owned($request, $id);
        abort_if($p->status === 'converted', 422, 'This prospect is already a customer.');
        abort_unless($p->agent, 422, 'Assign the prospect to a sales agent first.');
        $notes = collect([
            $p->title ? "Role: {$p->title}" : null, $p->location ? "Location: {$p->location}" : null,
            $p->website ? "Website: {$p->website}" : null, $p->linkedin_url ? "LinkedIn: {$p->linkedin_url}" : null,
            $p->notes ?: null,
        ])->filter()->implode("\n");

        $customerId = DB::transaction(function () use ($p, $notes) {
            $now = now()->toIso8601String();
            $cid = (string) Str::uuid();
            DB::table('customers')->insert([
                'id' => $cid, 'agent' => $p->agent, 'company' => $p->company ?: $p->name, 'contact_name' => $p->name ?: $p->company,
                'email' => $p->email ?: null, 'phone' => $p->phone ?: null, 'stage' => 'New Lead', 'notes' => $notes,
                'created' => $now, 'updated' => $now,
            ]);
            DB::table('customer_activities')->insert([
                'id' => (string) Str::uuid(), 'customer_id' => $cid, 'agent' => $p->agent, 'type' => 'note',
                'notes' => 'Added from the prospect inbox'.($p->source === 'extension' ? ' (captured from the web)' : '').'.', 'created' => $now,
            ]);
            DB::table('prospects')->where('id', $p->id)->update(['status' => 'converted', 'customer_id' => $cid, 'updated' => $now]);

            return $cid;
        });

        return response()->json(['customerId' => $customerId]);
    }

    public function destroy(Request $request, string $id)
    {
        abort_unless($request->user()->is_admin, 403, 'Administrator access is required.');
        abort_unless(DB::table('prospects')->where('id', $id)->delete(), 404, 'Prospect not found.');

        return response()->json(['ok' => true]);
    }

    public function createToken(Request $request)
    {
        $this->sellers($request);
        $token = 'cep_'.Str::random(40);
        DB::table('capture_tokens')->updateOrInsert(['user_id' => $request->user()->id], [
            'token_hash' => hash('sha256', $token), 'created' => now()->toIso8601String(), 'last_used_at' => null,
        ]);

        return response()->json(['token' => $token]);
    }

    public function revokeToken(Request $request)
    {
        DB::table('capture_tokens')->where('user_id', $request->user()->id)->delete();

        return response()->json(['ok' => true]);
    }

    /** Extension: confirm the token works and say who it belongs to. */
    public function ping(Request $request)
    {
        $this->sellers($request);
        $user = $request->user();

        return response()->json([
            'name' => $user->name, 'hasAgent' => (bool) $user->agent_id, 'isAdmin' => (bool) $user->is_admin,
        ]);
    }

    /** Extension: send the page the user is looking at. */
    public function capture(Request $request)
    {
        $this->sellers($request);
        $this->withSchemes($request);
        $v = $request->validate($this->rules() + ['keepForMe' => 'nullable|boolean']);
        $keep = $request->boolean('keepForMe') && $request->user()->agent_id;

        return $this->attempt($request, $this->fields($v), 'extension', $keep ? $request->user()->agent_id : null, !$keep);
    }
}
