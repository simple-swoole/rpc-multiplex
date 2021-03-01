<?php

declare(strict_types=1);
/**
 * This file is part of Simps.
 *
 * @link     https://simps.io
 * @document https://doc.simps.io
 * @license  https://github.com/simple-swoole/simps/blob/master/LICENSE
 */
namespace Simps\RpcMultiplex;

use Multiplex\Socket;
use Simps\Singleton;

class Client extends Socket\Client
{
    use Singleton;

    public function __construct(string $name, int $port)
    {
        parent::__construct($name, $port);
    }

    public function call(string $class, string $method, ...$params): Protocol
    {
        $protocol = new Protocol($class, $method, $params);

        return unserialize(parent::request(serialize($protocol)));
    }
}
