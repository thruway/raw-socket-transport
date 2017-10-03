<?php

namespace Thruway\Transport;

use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use Thruway\Peer\ClientInterface;
use Thruway\Serializer\JsonSerializer;

/**
 * Class RawSocketClientTransportProvider
 *
 * Implements transport provider on raw socket for client
 *
 * @package Thruway\Transport
 */
class RawSocketClientTransportProvider extends AbstractClientTransportProvider
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
     * @var ConnectorInterface
     */
    private $connector;

    /**
     * Constructor
     *
     * @param string $address
     * @param int $port
     * @param ConnectorInterface|null $connector
     */
    public function __construct($address = '127.0.0.1', $port = 8181, ConnectorInterface $connector = null)
    {
        $this->address   = $address;
        $this->port      = $port;
        $this->transport = null;
        $this->connector = $connector;
    }

    /**
     * Start transport provider
     *
     * @param \Thruway\Peer\ClientInterface $client
     * @param \React\EventLoop\LoopInterface $loop
     */
    public function startTransportProvider(ClientInterface $client, LoopInterface $loop)
    {
        $this->client = $client;
        $this->loop   = $loop;
        $connector    = $this->connector ?: new Connector($loop);

        $connector->connect($this->address . ':' . $this->port)->then(function (ConnectionInterface $connection) {
            $connection->on('data', [$this, 'handleData']);
            $connection->on('close', [$this, 'handleClose']);
            $this->handleConnection($connection);
        });
    }

    /**
     * Handle process on open new connection
     * @param ConnectionInterface $conn
     */
    public function handleConnection(ConnectionInterface $conn)
    {
        //$this->getManager()->debug("Raw socket opened");

        $this->transport = new RawSocketTransport($conn, $this->loop, $this->client);

        $this->transport->setSerializer(new JsonSerializer());

        $this->transport->on('message', function ($transport, $msg) {
            $this->client->onMessage($transport, $msg);
        });

        $this->client->onOpen($this->transport);
    }

    /**
     * Handle process reveiced data
     *
     * @param mixed $data
     */
    public function handleData($data)
    {
        $this->transport->handleData($data);
    }

    /**
     * Handle process on close connection
     * @return \Closure
     */
    public function handleClose()
    {
        $this->client->onClose($this->transport);
    }
}
