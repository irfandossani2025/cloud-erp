<?php

return [
    'supplier_username' => env('LUXURY_API_USERNAME', ''),
    'supplier_password' => env('LUXURY_API_PASSWORD', ''),
    'gemini_key' => env('GEMINI_API_KEY', ''),
    'text_model' => env('GEMINI_TEXT_MODEL', 'gemini-2.5-flash'),
    'image_model' => env('GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image'),
    'default_rate' => 0.104699,
    // Oman's statutory VAT rate. A fixed government rate, not a per-quote
    // business setting like the currency conversion rate.
    'vat_rate' => 0.05,
    // Days after the invoice date that payment is due unless it is changed.
    'payment_terms_days' => (int) env('ERP_PAYMENT_TERMS_DAYS', 30),
    // Clients are emailed this many days before an invoice is due.
    'reminder_days' => [7, 1],
    'timezone' => 'Asia/Muscat',
];
