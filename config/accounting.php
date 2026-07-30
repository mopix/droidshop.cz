<?php

return [
    /*
     * How many documents one export may carry. The export streams inside the
     * request (design decision 4), so a very wide period would hit the PHP
     * time limit; refusing with an instruction beats a silent timeout. The
     * figure is an estimate, not a measurement — see the spec's risks.
     */
    'max_documents' => (int) env('ACCOUNTING_MAX_DOCUMENTS', 5000),
];
