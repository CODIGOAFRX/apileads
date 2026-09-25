<?php

declare(strict_types=1);

namespace Leads\Domain\Mail;

/**
 * Sustituye {{variables}} en asunto y cuerpo. Los valores se escapan en HTML;
 * {{bloques_estudios}} admite HTML porque viene de las plantillas de estudio.
 */
final class TemplateRenderer
{
    private const RAW = ['bloques_estudios'];

    public const VARIABLES = [
        'nombre' => 'Nombre del lead',
        'nombre_completo' => 'Nombre y apellidos',
        'estudio' => 'Estudio (si solo hay uno) o lista',
        'estudios' => 'Lista de estudios de interés',
        'comercial' => 'Nombre y apellidos de quien envía',
        'comercial_nombre' => 'Solo el nombre de quien envía («Soy Verónica…»)',
        'bloques_estudios' => 'Contenido de las plantillas de cada estudio (solo en la genérica)',
        'inicio_bloque' => 'En la plantilla de un estudio: desde aquí se copia en el correo de varios estudios',
        'fin_bloque' => 'En la plantilla de un estudio: hasta aquí se copia en el correo de varios estudios',
    ];

    /** @param array<string,string> $variables */
    public function render(string $template, array $variables, bool $html): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function (array $m) use ($variables, $html): string {
            $value = (string) ($variables[$m[1]] ?? '');
            if (!$html) {
                return $value;
            }
            return in_array($m[1], self::RAW, true) ? $value : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }, $template);
    }

    /** Asunto: sin saltos de línea para que no se puedan inyectar cabeceras. */
    public function subject(string $template, array $variables): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $this->render($template, $variables, false)));
    }

    /** Lista legible: "A", "A y B", "A, B y C". */
    public static function humanList(array $items): string
    {
        $items = array_values(array_filter($items, static fn ($i) => $i !== ''));
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' y ' . $last;
    }
}
