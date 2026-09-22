-- Base de datos aparte para las pruebas automáticas (se vacía en cada prueba).
CREATE DATABASE IF NOT EXISTS apileads_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON apileads_test.* TO 'apileads'@'%';
FLUSH PRIVILEGES;
