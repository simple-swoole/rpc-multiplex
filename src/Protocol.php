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

class Protocol
{
    /**
     * @var string
     */
    public $class;

    /**
     * @var string
     */
    public $method;

    /**
     * @var array
     */
    public $params;

    /**
     * @var mixed
     */
    public $result;

    /**
     * @var array
     */
    public $error;

    public function __construct(string $class, string $method, array $params)
    {
        $this->class = $class;
        $this->method = $method;
        $this->params = $params;
    }

    public function getClass(): string
    {
        return $this->class;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function getResult()
    {
        return $this->result;
    }

    public function setResult($result)
    {
        $this->result = $result;
        return $this;
    }

    public function setError(int $code, string $message)
    {
        $this->error = [
            'code' => $code,
            'message' => $message,
        ];

        return $this;
    }

    public function getError(): array
    {
        return $this->error;
    }
}
