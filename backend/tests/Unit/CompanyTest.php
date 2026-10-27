<?php

declare(strict_types=1);

namespace Leads\Tests\Unit;

use Leads\Domain\Lead\Company;
use PHPUnit\Framework\TestCase;

final class CompanyTest extends TestCase
{
    public function testEsDeEstacionDisenoSiAlgunEstudioLoEs(): void
    {
        self::assertSame(Company::ESTACION, Company::ofStudies([['empresa' => 'EFP'], ['empresa' => 'ESTACION']]));
        self::assertSame(Company::EFP, Company::ofStudies([['empresa' => 'EFP']]));
        self::assertSame(Company::EFP, Company::ofStudies([]), 'sin estudio, de Grupo EFP');
        self::assertSame(Company::EFP, Company::ofStudies([['nombre' => 'Sin empresa']]));
    }

    public function testCatalogo(): void
    {
        self::assertSame(['ESTACION' => 'Estación Diseño', 'EFP' => 'Grupo EFP'], Company::catalog());
    }
}
