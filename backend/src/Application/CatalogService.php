<?php

declare(strict_types=1);

namespace Leads\Application;

use Leads\Core\HttpException;
use Leads\Domain\Shared\Text;
use Leads\Infrastructure\Persistence\CatalogRepository;

/** Mantenimiento de centros y estudios (y los alias con que se reconocen). */
final class CatalogService
{
    public const STUDY_TYPES = ['GRADO' => 'Grado', 'CFGS' => 'CFGS', 'MASTER' => 'Máster', 'CURSO' => 'Curso', 'OTRO' => 'Otro'];
    public const AREAS = [
        'GRAFICO' => 'Gráfico', 'MODA' => 'Moda', 'ILUSTRACION' => 'Ilustración', 'AUDIOVISUAL' => 'Audiovisual',
        'INTERACTIVA' => 'Interactiva', 'COMIC' => 'Cómic', 'MODELADO_3D' => 'Modelado y animación 3D',
        'INFORMATICA' => 'Informática', 'OTRA' => 'Otra',
    ];

    public function __construct(private readonly CatalogRepository $catalog)
    {
    }

    public function catalog(): array
    {
        return [
            'estudios' => $this->catalog->studies(),
            'centros' => $this->catalog->centers(),
            'tipos' => self::STUDY_TYPES,
            'areas' => self::AREAS,
        ];
    }

    public function saveStudy(?int $id, array $data): array
    {
        if ($id !== null && $this->catalog->study($id) === null) {
            throw HttpException::notFound('Estudio no encontrado');
        }
        $name = trim((string) ($data['nombre'] ?? ''));
        $type = (string) ($data['tipo'] ?? '');
        $area = (string) ($data['area'] ?? '');
        $color = strtoupper(trim((string) ($data['color'] ?? '#00FF81')));
        if ($name === '' || mb_strlen($name) > 160) {
            throw new \InvalidArgumentException('Indica el nombre del estudio');
        }
        if (!isset(self::STUDY_TYPES[$type])) {
            throw new \InvalidArgumentException('Tipo de estudio no válido');
        }
        if ($area !== '' && !isset(self::AREAS[$area])) {
            throw new \InvalidArgumentException('Área no válida');
        }
        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
            throw new \InvalidArgumentException('El color debe tener formato #RRGGBB');
        }
        $centerIds = array_values(array_filter(
            array_map('intval', (array) ($data['centroIds'] ?? [])),
            fn (int $c): bool => $this->catalog->centerExists($c)
        ));

        $id = $this->catalog->saveStudy($id, [
            'nombre' => $name,
            'tipo' => $type,
            'area' => $area === '' ? null : $area,
            'color' => $color,
            'orden' => (int) ($data['orden'] ?? 0),
            'activo' => filter_var($data['activo'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ], $centerIds);

        return $this->catalog->study($id);
    }

    public function deleteStudy(int $id): void
    {
        if ($this->catalog->studyHasLeads($id)) {
            throw HttpException::conflict('Hay leads con este estudio. Desactívalo en lugar de borrarlo.');
        }
        $this->catalog->deleteStudy($id);
    }

    public function addAlias(int $studyId, string $alias, \DateTimeImmutable $now): array
    {
        if ($this->catalog->study($studyId) === null) {
            throw HttpException::notFound('Estudio no encontrado');
        }
        $key = Text::slug($alias);
        if ($key === '') {
            throw new \InvalidArgumentException('El alias está vacío');
        }
        $this->catalog->saveAlias($key, $studyId, $now);

        return $this->catalog->study($studyId);
    }

    public function deleteAlias(int $aliasId): void
    {
        $this->catalog->deleteAlias($aliasId);
    }

    public function saveCenter(?int $id, array $data): array
    {
        $name = trim((string) ($data['nombre'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Indica el nombre del centro');
        }
        if ($id !== null && !$this->catalog->centerExists($id)) {
            throw HttpException::notFound('Centro no encontrado');
        }
        $id = $this->catalog->saveCenter($id, [
            'nombre' => mb_substr($name, 0, 120),
            'ciudad' => Text::nullIfEmpty((string) ($data['ciudad'] ?? '')),
            'provincia' => Text::nullIfEmpty((string) ($data['provincia'] ?? '')),
            'activo' => filter_var($data['activo'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ]);

        foreach ($this->catalog->centers() as $center) {
            if ($center['id'] === $id) {
                return $center;
            }
        }

        throw HttpException::notFound('Centro no encontrado');
    }
}
