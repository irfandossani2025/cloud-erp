<?php

namespace App\Http\Controllers;

use App\Services\ErpAccess;
use App\Services\OrderWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OrderController extends Controller
{
    public function __construct(private ErpAccess $access, private OrderWorkflow $workflow) {}

    public function advance(Request $request, string $id)
    {
        $order = DB::table('orders')->where('id', $id)->first();
        abort_unless($order, 404, 'Order not found.');
        $this->access->agent($request, $order->agent);
        $v = $request->validate([
            'stage' => 'required|string',
            'note' => 'nullable|string|max:2000',
            'poNumber' => 'nullable|string|max:100',
            'poAmountBaisa' => 'nullable|integer|min:0|max:1000000000000',
            'file' => 'nullable|file|max:8192',
        ]);
        $this->workflow->advance($id, $v['stage'], $v, $request->file('file'), $request->user()->name);

        return response()->json(['ok' => true]);
    }

    public function note(Request $request, string $id)
    {
        $order = DB::table('orders')->where('id', $id)->first();
        abort_unless($order, 404, 'Order not found.');
        $this->access->agent($request, $order->agent);
        $v = $request->validate([
            'note' => 'nullable|string|max:2000',
            'file' => 'nullable|file|max:8192',
        ]);
        $this->workflow->addNote($id, $v['note'] ?? null, $request->file('file'), $request->user()->name);

        return response()->json(['ok' => true]);
    }

    public function file(Request $request, string $id)
    {
        $event = DB::table('order_events')->where('id', $id)->first();
        abort_unless($event && $event->file_path, 404, 'Not found');
        $order = DB::table('orders')->where('id', $event->order_id)->first();
        $user = $request->user();
        abort_unless($user->is_admin || $user->role === 'accounts' || $user->agent_id === $order->agent, 403, 'This order belongs to another sales agent.');
        abort_unless(Storage::disk('local')->exists($event->file_path), 404, 'Not found');

        return response(Storage::disk('local')->get($event->file_path), 200, [
            'Content-Type' => $event->file_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($event->file_name).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
