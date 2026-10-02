<?php

namespace App\Console\Commands;

use App\Mail\InvoicePaymentReminder;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class SendPaymentReminders extends Command
{
    protected $signature = 'erp:send-payment-reminders {--allow-log : Run even when mail is only logged, not sent}';

    protected $description = 'Email clients a reminder shortly before a sent invoice falls due';

    public function handle(): int
    {
        if (in_array(config('mail.default'), ['log', 'array'], true) && !$this->option('allow-log')) {
            $this->error('Mail is not configured (MAIL_MAILER is "'.config('mail.default').'"). Set the SMTP details in .env first.');

            return self::FAILURE;
        }

        $windows = collect(config('erp.reminder_days'))->sort()->values();
        $today = now(config('erp.timezone'))->startOfDay();
        $sent = 0;

        $invoices = DB::table('invoices')
            ->where('status', 'Sent')
            ->whereNotNull('due_date')
            ->where('email', '!=', '')
            ->get();

        foreach ($invoices as $invoice) {
            $due = Carbon::parse(substr($invoice->due_date, 0, 10), config('erp.timezone'))->startOfDay();
            $daysLeft = (int) $today->diffInDays($due, false);
            $window = $windows->first(fn ($days) => $daysLeft <= $days);
            if ($daysLeft < 0 || $window === null) {
                continue;
            }
            if (DB::table('invoice_reminders')->where('invoice_id', $invoice->id)->where('days_before', $window)->exists()) {
                continue;
            }

            $company = DB::table('companies')->where('id', $invoice->company_id)->first();
            $agent = DB::table('users')->where('agent_id', $invoice->agent)->first();
            try {
                Mail::to($invoice->email)->send(new InvoicePaymentReminder(
                    $invoice,
                    $company ? ($company->trading_name ?: $company->name) : config('app.name'),
                    $daysLeft,
                    $due->format('j M Y'),
                    $agent->name ?? null,
                    $agent->email ?? null,
                ));
            } catch (Throwable $e) {
                Log::error('Payment reminder failed for invoice '.$invoice->number.': '.$e->getMessage());
                $this->warn('Could not email INV-'.$invoice->number.': '.$e->getMessage());

                continue;
            }
            DB::table('invoice_reminders')->insert([
                'id' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'days_before' => $window,
                'sent_to' => $invoice->email, 'sent_at' => now()->toIso8601String(),
            ]);
            $sent++;
            $this->info('Reminded '.$invoice->email.' about INV-'.str_pad((string) $invoice->number, 4, '0', STR_PAD_LEFT));
        }

        $this->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }
}
