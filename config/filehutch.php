<?php

return [

    /*
    | Project-scoped key from Dashboard → API keys. Server side only: it can
    | upload, sign and delete, so it never goes near a browser.
    */
    'api_key' => env('FILE_HUTCH_API_KEY'),

    // Only when not using FileHutch cloud.
    'url' => env('FILE_HUTCH_URL', 'https://api.filehutch.com'),

    // Seconds per request.
    'timeout' => (float) env('FILE_HUTCH_TIMEOUT', 30),

    // Signs webhook deliveries. Dashboard → Webhooks, shown once per endpoint.
    'webhook_secret' => env('FILE_HUTCH_WEBHOOK_SECRET'),

    /*
    | Browser-direct uploads: two routes that proxy the control-plane calls, so
    | the bytes go from the browser to storage and the key stays here. The
    | default prefix is the one <filehutch-upload> posts to without an
    | `endpoint` attribute. Closed until you call DirectUploads::authorize().
    */
    'direct_uploads' => [
        'enabled' => true,
        'prefix' => 'file_hutch',
        'middleware' => ['web'],
    ],

];
