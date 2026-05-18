<?php
declare(strict_types=1);

/**
 * Misma lógica que la Lambda Python de Fase 2 (lambda/financial_analyzer/lambda_function.py).
 * En Fase 1 se invoca localmente desde public/analisis.php cuando LAMBDA_FUNCTION_NAME no está seteada.
 *
 * Payload esperado:
 *   gastos:           [{monto, fecha, categoria, descripcion}, ...]
 *   gastos_anteriores [{monto, categoria}, ...] (mes anterior, para comparación)
 *   ingresos:         total numérico
 *   presupuestos:     [{categoria, monto_limite, consumido}, ...]
 *   metas:            [{nombre, monto_objetivo, monto_actual, fecha_objetivo}, ...]
 *   recurrentes:      [{descripcion, monto, dia_del_mes}, ...] (activos)
 */

// Clasificación 50/30/20 — categorías del sistema mapeadas a tipos.
// Categorías custom del usuario caen en 'otros' por default.
const CLASIFICACION_50_30_20 = [
    'Comida'        => 'necesidad',
    'Transporte'    => 'necesidad',
    'Materiales'    => 'necesidad',
    'Salud'         => 'necesidad',
    'Renta'         => 'necesidad',
    'Suscripciones' => 'deseo',
    'Salidas'       => 'deseo',
    'Otros'         => 'deseo',
];

