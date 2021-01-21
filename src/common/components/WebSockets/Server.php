<?php

namespace common\components\WebSockets;

use yii\base\Component;
use Workerman\Worker;

/**
 * Class Server
 * @package common\components\WebSockets
 */
class Server extends Component
{

    // используется для имитации присутствия пользователей в чате
    const FAKE_COUNT = 0;
    
    public $server;
    public $internalServer;
    private $worker;
    public $handlers = [];

    /**
     *
     * @return void
     * @throws Exception
     */
    public function init()
    {
        $context = array(
          'ssl' => array(
            'local_cert'  => '/etc/nginx/ssl/localhost.localdomain.crt',
            'local_pk'    => '/etc/nginx/ssl/localhost.localdomain.key',
            'verify_peer' => false,
          )
        );

        // Create a Websocket server
        $this->worker = new Worker($this->server);
        //$this->worker->transport = 'ssl';
    }

    /**
     *
     */
    public function run()
    {
        $self = $this;
        $users = [];
        $innerWorker = null;
        $count = 0;

        $fk = self::FAKE_COUNT;

        // срабатывает при новом подключении
        $this->worker->onConnect = function($connection) use (&$users, &$innerWorker, &$count, $fk) {
            $connection->onWebSocketConnect = function($connection) use (&$users, &$innerWorker, &$count, $fk) {
                if (!$innerWorker) {
                    // создаём локальный tcp-сервер, чтобы отправлять на него сообщения из кода нашего сайта
                    $innerWorker = new Worker($this->internalServer);
                    // создаём обработчик сообщений, который будет срабатывать,
                    // когда на локальный tcp-сокет приходит сообщение
                    $innerWorker->onMessage = function($connection, $data) use (&$users, &$count, $fk) {
                        $data = \json_decode($data);
                        if (!isset($data->message)) {
                            return;
                        }
                        $ms = \json_decode($data->message);
                        $ms->count = $count + $fk;
                        $ms = \json_encode($ms);
                        if (isset($data->uuid)) {
                            if (isset($users[$data->uuid])) {
                                $connections = $users[$data->uuid];
                                foreach ($connections as $conn) {
                                    $conn->send($ms);
                                }
                            }
                        } else {
                            foreach ($users as $uuid => $connections) {
                                foreach ($connections as $index => $conn) {
                                    $conn->send($ms);
                                }
                            }
                        }
                    };
                    $innerWorker->listen();
                }

                $uuid = (isset($_GET['uuid']) ? $_GET['uuid'] : null);
                if (!$uuid) {
                    return false;
                }
                if (!isset($users[$uuid])) {
                    $users[$uuid] = [];
                }
                
                $users[$uuid][] = $connection;
                $count++;
            };
        };

        // обработчик сообщений в чате
        $this->worker->onMessage = function($connection, $data) use ($self, &$users) {
            $self->handleRequest($connection, $data);
        };

        // пользователь отключился
        $this->worker->onClose = function($connection) use (&$count) {
            $count--;
            echo "Connection closed\n";
        };

        Worker::runAll();
    }

    /**
     * @param $name
     * @param $callback
     */
    public function addCallback($name, $callback)
    {
        $this->callbacks[$name] = $callback;
    }

    /**
     * @param $conn
     * @param $data
     */
    public function handleRequest($conn, $data)
    {
        $data = \json_decode($data);
        
        if (!isset($data->type) || !isset($this->handlers[$data->type])) {
            return;
        }
        
        $handler = new $this->handlers[$data->type];
        
        $conn->send(\json_encode($handler->run((isset($data->data) ? $data->data : null))));
    }

}
