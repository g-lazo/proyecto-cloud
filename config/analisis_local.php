<?php
declare(strict_types=1);

/**
 * Misma lógica que la Lambda Python de Fase 2 (lambda/financial_analyzer/lambda_function.py).
 * En Fase 1 se invoca localmente desde public/analisis.php cuando LAMBDA_FUNCTION_NAME no está seteada.
 */
function analizar_gastos(array $gastos, int $mes, int $anio): array
{
    if (!$gastos) {
        return [
            'mensaje'         => 'Sin gastos registrados en este periodo.',
            'metricas'        => new stdClass(),
            'anomalias'       => [],
            'recomendaciones' => [],
        ];
    }

    $diasEs = DIAS_ES; // lunes=0 ... domingo=6 (PHP DateTime->format('N') es 1..7)
    $montos = array_map(fn($g) => (float)$g['monto'], $gastos);
    $total  = array_sum($montos);

    $porCategoria  = [];
    $porDiaSemana  = [];
    foreach ($gastos as $g) {
        $cat = (string)$g['categoria'];
        $porCategoria[$cat] = ($porCategoria[$cat] ?? 0) + (float)$g['monto'];

        $fecha = DateTimeImmutable::createFromFormat('Y-m-d', $g['fecha']);
        if ($fecha === false) continue;
        $idx = ((int)$fecha->format('N')) - 1; // 0..6, lunes=0
        $dia = $diasEs[$idx];
        $porDiaSemana[$dia] = ($porDiaSemana[$dia] ?? 0) + (float)$g['monto'];
    }

    arsort($porCategoria);
    arsort($porDiaSemana);
    $topCategoria = array_key_first($porCategoria);
    $topDia       = array_key_first($porDiaSemana);

    // Anomalías = monto > media + 2*stdev (sample stdev)
    $anomalias = [];
    if (count($montos) >= 3) {
        $n     = count($montos);
        $mu    = $total / $n;
        $sumSq = 0.0;
        foreach ($montos as $m) $sumSq += ($m - $mu) ** 2;
        $sigma  = sqrt($sumSq / ($n - 1));
        $umbral = $mu + 2 * $sigma;
        foreach ($gastos as $g) {
            if ((float)$g['monto'] > $umbral) {
                $anomalias[] = [
                    'descripcion' => ($g['descripcion'] ?? '') !== '' ? (string)$g['descripcion'] : '(sin descripción)',
                    'monto'       => round((float)$g['monto'], 2),
                    'fecha'       => (string)$g['fecha'],
                ];
            }
        }
    }

    $diasMes = (int)cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
    $hoy     = new DateTimeImmutable('now');
    if ((int)$hoy->format('Y') === $anio && (int)$hoy->format('n') === $mes) {
        $diasTranscurridos = max((int)$hoy->format('j'), 1);
        $proyeccion = ($total / $diasTranscurridos) * $diasMes;
    } else {
        $proyeccion = $total;
    }

    $recomendaciones = [];
    if ($total > 0 && ($porCategoria[$topCategoria] / $total) > 0.4) {
        $recomendaciones[] = "Más del 40% de tus gastos van a {$topCategoria}. Revisa esa categoría.";
    }
    if ($anomalias) {
        $recomendaciones[] = 'Detectamos ' . count($anomalias) . ' gasto(s) inusualmente altos. Revísalos.';
    }
    if ($proyeccion > $total * 1.2) {
        $recomendaciones[] = sprintf('A este ritmo gastarás aprox. $%.2f este mes.', $proyeccion);
    }
    if (!$recomendaciones) {
        $recomendaciones[] = 'Tu patrón de gastos se ve saludable. Sigue así.';
    }

    return [
        'metricas' => [
            'total'                   => round($total, 2),
            'promedio_por_gasto'      => round($total / count($montos), 2),
            'numero_gastos'           => count($montos),
            'categoria_top'           => ['nombre' => $topCategoria, 'monto' => round($porCategoria[$topCategoria], 2)],
            'dia_top'                 => ['nombre' => $topDia,       'monto' => round($porDiaSemana[$topDia], 2)],
            'distribucion_categorias' => array_map(fn($v) => round($v, 2), $porCategoria),
            'proyeccion_fin_mes'      => round($proyeccion, 2),
        ],
        'anomalias'       => $anomalias,
        'recomendaciones' => $recomendaciones,
    ];
}
