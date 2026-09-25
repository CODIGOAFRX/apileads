<?php

declare(strict_types=1);

namespace Leads\Application;

use Leads\Core\Clock;
use Leads\Core\HttpException;
use Leads\Infrastructure\Mail\ImapClient;
use Leads\Infrastructure\Mail\ImapSettings;
use Leads\Infrastructure\Mail\MimeBuilder;
use Leads\Infrastructure\Mail\PersonalMailbox;
use Leads\Infrastructure\Mail\SmtpSettings;
use Leads\Infrastructure\Mail\SmtpTransport;
use Leads\Infrastructure\Persistence\DbTime;
use Leads\Infrastructure\Persistence\MailAccountRepository;
use Leads\Infrastructure\Persistence\UserRepository;
use Leads\Infrastructure\Security\SecretBox;

/**
 * Buzón de correo de cada comercial (lo configura administración). Con él, el
 * correo de información sale de su propia dirección y queda en sus Enviados.
 * Sin buzón se usa el remitente general del .env (MAIL_*).
 */
final class MailAccountService
{
    private const HOST = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i';

    public function __construct(
        private readonly MailAccountRepository $accounts,
        private readonly UserRepository $users,
        private readonly SecretBox $secrets,
        private readonly Clock $clock,
        private readonly int $timeout = 20,
    ) {
    }

    public function get(int $userId): ?array
    {
        $this->assertUser($userId);
        $account = $this->accounts->findByUser($userId);

        return $account === null ? null : self::present($account);
    }

    /** La contraseña vacía conserva la guardada. */
    public function save(int $userId, array $data): array
    {
        $this->assertUser($userId);
        $existing = $this->accounts->findByUser($userId);
        $values = $this->validate($data);
        $password = (string) ($data['password'] ?? '');
        if ($password === '' && $existing === null) {
            throw new \InvalidArgumentException('Escribe la contraseña del buzón');
        }
        if ($password !== '') {
            $values['password_cifrada'] = $this->secrets->encrypt($password);
        }
        $this->accounts->save($userId, $values + ['actualizado_en' => DbTime::toDb($this->clock->now())]);

        return self::present($this->accounts->findByUser($userId));
    }

    public function delete(int $userId): void
    {
        $this->assertUser($userId);
        $this->accounts->delete($userId);
    }

    /**
     * Prueba los datos del formulario sin guardarlos ni enviar nada.
     * Sin contraseña, usa la que ya estaba guardada.
     */
    public function test(int $userId, array $data): array
    {
        $this->assertUser($userId);
        $values = $this->validate($data);
        $password = (string) ($data['password'] ?? '');
        if ($password === '') {
            $existing = $this->accounts->findByUser($userId) ?? throw new \InvalidArgumentException('Escribe la contraseña del buzón');
            $password = $this->password($existing);
        }

        return $this->mailboxFor($values, $password)->verify();
    }

    /** Buzón con el que envía este usuario, o null si no tiene (se usa el general). */
    public function mailbox(int $userId): ?PersonalMailbox
    {
        $account = $this->accounts->findByUser($userId);

        return $account === null ? null : $this->mailboxFor($account, $this->password($account));
    }

    public function senderAddress(int $userId): ?string
    {
        return $this->accounts->findByUser($userId)['email'] ?? null;
    }

    public static function present(array $account): array
    {
        return [
            'email' => $account['email'],
            'usuario' => $account['usuario'],
            'smtpHost' => $account['smtp_host'],
            'smtpPuerto' => (int) $account['smtp_puerto'],
            'smtpCifrado' => $account['smtp_cifrado'],
            'imapHost' => $account['imap_host'],
            'imapPuerto' => $account['imap_puerto'] === null ? null : (int) $account['imap_puerto'],
            'guardarEnviados' => (bool) $account['guardar_enviados'],
            'carpetaEnviados' => $account['carpeta_enviados'],
            'verificarCertificado' => (bool) $account['verificar_certificado'],
            'actualizado' => DbTime::toApi($account['actualizado_en']),
        ];
    }

    /** Datos del formulario (API) → columnas de cuenta_correo. */
    private function validate(array $data): array
    {
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            throw new \InvalidArgumentException('Indica una dirección de correo válida');
        }
        $username = trim((string) ($data['usuario'] ?? '')) ?: $email;
        if (mb_strlen($username) > 190 || preg_match('/[\x00-\x1F]/', $username)) {
            throw new \InvalidArgumentException('El nombre de usuario del buzón no es válido');
        }

