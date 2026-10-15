<?php

/*
| Limits below are placeholders until decision B04 (size/page/signer limits) is made after the POC.
*/
return [

    /* Private disk for sources, previews, signatures and final PDFs. Use an S3-compatible disk (R2) in staging/production. */
    'disk' => env('DOCUMENT_DISK', 'local'),

    'processing' => [
        'connection' => env('DOCUMENT_QUEUE_CONNECTION', 'database'),
        'queue' => env('DOCUMENT_QUEUE', 'documents'),
        'max_pages' => 200,
        'libreoffice_binary' => env('LIBREOFFICE_BINARY'),
    ],

    'invitations' => [
        'expires_days' => (int) env('INVITATION_EXPIRES_DAYS', 7),
    ],

    'signing' => [
        'max_signers' => 50,
        'max_placeholders_per_signer' => 100,
    ],

    'signature_assets' => [
        'max_kilobytes' => 2048,
        'max_width' => 2048,
        'max_height' => 1024,
        'min_ink_pixels' => 10,
    ],

    'import' => [
        'max_kilobytes' => (int) env('DOCUMENT_IMPORT_MAX_KB', 20480),
    ],

    'editor' => [
        'max_blocks' => 500,
        'max_bytes' => 1_048_576,
        'max_text_length' => 20_000,
        'max_list_items' => 200,
        'max_list_depth' => 3,
        'max_table_rows' => 50,
        'max_table_columns' => 20,
        'max_image_data_length' => 700_000,
    ],

];
