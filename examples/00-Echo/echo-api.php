<?php
require_once('../../api.php');

function apiEcho($req) {
    return $req['data'];
}

API::$def = array(
    'ECHO API' => array(                     // API name
        'endpoints' => array(                // definition of endpoints
            'echo' => array(                 // echo endpoint
                'POST' => array(
                    'function' => 'apiEcho'
                ),
            ),
        ),
    ),
);

API::run();
