import json
from collections import defaultdict
from datetime import datetime
from dateutil.relativedelta import relativedelta
import calendar
import math
from statistics import mean, stdev

DIAS_ES = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo']

CLASIFICACION_50_30_20 = {
    'Comida': 'necesidad',
    'Transporte': 'necesidad',
    'Materiales': 'necesidad',
    'Salud': 'necesidad',
    'Renta': 'necesidad',
    'Suscripciones': 'deseo',
    'Salidas': 'deseo',
    'Otros': 'deseo',
}


def lambda_handler(event, context):
    try:
        body = event
        if isinstance(event.get('body'), str):
            body = json.loads(event['body'])

        gastos             = body.get('gastos', [])
        gastos_anteriores  = body.get('gastos_anteriores', [])
        ingresos_mes       = float(body.get('ingresos', 0))
        presupuestos       = body.get('presupuestos', [])
        metas              = body.get('metas', [])
        recurrentes        = body.get('recurrentes', [])
        mes                = int(body.get('mes'))
        anio               = int(body.get('anio'))

        # Compromisos mensuales (siempre, incluso sin gastos)
        compromisos = None
        if recurrentes:
            total_comp = sum(float(r['monto']) for r in recurrentes)
            total_subs = sum(float(r['monto']) for r in recurrentes if r.get('categoria') == 'Suscripciones')
            items = sorted(
                [{
                    'descripcion': r['descripcion'],
                    'categoria': r.get('categoria', ''),
                    'monto': round(float(r['monto']), 2),
                    'dia_del_mes': int(r['dia_del_mes']),
                } for r in recurrentes],
                key=lambda x: -x['monto']
            )
            compromisos = {
                'total': round(total_comp, 2),
                'suscripciones': round(total_subs, 2),
                'numero': len(items),
                'items': items,
                'pct_ingresos': round(total_comp / ingresos_mes * 100, 1) if ingresos_mes > 0 else None,
            }

        if not gastos:
            return _resp(200, {
                'mensaje': 'Sin gastos registrados en este periodo.',
                'metricas': {}, 'anomalias': [], 'recomendaciones': [],
                'comparacion': None, 'ahorro': None, 'quiebra': None,
                'regla_50_30_20': None, 'sin_presupuesto': [], 'metas_factibilidad': [],
                'compromisos': compromisos,
            })

        montos = [float(g['monto']) for g in gastos]
        total = sum(montos)

        por_categoria = defaultdict(float)
        por_dia_semana = defaultdict(float)
        for g in gastos:
            por_categoria[g['categoria']] += float(g['monto'])
            fecha = datetime.strptime(g['fecha'], '%Y-%m-%d')
            por_dia_semana[DIAS_ES[fecha.weekday()]] += float(g['monto'])

        top_categoria = max(por_categoria.items(), key=lambda x: x[1])
        top_dia       = max(por_dia_semana.items(), key=lambda x: x[1])

        # Anomalías
        anomalias = []
        if len(montos) >= 3:
            mu, sigma = mean(montos), stdev(montos)
            umbral = mu + 2 * sigma
            for g in gastos:
                if float(g['monto']) > umbral:
                    anomalias.append({
                        'descripcion': g.get('descripcion') or '(sin descripción)',
                        'monto': round(float(g['monto']), 2),
                        'fecha': g['fecha'],
                    })

        # Proyección
        dias_mes = calendar.monthrange(anio, mes)[1]
        hoy = datetime.now()
        es_mes_actual = (hoy.year == anio and hoy.month == mes)
        if es_mes_actual:
            dias_transcurridos = max(hoy.day, 1)
            promedio_diario = total / dias_transcurridos
            proyeccion = promedio_diario * dias_mes
        else:
            dias_transcurridos = dias_mes
            promedio_diario = total / dias_mes
            proyeccion = total

        # 1. Comparación vs mes anterior
        total_anterior = sum(float(g['monto']) for g in gastos_anteriores)
        por_cat_anterior = defaultdict(float)
        for g in gastos_anteriores:
            por_cat_anterior[g['categoria']] += float(g['monto'])

        delta_total = ((total - total_anterior) / total_anterior * 100) if total_anterior > 0 else None
        delta_categorias = []
        for cat, monto in sorted(por_categoria.items(), key=lambda x: -x[1]):
            previo = por_cat_anterior.get(cat, 0)
            d = ((monto - previo) / previo * 100) if previo > 0 else None
            delta_categorias.append({
                'categoria': cat,
                'actual': round(monto, 2),
                'anterior': round(previo, 2),
                'delta_pct': None if d is None else round(d, 1),
            })
        comparacion = {
            'total_actual': round(total, 2),
            'total_anterior': round(total_anterior, 2),
            'delta_pct': None if delta_total is None else round(delta_total, 1),
            'por_categoria': delta_categorias,
        }

        # 2. Tasa de ahorro
        ahorro = None
        if ingresos_mes > 0:
            balance = ingresos_mes - total
            tasa = (balance / ingresos_mes) * 100
            if tasa >= 20:   evaluacion = 'excelente'
            elif tasa >= 10: evaluacion = 'buena'
            elif tasa >= 0:  evaluacion = 'baja'
            else:            evaluacion = 'negativa'
            ahorro = {
                'ingresos': round(ingresos_mes, 2),
                'gastos': round(total, 2),
                'balance': round(balance, 2),
                'tasa_pct': round(tasa, 1),
                'evaluacion': evaluacion,
            }

        # 4. Días que aguanta
        quiebra = None
        if es_mes_actual and ahorro is not None and ahorro['balance'] > 0 and promedio_diario > 0:
            dias_restantes = dias_mes - dias_transcurridos
            dias_aguanta = int(ahorro['balance'] // promedio_diario)
            quiebra = {
                'balance_disponible': round(ahorro['balance'], 2),
                'promedio_diario': round(promedio_diario, 2),
                'dias_aguanta': dias_aguanta,
                'dias_restantes_mes': dias_restantes,
                'alcanza': dias_aguanta >= dias_restantes,
            }

        # 5. Regla 50/30/20
        necesidades = sum(m for c, m in por_categoria.items() if CLASIFICACION_50_30_20.get(c, 'deseo') == 'necesidad')
        deseos      = sum(m for c, m in por_categoria.items() if CLASIFICACION_50_30_20.get(c, 'deseo') != 'necesidad')
        base = ingresos_mes if ingresos_mes > 0 else total
        ahorro_estimado = max(0.0, ingresos_mes - total) if ingresos_mes > 0 else 0.0
        regla = {
            'necesidades': {
                'monto': round(necesidades, 2),
                'pct': round(necesidades / max(base, 1) * 100, 1),
                'objetivo': 50,
                'estado': 'ok' if (necesidades / max(base, 1) * 100) <= 55 else 'alto',
            },
            'deseos': {
                'monto': round(deseos, 2),
                'pct': round(deseos / max(base, 1) * 100, 1),
                'objetivo': 30,
                'estado': 'ok' if (deseos / max(base, 1) * 100) <= 35 else 'alto',
            },
            'ahorro': {
                'monto': round(ahorro_estimado, 2),
                'pct': round(ahorro_estimado / max(base, 1) * 100, 1),
                'objetivo': 20,
                'estado': 'ok' if (ahorro_estimado / max(base, 1) * 100) >= 20 else 'bajo',
            },
            'base': round(base, 2),
            'basado_en': 'ingresos' if ingresos_mes > 0 else 'gastos',
        }

        # 7. Sin presupuesto
        cats_con_pres = {p['categoria'] for p in presupuestos}
        sin_presupuesto = [
            {'categoria': c, 'gastado': round(m, 2)}
            for c, m in por_categoria.items()
            if c not in cats_con_pres and m > 0
        ]

        # 8. Factibilidad metas
        metas_fact = []
        ahorro_mensual = ahorro['balance'] if ahorro else 0
        for meta in metas:
            objetivo = float(meta['monto_objetivo'])
            actual = float(meta['monto_actual'])
            faltante = max(0.0, objetivo - actual)
            fecha_obj = meta.get('fecha_objetivo')

            if faltante <= 0:
                metas_fact.append({
                    'nombre': meta['nombre'],
                    'estado': 'completada',
                    'mensaje': 'Meta ya completada',
                })
                continue
            if ahorro_mensual <= 0:
                metas_fact.append({
                    'nombre': meta['nombre'],
                    'estado': 'imposible',
                    'mensaje': 'Con balance negativo no es posible ahorrar.',
                    'faltante': round(faltante, 2),
                })
                continue

            meses = math.ceil(faltante / ahorro_mensual)
            fecha_proy = (hoy + relativedelta(months=meses)).strftime('%Y-%m-%d')
            if fecha_obj is not None:
                metas_fact.append({
                    'nombre': meta['nombre'],
                    'estado': 'a_tiempo' if fecha_proy <= fecha_obj else 'tarde',
                    'faltante': round(faltante, 2),
                    'meses': meses,
                    'fecha_proyectada': fecha_proy,
                    'fecha_objetivo': fecha_obj,
                })
            else:
                metas_fact.append({
                    'nombre': meta['nombre'],
                    'estado': 'sin_fecha',
                    'faltante': round(faltante, 2),
                    'meses': meses,
                    'fecha_proyectada': fecha_proy,
                })

        # Recomendaciones
        recomendaciones = []
        if total > 0 and por_categoria[top_categoria[0]] / total > 0.4:
            recomendaciones.append(f"Más del 40% de tus gastos van a {top_categoria[0]}. Revisa esa categoría.")
        if anomalias:
            recomendaciones.append(f"Detectamos {len(anomalias)} gasto(s) inusualmente altos. Revísalos.")
        if es_mes_actual and proyeccion > total * 1.2:
            recomendaciones.append(f"A este ritmo gastarás aprox. ${proyeccion:.2f} este mes.")
        if ahorro is not None and ahorro['evaluacion'] == 'negativa':
            recomendaciones.append("Estás gastando más de lo que ingresas. Reduce gastos o busca ingresos extra.")
        if delta_total is not None and delta_total > 20:
            recomendaciones.append(f"Gastaste {delta_total:.1f}% más que el mes anterior.")
        if sin_presupuesto:
            cats = ', '.join(s['categoria'] for s in sin_presupuesto)
            recomendaciones.append(f"Sin presupuesto: {cats}. Defínelos para mejor control.")
        if compromisos is not None and compromisos['pct_ingresos'] is not None and compromisos['pct_ingresos'] > 50:
            recomendaciones.append(
                f"Tus compromisos recurrentes son {compromisos['pct_ingresos']:.1f}% de tus ingresos. Considera cancelar alguno."
            )
        if not recomendaciones:
            recomendaciones.append("Tu patrón de gastos se ve saludable. Sigue así.")

        return _resp(200, {
            'metricas': {
                'total': round(total, 2),
                'promedio_por_gasto': round(mean(montos), 2),
                'numero_gastos': len(montos),
                'categoria_top': {'nombre': top_categoria[0], 'monto': round(top_categoria[1], 2)},
                'dia_top': {'nombre': top_dia[0], 'monto': round(top_dia[1], 2)},
                'distribucion_categorias': {k: round(v, 2) for k, v in por_categoria.items()},
                'proyeccion_fin_mes': round(proyeccion, 2),
            },
            'anomalias': anomalias,
            'recomendaciones': recomendaciones,
            'comparacion': comparacion,
            'ahorro': ahorro,
            'quiebra': quiebra,
            'regla_50_30_20': regla,
            'sin_presupuesto': sin_presupuesto,
            'metas_factibilidad': metas_fact,
            'compromisos': compromisos,
        })
    except Exception as e:
        return _resp(500, {'error': str(e)})


def _resp(status, data):
    return {
        'statusCode': status,
        'headers': {'Content-Type': 'application/json'},
        'body': json.dumps(data, ensure_ascii=False),
    }
