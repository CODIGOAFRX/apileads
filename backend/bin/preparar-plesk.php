<?php

declare(strict_types=1);

/**
 * Ayudante de ./preparar-plesk.sh (se ejecuta dentro del contenedor de Docker).
 *
 *   php bin/preparar-plesk.php env                → escribe por pantalla un backend/.env de producción con
 *                                                   claves nuevas (JWT_SECRET, MAIL_ACCOUNTS_KEY, contraseñas)
 *   php bin/preparar-plesk.php datos <env-prod>   → sobre la copia de la base de datos que se va a subir
 *                                                   (DB_DSN apuntando a ella): pone al administrador la
 *                                                   ADMIN_PASSWORD de producción y vuelve a cifrar las
 *                                                   contraseñas de los buzones con la clave de producción.
 */

use Leads\Core\App;
use Leads\Core\Env;
use Leads\Domain\User\Role;
use Leads\Infrastructure\Security\SecretBox;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Env::load($root . '/.env');

/** Contraseña con mayúsculas, minúsculas, números y símbolo (lo que pide Plesk), sin caracteres que líen al .env. */
function clave(int $length): string
{
    $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $chars = ['-', '!', random_int(2, 9), 'ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 23)], 'abcdefghijkmnpqrstuvwxyz'[random_int(0, 23)]];
    while (count($chars) < $length) {
        $chars[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    // Barajado con random_int (shuffle() no es criptográfico); el primero siempre letra.
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }

    return 'Ed' . implode('', $chars);
}

/** @return array<string, string> */
function leerEnv(string $file): array
{
    $values = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        $values[$key] = $value;
    }

    return $values;
}

$command = $argv[1] ?? '';

if ($command === 'env') {
    $env = (string) file_get_contents($root . '/.env.example');
    $values = [
        'APP_DEBUG' => '0',
        'DB_PASSWORD' => clave(20),
        'JWT_SECRET' => base64_encode(random_bytes(48)),
        'ADMIN_PASSWORD' => clave(14),
        // El servidor de correo de la escuela (el mismo que usan los buzones de las comerciales).
        'MAIL_HOST' => 'estaciondiseno.es',
        'MAIL_PORT' => '465',
        'MAIL_ENCRYPTION' => 'ssl',
        'MAIL_PASSWORD' => '',
        'MAIL_ACCOUNTS_KEY' => base64_encode(random_bytes(32)),
    ];
    foreach ($values as $key => $value) {
        $env = preg_replace_callback('/^' . preg_quote($key, '/') . '=.*$/m', static fn () => "{$key}={$value}", $env, 1, $found);
        if ($found !== 1) {
            fwrite(STDERR, "No encuentro {$key} en .env.example\n");
            exit(1);
        }
    }
    echo "# CONFIGURACIÓN DE PRODUCCIÓN (Plesk) · generada el " . date('Y-m-d') . " por preparar-plesk.sh\n"
        . "# Contiene contraseñas: no la subas a GitHub ni la mandes por correo.\n"
        . "# DB_PASSWORD es la que hay que poner al crear la base de datos en Plesk (o cámbiala aquí por la que se use).\n"
        . "# MAIL_PASSWORD: contraseña de admisiones@ si se quiere que envíen desde ahí las comerciales sin buzón propio.\n\n"
        . $env;
    exit(0);
}

if ($command === 'datos' && isset($argv[2]) && is_file($argv[2])) {
    $prod = leerEnv($argv[2]);
    $pdo = (new App($root))->pdo();

    $admin = $pdo->prepare('UPDATE usuario SET password_hash = :hash, activo = 1 WHERE email = :email AND rol = :rol');
    $admin->execute(['hash' => password_hash($prod['ADMIN_PASSWORD'], PASSWORD_DEFAULT), 'email' => $prod['ADMIN_EMAIL'], 'rol' => Role::ADMIN->value]);
    if ($admin->rowCount() !== 1) {
        fwrite(STDERR, "No hay ningún administrador con el correo {$prod['ADMIN_EMAIL']}\n");
        exit(1);
    }
    echo "Administrador {$prod['ADMIN_EMAIL']}: contraseña de producción puesta.\n";

    $local = new SecretBox(Env::get('MAIL_ACCOUNTS_KEY') ?? Env::required('JWT_SECRET'));
    $server = new SecretBox(($prod['MAIL_ACCOUNTS_KEY'] ?? '') !== '' ? $prod['MAIL_ACCOUNTS_KEY'] : $prod['JWT_SECRET']);
    $update = $pdo->prepare('UPDATE cuenta_correo SET password_cifrada = :box WHERE id = :id');
    foreach ($pdo->query('SELECT id, email, password_cifrada FROM cuenta_correo')->fetchAll(PDO::FETCH_ASSOC) as $box) {
        try {
            $update->execute(['box' => $server->encrypt($local->decrypt($box['password_cifrada'])), 'id' => $box['id']]);
            echo "Buzón {$box['email']}: contraseña cifrada con la clave del servidor.\n";
        } catch (RuntimeException) {
            $pdo->prepare('DELETE FROM cuenta_correo WHERE id = :id')->execute(['id' => $box['id']]);
            echo "Buzón {$box['email']}: su contraseña no se podía leer; se quita y habrá que configurarlo en el servidor.\n";
        }
    }
    exit(0);
}

if ($command === 'zip' && isset($argv[2], $argv[3]) && is_dir($argv[2])) {
    // Con ZipArchive y no con Compress-Archive de Windows, que se deja los ficheros que empiezan por punto (.htaccess, .env).
    @unlink($argv[3]);
    $zip = new ZipArchive();
    $zip->open($argv[3], ZipArchive::CREATE);
    $base = realpath($argv[2]);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($files as $file) {
        $name = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
        $file->isDir() ? $zip->addEmptyDir($name) : $zip->addFile($file->getPathname(), $name);
    }
    $count = $zip->numFiles;
    $zip->close();
    echo "{$argv[3]}: {$count} entradas.\n";
    exit(0);
}

fwrite(STDERR, "Uso: php bin/preparar-plesk.php env | datos <fichero-env-produccion> | zip <carpeta> <fichero.zip>\n");
exit(1);
