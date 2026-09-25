<?php

declare(strict_types=1);

namespace Leads\Http\Controller;

use Leads\Core\App;
use Leads\Core\Env;
use Leads\Core\HttpException;
use Leads\Core\Request;
use Leads\Domain\Import\Table;

abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    /**
     * Lee el fichero subido en el campo "archivo" y lo convierte en tabla.
     *
     * @return array{0: Table, 1: string} tabla y nombre original del fichero
     */
    protected function uploadedTable(Request $request): array
    {
        $file = $request->file('archivo') ?? throw HttpException::badRequest('No se ha recibido ningún fichero');
        $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw HttpException::badRequest('El fichero es demasiado grande para el servidor');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw HttpException::badRequest('No se pudo subir el fichero (código ' . $error . ')');
        }
        $maxBytes = Env::int('IMPORT_MAX_MB', 10) * 1024 * 1024;
        if ((int) $file['size'] > $maxBytes) {
            throw HttpException::badRequest('El fichero supera el máximo de ' . Env::int('IMPORT_MAX_MB', 10) . ' MB');
        }
        $name = basename((string) ($file['name'] ?? 'leads.csv'));

        return [$this->app->spreadsheetReader()->read((string) $file['tmp_name'], $name), $name];
    }

    /** Opciones enviadas como JSON en un campo de formulario multipart. */
    protected function jsonField(Request $request, string $name): array
    {
        $raw = $request->input($name);
        if (is_array($raw)) {
            return $raw;
        }

        return Request::decodeJson((string) ($raw ?? ''));
    }

    /** Anota en el registro de actividad lo que acaba de hacer quien llama. */
    protected function audit(Request $request, string $type, string $summary, array $detail = [], ?int $leadId = null, int $dedupeSeconds = 0): void
    {
        $this->app->audit()->record($request->userId(), $type, $summary, $detail, $leadId, $request->ip(), $dedupeSeconds);
    }

    protected function optionalId(mixed $value): ?int
    {
        return $value === null || $value === '' || (int) $value <= 0 ? null : (int) $value;
    }
}
