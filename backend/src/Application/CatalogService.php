<?php

declare(strict_types=1);

namespace Leads\Application;

use Leads\Core\Clock;
use Leads\Core\HttpException;
use Leads\Core\Input;
use Leads\Domain\Lead\Company;
use Leads\Domain\Lead\LeadOrigin;
use Leads\Domain\Shared\Text;
use Leads\Domain\User\Role;
use Leads\Infrastructure\Persistence\DbTime;
use Leads\Infrastructure\Persistence\CatalogRepository;

/**
 * Mantenimiento de centros y estudios (y los alias con que se reconocen).
 * Cualquier usuario puede crear un estudio que falte (p. ej. un curso corto
 * nuevo); editarlos, borrarlos y los alias quedan para administración.
 */
final class CatalogService
{
    public const STUDY_TYPES = ['GRADO' => 'Grado', 'CFGS' => 'CFGS', 'MASTER' => 'Máster', 'CURSO' => 'Curso', 'OTRO' => 'Otro'];
    public const AREAS = [
        'GRAFICO' => 'Gráfico', 'MODA' => 'Moda', 'ILUSTRACION' => 'Ilustración', 'AUDIOVISUAL' => 'Audiovisual',
        'INTERACTIVA' => 'Interactiva', 'COMIC' => 'Cómic', 'MODELADO_3D' => 'Modelado y animación 3D',
        'INFORMATICA' => 'Informática', 'OTRA' => 'Otra',
    ];

    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly ?Clock $clock = null,
    ) {
    }

    /** $viewer limitado a una empresa: solo ve los estudios de esa. */
    public function catalog(?array $viewer = null): array
    {
        $company = $viewer === null ? null : Company::ofUser($viewer);
        $studies = $this->catalog->studies();
        if ($company !== null) {
            $studies = array_values(array_filter($studies, static fn (array $s): bool => $s['empresa'] === $company->value));
        }

        return [
            'estudios' => $studies,
            'centros' => $this->catalog->centers(),
            'tipos' => self::STUDY_TYPES,
            'areas' => self::AREAS,
            'empresas' => Company::catalog(),
            'origenes' => LeadOrigin::catalog(),
        ];
    }

    /**
     * Alta (id null) o edición. $viewer: quién lo hace. Una comercial solo da de
     * alta: el estudio queda activo, en todos los centros si no elige, y al final
     * de la lista. Si ya existe uno con ese nombre (o con ese alias) se avisa con
     * 409 y se devuelve, para usarlo en lugar de duplicarlo.
     */
    public function saveStudy(?int $id, array $data, ?array $viewer = null): array
    {
        $current = $id === null ? null : $this->catalog->study($id);
        if ($id !== null && $current === null) {
            throw HttpException::notFound('Estudio no encontrado');
        }
        $isAdmin = $viewer === null || ($viewer['rol'] ?? null) === Role::ADMIN->value;
        if (!$isAdmin && $id !== null) {
            throw HttpException::forbidden('Solo administración puede cambiar un estudio');
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', Input::text($data, 'nombre')));
        $type = Input::text($data, 'tipo', $isAdmin ? '' : 'CURSO');
        $area = Input::text($data, 'area');
        // Quien lo crea puede decir de qué empresa es; si no lo dice, de Grupo EFP (o la que ya tenía).
        // Quien solo trabaja con una empresa crea (y edita) estudios de esa, sin poder elegir.
        $scope = $viewer === null ? null : Company::ofUser($viewer);
        if ($scope !== null && $current !== null && $current['empresa'] !== $scope->value) {
            throw HttpException::notFound('Estudio no encontrado');
        }
        $company = $scope ?? Company::tryFrom(Input::text($data, 'empresa', $current['empresa'] ?? Company::EFP->value));
        $color = strtoupper(trim(Input::text($data, 'color', '#00FF81')));
        if ($name === '' || mb_strlen($name) > 160) {
            throw new \InvalidArgumentException('Indica el nombre del estudio');
        }
        if (!isset(self::STUDY_TYPES[$type])) {
            throw new \InvalidArgumentException('Tipo de estudio no válido');
        }
        if ($area !== '' && !isset(self::AREAS[$area])) {
            throw new \InvalidArgumentException('Área no válida');
        }
        if ($company === null) {
            throw new \InvalidArgumentException('Empresa no válida');
        }
        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
            throw new \InvalidArgumentException('El color debe tener formato #RRGGBB');
        }
        $existing = $this->findByName($name);
        if ($existing !== null && $existing['id'] !== $id) {
            throw HttpException::conflict("Ya existe «{$existing['nombre']}»: elígelo en lugar de crear otro", ['estudio' => $existing]);
        }

        $centerIds = array_values(array_filter(Input::ids($data['centroIds'] ?? []), fn (int $c): bool => $this->catalog->centerExists($c)));
        if ($id === null && !array_key_exists('centroIds', $data)) {
            $centerIds = array_column(array_filter($this->catalog->centers(), static fn (array $c): bool => $c['activo']), 'id');
        }
        // Posición en las listas: 0-9999 (la columna es INT y no admite cualquier número).
        $order = filter_var($data['orden'] ?? null, FILTER_VALIDATE_INT);
        if ($order === false || !$isAdmin) {
            $order = $id === null ? $this->nextOrder() : 0;
        }
        $order = max(0, min(9999, $order));
        $values = [
            'nombre' => $name,
            'tipo' => $type,
            'area' => $area === '' ? null : $area,
            'empresa' => $company->value,
            'color' => $color,
            'orden' => $order,
            'activo' => !$isAdmin || filter_var($data['activo'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ];
        if ($id === null) {
            $values += ['creado_por' => $viewer === null ? null : (int) $viewer['id'], 'creado_en' => DbTime::toDb($this->clock?->now())];
        }

        $id = $this->catalog->saveStudy($id, $values, $centerIds);

        return $this->catalog->study($id) ?? throw HttpException::notFound('Estudio no encontrado');
    }

    /** Estudio con ese nombre (sin mirar tildes, mayúsculas ni signos) o con ese alias. */
    public function findByName(string $name): ?array
    {
        $key = Text::slug($name);
        if ($key === '') {
            return null;
        }
        $studyId = $this->catalog->aliasMap()[$key] ?? null;
        foreach ($this->catalog->studies() as $study) {
            if ($study['id'] === $studyId || Text::slug($study['nombre']) === $key) {
                return $study;
            }
        }

        return null;
    }

    private function nextOrder(): int
    {
        $orders = array_column($this->catalog->studies(), 'orden');

        return $orders === [] ? 1 : max($orders) + 1;
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
        // Tamaño de la columna estudio_alias.alias.
        $key = trim(substr(Text::slug($alias), 0, 190));
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
        $name = trim(Input::text($data, 'nombre'));
        if ($name === '') {
            throw new \InvalidArgumentException('Indica el nombre del centro');
        }
        if ($id !== null && !$this->catalog->centerExists($id)) {
            throw HttpException::notFound('Centro no encontrado');
        }
        $id = $this->catalog->saveCenter($id, [
            'nombre' => mb_substr($name, 0, 120),
            'ciudad' => Text::truncate(Text::nullIfEmpty(Input::text($data, 'ciudad')), 120),
            'provincia' => Text::truncate(Text::nullIfEmpty(Input::text($data, 'provincia')), 120),
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
