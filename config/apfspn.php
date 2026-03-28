<?php

/*
|--------------------------------------------------------------------------
| Quiva Client Authorization
|--------------------------------------------------------------------------
|
| This values are needed to build up the authorization headers. This are used
| to network request to Qore API
|
*/

return [

    'infobip_base_url' => env('INFOBIP_BASE_URL', null),

    'infobip_secret_key' => env('INFOBIP_SECRET_KEY', null),

    'infobip_sender' => env('INFOBIP_SENDER', 'Quiva') ,
];