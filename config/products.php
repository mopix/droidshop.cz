<?php

return [
    'import' => [
        // Kilobytes. A catalogue of ~20 000 rows fits comfortably; anything
        // larger is a sign the shop wants a scheduled supplier feed, which is
        // a different feature (docs/future/).
        'max_size_kb' => (int) env('PRODUCTS_IMPORT_MAX_SIZE_KB', 5120),

        // Rows between progress writes. Small enough that the admin screen
        // moves, large enough that the run is not dominated by UPDATEs.
        'chunk' => (int) env('PRODUCTS_IMPORT_CHUNK', 200),
    ],
];
