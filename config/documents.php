<?php

return [
    // Private disk key used by FileStorage for invoice PDFs.
    'signed_url_ttl' => (int) env('DOCUMENTS_SIGNED_URL_TTL', 300),

    // Fallback due-days when the tenant has not configured one.
    'default_due_days' => 14,

    // Series used with SequenceService for invoice numbers.
    'invoice_series' => 'invoices',

    // Series used with SequenceService for credit note and proforma numbers.
    'credit_note_series' => 'credit_notes',
    'proforma_series' => 'proformas',

    // Zero-pad width of the sequence part of a document number ({PREFIX}{YYYY}{NNNN}).
    'number_pad' => 4,
];
