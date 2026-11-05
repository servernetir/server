<?php

return [
    // A parallel customer interface only. The existing /account URLs stay active.
    // A private storage/app/console-next.state marker can explicitly override this
    // value through console-next:enable / console-next:disable, without .env edits.
    'enabled' => filter_var(env('CONSOLE_NEXT_ENABLED', false), FILTER_VALIDATE_BOOL),
];