function analizar_gastos(array $payload, int $mes, int $anio): array
{
    $gastos             = $payload['gastos']            ?? [];
    $gastos_anteriores  = $payload['gastos_anteriores'] ?? [];
    $ingresos_mes       = (float)($payload['ingresos']  ?? 0);
    $presupuestos       = $payload['presupuestos']      ?? [];
    $metas              = $payload['metas']             ?? [];
    $recurrentes        = $payload['recurrentes']       ?? [];

    // Compromisos mensuales (recurrentes activos) — se calcula siempre, incluso sin gastos
    $compromisos = null;
    if ($recurrentes) {
        $totalCompromisos = 0.0;
        $totalSubs        = 0.0;
        $items            = [];
        foreach ($recurrentes as $r) {
            $monto = (float)$r['monto'];
            $cat   = (string)($r['categoria'] ?? '');
            $totalCompromisos += $monto;
            if ($cat === 'Suscripciones') $totalSubs += $monto;
            $items[] = [
                'descripcion' => (string)$r['descripcion'],
                'categoria'   => $cat,
                'monto'       => round($monto, 2),
                'dia_del_mes' => (int)$r['dia_del_mes'],
            ];
        }
        usort($items, fn($a, $b) => $b['monto'] <=> $a['monto']);
        $compromisos = [
            'total'         => round($totalCompromisos, 2),
            'suscripciones' => round($totalSubs, 2),
            'numero'        => count($items),
            'items'         => $items,
            'pct_ingresos'  => $ingresos_mes > 0 ? round(($totalCompromisos / $ingresos_mes) * 100, 1) : null,
        ];
    }

    if (!$gastos) {
        return [
            'mensaje'         => 'Sin gastos registrados en este periodo.',
            'metricas'        => new stdClass(),
            'anomalias'       => [],
            'recomendaciones' => [],
            'comparacion'     => null,
            'ahorro'          => null,
            'quiebra'         => null,
            'regla_50_30_20'  => null,
            'sin_presupuesto' => [],
            'metas_factibilidad' => [],
            'compromisos'     => $compromisos,
        ];
    }

    $montos = array_map(fn($g) => (float)$g['monto'], $gastos);
    $total  = array_sum($montos);

    // --- Distribución por categoría y día de la semana ---
    $diasEs = DIAS_ES;
    $porCategoria = [];
    $porDiaSemana = [];
    foreach ($gastos as $g) {
        $cat = (string)$g['categoria'];
        $porCategoria[$cat] = ($porCategoria[$cat] ?? 0) + (float)$g['monto'];
        $fecha = DateTimeImmutable::createFromFormat('Y-m-d', $g['fecha']);
        if ($fecha === false) continue;
        $idx = ((int)$fecha->format('N')) - 1;
        $porDiaSemana[$diasEs[$idx]] = ($porDiaSemana[$diasEs[$idx]] ?? 0) + (float)$g['monto'];
    }
    arsort($porCategoria);
    arsort($porDiaSemana);
    $topCategoria = array_key_first($porCategoria);
    $topDia       = array_key_first($porDiaSemana);

    // --- Anomalías ---
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

    // --- Proyección fin de mes ---
    $diasMes = (int)date('t', mktime(0, 0, 0, $mes, $anio === 0 ? (int)date('Y') : $anio, 1));
    // ojo: mktime args = hour, min, sec, month, day, year
    $diasMes = (int)date('t', mktime(0, 0, 0, $mes, 1, $anio));
    $hoy     = new DateTimeImmutable('now');
    $esMesActual = ((int)$hoy->format('Y') === $anio && (int)$hoy->format('n') === $mes);
    if ($esMesActual) {
        $diasTranscurridos = max((int)$hoy->format('j'), 1);
        $promedioDiario    = $total / $diasTranscurridos;
        $proyeccion        = $promedioDiario * $diasMes;
    } else {
        $diasTranscurridos = $diasMes;
        $promedioDiario    = $total / $diasMes;
        $proyeccion        = $total;
    }

    // --- 1. Comparación vs mes anterior ---
    $totalAnterior = 0.0;
    $porCatAnterior = [];
    foreach ($gastos_anteriores as $g) {
        $totalAnterior += (float)$g['monto'];
        $cat = (string)$g['categoria'];
        $porCatAnterior[$cat] = ($porCatAnterior[$cat] ?? 0) + (float)$g['monto'];
    }
    $deltaTotal = $totalAnterior > 0 ? (($total - $totalAnterior) / $totalAnterior) * 100 : null;
    $deltaCategorias = [];
    foreach ($porCategoria as $cat => $monto) {
        $previo = (float)($porCatAnterior[$cat] ?? 0);
        $delta  = $previo > 0 ? (($monto - $previo) / $previo) * 100 : null;
        $deltaCategorias[] = [
            'categoria' => $cat,
            'actual'    => round($monto, 2),
            'anterior'  => round($previo, 2),
            'delta_pct' => $delta === null ? null : round($delta, 1),
        ];
    }
    $comparacion = [
        'total_actual'   => round($total, 2),
        'total_anterior' => round($totalAnterior, 2),
        'delta_pct'      => $deltaTotal === null ? null : round($deltaTotal, 1),
        'por_categoria'  => $deltaCategorias,
    ];

    // --- 2. Tasa de ahorro ---
    $ahorro = null;
    if ($ingresos_mes > 0) {
        $balance = $ingresos_mes - $total;
        $tasa    = ($balance / $ingresos_mes) * 100;
        $ahorro = [
            'ingresos'        => round($ingresos_mes, 2),
            'gastos'          => round($total, 2),
            'balance'         => round($balance, 2),
            'tasa_pct'        => round($tasa, 1),
            'evaluacion'      => $tasa >= 20 ? 'excelente' : ($tasa >= 10 ? 'buena' : ($tasa >= 0 ? 'baja' : 'negativa')),
        ];
    }

    // --- 4. Días hasta "quiebra" (mes actual con balance positivo) ---
    $quiebra = null;
    if ($esMesActual && $ahorro !== null && $ahorro['balance'] > 0 && $promedioDiario > 0) {
        $diasRestantesMes = $diasMes - $diasTranscurridos;
        $diasAguanta      = (int)floor($ahorro['balance'] / $promedioDiario);
        $quiebra = [
            'balance_disponible' => round($ahorro['balance'], 2),
            'promedio_diario'    => round($promedioDiario, 2),
            'dias_aguanta'       => $diasAguanta,
            'dias_restantes_mes' => $diasRestantesMes,
            'alcanza'            => $diasAguanta >= $diasRestantesMes,
        ];
    }

    // --- 5. Regla 50/30/20 ---
    $necesidades = 0.0; $deseos = 0.0;
    foreach ($porCategoria as $cat => $monto) {
        $tipo = CLASIFICACION_50_30_20[$cat] ?? 'deseo';
        if ($tipo === 'necesidad') $necesidades += $monto;
        else                       $deseos      += $monto;
    }
    $base = $ingresos_mes > 0 ? $ingresos_mes : $total;
    $ahorroEstimado = $ingresos_mes > 0 ? max(0.0, $ingresos_mes - $total) : 0.0;
    $regla = [
        'necesidades' => [
            'monto'      => round($necesidades, 2),
            'pct'        => $base > 0 ? round(($necesidades / $base) * 100, 1) : 0,
            'objetivo'   => 50,
            'estado'     => ($necesidades / max($base, 1)) * 100 <= 55 ? 'ok' : 'alto',
        ],
        'deseos' => [
            'monto'      => round($deseos, 2),
            'pct'        => $base > 0 ? round(($deseos / $base) * 100, 1) : 0,
            'objetivo'   => 30,
            'estado'     => ($deseos / max($base, 1)) * 100 <= 35 ? 'ok' : 'alto',
        ],
        'ahorro' => [
            'monto'      => round($ahorroEstimado, 2),
            'pct'        => $base > 0 ? round(($ahorroEstimado / $base) * 100, 1) : 0,
            'objetivo'   => 20,
            'estado'     => ($ahorroEstimado / max($base, 1)) * 100 >= 20 ? 'ok' : 'bajo',
        ],
        'base'    => round($base, 2),
        'basado_en' => $ingresos_mes > 0 ? 'ingresos' : 'gastos',
    ];

    // --- 7. Gastos sin presupuesto ---
    $catsConPresupuesto = [];
    foreach ($presupuestos as $p) {
        $catsConPresupuesto[(string)$p['categoria']] = (float)$p['monto_limite'];
    }
    $sinPresupuesto = [];
    foreach ($porCategoria as $cat => $monto) {
        if (!isset($catsConPresupuesto[$cat]) && $monto > 0) {
            $sinPresupuesto[] = [
                'categoria' => $cat,
                'gastado'   => round($monto, 2),
            ];
        }
    }

    // --- 8. Factibilidad de metas ---
    $metasFactibilidad = [];
    $ahorroMensual = $ahorro['balance'] ?? 0;
    foreach ($metas as $meta) {
        $objetivo = (float)$meta['monto_objetivo'];
        $actual   = (float)$meta['monto_actual'];
        $faltante = max(0.0, $objetivo - $actual);
        $fechaObj = $meta['fecha_objetivo'] ?? null;

        if ($faltante <= 0) {
            $metasFactibilidad[] = [
                'nombre'      => (string)$meta['nombre'],
                'estado'      => 'completada',
                'mensaje'     => 'Meta ya completada',
            ];
            continue;
        }

        if ($ahorroMensual <= 0) {
            $metasFactibilidad[] = [
                'nombre'      => (string)$meta['nombre'],
                'estado'      => 'imposible',
                'mensaje'     => 'Con balance negativo no es posible ahorrar.',
                'faltante'    => round($faltante, 2),
            ];
            continue;
        }

        $mesesNecesarios = (int)ceil($faltante / $ahorroMensual);
        $fechaProyectada = $hoy->modify("+{$mesesNecesarios} months")->format('Y-m-d');

        if ($fechaObj !== null) {
            $alcanza = $fechaProyectada <= $fechaObj;
            $metasFactibilidad[] = [
                'nombre'      => (string)$meta['nombre'],
                'estado'      => $alcanza ? 'a_tiempo' : 'tarde',
                'faltante'    => round($faltante, 2),
                'meses'       => $mesesNecesarios,
                'fecha_proyectada' => $fechaProyectada,
                'fecha_objetivo'   => $fechaObj,
            ];
        } else {
            $metasFactibilidad[] = [
                'nombre'      => (string)$meta['nombre'],
                'estado'      => 'sin_fecha',
                'faltante'    => round($faltante, 2),
                'meses'       => $mesesNecesarios,
                'fecha_proyectada' => $fechaProyectada,
            ];
        }
    }

    // --- Recomendaciones generales ---
    $recomendaciones = [];
    if ($total > 0 && ($porCategoria[$topCategoria] / $total) > 0.4) {
        $recomendaciones[] = "Más del 40% de tus gastos van a {$topCategoria}. Revisa esa categoría.";
    }
    if ($anomalias) {
        $recomendaciones[] = 'Detectamos ' . count($anomalias) . ' gasto(s) inusualmente altos. Revísalos.';
    }
    if ($proyeccion > $total * 1.2 && $esMesActual) {
        $recomendaciones[] = sprintf('A este ritmo gastarás aprox. $%.2f este mes.', $proyeccion);
    }
    if ($ahorro !== null && $ahorro['evaluacion'] === 'negativa') {
        $recomendaciones[] = 'Estás gastando más de lo que ingresas. Reduce gastos o busca ingresos extra.';
    }
    if ($deltaTotal !== null && $deltaTotal > 20) {
        $recomendaciones[] = sprintf('Gastaste %.1f%% más que el mes anterior.', $deltaTotal);
    }
    if ($sinPresupuesto) {
        $cats = array_column($sinPresupuesto, 'categoria');
        $recomendaciones[] = 'Sin presupuesto: ' . implode(', ', $cats) . '. Defínelos para mejor control.';
    }
    if ($compromisos !== null && $compromisos['pct_ingresos'] !== null && $compromisos['pct_ingresos'] > 50) {
        $recomendaciones[] = sprintf(
            'Tus compromisos recurrentes son %.1f%% de tus ingresos. Considera cancelar alguno.',
            (float)$compromisos['pct_ingresos']
        );
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
        'anomalias'          => $anomalias,
        'recomendaciones'    => $recomendaciones,
        'comparacion'        => $comparacion,
        'ahorro'             => $ahorro,
        'quiebra'            => $quiebra,
        'regla_50_30_20'     => $regla,
        'sin_presupuesto'    => $sinPresupuesto,
        'metas_factibilidad' => $metasFactibilidad,
        'compromisos'        => $compromisos,
    ];
}
