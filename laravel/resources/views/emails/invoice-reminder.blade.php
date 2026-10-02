<!doctype html>
<html>
<body style="font-family: Arial, sans-serif; color: #17283e; line-height: 1.6; max-width: 560px;">
    <p>Dear {{ $invoice->customer }},</p>
    <p>
        This is a friendly reminder that invoice
        <strong>INV-{{ str_pad((string) $invoice->number, 4, '0', STR_PAD_LEFT) }}</strong>
        from {{ $companyName }} is due on <strong>{{ $dueOn }}</strong>
        ({{ $daysLeft <= 0 ? 'today' : ($daysLeft === 1 ? 'tomorrow' : 'in '.$daysLeft.' days') }}).
    </p>
    <table style="border-collapse: collapse; margin: 16px 0;">
        <tr><td style="padding: 4px 16px 4px 0; color: #637085;">Invoice</td><td>INV-{{ str_pad((string) $invoice->number, 4, '0', STR_PAD_LEFT) }}</td></tr>
        @if ($invoice->po_number)
            <tr><td style="padding: 4px 16px 4px 0; color: #637085;">Your PO</td><td>{{ $invoice->po_number }}</td></tr>
        @endif
        <tr><td style="padding: 4px 16px 4px 0; color: #637085;">Amount due</td><td><strong>OMR {{ number_format($invoice->total / 1000, 3) }}</strong> (incl. VAT)</td></tr>
        <tr><td style="padding: 4px 16px 4px 0; color: #637085;">Due date</td><td>{{ $dueOn }}</td></tr>
    </table>
    <p>If you have already arranged payment, please disregard this message and accept our thanks.</p>
    <p>
        If you have any questions about this invoice, simply reply to this email{{ $agentName ? ' to reach '.$agentName : '' }}.
    </p>
    <p>Kind regards,<br>{{ $companyName }}</p>
</body>
</html>
