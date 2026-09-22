<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Mail;

use Leads\Domain\Mail\MailException;

final class SocketSmtpConnection implements SmtpConnection
{
    /** @var resource */
    private $socket;

    public function __construct(string $host, int $port, bool $implicitTls, int $timeout)
    {
        $target = ($implicitTls ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $socket = @stream_socket_client($target, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new MailException("No se pudo conectar con el servidor de correo ({$host}:{$port}): {$error}");
        }
        stream_set_timeout($socket, $timeout);
        $this->socket = $socket;
    }

    public function write(string $line): void
    {
        if (@fwrite($this->socket, $line) === false) {
            throw new MailException('Se perdió la conexión con el servidor de correo');
        }
    }

    public function readLine(): ?string
    {
        $line = fgets($this->socket, 515);
        return $line === false ? null : $line;
    }

    public function enableTls(): void
    {
        $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if (!@stream_socket_enable_crypto($this->socket, true, $method)) {
            throw new MailException('No se pudo iniciar TLS con el servidor de correo');
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }
}
