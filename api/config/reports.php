<?php

return [
    // Timezone used to bucket entries into days and to render times in reports.
    'timezone' => env('REPORT_TIMEZONE', 'Europe/Berlin'),

    // Allowed rounding intervals (minutes). 0 disables rounding.
    'rounding_intervals' => [0, 5, 6, 10, 15, 30, 60],

    // Default rounding when the client does not send one.
    'default_rounding' => (int) env('REPORT_DEFAULT_ROUNDING', 0),
];
