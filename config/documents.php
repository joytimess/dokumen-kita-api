<?php

/*
| Limits below are placeholders until decision B04 (size/page/signer limits) is made after the POC.
*/
return [

    /* Private disk for sources, previews, signatures and final PDFs. Use an S3-compatible disk (R2) in staging/production. */
    'disk' => env('DOCUMENT_DISK', 'local'),

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
