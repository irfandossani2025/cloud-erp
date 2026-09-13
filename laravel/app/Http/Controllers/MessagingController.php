<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MessagingController extends Controller
{
    private const MAX_ATTACHMENTS = 3;

    private const ALLOWED_MIMES = [
        'image/png', 'image/jpeg', 'image/webp', 'image/gif',
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain', 'application/zip', 'application/x-zip-compressed',
    ];

    public function index(Request $request)
    {
        $agentId = $this->currentAgent($request);
        $conversationIds = DB::table('conversation_participants')->where('agent_id', $agentId)->pluck('conversation_id');

        $conversations = DB::table('conversations')->whereIn('id', $conversationIds)->orderByDesc('updated')->get();

        $participants = DB::table('conversation_participants')
            ->join('agents', 'agents.id', '=', 'conversation_participants.agent_id')
            ->whereIn('conversation_id', $conversationIds)
            ->get(['conversation_id', 'agent_id', 'name', 'last_read_at'])
            ->groupBy('conversation_id');

        $lastMessages = DB::table('messages')
            ->whereIn('conversation_id', $conversationIds)
            ->orderByDesc('created')
            ->get()
            ->unique('conversation_id')
            ->keyBy('conversation_id');

        $unreadCounts = DB::table('messages')
            ->whereIn('conversation_id', $conversationIds)
            ->where('sender_agent_id', '!=', $agentId)
            ->orderBy('created')
            ->get(['conversation_id', 'created'])
            ->groupBy('conversation_id');

        $directory = DB::table('users')
            ->join('agents', 'agents.id', '=', 'users.agent_id')
            ->whereNotNull('users.agent_id')
            ->where('users.agent_id', '!=', $agentId)
            ->orderBy('agents.name')
            ->get(['agents.id', 'agents.name'])
            ->unique('id')
            ->values();

        return response()->json([
            'conversations' => $conversations->map(function ($c) use ($participants, $lastMessages, $unreadCounts, $agentId) {
                $people = ($participants[$c->id] ?? collect());
                $me = $people->firstWhere('agent_id', $agentId);
                $others = $people->where('agent_id', '!=', $agentId)->values();
                $last = $lastMessages[$c->id] ?? null;
                $myLastRead = $me?->last_read_at;
                $unread = ($unreadCounts[$c->id] ?? collect())
                    ->when($myLastRead, fn ($rows) => $rows->filter(fn ($r) => $r->created > $myLastRead))
                    ->count();
                return [
                    'id' => $c->id,
                    'isGroup' => (bool) $c->is_group,
                    'title' => $c->title ?: $others->pluck('name')->implode(', '),
                    'participants' => $people->map(fn ($p) => ['agentId' => $p->agent_id, 'name' => $p->name])->values(),
                    'lastMessage' => $last ? ['body' => $last->body, 'senderAgentId' => $last->sender_agent_id, 'created' => $last->created] : null,
                    'unreadCount' => $unread,
                    'updated' => $c->updated,
                ];
            }),
            'directory' => $directory,
        ]);
    }

    public function store(Request $request)
    {
        $agentId = $this->currentAgent($request);
        $v = $request->validate([
            'participantIds' => 'required|array|min:1|max:20',
            'participantIds.*' => 'required|uuid|exists:agents,id',
            'title' => 'nullable|string|max:200',
        ]);
        $participantIds = array_values(array_unique([...$v['participantIds'], $agentId]));
        $messageable = DB::table('users')->whereIn('agent_id', $participantIds)->pluck('agent_id')->all();
        abort_unless(count(array_diff($participantIds, $messageable)) === 0, 422, 'Everyone in the conversation needs a staff login.');
        $isGroup = count($participantIds) > 2;

        if (!$isGroup) {
            $candidates = DB::table('conversation_participants')
                ->whereIn('agent_id', $participantIds)
                ->select('conversation_id')
                ->groupBy('conversation_id')
                ->havingRaw('COUNT(DISTINCT agent_id) = 2')
                ->pluck('conversation_id');
            foreach ($candidates as $cid) {
                $total = DB::table('conversation_participants')->where('conversation_id', $cid)->count();
                $isTwoPerson = DB::table('conversations')->where('id', $cid)->where('is_group', false)->exists();
                if ($total === 2 && $isTwoPerson) {
                    return response()->json(['id' => $cid]);
                }
            }
        }

        $id = DB::transaction(function () use ($participantIds, $isGroup, $v) {
            $id = (string) Str::uuid();
            DB::table('conversations')->insert([
                'id' => $id, 'is_group' => $isGroup, 'title' => $isGroup ? ($v['title'] ?? null) : null,
                'created' => now()->toIso8601String(), 'updated' => now()->toIso8601String(),
            ]);
            foreach ($participantIds as $pid) {
                DB::table('conversation_participants')->insert(['id' => (string) Str::uuid(), 'conversation_id' => $id, 'agent_id' => $pid]);
            }
            return $id;
        });
        return response()->json(['id' => $id]);
    }

    public function thread(Request $request)
    {
        $agentId = $this->currentAgent($request);
        $v = $request->validate(['conversation' => 'required|uuid|exists:conversations,id']);
        $this->assertParticipant($v['conversation'], $agentId);

        $messages = DB::table('messages')->where('conversation_id', $v['conversation'])->orderBy('created')->limit(500)->get();
        $attachments = DB::table('message_attachments')->whereIn('message_id', $messages->pluck('id'))->get()->groupBy('message_id');
        $agentNames = DB::table('agents')->pluck('name', 'id');

        DB::table('conversation_participants')->where('conversation_id', $v['conversation'])->where('agent_id', $agentId)
            ->update(['last_read_at' => now()->toIso8601String()]);

        return response()->json([
            'messages' => $messages->map(fn ($m) => [
                'id' => $m->id,
                'senderAgentId' => $m->sender_agent_id,
                'senderName' => $agentNames[$m->sender_agent_id] ?? 'Unknown',
                'body' => $m->body,
                'created' => $m->created,
                'attachments' => ($attachments[$m->id] ?? collect())->map(fn ($a) => [
                    'id' => $a->id, 'filename' => $a->filename, 'mime' => $a->mime, 'size' => $a->size,
                ])->values(),
            ]),
        ]);
    }

    public function send(Request $request)
    {
        $agentId = $this->currentAgent($request);
        $v = $request->validate([
            'conversationId' => 'required|uuid|exists:conversations,id',
            'body' => 'nullable|string|max:4000',
            'attachments' => 'nullable|array|max:'.self::MAX_ATTACHMENTS,
            'attachments.*' => 'file|max:8192',
        ]);
        $this->assertParticipant($v['conversationId'], $agentId);
        $files = $request->file('attachments', []);
        abort_if(empty($v['body']) && empty($files), 422, 'Write a message or attach a file.');
        foreach ($files as $file) $this->checkAttachment($file);

        $id = (string) Str::uuid();
        DB::table('messages')->insert([
            'id' => $id, 'conversation_id' => $v['conversationId'], 'sender_agent_id' => $agentId,
            'body' => $v['body'] ?? null, 'created' => now()->toIso8601String(),
        ]);
        foreach ($files as $file) {
            $attId = (string) Str::uuid();
            $path = "message-attachments/{$attId}.".$file->getClientOriginalExtension();
            Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));
            DB::table('message_attachments')->insert([
                'id' => $attId, 'message_id' => $id, 'filename' => $file->getClientOriginalName(),
                'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(),
            ]);
        }
        DB::table('conversations')->where('id', $v['conversationId'])->update(['updated' => now()->toIso8601String()]);
        DB::table('conversation_participants')->where('conversation_id', $v['conversationId'])->where('agent_id', $agentId)
            ->update(['last_read_at' => now()->toIso8601String()]);

        return response()->json(['id' => $id]);
    }

    public function asset(Request $request)
    {
        $agentId = $this->currentAgent($request);
        $v = $request->validate(['id' => 'required|uuid|exists:message_attachments,id']);
        $attachment = DB::table('message_attachments')->where('id', $v['id'])->first();
        $message = DB::table('messages')->where('id', $attachment->message_id)->first();
        $this->assertParticipant($message->conversation_id, $agentId);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404, 'Not found');
        return response(Storage::disk('local')->get($attachment->path), 200, [
            'Content-Type' => $attachment->mime,
            'Content-Disposition' => 'inline; filename="'.addslashes($attachment->filename).'"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function currentAgent(Request $request): string
    {
        $agentId = $request->user()->agent_id;
        abort_unless($agentId, 403, 'Only staff accounts can use messaging.');
        return $agentId;
    }

    private function assertParticipant(string $conversationId, string $agentId): void
    {
        abort_unless(
            DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('agent_id', $agentId)->exists(),
            403,
            'You are not part of this conversation.'
        );
    }

    private function checkAttachment(UploadedFile $file): void
    {
        $mime = $file->getMimeType();
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages(['attachments' => 'That file type is not supported.']);
        }
        if (str_starts_with((string) $mime, 'image/')) {
            $head = substr(file_get_contents($file->getRealPath()), 0, 12);
            $valid = match ($mime) {
                'image/png' => substr($head, 0, 4) === "\x89PNG",
                'image/jpeg' => substr($head, 0, 3) === "\xFF\xD8\xFF",
                'image/webp' => substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP',
                'image/gif' => substr($head, 0, 3) === 'GIF',
                default => false,
            };
            if (!$valid) {
                throw ValidationException::withMessages(['attachments' => 'The image file type does not match its content.']);
            }
        }
    }
}
