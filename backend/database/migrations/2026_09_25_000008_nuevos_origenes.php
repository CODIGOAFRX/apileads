<?php

/**
 * Orígenes nuevos: Web, Redes sociales, Referencias, Año pasado, Feria, Colegios,
 * AprendeMás, Emagister, TuMaster y EducaWeb (ver Leads\Domain\Lead\LeadOrigin).
 * Los antiguos se pasan al que les corresponde; Teléfono, Alta manual y Otro
 * quedan como «Sin indicar» (OTRO), que ya no se puede elegir.
 */
$equivalences = [
    'REDES_SOCIALES' => ['FACEBOOK', 'INSTAGRAM', 'TIKTOK'],
    'WEB' => ['GOOGLE'],
    'OTRO' => ['TELEFONO', 'MANUAL'],
];

$sql = [];
foreach (['leads', 'importacion'] as $table) {
    foreach ($equivalences as $new => $old) {
        $sql[] = sprintf(
            "UPDATE %s SET origen = '%s' WHERE origen IN ('%s')",
            $table, $new, implode("', '", $old)
        );
    }
}

return $sql;
