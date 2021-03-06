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

use GuzzleHttp\Client;
use Multiplex\Packer;
use Multiplex\Packet;
use Multiplex\Serializer\StringSerializer;
use Simps\Application;
use Simps\Consul\Agent;
use Simps\Listener;
use Simps\Route;
use Swoole\Coroutine;
use Swoole\Server;

class TcpServer
{
    public static $incr = 0;

    /**
     * @var Server
     */
    protected $_server;

    /**
     * @var array
     */
    protected $_config;

    /**
     * @var Route
     */
    protected $_route;

    /**
     * @var Packer
     */
    protected static $packer;

    /**
     * @var StringSerializer
     */
    protected static $serializer;

    public static function onStart(Server $server)
    {
        $subs = config('servers.main.sub');

        foreach ($subs as $sub) {
            $ip = $sub['ip'];
            $port = $sub['port'];
            Application::echoSuccess("Swoole Multiplex RPC Server running：tcp://{$ip}:{$port}");
        }

        self::registerService();

        Listener::getInstance()->listen('start', $server);
    }

    public static function onWorkerStart(Server $server, int $workerId)
    {
        self::$packer = new Packer();
        self::$serializer = new StringSerializer();
        Listener::getInstance()->listen('workerStart', $server, $workerId);
    }

    public static function onReceive(Server $server, int $fd, int $fromId, string $data)
    {
        Coroutine::create(function () use ($server, $fd, $data) {
            $packet = self::$packer->unpack($data);
            $id = $packet->getId();
            try {
                /** @var Protocol $protocol */
                $protocol = unserialize($packet->getBody());

                $class = $protocol->getClass();
                $method = $protocol->getMethod();
                $params = $protocol->getParams();

                $result = serialize($protocol->setResult((new $class())->{$method}(...$params)));
            } catch (\Throwable $exception) {
                $result = serialize($protocol->setError($exception->getCode(), $exception->getMessage()));
            } finally {
                $server->send($fd, self::$packer->pack(new Packet($id, self::$serializer->serialize($result))));
            }
        });
    }

    private static function registerService()
    {
        $subs = config('servers.main.sub');
        foreach ($subs as $sub) {
            if (isset($sub['consul'])) {//注册到consul
                $agent = new Agent(function () use ($sub) {
                    return new Client(
                        [
                            'base_uri' => $sub['consul']['publish_to'],
                        ]
                    );
                });
                if ($sub['ip'] == '0.0.0.0') {
                    $ips = swoole_get_local_ip();
                    if (empty($ips)) {
                        Application::echoError('Service Register Fail, Cannot Get Ips');
                        return;
                    }
                    $sub['ip'] = array_shift($ips);
                }

                foreach ($sub['service_name'] as $serverName) {
                    try {
                        $res = $agent->registerService([
                            'id' => $serverName,
                            'name' => $serverName,
                            'tags' => ['primary'],
                            'address' => $sub['ip'],
                            'port' => $sub['port'],
                            'meta' => [
                                'meta' => $serverName,
                            ],
                            'checks' => $sub['consul']['checks'] ?? [],
                        ])->getBody();
                        Application::echoSuccess("Service {$serverName} Register to Consul Success");
                    } catch (\Exception $exc) {
                        Application::echoError("Service {$serverName} Register Fail");
                    }
                }
            }
        }
    }
}
