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
];
