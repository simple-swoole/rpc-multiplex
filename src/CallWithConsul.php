<?php

declare(strict_types = 1);
/**
 * This file is part of Simps.
 *
 * @link     https://simps.io
 * @document https://doc.simps.io
 * @license  https://github.com/simple-swoole/simps/blob/master/LICENSE
 */

namespace Simps\RpcMultiplex;

use Multiplex\Socket;
use Simps\Consul\Health;
use GuzzleHttp\Client as guzzle;

class CallWithConsul extends Socket\Client
{

    protected static $clientContainer = [];
    
    public static function getInstance(string $serverName, string $consulUri)
    {
        if (empty(self::$clientContainer[$serverName])) {
            if (empty($serverName)) {
                throw new \Exception('service name empty');
            }
            self::$clientContainer[$serverName] = new static($serverName, $consulUri);
        }

        return self::$clientContainer[$serverName];
    }

    public function __construct(string $serverName, string $consulUri)
    {

        $health = new Health(function () use($consulUri) {
            return new guzzle(
                    [
                'base_uri' => $consulUri
                    ]
            );
        });
        $services = $health->service($serverName, ['passing'=>true])->json();
        if (empty($services)) {
            throw new \Exception("no service '$serverName' is available");
        }

        //随机找一个
        shuffle($services);
        $point = current($services);
        $ip = $point['Service']['Address'];
        $port = $point['Service']['Port'];
        parent::__construct($ip, (int) $port);
    }

    public function call(string $class, string $method, ...$params): Protocol
    {
        $protocol = new Protocol($class, $method, $params);

        return unserialize(parent::request(serialize($protocol)));
    }

}
