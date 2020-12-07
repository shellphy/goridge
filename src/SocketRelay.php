<?php

/**
 * Dead simple, high performance, drop-in bridge to Golang RPC with zero dependencies
 *
 * @author Wolfy-J
 */

declare(strict_types=1);

namespace Spiral\Goridge;

use Error;

/**
 * Communicates with remote server/client over be-directional socket using byte payload:
 *
 * [ prefix       ][ payload                               ]
 * [ 1+8+8 bytes  ][ message length|LE ][message length|BE ]
 *
 * prefix:
 * [ flag       ][ message length, unsigned int 64bits, LittleEndian ]
 */
class SocketRelay extends Relay implements StringableRelayInterface
{
    /** Supported socket types. */
    public const SOCK_TCP  = 0;
    public const SOCK_UNIX = 1;

    private string $address;
    private bool   $connected = false;
    private ?int   $port;
    private int    $type;

    /** @var resource */
    private $socket;


    /**
     * Example:
     * $relay = new SocketRelay("localhost", 7000);
     * $relay = new SocketRelay("/tmp/rpc.sock", null, Socket::UNIX_SOCKET);
     *
     * @param string   $address Localhost, ip address or hostname.
     * @param int|null $port    Ignored for UNIX sockets.
     * @param int      $type    Default: TCP_SOCKET
     *
     * @throws Exception\InvalidArgumentException
     */
    public function __construct(string $address, ?int $port = null, int $type = self::SOCK_TCP)
    {
        if (!extension_loaded('sockets')) {
            throw new Exception\InvalidArgumentException("'sockets' extension not loaded");
        }

        switch ($type) {
            case self::SOCK_TCP:
                if ($port === null) {
                    throw new Exception\InvalidArgumentException(sprintf(
                        "no port given for TPC socket on '%s'",
                        $address
                    ));
                }

                if ($port < 0 || $port > 65535) {
                    throw new Exception\InvalidArgumentException(sprintf(
                        "invalid port given for TPC socket on '%s'",
                        $address
                    ));
                }
                break;
            case self::SOCK_UNIX:
                $port = null;
                break;
            default:
                throw new Exception\InvalidArgumentException(sprintf(
                    "undefined connection type %s on '%s'",
                    $type,
                    $address
                ));
        }

        $this->address = $address;
        $this->port = $port;
        $this->type = $type;
    }

    /**
     * Destruct connection and disconnect.
     */
    public function __destruct()
    {
        if ($this->isConnected()) {
            $this->close();
        }
    }

    /**
     * @return string
     */
    public function getAddress(): string
    {
        return $this->address;
    }

    /**
     * @return int|null
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * @return int
     */
    public function getType(): int
    {
        return $this->type;
    }

    /**
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        if ($this->type === self::SOCK_TCP) {
            return "tcp://{$this->address}:{$this->port}";
        }

        return "unix://{$this->address}";
    }

    /**
     * @return Frame|null
     */
    public function waitFrame(): ?Frame
    {
        $this->connect();

        $msg = new Frame(null, null, 0);

        // todo: implement new protocol
        $prefix = $this->fetchPrefix();
        $msg->flags = $prefix['flags'];

        if ($prefix['size'] !== 0) {
            $msg->body = '';
            $readBytes = $prefix['size'];

            //Add ability to write to stream in a future
            while ($readBytes > 0) {
                $bufferLength = socket_recv(
                    $this->socket,
                    $buffer,
                    min(self::BUFFER_SIZE, $readBytes),
                    MSG_WAITALL
                );
                if ($bufferLength === false || $buffer === null) {
                    throw new Exception\PrefixException(sprintf(
                        'unable to read prefix from socket: %s',
                        socket_strerror(socket_last_error($this->socket))
                    ));
                }

                $msg->body .= $buffer;
                $readBytes -= $bufferLength;
            }
        }

        return $msg;
    }

    /**
     * @param Frame ...$frame
     */
    public function send(Frame ...$frame): void
    {
        $this->connect();

        $body = '';
        foreach ($frame as $f) {
            $body = self::packFrame($f);
        }

        if (socket_send($this->socket, $body, strlen($body), 0) === false) {
            throw new Exception\TransportException('unable to write payload to the stream');
        }
    }

    /**
     * Ensure socket connection. Returns true if socket successfully connected
     * or have already been connected.
     *
     * @return bool
     *
     * @throws Exception\RelayException
     * @throws Error When sockets are used in unsupported environment.
     */
    public function connect(): bool
    {
        if ($this->isConnected()) {
            return true;
        }

        $socket = $this->createSocket();
        if ($socket === false) {
            throw new Exception\RelayException("unable to create socket {$this}");
        }

        try {
            if (socket_connect($socket, $this->address, $this->port ?? 0) === false) {
                throw new Exception\RelayException(socket_strerror(socket_last_error($socket)));
            }
        } catch (\Exception $e) {
            throw new Exception\RelayException("unable to establish connection {$this}", 0, $e);
        }

        $this->socket = $socket;
        $this->connected = true;

        return true;
    }

    /**
     * Close connection.
     *
     * @throws Exception\RelayException
     */
    public function close(): void
    {
        if (!$this->isConnected()) {
            throw new Exception\RelayException("unable to close socket '{$this}', socket already closed");
        }

        socket_close($this->socket);
        $this->connected = false;
        unset($this->socket);
    }

    /**
     * @return array Prefix [flag, length]
     *
     * @throws Exception\PrefixException
     */
    private function fetchPrefix(): array
    {
        $prefixLength = socket_recv($this->socket, $prefixBody, 17, MSG_WAITALL);
        if ($prefixBody === null || $prefixLength !== 17) {
            throw new Exception\PrefixException(sprintf(
                'unable to read prefix from socket: %s',
                socket_strerror(socket_last_error($this->socket))
            ));
        }

        // todo: update protocol
        $result = unpack('Cflags/Psize/Jrevs', $prefixBody);
        if (!is_array($result)) {
            throw new Exception\PrefixException('invalid prefix');
        }

        if ($result['size'] !== $result['revs']) {
            throw new Exception\PrefixException('invalid prefix (checksum)');
        }

        return $result;
    }

    /**
     * @return resource|false
     * @throws Exception\GoridgeException
     */
    private function createSocket()
    {
        if ($this->type === self::SOCK_UNIX) {
            return socket_create(AF_UNIX, SOCK_STREAM, 0);
        }

        return socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    }
}
