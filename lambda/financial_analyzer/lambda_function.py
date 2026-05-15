import json
import calendar
from collections import defaultdict
from datetime import datetime
from statistics import mean, stdev

DIAS_ES = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo']


def lambda_handler(event, context):
    try:
        body = event
        if isinstance(event.get('body'), str):
            body = json.loads(event['body'])

        gastos = body.get('gastos', [])
        mes  = int(body.get('mes'))
        anio = int(body.get('anio'))

        if not gastos:
            return _resp(200, {
                'mensaje': 'Sin gastos registrados en este periodo.',
                'metricas': {}, 'anomalias': [], 'recomendaciones': []
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

        dias_mes = calendar.monthrange(anio, mes)[1]
        hoy = datetime.now()
        if hoy.year == anio and hoy.month == mes:
            dias_transcurridos = max(hoy.day, 1)
            proyeccion = (total / dias_transcurridos) * dias_mes
        else:
            proyeccion = total

        recomendaciones = []
        if total > 0 and top_categoria[1] / total > 0.4:
            recomendaciones.append(
                f"Más del 40% de tus gastos van a {top_categoria[0]}. Revisa esa categoría."
            )
        if anomalias:
            recomendaciones.append(
                f"Detectamos {len(anomalias)} gasto(s) inusualmente altos. Revísalos."
            )
        if proyeccion > total * 1.2:
            recomendaciones.append(
                f"A este ritmo gastarás aprox. ${proyeccion:.2f} este mes."
            )
        if not recomendaciones:
            recomendaciones.append("Tu patrón de gastos se ve saludable. Sigue así.")

        return _resp(200, {
            'metricas': {
                'total': round(total, 2),
                'promedio_por_gasto': round(mean(montos), 2),
                'numero_gastos': len(montos),
                'categoria_top': {'nombre': top_categoria[0],
                                  'monto': round(top_categoria[1], 2)},
                'dia_top': {'nombre': top_dia[0],
                            'monto': round(top_dia[1], 2)},
                'distribucion_categorias': {k: round(v, 2) for k, v in por_categoria.items()},
                'proyeccion_fin_mes': round(proyeccion, 2),
            },
            'anomalias': anomalias,
            'recomendaciones': recomendaciones,
        })
    except Exception as e:
        return _resp(500, {'error': str(e)})


def _resp(status, data):
    return {
        'statusCode': status,
        'headers': {'Content-Type': 'application/json'},
        'body': json.dumps(data, ensure_ascii=False),
    }
