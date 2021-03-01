# Rpc for multiplexing connection

[![PHPUnit](https://github.com/simple-swoole/rpc-multiplex/actions/workflows/test.yml/badge.svg)](https://github.com/simple-swoole/rpc-multiplex/actions/workflows/test.yml)

## 安装

```
composer require simple-swoole/rpc-multiplex
```

## 配置 Server 端

```php
<?php

declare(strict_types=1);

return [
    'mode' => SWOOLE_BASE,
    'rpc' => [
        'class_name' => Simps\RpcMultiplex\TcpServer::class,
        'ip' => '0.0.0.0',
        'port' => 9503,
        'sock_type' => SWOOLE_SOCK_TCP,
        'settings' => [
            'worker_num' => 1,
            // 以下参数除 package_max_length 外，其他不允许修改
            'open_length_check' => true,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
            'package_max_length' => 1024 * 1024 * 2,
        ],
    ],
];

```

### 编写对应的服务端代码

```php
<?php

namespace App\Service;

class RpcService
{
    public function hello(string $name): string
    {
        return 'Hello ' . $name;
    }
}
```

## 直接使用 Client::instance 调用服务代码

```php
<?php

use Simps\RpcMultiplex\Client;

$data = Client::getInstance('127.0.0.1', 9503)->call('App\Service\RpcService', 'hello', 'World');

var_dump($data->getResult()); // Hello World
```

因 `getInstance` 不会根据参数重新初始化，故以下代码获得的 `instance` 为同一个实例。

```php
<?php

use Simps\RpcMultiplex\Client;

$client1 = Client::getInstance('127.0.0.1', 9503);
$client2 = Client::getInstance('127.0.0.1', 9504);
```

所以如果存在多个 RpcServer 时，需要使用继承，创建对应的单例。
