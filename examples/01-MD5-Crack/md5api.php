<?php
require_once('../../api.php');

function calculate($req) {
    if (!isset($req['data']['str'])) {
        API::resp(400);
    }

    return array(
        'str' => $req['data']['str'],
        'md5' => md5($req['data']['str'])
    );
}

function crack($req) {
    define('REGEX_MD5', '/^[a-f0-9]{32}$/i');

    if (!isset($req['data']['md5'])) {
        API::resp(400);
    }

    $hash = $req['data']['md5'];
    $str = 'a';

    if (!preg_match(REGEX_MD5, $hash)) {
        return array('error' => "\"$hash\" is not valid MD5 hash!");
    }

    $hash = strtolower($hash);

    while (strlen($str) < 5) {
        if (md5($str) == $hash) {
            return array(
                'str' => $str,
                'md5' => $hash
            );
        }

        ++$str;
    }

    return array('error' => "I can not crack \"$hash\"!");
}

API::$def = json_decode('{
    "MD5_API": {
        "endpoints": {
            "calculate": {
                "GET": {
                    "function": "calculate"
                }
            },
            "crack": {
                "GET": {
                    "function": "crack"
                }
            }
        }
    }
}', true);

API::run();
