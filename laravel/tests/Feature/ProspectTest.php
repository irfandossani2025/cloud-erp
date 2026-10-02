<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProspectTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(string $name, bool $admin = false, ?string $role = null): array
    {
        $agentId = (string) Str::uuid();
        DB::table('agents')->insert(['id' => $agentId, 'name' => $name]);
        $user = User::create([
            'name' => $name, 'email' => Str::slug($name).'@test.invalid', 'password' => 'password',
            'is_admin' => $admin, 'agent_id' => $agentId, 'role' => $role,
        ]);

        return [$user, $agentId];
    }

    private function add(User $user, array $data = [])
    {
        return $this->actingAs($user)->postJson('/api/prospects', ['name' => 'Jane Doe', 'company' => 'Acme', ...$data]);
    }

    private function token(User $user): string
    {
        return $this->actingAs($user)->postJson('/api/capture-token')->assertOk()->json('token');
    }

    private function capture(string $token, array $data)
    {
        // Fresh app so the bearer token, not the previous actingAs session, authenticates.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/capture/prospect', $data);
    }

    public function test_leads_rotate_through_eligible_agents_only(): void
    {
        [$admin] = $this->makeAgent('Admin', true);
        [, $a] = $this->makeAgent('Alice');
        [, $b] = $this->makeAgent('Bob');
        [, $acc] = $this->makeAgent('Akeeb', false, 'accounts');
        DB::table('agents')->where('id', $acc)->update(['takes_leads' => false]);
        DB::table('agents')->where('id', $admin->agent_id)->update(['takes_leads' => false]);
        $localOnly = (string) Str::uuid();
        DB::table('agents')->insert(['id' => $localOnly, 'name' => 'Aaron (no sign-in)']);

        $order = [];
        foreach (range(1, 5) as $i) {
            $id = $this->add($admin, ['name' => "Lead $i", 'company' => "Co $i"])->assertOk()->json('id');
            $order[] = DB::table('prospects')->where('id', $id)->value('agent');
        }

        $this->assertSame([$a, $b, $a, $b, $a], $order);
    }

    public function test_unassigned_when_nobody_takes_leads_and_admin_can_assign_later(): void
    {
        [$admin, $adminAgent] = $this->makeAgent('Admin', true);
        DB::table('agents')->update(['takes_leads' => false]);
        $id = $this->add($admin)->assertOk()->json('id');
        $this->assertNull(DB::table('prospects')->where('id', $id)->value('agent'));

        $this->actingAs($admin)->postJson("/api/prospects/$id/assign", ['agentId' => $adminAgent])->assertOk();
        $this->assertSame($adminAgent, DB::table('prospects')->where('id', $id)->value('agent'));

        $this->actingAs($admin)->postJson("/api/prospects/$id/assign", ['agentId' => 'auto'])->assertStatus(422);
    }

    public function test_capture_token_flow_and_replacement(): void
    {
        [$user] = $this->makeAgent('Alice');
        $this->makeAgent('Bob');
        $token = $this->token($user);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer nope')->getJson('/api/capture/ping')->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/capture/ping')->assertOk()->assertJson(['name' => 'Alice']);

        $this->capture($token, ['name' => 'Sam', 'company' => 'Zed', 'linkedinUrl' => 'https://www.linkedin.com/in/sam/'])
            ->assertOk()->assertJson(['created' => true, 'duplicate' => null]);
        $this->assertSame('extension', DB::table('prospects')->value('source'));
        $this->assertSame('Alice', DB::table('prospects')->value('captured_by'));

        $new = $this->token($user);
        $this->capture($token, ['name' => 'X'])->assertStatus(401);
        $this->capture($new, ['name' => 'X'])->assertOk();
        $this->assertSame(hash('sha256', $new), DB::table('capture_tokens')->value('token_hash'));
        $this->assertStringNotContainsString($new, json_encode(DB::table('capture_tokens')->get()));
    }

    public function test_keep_for_me_overrides_rotation(): void
    {
        [$alice, $a] = $this->makeAgent('Alice');
        $this->makeAgent('Bob');
        $token = $this->token($alice);
        $r = $this->capture($token, ['name' => 'Mine', 'keepForMe' => true])->assertOk();
        $this->assertTrue($r->json('mine'));
        $this->assertSame($a, DB::table('prospects')->value('agent'));
    }

    public function test_duplicates_are_recognised_and_only_blanks_are_filled(): void
    {
        [$admin] = $this->makeAgent('Admin', true);
        $first = $this->add($admin, ['name' => 'Sam', 'company' => 'Zed', 'linkedinUrl' => 'https://uk.linkedin.com/in/Sam-Lee/?trk=abc'])->json();
        $this->assertSame('https://www.linkedin.com/in/sam-lee', DB::table('prospects')->value('linkedin_url'));

        $again = $this->add($admin, ['name' => 'Samuel', 'company' => 'Other', 'phone' => '+968 1', 'linkedinUrl' => 'https://www.linkedin.com/in/sam-lee/'])->assertOk()->json();
        $this->assertSame('prospect', $again['duplicate']);
        $this->assertSame($first['id'], $again['id']);
        $row = DB::table('prospects')->first();
        $this->assertSame('Sam', $row->name);
        $this->assertSame('+968 1', $row->phone);
        $this->assertSame(1, DB::table('prospects')->count());

        $this->add($admin, ['name' => 'P', 'company' => 'Q', 'email' => 'Pat@Q.test'])->assertOk();
        $this->assertSame('prospect', $this->add($admin, ['name' => 'Someone', 'company' => 'Else', 'email' => 'pat@q.test'])->json('duplicate'));
        $this->assertSame('prospect', $this->add($admin, ['name' => 'sam', 'company' => 'ZED'])->json('duplicate'));
    }

    public function test_existing_customer_email_is_reported(): void
    {
        [$admin, $agentId] = $this->makeAgent('Admin', true);
        DB::table('customers')->insert(['id' => (string) Str::uuid(), 'agent' => $agentId, 'company' => 'Old Co', 'contact_name' => 'Olga', 'email' => 'olga@old.test', 'stage' => 'Won', 'created' => now()->toIso8601String(), 'updated' => now()->toIso8601String()]);
        $r = $this->add($admin, ['name' => 'Olga', 'company' => 'Old Co', 'email' => 'OLGA@old.test'])->assertOk();
        $this->assertSame('customer', $r->json('duplicate'));
        $this->assertSame(0, DB::table('prospects')->count());
    }

    public function test_input_is_validated(): void
    {
        [$admin] = $this->makeAgent('Admin', true);
        $this->add($admin, ['name' => '', 'company' => ''])->assertStatus(422);
        $this->add($admin, ['website' => 'javascript:alert(1)'])->assertStatus(422);
        $this->add($admin, ['email' => 'nope'])->assertStatus(422);
        $this->add($admin, ['website' => 'acme.com', 'linkedinUrl' => 'linkedin.com/in/x'])->assertOk();
        $this->assertSame('https://acme.com', DB::table('prospects')->value('website'));
    }

    public function test_agents_only_see_and_edit_their_own_prospects(): void
    {
        [$admin] = $this->makeAgent('Admin', true);
        [$alice, $a] = $this->makeAgent('Alice');
        [$bob] = $this->makeAgent('Bob');
        [$akeeb] = $this->makeAgent('Akeeb', false, 'accounts');
        DB::table('agents')->where('id', $akeeb->agent_id)->update(['takes_leads' => false]);
        DB::table('agents')->where('id', $admin->agent_id)->update(['takes_leads' => false]);
        $mine = $this->add($admin, ['name' => 'For Alice'])->json('id');
        $this->add($admin, ['name' => 'For Bob']);

        $seen = fn (User $u) => collect($this->actingAs($u)->getJson('/api/erp')->json('prospects'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['For Alice'], $seen($alice));
        $this->assertSame(['For Bob'], $seen($bob));
        $this->assertSame(['For Alice', 'For Bob'], $seen($admin));
        $this->assertSame([], $seen($akeeb));

        $this->actingAs($bob)->postJson("/api/prospects/$mine", ['name' => 'Hijack'])->assertStatus(403);
        $this->actingAs($akeeb)->postJson("/api/prospects/$mine", ['name' => 'Hijack'])->assertStatus(403);
        $this->actingAs($alice)->postJson("/api/prospects/$mine", ['name' => 'Renamed', 'company' => 'Acme', 'status' => 'contacted', 'phone' => '123'])->assertOk();
        $row = DB::table('prospects')->where('id', $mine)->first();
        $this->assertSame(['Renamed', 'contacted'], [$row->name, $row->status]);
        $this->actingAs($akeeb)->postJson('/api/prospects', ['name' => 'X'])->assertStatus(403);
        $this->actingAs($akeeb)->postJson('/api/capture-token')->assertStatus(403);
    }

    public function test_only_admin_assigns_or_deletes(): void
    {
        [$admin] = $this->makeAgent('Admin', true);
        [$alice, $a] = $this->makeAgent('Alice');
        $id = $this->add($alice)->json('id');
        $this->actingAs($alice)->postJson("/api/prospects/$id/assign", ['agentId' => $a])->assertStatus(403);
        $this->actingAs($alice)->postJson("/api/prospects/$id/delete")->assertStatus(403);
        $this->actingAs($admin)->postJson("/api/prospects/$id/delete")->assertOk();
        $this->assertSame(0, DB::table('prospects')->count());
    }

    public function test_converting_creates_a_customer_once(): void
    {
        [$alice, $a] = $this->makeAgent('Alice');
        [$bob] = $this->makeAgent('Bob');
        $id = $this->add($alice, ['name' => 'Jane', 'company' => 'Acme', 'email' => 'jane@acme.test', 'title' => 'Procurement', 'notes' => 'Met at expo', 'assignToMe' => true])->json('id');

        $this->actingAs($bob)->postJson("/api/prospects/$id/convert")->assertStatus(403);
        $cid = $this->actingAs($alice)->postJson("/api/prospects/$id/convert")->assertOk()->json('customerId');

        $c = DB::table('customers')->where('id', $cid)->first();
        $this->assertSame([$a, 'Acme', 'Jane', 'jane@acme.test', 'New Lead'], [$c->agent, $c->company, $c->contact_name, $c->email, $c->stage]);
        $this->assertStringContainsString('Procurement', $c->notes);
        $this->assertStringContainsString('Met at expo', $c->notes);
        $this->assertSame(1, DB::table('customer_activities')->where('customer_id', $cid)->count());
        $p = DB::table('prospects')->where('id', $id)->first();
        $this->assertSame(['converted', $cid], [$p->status, $p->customer_id]);

        $this->actingAs($alice)->postJson("/api/prospects/$id/convert")->assertStatus(422);
        $this->actingAs($alice)->postJson("/api/prospects/$id", ['name' => 'Jane', 'status' => 'not_a_fit'])->assertOk();
        $this->assertSame('converted', DB::table('prospects')->where('id', $id)->value('status'));
    }

    public function test_cannot_convert_an_unassigned_prospect(): void
    {
        [$admin] = $this->makeAgent('Admin', true);
        DB::table('agents')->update(['takes_leads' => false]);
        $id = $this->add($admin)->json('id');
        $this->actingAs($admin)->postJson("/api/prospects/$id/convert")->assertStatus(422);
    }

    public function test_admin_controls_who_receives_leads(): void
    {
        [$admin] = $this->makeAgent('Admin', true);
        [$alice, $a] = $this->makeAgent('Alice');
        $this->actingAs($alice)->postJson('/api/erp', ['action' => 'agent_takes_leads', 'agentId' => $a, 'takesLeads' => false])->assertStatus(403);
        $this->actingAs($admin)->postJson('/api/erp', ['action' => 'agent_takes_leads', 'agentId' => $a, 'takesLeads' => false])->assertOk();
        $this->assertSame(0, (int) DB::table('agents')->where('id', $a)->value('takes_leads'));
        $agents = collect($this->actingAs($admin)->getJson('/api/erp')->json('agents'))->firstWhere('id', $a);
        $this->assertEmpty($agents['takes_leads']);
    }

    public function test_accounts_staff_never_receive_leads(): void
    {
        [$admin] = $this->makeAgent('Admin', true);
        $made = $this->actingAs($admin)->postJson('/api/erp', ['action' => 'agent', 'name' => 'Akeeb', 'email' => 'ak@test.invalid', 'password' => 'password123', 'role' => 'accounts'])->assertOk()->json('id');
        $this->assertSame(0, (int) DB::table('agents')->where('id', $made)->value('takes_leads'));

        [, $sam] = $this->makeAgent('Sam');
        $this->actingAs($admin)->postJson('/api/erp', ['action' => 'agent_role', 'agentId' => $sam, 'role' => 'accounts'])->assertOk();
        $this->assertSame(0, (int) DB::table('agents')->where('id', $sam)->value('takes_leads'));
    }
}
