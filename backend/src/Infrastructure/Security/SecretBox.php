<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Security;

/**
 * Cifrado reversible (AES-256-GCM) para las contraseñas de los buzones de
 * correo: hay que enviarlas al servidor SMTP, así que no sirve un hash.
 * La clave se deriva de MAIL_ACCOUNTS_KEY (o, si no existe, de JWT_SECRET):
 * si esa clave cambia, hay que volver a escribir las contraseñas.
 */
final class SecretBox
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'v1:';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private readonly string $key;

    public function __construct(string $secret)
    {
        if (strlen($secret) < 16) {
            throw new \RuntimeException('La clave para cifrar las contraseñas de correo es demasiado corta');
        }
        $this->key = hash_hmac('sha256', 'cuentas-de-correo', $secret, true);
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);
        if ($cipher === false) {
            throw new \RuntimeException('No se pudo cifrar la contraseña');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $box): string
    {
        $data = str_starts_with($box, self::PREFIX) ? base64_decode(substr($box, strlen(self::PREFIX)), true) : false;
        $plain = $data === false || strlen($data) < self::IV_LENGTH + self::TAG_LENGTH ? false : openssl_decrypt(
            substr($data, self::IV_LENGTH + self::TAG_LENGTH),
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            substr($data, 0, self::IV_LENGTH),
            substr($data, self::IV_LENGTH, self::TAG_LENGTH),
        );
        if ($plain === false) {
            throw new \RuntimeException('No se pudo leer la contraseña guardada del buzón (¿ha cambiado JWT_SECRET o MAIL_ACCOUNTS_KEY?). Vuelve a escribirla en Ajustes → Usuarios.');
        }

        return $plain;
    }
}
