<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\Grpc;

use Google\Protobuf\GPBEmpty;
use Google\Protobuf\Internal\Message;

class Parser
{
    public const GRPC_ERROR_NO_RESPONSE = -1;

    public static function pack(string $data): string
    {
        return pack('CN', 0, strlen($data)) . $data;
    }

    public static function unpack(string $data): string
    {
        // it's the way to verify the package length
        // 1 + 4 + data
        // $len = unpack('N', substr($data, 1, 4))[1];
        // assert(strlen($data) - 5 === $len);
        return substr($data, 5);
    }

    public static function serializeMessage($data)
    {
        if ($data === null) {
            $data = new GPBEmpty();
        }
        if (method_exists($data, 'encode')) {
            $data = $data->encode();
        } elseif (method_exists($data, 'serializeToString')) {
            $data = $data->serializeToString();
        } elseif (method_exists($data, 'serialize')) {
            /** @noinspection PhpUndefinedMethodInspection */
            $data = $data->serialize();
        }
        return self::pack((string) $data);
    }

    public static function deserializeMessage($deserialize, string $value)
    {
        if (empty($value)) {
            return null;
        }
        $value = self::unpack($value);

        if (is_array($deserialize)) {
            [$className, $deserializeFunc] = $deserialize;
            /** @var \Google\Protobuf\Internal\Message $object */
            $object = new $className();
            if ($deserializeFunc && method_exists($object, $deserializeFunc)) {
                $object->{$deserializeFunc}($value);
            } else {
                // @noinspection PhpUndefinedMethodInspection
                $object->mergeFromString($value);
            }
            return $object;
        }
        return call_user_func($deserialize, $value);
    }

    /**
     * @param null|\swoole_http2_response $response
     * @param mixed $deserialize
     * @return \Grpc\StringifyAble[]|Message[]|\swoole_http2_response[]
     */
    public static function parseResponse($response, $deserialize): array
    {
        if (! $response) {
            return ['No response', self::GRPC_ERROR_NO_RESPONSE, $response];
        }
        if (self::isinvalidStatus($response->statusCode)) {
            $message = $response->headers['grpc-message'] ?? 'Http status Error';
            $code = $response->headers['grpc-status'] ?? ($response->errCode ?: $response->statusCode);
            return [$message, (int) $code, $response];
        }
        $grpcStatus = (int) ($response->headers['grpc-status'] ?? 0);
        if ($grpcStatus !== 0) {
            return [$response->headers['grpc-message'] ?? 'Unknown error', $grpcStatus, $response];
        }
        $data = $response->data ?? '';
        $reply = self::deserializeMessage($deserialize, $data);
        $status = (int) ($response->headers['grpc-status'] ?? 0);
        return [$reply, $status, $response];
    }

    private static function isinvalidStatus(int $code)
    {
        return $code !== 0 && $code !== 200 && $code !== 400;
    }
}
