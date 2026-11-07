<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | The package's own default page path is 'resources/js/pages' (lowercase),
    | which never matches this app's actual 'resources/js/Pages' on a
    | case-sensitive filesystem — silently breaking any test assertion that
    | checks a page component exists. Override just the path here.
    */

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [
            resource_path('js/Pages'),
        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

];
