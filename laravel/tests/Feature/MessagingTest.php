<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(string $name): array
    {
        $agentId = (string) Str::uuid();
        DB::table('agents')->insert(['id' => $agentId, 'name' => $name]);
        $user = User::create([
            'name' => $name,
            'email' => Str::slug($name).'@test.invalid',
            'password' => 'password',
            'is_admin' => false,
            'agent_id' => $agentId,
        ]);

        return [$user, $agentId];
    }

    private function makeLoginlessAgent(string $name): string
    {
        $agentId = (string) Str::uuid();
        DB::table('agents')->insert(['id' => $agentId, 'name' => $name]);

        return $agentId;
    }

    public function test_agent_can_start_a_conversation_and_send_a_message(): void
    {
        [$agentA, $agentAId] = $this->makeAgent('Agent A');
        [$agentB, $agentBId] = $this->makeAgent('Agent B');

        $conversationId = $this->actingAs($agentA)->postJson('/api/messages', [
            'participantIds' => [$agentBId],
        ])->assertOk()->json('id');

        $this->actingAs($agentA)->postJson('/api/messages/send', [
            'conversationId' => $conversationId,
            'body' => 'Hello there',
        ])->assertOk();

        $thread = $this->actingAs($agentB)->getJson('/api/messages/thread?conversation='.$conversationId)
            ->assertOk()->json('messages');
        $this->assertCount(1, $thread);
        $this->assertSame('Hello there', $thread[0]['body']);
        $this->assertSame($agentAId, $thread[0]['senderAgentId']);
    }

    public function test_agent_cannot_read_a_conversation_they_are_not_part_of(): void
    {
        [$agentA, $agentAId] = $this->makeAgent('Agent A');
        [, $agentBId] = $this->makeAgent('Agent B');
        [$agentC] = $this->makeAgent('Agent C');

        $conversationId = $this->actingAs($agentA)->postJson('/api/messages', [
            'participantIds' => [$agentBId],
        ])->json('id');

        $this->actingAs($agentC)->getJson('/api/messages/thread?conversation='.$conversationId)
            ->assertForbidden();
        $this->actingAs($agentC)->postJson('/api/messages/send', [
            'conversationId' => $conversationId,
            'body' => 'Sneaky message',
        ])->assertForbidden();
    }

    public function test_starting_a_conversation_with_the_same_pair_reuses_it(): void
    {
        [$agentA] = $this->makeAgent('Agent A');
        [, $agentBId] = $this->makeAgent('Agent B');

        $first = $this->actingAs($agentA)->postJson('/api/messages', [
            'participantIds' => [$agentBId],
        ])->json('id');
        $second = $this->actingAs($agentA)->postJson('/api/messages', [
            'participantIds' => [$agentBId],
        ])->json('id');

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('conversations')->count());
    }

    public function test_message_requires_a_body_or_attachment(): void
    {
        [$agentA] = $this->makeAgent('Agent A');
        [, $agentBId] = $this->makeAgent('Agent B');
        $conversationId = $this->actingAs($agentA)->postJson('/api/messages', [
            'participantIds' => [$agentBId],
        ])->json('id');

        $this->actingAs($agentA)->postJson('/api/messages/send', [
            'conversationId' => $conversationId,
        ])->assertStatus(422);
    }

    public function test_conversation_requires_every_participant_to_have_a_login(): void
    {
        [$agentA] = $this->makeAgent('Agent A');
        $loginlessId = $this->makeLoginlessAgent('Warehouse Only');

        $this->actingAs($agentA)->postJson('/api/messages', [
            'participantIds' => [$loginlessId],
        ])->assertStatus(422);
    }

    public function test_unread_count_reflects_new_messages_until_read(): void
    {
        [$agentA] = $this->makeAgent('Agent A');
        [$agentB, $agentBId] = $this->makeAgent('Agent B');
        $conversationId = $this->actingAs($agentA)->postJson('/api/messages', [
            'participantIds' => [$agentBId],
        ])->json('id');

        $this->actingAs($agentA)->postJson('/api/messages/send', [
            'conversationId' => $conversationId, 'body' => 'One',
        ])->assertOk();
        $this->actingAs($agentA)->postJson('/api/messages/send', [
            'conversationId' => $conversationId, 'body' => 'Two',
        ])->assertOk();

        $listBefore = $this->actingAs($agentB)->getJson('/api/messages')->json('conversations');
        $this->assertSame(2, $listBefore[0]['unreadCount']);

        $this->actingAs($agentB)->getJson('/api/messages/thread?conversation='.$conversationId)->assertOk();

        $listAfter = $this->actingAs($agentB)->getJson('/api/messages')->json('conversations');
        $this->assertSame(0, $listAfter[0]['unreadCount']);
    }

    public function test_attachment_download_is_restricted_to_participants(): void
    {
        [$agentA] = $this->makeAgent('Agent A');
        [, $agentBId] = $this->makeAgent('Agent B');
        [$agentC] = $this->makeAgent('Agent C');
        $conversationId = $this->actingAs($agentA)->postJson('/api/messages', [
            'participantIds' => [$agentBId],
        ])->json('id');

        $file = UploadedFile::fake()->create('note.txt', 10, 'text/plain');
        $this->actingAs($agentA)->postJson('/api/messages/send', [
            'conversationId' => $conversationId,
            'attachments' => [$file],
        ])->assertOk();

        $attachmentId = DB::table('message_attachments')->value('id');

        $this->actingAs($agentC)->getJson('/api/messages/asset?id='.$attachmentId)->assertForbidden();
        $this->actingAs($agentA)->getJson('/api/messages/asset?id='.$attachmentId)->assertOk();
    }
}