        $smtpHost = self::host($data['smtpHost'] ?? '', 'Indica el servidor de correo saliente (p. ej. estaciondiseno.es)');
        $smtpPort = self::port($data['smtpPuerto'] ?? 465, 'saliente');
        $encryption = (string) ($data['smtpCifrado'] ?? '') ?: ($smtpPort === 465 ? 'ssl' : 'tls');
        if (!in_array($encryption, SmtpTransport::ENCRYPTIONS, true)) {
            throw new \InvalidArgumentException('La seguridad del servidor saliente debe ser SSL, STARTTLS o ninguna');
        }

        $saveCopy = (bool) ($data['guardarEnviados'] ?? false);
        $imapHost = trim((string) ($data['imapHost'] ?? ''));
        if ($imapHost !== '' || $saveCopy) {
            $imapHost = self::host($imapHost, 'Indica el servidor de correo entrante para guardar la copia en Enviados');
        }
        $folder = trim((string) ($data['carpetaEnviados'] ?? ''));
        if (mb_strlen($folder) > 190 || preg_match('/[\x00-\x1F"\\\\]/', $folder)) {
            throw new \InvalidArgumentException('El nombre de la carpeta de Enviados no es válido');
        }

        return [
            'email' => $email,
            'usuario' => $username,
            'smtp_host' => $smtpHost,
            'smtp_puerto' => $smtpPort,
            'smtp_cifrado' => $encryption,
            'imap_host' => $imapHost === '' ? null : $imapHost,
            'imap_puerto' => $imapHost === '' ? null : self::port($data['imapPuerto'] ?? 993, 'entrante'),
            'guardar_enviados' => $saveCopy ? 1 : 0,
            'carpeta_enviados' => $folder === '' ? null : $folder,
            'verificar_certificado' => ($data['verificarCertificado'] ?? true) ? 1 : 0,
        ];
    }

    /** $account: fila de cuenta_correo o lo que devuelve validate(). */
    private function mailboxFor(array $account, string $password): PersonalMailbox
    {
        $from = (string) $account['email'];
        $verifyPeer = (bool) $account['verificar_certificado'];
        $mime = new MimeBuilder($from, '');
        $smtp = new SmtpTransport($mime, new SmtpSettings(
            host: (string) $account['smtp_host'],
            port: (int) $account['smtp_puerto'],
            encryption: (string) $account['smtp_cifrado'],
            username: (string) $account['usuario'],
            password: $password,
            fromEmail: $from,
            timeout: $this->timeout,
            verifyPeer: $verifyPeer,
        ));
        $imap = null;
        if ((bool) $account['guardar_enviados'] && ($account['imap_host'] ?? null) !== null) {
            $imapPort = (int) $account['imap_puerto'];
            $imap = new ImapClient(new ImapSettings(
                host: (string) $account['imap_host'],
                port: $imapPort,
                // 993: SSL directo · 143: STARTTLS
                encryption: $imapPort === 143 ? 'tls' : 'ssl',
                username: (string) $account['usuario'],
                password: $password,
                sentFolder: $account['carpeta_enviados'],
                timeout: $this->timeout,
                verifyPeer: $verifyPeer,
            ));
        }

        return new PersonalMailbox($mime, $smtp, $from, $imap);
    }

    private function password(array $account): string
    {
        try {
            return $this->secrets->decrypt((string) $account['password_cifrada']);
        } catch (\RuntimeException $e) {
            throw HttpException::conflict($e->getMessage());
        }
    }

    private function assertUser(int $userId): void
    {
        if ($this->users->find($userId) === null) {
            throw HttpException::notFound('Usuario no encontrado');
        }
    }

    private static function host(mixed $value, string $error): string
    {
        $host = mb_strtolower(trim((string) $value));
        if ($host === '' || strlen($host) > 190 || !preg_match(self::HOST, $host)) {
            throw new \InvalidArgumentException($error);
        }

        return $host;
    }

    private static function port(mixed $value, string $which): int
    {
        $port = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            throw new \InvalidArgumentException("El puerto del servidor {$which} no es válido");
        }

        return $port;
    }
}
