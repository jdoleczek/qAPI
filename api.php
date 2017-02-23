<?php

/**
 * qAPI - Inspired by Swagger but a bit different PHP library for speeding up with API's and their documentation.
 *
 * @package  qAPI
 * @author   Jan Doleczek <jan@doleczek.pl>
 */

class APIException extends Exception {}

class API
{
    public static $rewrite = false;
    public static $cors = true;
    public static $pretty = false;
    public static $def = array();
    public static $types = array();

    private static $httpCode = 200;

    private static $httpCodes = array(
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Moved Temporarily',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Time-out',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Large',
        415 => 'Unsupported Media Type',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Time-out',
        505 => 'HTTP Version not supported',
    );

    public static $corsHeaders = array(
        'X-Requested-With',
        'Content-Type',
        'Accept',
        'Origin',
        'Authorization',
        'Expect-Type',
        'Access-Token'
    );

    public static function setHttpCode($code)
    {
        self::$httpCode = isset(self::$httpCodes[$code]) ? $code : 500;
        return self::$httpCode;
    }

    private static function _resp($data)
    {
        if (!defined('JSON_PRETTY_PRINT')) {
            define('JSON_PRETTY_PRINT', 128);
        }

        header('HTTP/1.1 ' . self::$httpCode . ' ' . self::$httpCodes[self::$httpCode]);
        header('Content-Type: application/json');
        echo json_encode($data, self::$pretty ? JSON_PRETTY_PRINT : 0);
    }

    public static function resp($code)
    {
        throw new APIException($code);
    }

    public static function withoutHidden()
    {
        return array('msg' => 'api');
    }

    public static function run()
    {
        if (self::$cors) {
            header('Access-Control-Allow-Headers: ' . join(', ', self::$corsHeaders));
            header('Access-Control-Allow-Origin: *');

            if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
                exit(0);
            }
        }

        try {
            if (!is_array(self::$def)) {
                self::resp(500);
            }

            $data = $_SERVER['REQUEST_METHOD'] == 'GET' ? urldecode($_SERVER['QUERY_STRING']) : file_get_contents('php://input');
            $data = json_decode($data, true);
            $theOne = null;

            $req = array(
                'method' => $_SERVER['REQUEST_METHOD'],
                'params' => explode('/', explode('?', $_SERVER['REQUEST_URI'])[0]),
                'data' => $data
            );

            if (!self::$rewrite) {
                $path = explode('/', $_SERVER['SCRIPT_NAME']);

                while (count($path) > 0 && count($req['params']) > 0) {
                    if ($path[0] == $req['params'][0]) {
                        array_shift($path);
                        array_shift($req['params']);
                    } else {
                        self::resp(500);
                    }
                }
            }

            foreach (self::$def as $api) {
                if (!isset($api['endpoints'])) {
                    continue;
                }

                foreach ($api['endpoints'] as $endpoint => $methods) {
                    reset($req['params']);
                    $endpointWithNamespace = (isset($api['namespace']) ? $api['namespace'] . '/' : '') . $endpoint;
                    $path = explode('/', $endpointWithNamespace);

                    foreach ($path as $part) {
                        if ($part == '*') {
                            break;
                        }

                        if (substr($part, 0, 1) != '$' && $part != current($req['params'])) {
                            continue 2;
                        }

                        next($req['params']);
                    }

                    if (current($req['params']) !== false && $part != '*') {
                        continue;
                    }

                    if (!is_array($methods) || !isset($methods[$req['method']])) {
                        continue;
                    }

                    if (isset($api['namespace'])) {
                        $req['params'] = array_slice($req['params'], count(array_filter(explode('/', $api['namespace']))));
                    }

                    $theOne = $methods[$req['method']];
                }
            }

            if ($theOne !== null) {
                if (isset($theOne['require'])) {
                    if (is_readable($theOne['require'])) {
                        require_once($theOne['require']);
                    } else {
                        self::resp(500);
                    }
                }

                if (!isset($theOne['function']) || (!is_callable($theOne['function']))) {
                    self::resp(501);
                }

                if (isset($theOne['validate']) && !self::validate($theOne['validate'], $req['data'])) {
                    self::resp(400);
                }

                $result = call_user_func($theOne['function'], $req);

                if (!isset($theOne['raw']) || !$theOne['raw']) {
                    self::_resp($result);
                }
            } else {
                self::resp(404);
            }
        } catch (APIException $e) {
            self::_resp(array('message' => self::$httpCodes[self::setHttpCode($e->getMessage())]));
        }
    }

    public static function isStrictArray($var) {
        return is_array($var) && count(array_diff_key($var, array_keys(array_keys($var)))) == 0;
    }

    public static function validate($ref, $data) {
        if (!is_array($ref)) {
            return self::validate(array('type' => $ref), $data);
        }

        if (isset($ref['optional']) && $ref['optional']) {
            return true;
        } else if ($data === null) {
            return false;
        }

        if (isset($ref['validator'])) {
            return call_user_func($ref['validator'], $ref, $data);
        }

        if (!isset($ref['type']) || $ref['type'] == 'ANY') {
            return true;
        } else if ($ref['type'] == 'BOOLEAN') {
            if (!is_bool($data)) {
                return false;
            }
        } else if ($ref['type'] == 'INT') {
            if (!is_int($data) || (isset($ref['min']) && $data < $ref['min']) || (isset($ref['max']) && $data > $ref['max'])) {
                return false;
            }
        } else if ($ref['type'] == 'FLOAT') {
            if (!is_float($data) || (isset($ref['min']) && $data < $ref['min']) || (isset($ref['max']) && $data > $ref['max'])) {
                return false;
            }
        } else if ($ref['type'] == 'STRING') {
            if (!is_string($data) || (isset($ref['regex']) && preg_match($ref['regex'], $data) != 1)) {
                return false;
            }
        } else if ($ref['type'] == 'ARRAY') {
            if (!self::isStrictArray($data) ||
                (isset($ref['min']) && count($data) < $ref['min']) ||
                    (isset($ref['max']) && count($data) > $ref['max']))
            {
                return false;
            }
            
            if (isset($ref['types'])) {
                for ($i = 0; $i < count($data); $i++) {
                    foreach ($ref['types'] as $v) {
                        if (self::validate($v, $data[$i])) {
                            continue 2;
                        }
                    }

                    return false;
                }
            }
        } else if ($ref['type'] == 'OBJECT') {
            if (!is_array($data) || self::isStrictArray($data) || !isset($ref['fields']) || !is_array($ref['fields'])) {
                return false;
            }

            if ((!isset($ref['strict']) || $ref['strict'] == true) && count(array_diff_key($data, $ref['fields'])) > 0) {
                return false;
            }

            $ok = true;

            foreach ($ref['fields'] as $field => $fieldType) {
                $ok = $ok && self::validate($fieldType, isset($data[$field]) ? $data[$field] : null);
            }

            return $ok;
        } else if (isset(self::$types[$ref['type']])) {
            return self::validate(self::$types[$ref['type']], $data);
        } else {
            return false;
        }

        return true;
    }
}
