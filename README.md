## 安装consul

`consul`具体的原理这里不做介绍，只需要记得consul中有两个角色，`server`和`client`，我们首先需要一个consul的`server`集群，生产环境推荐3台机器以上的consul集群保证高可用，server集群间采用`raft`强一致性算法协调，`client`是性能很高的轻量级进程，client和server集群间通过`gossip`协议同步数据，我们需要在微服务的`rpc调用端`和`rpc服务端`的机器上都安装consul的`client`。

`rpc调用端`通过请求本机的consul`client`来`发现服务`。
`rpc服务端`通过请求本机的consul`client`来`注册服务`。

这里推荐采用docker来安装`consul`。

#### consul server集群安装
```bash
一.  docker pull consul   

#CONSUL_BIND_INTERFACE 是内网的网卡名，consul服务将绑定到这个网卡上
#server=true 表示这个consul进程是server角色
#bootstrap-expect=1 表示只需要1个server就可以对外提供服务，推荐3个，我这里为了演示写1
二.  docker run -d --name=consul1 --net=host -e CONSUL_BIND_INTERFACE=eth0 consul agent --server=true --bootstrap-expect=1 --client=0.0.0.0 -ui
```
#### 在rpc的客户端和服务端安装consul client
```bash
一.  docker pull consul   

#server=false 表示这是个client进程
#join=192.168.2.132，这个ip是consul server那台机器的ip，由于server是集群部署，即使这个ip机器宕机，也会自动选主到新ip
二.  docker run -d --name=client --net=host -e CONSUL_BIND_INTERFACE=eth0 consul agent --server=false --client=0.0.0.0 -ui -join=192.168.2.132
```


## 安装simps的rpc组件

首先安装swoole扩展，[参考此文档](https://wiki.swoole.com/#/environment)

安装simps脚手架和rpc组件
```php
composer create-project simple-swoole/skeleton
cd skeleton
composer require simple-swoole/rpc-multiplex
```
`rpc-multiplex`组件采用单连接复用的方式，不需要连接池，性能极高，感谢`@李铭昕`小伙伴的贡献。

## 添加rpc服务端代码

在`app/Service`目录下面，填充你的业务代码
```php
namespace App\Service;

class RpcService
{
    public function hello(string $name): string
    {
        return 'Hello ' . $name;
    }
}
```
在`config/routes.php`定义一个`Health`接口给consul探活用
```php
return [
    ['GET', '/Health', function ($request, $response) {
        $response->end('Im ok');//定义健康监测接口给consul调用
    }],
];
```

## 修改rpc服务端的配置
修改`config/servers.php`
```php
<?php
return [
    'main' => [
        'class_name' => \Swoole\Http\Server::class,
        'ip' => '0.0.0.0',
        'port' => 9501,//主服务用来接收consul的心跳请求
        'sock_type' => SWOOLE_SOCK_TCP,
        'callbacks' => [
            'request' => [\App\Events\Http::class, 'onRequest'],
            'start' => [Simps\RpcMultiplex\TcpServer::class, 'onStart'],
            'workerStart' => [Simps\RpcMultiplex\TcpServer::class, 'onWorkerStart'],
        ],
        'settings' => [
            'worker_num' => swoole_cpu_num(),
        ],
        'sub' => [
             //定义rpc服务，注册服务到consul
                [
                'callbacks' => [
                    "receive" => [Simps\RpcMultiplex\TcpServer::class, 'onReceive'],
                ],
                'port' => 9503,//rpc服务监听9503端口
                'sock_type' => SWOOLE_SOCK_TCP,
                //注册到consul时候，'0.0.0.0'默认会拿第一块网卡的ip注册到consul
                'ip' => '0.0.0.0',
                //服务名称
                'service_name' => ['rpc1','rpc2'],
                //有`consul`配置的时候会自动注册到consul
                'consul' => [
                    'publish_to' => 'http://127.0.0.1:8500', //本机的consul client地址
                    "checks" => [
                            [
                            "name" => "rpc1",
                            "http" => "http://127.0.0.1:9501/Health", //consul请求这个地址 连通就证明服务可用，checks的更多配置请参考consul官方文档。
                            "interval" => "5s",
                        ],
                            [
                            "name" => "rpc2",
                            "http" => "http://127.0.0.1:9501/Health", //consul请求这个地址 连通就证明服务可用，checks的更多配置请参考consul官方文档。
                            "interval" => "5s",
                        ]
                    ]
                ],
                'settings' => [
                    // 以下参数不允许修改
                    'open_length_check' => true,
                    'package_length_type' => 'N',
                    'package_length_offset' => 0,
                    'package_body_offset' => 4,
                    'package_max_length' => 1024 * 1024 * 2,
                ],
            ],
        ]
    ]
];


```

## 添加rpc调用端代码
```php
class IndexController
{
    public function index($request, $response)
    {
        $serviceName = 'rpc1';
        $consulUri = 'http://127.0.0.1:8500';//本机的consul client地址，框架会通过这个consul client找到所有可用的机器，随机找到一台机器进行rpc调用，如果所有机器都挂了会抛异常
        $data = CallWithConsul::getInstance($serviceName, $consulUri)->call('App\Service\RpcService', 'hello', 'World'); //call(类名，方法，参数)

        $response->end(
                json_encode(
                        [
                            'method' => $request->server['request_method'],
                            'message' => 'Hello Simps. ' . $data->getResult(),
                        ]
                )
        );
    }

}
```

## 微服务其他东西

当然微服务是个很大的话题，这里只解决了服务发现和注册，还有服务监控，限流等，监控这里推荐专门针对php的监控产品 [Swoole Tracker](https://business.swoole.com/tracker/index)
