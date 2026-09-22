<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');
$_ENV['JWT_SECRET'] = base64_encode(str_repeat('clave-de-prueba-', 3));
$_ENV['APP_TIMEZONE'] = 'Europe/Madrid';
$_ENV['MAIL_TRANSPORT'] = 'log';
