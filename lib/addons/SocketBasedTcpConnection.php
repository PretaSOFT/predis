<?php
namespace Predis;

// This is an alternative class to Predis\TcpConnection that uses raw socket 
// resources provided by the socket extension instead of PHP's socket streams. 
// The actual reason for the existence of this class is described in this bug 
// report http://github.com/nrk/predis/issues/10 but it basically comes down to 
// the inability for developers to disable the Nagle's algorithm on socket 
// streams in userland PHP code. Setting the TCP_NODELAY option is important 
// to obtain appropriate performances in certain scenarios. See Wikipedia for 
// further information: http://en.wikipedia.org/wiki/Nagle's_algorithm
//
// If you want to use this class to handle TCP connections instead of the 
// default one you must register it before creating any client instance, just 
// like in the following example:
// 
// use Predis;
// ConnectionFactory::registerScheme('tcp', '\Predis\SocketBasedTcpConnection');
// $redis = new Client('tcp://127.0.0.1');
//

class SocketBasedTcpConnection extends TcpConnection {
    protected function checkParameters(ConnectionParameters $parameters) {
        parent::checkParameters($parameters);
        if ($parameters->connection_persistent == true) {
            throw new \InvalidArgumentException(
                'Persistent connections are not supported by this connection class.'
            );
        }
        return $parameters;
    }

    protected function createResource() {
        $this->_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!is_resource($this->_socket)) {
            $this->emitSocketError();
        }

        // TODO: handle async, persistent, and timeout options
        // $this->_params->connection_async

        $host = $this->_params->host;
        $port = $this->_params->port;
        $addressLong = ip2long($host);
        if ($addressLong == -1 || $addressLong === false) {
            $host = gethostbyname($host);
        }

        $this->connectWithTimeout($host, $port, $this->_params->connection_timeout);
        $this->setSocketOptions();
    }

    private function connectWithTimeout($host, $port, $timeout = 5) {
        socket_set_nonblock($this->_socket);
        if (@socket_connect($this->_socket, $host, $port) === false) {
            $error = socket_last_error();
            if ($error != SOCKET_EINPROGRESS && $error != SOCKET_EALREADY) {
                $this->emitSocketError();
            }
        }
        socket_set_block($this->_socket);

        $null = null;
        $selectable = array($this->_socket);
        $timeoutSeconds  = floor($timeout);
        $timeoutUSeconds = ($timeout - $timeoutSeconds) * 1000000;

        $selected = socket_select($selectable, $selectable, $null, $timeoutSeconds, $timeoutUSeconds);
        if ($selected === 2) {
            $this->onCommunicationException('Connection refused', SOCKET_ECONNREFUSED);
        }
        if ($selected === 0) {
            $this->onCommunicationException('Connection timed out', SOCKET_ETIMEDOUT);
        }
        if ($selected === false) {
            $this->emitSocketError();
        }
    }

    private function setSocketOptions() {
        if (!socket_set_option($this->_socket, SOL_TCP, TCP_NODELAY, 1)) {
            $this->emitSocketError();
        }
        if (!socket_set_option($this->_socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            $this->emitSocketError();
        }
        if (isset($this->_params->read_write_timeout)) {
            $rwtimeout = $this->_params->read_write_timeout;
            $timeoutSec  = floor($rwtimeout);
            $timeoutUsec = ($rwtimeout - $timeoutSec) * 1000000;
            $timeout = array('sec' => $timeoutSec, 'usec' => $timeoutUsec);
            if (!socket_set_option($this->_socket, SOL_SOCKET, SO_SNDTIMEO, $timeout)) {
                $this->emitSocketError();
            }
            if (!socket_set_option($this->_socket, SOL_SOCKET, SO_RCVTIMEO, $timeout)) {
                $this->emitSocketError();
            }
        }
    }

    public function disconnect() {
        if ($this->isConnected()) {
            // TODO: inspect linger options and socket_shutdown()
            socket_close($this->_socket);
            $this->_socket = null;
        }
    }

    private function emitSocketError() {
        $errno  = socket_last_error();
        $errstr = socket_strerror($errno);
        $this->_socket = null;
        $this->onCommunicationException(trim($errstr), $errno);
    }

    public function writeBytes($value) {
        $socket = $this->getSocket();
        while (($length = strlen($value)) > 0) {
            $written = socket_write($socket, $value, $length);
            if ($length === $written) {
                return true;
            }
            if ($written === false) {
                $this->onCommunicationException('Error while writing bytes to the server');
            }
            $value = substr($value, $written);
        }
        return true;
    }

    public function readBytes($length) {
        if ($length == 0) {
            throw new \InvalidArgumentException('Length parameter must be greater than 0');
        }
        $socket = $this->getSocket();
        $value  = '';
        do {
            $chunk = socket_read($socket, $length, PHP_BINARY_READ);
            if ($chunk === false) {
                $this->onCommunicationException('Error while reading bytes from the server');
            } else if ($chunk === '') {
                $this->onCommunicationException('Unexpected empty result while reading bytes from the server');
            }
            $value .= $chunk;
        }
        while (($length -= strlen($chunk)) > 0);
        return $value;
    }

    public function readLine() {
        $socket = $this->getSocket();
        $value  = '';
        do {
            $chunk_len = 4096;
            // peek ahead (look for Predis\Protocol::NEWLINE)
            $chunk = '';
            $chunk_res = @socket_recv($socket, $chunk, $chunk_len, MSG_PEEK);
            if ($chunk_res === false) {
                $this->onCommunicationException('Error while peeking line from the server');
            } else if ($chunk === '' || is_null($chunk)) {
                $this->onCommunicationException('Unexpected empty result while peeking line from the server');
            }
            if (($newline_pos = strpos($chunk, Protocol::NEWLINE)) !== false) {
                $chunk_len = $newline_pos + 2;
            }
            // actual recv (with possibly adjusted chunk_len)
            $chunk = '';
            $chunk_res = @socket_recv($socket, $chunk, $chunk_len, 0);
            if ($chunk_res === false) {
                $this->onCommunicationException('Error while reading line from the server');
            } else if ($chunk === '' || is_null($chunk)) {
                $this->onCommunicationException('Unexpected empty result while reading line from the server');
            }
            $value .= $chunk;
        }
        while (substr($value, -2) !== Protocol::NEWLINE);
        return substr($value, 0, -2);
    }
}
?>