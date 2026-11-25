<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Mail;

use Leads\Domain\Mail\MailException;

/** Conexión TCP/TLS a un servidor de correo. Sirve igual para SMTP que para IMAP. */
final class SocketSmtpConnection implements SmtpConnection
{
    /** Se escribe por trozos: con TLS, fwrite no siempre acepta un adjunto grande de una vez. */
    private const CHUNK = 65536;

    /** @var resource */
    private $socket;

    public function __construct(string $host, int $port, bool $implicitTls, int $timeout, bool $verifyPeer = true)
    {
        $target = ($implicitTls ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $verifyPeer,
            'verify_peer_name' => $verifyPeer,
            'allow_self_signed' => !$verifyPeer,
            'peer_name' => $host,
        ]]);
        $socket = @stream_socket_client($target, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            $hint = $implicitTls && $verifyPeer ? ' (si es un problema de certificado, prueba a no comprobarlo)' : '';
            throw new MailException("No se pudo conectar con el servidor de correo ({$host}:{$port}): {$error}{$hint}");
        }
        stream_set_timeout($socket, $timeout);
        $this->socket = $socket;
    }

    public function write(string $line): void
    {
        $length = strlen($line);
        for ($offset = 0; $offset < $length; $offset += $written) {
            $written = @fwrite($this->socket, substr($line, $offset, self::CHUNK));
            if ($written === false || $written === 0) {
                throw new MailException('Se perdió la conexión con el servidor de correo');
            }
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
