<?php

namespace Thruway\Transport;

use React\Socket\Connection;
use React\Socket\ConnectionInterface;
use React\Socket\Server;
use Thruway\Event\ConnectionCloseEvent;
use Thruway\Event\ConnectionOpenEvent;
use Thruway\Event\RouterStartEvent;
use Thruway\Event\RouterStopEvent;
use Thruway\Logging\Logger;
use Thruway\Serializer\JsonSerializer;

/**
 * Class RawSocketTransportProvider
 *
 * Implements a transport provider on raw socket (for router)
 *
 * @package Thruway\Transport
 */
class RawSocketTransportProvider extends AbstractRouterTransportProvider
{
    /**
     * @var string
     */
    private $address;

    /**
     * @var int
     */
    private $port;

    /**
     * @var \SplObjectStorage
     */
    private $sessions;

    /**
     * @var Server
     */
    private $server;

    /**
     * Constructor
     *
     * @param string $address
     */
    public function __construct($address = '127.0.0.1', $port = 8181)
    {
        $this->address  = $address;
        $this->port     = $port;
        $this->sessions = new \SplObjectStorage();
    }

    /**
     * Handle process on open new connection
     * @param ConnectionInterface $conn
     */
    public function handleConnection(ConnectionInterface $conn)
    {
        Logger::debug($this, 'Raw socket opened ' . $conn->getRemoteAddress());

        $transport = new RawSocketTransport($conn, $this->loop, $this->router);

        $transport->setSerializer(new JsonSerializer());

        $transport->setTrusted($this->trusted);

        $session = $this->router->createNewSession($transport);
        $this->sessions->attach($conn, $session);

        $transport->on('message', function ($transport, $msg) use ($session) {
            $session->dispatchMessage($msg);
        });

        $this->router->getEventDispatcher()->backwardsCompatibleDispatch(new ConnectionOpenEvent($session), 'connection_open');

        $conn->on('data', [$transport, 'handleData']);
        $conn->on('close', $this->handleClose($conn));
    }

    /**
     * Handle process on close transport
     * @param ConnectionInterface $conn
     * @return \Closure
     */
    public function handleClose(ConnectionInterface $conn)
    {
        return function () use ($conn) {
            Logger::debug($this, 'Raw socket closed ');
            $session = $this->sessions[$conn];
            $this->sessions->detach($conn);

            $this->router->getEventDispatcher()->backwardsCompatibleDispatch(new ConnectionCloseEvent($session), 'connection_close');
        };
    }

    public function handleRouterStart(RouterStartEvent $event)
    {
        $socket = new Server('tcp://' . $this->address . ':' . $this->port, $this->loop);
        $socket->on('connection', [$this, 'handleConnection']);

        Logger::info($this, 'Raw socket listening on ' . $this->address . ':' . $this->port);

        $this->server = $socket;
    }

    public function handleRouterStop(RouterStopEvent $event)
    {
        if ($this->server) {
            $this->server->close();
        }

        foreach ($this->sessions as $k) {
            $this->sessions[$k]->shutdown();
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            'router.start' => ['handleRouterStart', 10],
            'router.stop'  => ['handleRouterStop', 10]
        ];
    }
}
