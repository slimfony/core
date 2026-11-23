<?php

declare(strict_types=1);

namespace Slimfony\Core\Observability;

use Slimfony\Core\Exception\ConfigurationException;
use Slimfony\Core\Exception\HttpException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets Prometheus read the metrics of a command line process (relay, consumer), which has no web server of its own.
 *
 * The process calls `serve()` between its units of work; it answers at most one waiting request and never waits for
 * a new one. Metrics must live in a storage the process itself reads (not APCu, which a CLI process does not share).
 * Only `GET /metrics` exists, and the core's MetricsController decides who may read (bearer token). A client
 * that sends its request slowly can hold the process for at most a second in all (the whole request head must arrive
 * within that), so this is meant for the private network of the cluster, where only Prometheus connects. The address
 * of the peer is what the controller's address list (CIDR) is checked against.
 *
 * @api
 */
final class ProcessMetricsEndpoint
{
    private const int MAX_HEAD_BYTES = 8192;
    private const float HEAD_SECONDS = 1.0;

    /** @var resource */
    private $server;

    public function __construct(
        string $address,
        private readonly MetricsController $controller,
    ) {
        $server = @stream_socket_server($address, $code, $message);
        if (false === $server) {
            throw new ConfigurationException(\sprintf('The metrics endpoint cannot listen on %s: %s', $address, $message));
        }
        stream_set_blocking($server, false);
        $this->server = $server;
    }

    /** The address it listens on (useful when the port was chosen by the system). */
    public function address(): string
    {
        return (string) stream_socket_get_name($this->server, false);
    }

    public function serve(): void
    {
        $connection = @stream_socket_accept($this->server, 0);
        if (false === $connection) {
            return;
        }

        try {
            stream_set_timeout($connection, 1);
            $response = $this->respond($this->readHead($connection), (string) stream_socket_get_name($connection, true));
            $body = (string) $response->getContent();
            $this->write($connection, \sprintf(
                "HTTP/1.1 %d %s\r\nContent-Type: %s\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
                $response->getStatusCode(),
                Response::$statusTexts[$response->getStatusCode()] ?? 'Unknown',
                $response->headers->get('Content-Type', 'text/plain'),
                \strlen($body),
                $body,
            ));
        } finally {
            fclose($connection);
        }
    }

    /**
     * Writes the answer within the same second a request head may take: a peer that sends a request and never reads
     * the answer must not hold the process (stream_set_timeout covers reads only).
     *
     * @param resource $connection
     */
    private function write($connection, string $payload): void
    {
        stream_set_blocking($connection, false);
        $deadline = microtime(true) + self::HEAD_SECONDS;
        for ($offset = 0, $length = \strlen($payload); $offset < $length && microtime(true) < $deadline;) {
            $written = fwrite($connection, substr($payload, $offset));
            if (false === $written) {
                return;
            }
            $offset += $written;
            if (0 === $written) { // the peer's buffer is full: wait a moment for room
                $read = null;
                $write = [$connection];
                $except = null;
                stream_select($read, $write, $except, 0, 50_000);
            }
        }
    }

    /**
     * @param resource $connection
     */
    private function readHead($connection): string
    {
        $head = '';
        $deadline = microtime(true) + self::HEAD_SECONDS;
        while (!str_contains($head, "\r\n\r\n") && \strlen($head) < self::MAX_HEAD_BYTES && microtime(true) < $deadline) {
            $chunk = fread($connection, 2048);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $head .= $chunk;
        }

        return $head;
    }

    /** "10.0.0.7:5555" or "[::1]:5555" to the address alone; a peer without an address (a unix socket) matches no list */
    private static function host(string $peer): string
    {
        $at = strrpos($peer, ':');

        return false === $at ? '' : trim(substr($peer, 0, $at), '[]');
    }

    private function respond(string $head, string $peer): Response
    {
        $lines = explode("\r\n", $head);
        if (1 !== preg_match('#^GET /metrics HTTP/1\.[01]$#', $lines[0])) {
            return new Response('', 1 === preg_match('#^[A-Z]+ \S+ HTTP/1\.[01]$#', $lines[0]) ? 404 : 400);
        }

        $server = ['REMOTE_ADDR' => self::host($peer)];
        foreach (\array_slice($lines, 1) as $line) {
            if (1 === preg_match('/^authorization:\s*(.+)$/i', $line, $match)) {
                $server['HTTP_AUTHORIZATION'] = trim($match[1]);
            }
        }

        try {
            return ($this->controller)(Request::create('/metrics', server: $server));
        } catch (HttpException $e) { // the controller refuses by exception; the kernel would turn it into a response, here we do
            return new Response('', $e->status, $e->headers);
        }
    }
}
