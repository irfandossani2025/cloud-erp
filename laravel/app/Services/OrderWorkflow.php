<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderWorkflow
{
    /**
     * Ordered steps of a won order. 'file' says what must be attached to
     * complete the step: 'any' (a PO / email confirmation), 'image' (a photo)
     * or null (optional).
     */
    public const STAGES = [
        'sales_order' => ['label' => 'Sales order', 'file' => 'any'],
        'order_received' => ['label' => 'Order received by production', 'file' => null],
        'sample_photo' => ['label' => '1st sample photo', 'file' => 'image'],
        'material_approval' => ['label' => 'Material approval', 'file' => null],
        'bulk_production' => ['label' => 'Bulk production', 'file' => null],
        'order_packed' => ['label' => 'Order packed', 'file' => null],
        'in_transport' => ['label' => 'In transport', 'file' => null],
        'at_warehouse' => ['label' => 'Arrived at transport warehouse', 'file' => null],
        'qc' => ['label' => 'Quality check', 'file' => null],
        'delivery' => ['label' => 'Delivery to client', 'file' => null],
        'completed' => ['label' => 'Completed', 'file' => null],
    ];

    private const ALLOWED_MIMES = [
        'image/png', 'image/jpeg', 'image/webp', 'image/gif',
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'message/rfc822', 'application/vnd.ms-outlook',
        'text/plain', 'application/zip', 'application/x-zip-compressed',
    ];

    public function __construct(private DocumentFactory $documents) {}

    public function ensureForQuote(object $quote): void
    {
        if (DB::table('orders')->where('quote_id', $quote->id)->exists()) {
            return;
        }
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(), 'quote_id' => $quote->id, 'agent' => $quote->agent,
            'company_id' => $quote->company_id, 'customer' => $quote->customer, 'stage' => 'sales_order',
            'created' => now()->toIso8601String(), 'updated' => now()->toIso8601String(),
        ]);
    }

    public function purgeForQuote(string $quoteId): void
    {
        $orderIds = DB::table('orders')->where('quote_id', $quoteId)->pluck('id');
        foreach (DB::table('order_events')->whereIn('order_id', $orderIds)->whereNotNull('file_path')->pluck('file_path') as $path) {
            Storage::disk('local')->delete($path);
        }
        DB::table('order_events')->whereIn('order_id', $orderIds)->delete();
        DB::table('orders')->whereIn('id', $orderIds)->delete();
    }

    public function advance(string $orderId, string $expectedStage, array $data, ?UploadedFile $file, string $actor): void
    {
        DB::transaction(function () use ($orderId, $expectedStage, $data, $file, $actor) {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            abort_unless($order, 404, 'Order not found.');
            abort_unless($order->stage === $expectedStage, 409, 'This order has moved on. Refresh and try again.');
            abort_if($order->stage === 'completed', 422, 'This order is already completed.');

            if ($file) {
                $this->checkFile($file);
            }
            $needs = self::STAGES[$order->stage]['file'];
            if ($needs && !$file) {
                throw ValidationException::withMessages(['file' => $needs === 'image'
                    ? 'Upload a photo to complete this step.'
                    : 'Upload the purchase order or email confirmation to complete this step.']);
            }
            if ($needs === 'image' && !str_starts_with((string) $file->getMimeType(), 'image/')) {
                throw ValidationException::withMessages(['file' => 'This step needs a photo (PNG, JPG or WebP).']);
            }

            $updates = [];
            if ($order->stage === 'sales_order') {
                $updates['po_number'] = $data['poNumber'] ?? null;
                $updates['po_amount_baisa'] = $data['poAmountBaisa'] ?? null;
                $order->po_number = $updates['po_number'];
            }
            $messages = [];
            if ($order->stage === 'qc') {
                $messages[] = $this->prepareDocuments($order);
            }
            if ($order->stage === 'delivery') {
                $this->markDelivered($order);
            }

            $keys = array_keys(self::STAGES);
            $next = $keys[array_search($order->stage, $keys, true) + 1];
            $this->recordEvent($order->id, 'stage', $order->stage, $data['note'] ?? null, $actor, $file);
            foreach ($messages as $message) {
                $this->recordEvent($order->id, 'note', $next, $message, 'System', null);
            }
            DB::table('orders')->where('id', $order->id)->update([...$updates, 'stage' => $next, 'updated' => now()->toIso8601String()]);
        });
    }

    public function addNote(string $orderId, ?string $note, ?UploadedFile $file, string $actor): void
    {
        $order = DB::table('orders')->where('id', $orderId)->first();
        abort_unless($order, 404, 'Order not found.');
        if (!$note && !$file) {
            throw ValidationException::withMessages(['note' => 'Write a note or attach a file.']);
        }
        if ($file) {
            $this->checkFile($file);
        }
        $this->recordEvent($order->id, 'note', $order->stage, $note, $actor, $file);
        DB::table('orders')->where('id', $order->id)->update(['updated' => now()->toIso8601String()]);
    }

    private function prepareDocuments(object $order): string
    {
        $quote = DB::table('quotes')->where('id', $order->quote_id)->lockForUpdate()->first();
        abort_unless($quote->pricing_status === 'Priced', 422, 'Price every line of the quotation before passing QC.');
        if ($quote->status !== 'Accepted') {
            DB::table('quotes')->where('id', $quote->id)->update([
                'status' => 'Accepted', 'revision' => $quote->revision + 1, 'updated' => now()->toIso8601String(),
            ]);
        }

        $dn = DB::table('delivery_notes')->where('quote_id', $quote->id)->first();
        if (!$dn) {
            $address = $quote->customer_id ? (string) DB::table('customers')->where('id', $quote->customer_id)->value('address') : '';
            $dnId = $this->documents->deliveryNote($quote, $address, '', $order->po_number);
            $dn = DB::table('delivery_notes')->where('id', $dnId)->first();
        } elseif ($order->po_number && !$dn->po_number) {
            DB::table('delivery_notes')->where('id', $dn->id)->update(['po_number' => $order->po_number]);
        }

        $invoice = DB::table('invoices')->where('quote_id', $quote->id)->first();
        if (!$invoice) {
            $invoiceId = $this->documents->invoice($quote);
            $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        } elseif ($order->po_number && !$invoice->po_number) {
            DB::table('invoices')->where('id', $invoice->id)->update(['po_number' => $order->po_number]);
        }

        return sprintf(
            'Quality check passed. Draft delivery note DN-%s and invoice INV-%s were prepared from the quotation for review.',
            str_pad((string) $dn->number, 4, '0', STR_PAD_LEFT),
            str_pad((string) $invoice->number, 4, '0', STR_PAD_LEFT),
        );
    }

    private function markDelivered(object $order): void
    {
        DB::table('delivery_notes')->where('quote_id', $order->quote_id)->update([
            'status' => 'Delivered', 'updated' => now()->toIso8601String(),
        ]);
        $quote = DB::table('quotes')->where('id', $order->quote_id)->first();
        if (!DB::table('invoices')->where('quote_id', $order->quote_id)->exists()) {
            $this->documents->invoice($quote);
        }
    }

    private function recordEvent(string $orderId, string $kind, string $stage, ?string $note, string $actor, ?UploadedFile $file): void
    {
        $id = (string) Str::uuid();
        $row = [
            'id' => $id, 'order_id' => $orderId, 'kind' => $kind, 'stage' => $stage,
            'note' => $note, 'actor' => $actor, 'created' => now()->toIso8601String(),
        ];
        if ($file) {
            $ext = preg_replace('/[^a-z0-9]/', '', strtolower($file->getClientOriginalExtension()));
            $path = "order-files/{$id}".($ext ? ".{$ext}" : '');
            Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));
            $row += [
                'file_path' => $path, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'file_mime' => $file->getMimeType(), 'file_size' => $file->getSize(),
            ];
        }
        DB::table('order_events')->insert($row);
    }

    private function checkFile(UploadedFile $file): void
    {
        $mime = $file->getMimeType();
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages(['file' => 'That file type is not supported.']);
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
                throw ValidationException::withMessages(['file' => 'The image file type does not match its content.']);
            }
        }
    }
}
