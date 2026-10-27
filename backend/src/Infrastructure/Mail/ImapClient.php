<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Mail;

use Leads\Domain\Mail\MailException;

/**
 * Cliente IMAP mínimo: entra en el buzón y guarda una copia del correo enviado
 * en la carpeta de Enviados (APPEND), para que la comercial lo vea en su
 * programa de correo y las respuestas le lleguen en el mismo hilo.
 * No necesita la extensión imap de PHP (desaparece en PHP 8.4).
 */
final class ImapClient
{
    /** Nombres habituales si el servidor no marca la carpeta con \Sent (RFC 6154). */
    private const SENT_NAMES = '/^(INBOX[.\/])?(Sent|Sent Items|Sent Messages|Enviados|Elementos enviados)$/i';

    /** @var \Closure(): SmtpConnection */
    private \Closure $connect;
    private int $tag = 0;

    public function __construct(private readonly ImapSettings $settings, ?callable $connect = null)
    {
        $this->connect = $connect !== null
            ? \Closure::fromCallable($connect)
            : fn (): SmtpConnection => new SocketSmtpConnection(
                $settings->host,
                $settings->port,
                $settings->encryption === 'ssl',
                $settings->timeout,
                $settings->verifyPeer,
            );
    }

    /** Guarda $raw (mensaje completo) en Enviados, marcado como leído. Devuelve la carpeta usada. */
    public function appendToSent(string $raw): string
    {
        return $this->session(function (SmtpConnection $imap) use ($raw): string {
            $folder = $this->sentFolder($imap);
            $raw = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $raw);
            $tag = $this->nextTag();
            $imap->write(sprintf("%s APPEND %s (\\Seen) {%d}\r\n", $tag, $this->quote($folder), strlen($raw)));
            $ready = $this->readLine($imap);
            if (!str_starts_with($ready, '+')) {
                throw new MailException("El servidor IMAP no aceptó la copia en «{$folder}»: " . trim($ready));
            }
            $imap->write($raw . "\r\n");
            $this->finish($imap, $tag, "APPEND {$folder}");

            return $folder;
        });
    }

    /** Comprueba conexión y usuario/contraseña. Devuelve la carpeta de Enviados que se usará. */
    public function verify(): string
    {
        return $this->session(fn (SmtpConnection $imap): string => $this->sentFolder($imap));
    }

    private function session(callable $work): mixed
    {
        $imap = ($this->connect)();
        try {
            $greeting = $this->readLine($imap);
            if (!preg_match('/^\* (OK|PREAUTH)/i', $greeting)) {
                throw new MailException('El servidor IMAP no respondió como se esperaba: ' . (trim($greeting) ?: 'sin respuesta'));
            }
            if ($this->settings->encryption === 'tls') {
                $this->command($imap, 'STARTTLS');
                $imap->enableTls();
            }
            if (!str_starts_with(strtoupper($greeting), '* PREAUTH')) {
                $this->command($imap, 'LOGIN ' . $this->quote($this->settings->username) . ' ' . $this->quote($this->settings->password), 'LOGIN (usuario y contraseña)');
            }
            $result = $work($imap);
            try {
                $this->command($imap, 'LOGOUT');
            } catch (MailException) {
                // La copia ya está guardada.
            }

            return $result;
        } finally {
            $imap->close();
        }
    }

    private function sentFolder(SmtpConnection $imap): string
    {
        if ($this->settings->sentFolder !== null && $this->settings->sentFolder !== '') {
            return $this->settings->sentFolder;
        }

        $byName = null;
        foreach ($this->command($imap, 'LIST "" "*"') as $line) {
            // * LIST (\HasNoChildren \Sent) "." "INBOX.Sent"
            if (!preg_match('/^\* LIST \(([^)]*)\) (?:"(?:[^"\\\\]|\\\\.)*"|NIL) (.+)$/i', rtrim($line), $m)) {
                continue;
            }
            $name = $this->unquote($m[2]);
            if (preg_match('/(^|\s)\\\\Sent(\s|$)/i', $m[1])) {
                return $name;
            }
            if ($byName === null && preg_match(self::SENT_NAMES, $name)) {
                $byName = $name;
            }
        }

        return $byName ?? throw new MailException('No se encontró la carpeta de Enviados: indica su nombre a mano (p. ej. INBOX.Sent)');
    }

    /** @return list<string> respuestas sin etiqueta (las que empiezan por «*») */
    private function command(SmtpConnection $imap, string $command, ?string $label = null): array
    {
        $tag = $this->nextTag();
        $imap->write("{$tag} {$command}\r\n");

        return $this->finish($imap, $tag, $label ?? $command);
    }

    /** @return list<string> */
    private function finish(SmtpConnection $imap, string $tag, string $label): array
    {
        $untagged = [];
        while (true) {
            $line = $this->readLine($imap);
            if ($line === '') {
                throw new MailException("El servidor IMAP no respondió a {$label}");
            }
            if (!str_starts_with($line, $tag . ' ')) {
                $untagged[] = $line;
                continue;
            }
            if (!preg_match('/^\S+ OK/i', $line)) {
                throw new MailException("El servidor IMAP rechazó {$label}: " . trim(substr($line, strlen($tag) + 1)));
            }

            return $untagged;
        }
    }

    /** Una línea completa (la conexión entrega trozos de como mucho 514 bytes). */
    private function readLine(SmtpConnection $imap): string
    {
        $line = '';
        while (($chunk = $imap->readLine()) !== null) {
            $line .= $chunk;
            if (str_ends_with($chunk, "\n")) {
                break;
            }
        }

        return $line;
    }

    private function nextTag(): string
    {
        return 'A' . (++$this->tag);
    }

    private function quote(string $value): string
    {
        if (preg_match('/[\r\n\x00]/', $value)) {
            throw new MailException('El usuario, la contraseña o la carpeta contienen caracteres no válidos');
        }

        return '"' . addcslashes($value, '"\\') . '"';
    }

    private function unquote(string $value): string
    {
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') {
            return (string) preg_replace('/\\\\(.)/', '$1', substr($value, 1, -1));
        }

        return $value;
    }
}
