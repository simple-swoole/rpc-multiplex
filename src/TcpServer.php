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
namespace Simps\RpcMultiplex;

use Multiplex\Packer;
use Multiplex\Packet;
use Multiplex\Serializer\StringSerializer;
use Simps\Application;
use Simps\Listener;
use Simps\Route;
use Swoole\Coroutine;
use Swoole\Server;

class TcpServer
{
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
    protected $packer;

    /**
     * @var StringSerializer
     */
    protected $serializer;

    public function __construct()
    {
        $this->packer = new Packer();
        $this->serializer = new StringSerializer();

        $config = config('servers');
        $wsConfig = $config['rpc'];
        $this->_config = $wsConfig;
        $this->_server = new Server($wsConfig['ip'], $wsConfig['port'], $config['mode']);
        $this->_server->set($wsConfig['settings']);

        if ($config['mode'] == SWOOLE_BASE) {
            $this->_server->on('managerStart', [$this, 'onManagerStart']);
        } else {
            $this->_server->on('start', [$this, 'onStart']);
        }

        $this->_server->on('workerStart', [$this, 'onWorkerStart']);
        $this->_server->on('receive', [$this, 'onReceive']);

        foreach ($wsConfig['callbacks'] as $eventKey => $callbackItem) {
            [$class, $func] = $callbackItem;
            $this->_server->on($eventKey, [$class, $func]);
        }

        if (isset($this->_config['process']) && ! empty($this->_config['process'])) {
            foreach ($this->_config['process'] as $processItem) {
                [$class, $func] = $processItem;
                $this->_server->addProcess($class::$func($this->_server));
            }
        }

        $this->_server->start();
    }

    public function onStart(Server $server)
    {
        Application::echoSuccess("Swoole Multiplex RPC Server running：tcp://{$this->_config['ip']}:{$this->_config['port']}");
        Listener::getInstance()->listen('start', $server);
    }

    public function onManagerStart(Server $server)
    {
        Application::echoSuccess("Swoole Multiplex RPC Server running：tcp://{$this->_config['ip']}:{$this->_config['port']}");
        Listener::getInstance()->listen('managerStart', $server);
    }

    public function onWorkerStart(Server $server, int $workerId)
    {
        $this->_route = Route::getInstance();
        Listener::getInstance()->listen('workerStart', $server, $workerId);
    }

    public function onReceive(Server $server, int $fd, int $fromId, string $data)
    {
        Coroutine::create(function () use ($server, $fd, $data) {
            $packet = $this->packer->unpack($data);
            $id = $packet->getId();
            try {
                /** @var Protocol $protocol */
                $protocol = unserialize($packet->getBody());

                $class = $protocol->getClass();
                $method = $protocol->getMethod();
                $params = $protocol->getParams();
                $protocol->setResult((new $class())->{$method}(...$params));
                $result = serialize($protocol);
            } catch (\Throwable $exception) {
                $result = serialize($protocol->setError($exception->getCode(), $exception->getMessage()));
            } finally {
                $server->send($fd, $this->packer->pack(new Packet($id, $this->serializer->serialize($result))));
            }
        });
    }
}