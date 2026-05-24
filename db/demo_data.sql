-- Datos de prueba para usuario demo. Se ejecuta como 04-demo-data.sql
-- DESPUÉS de demo_user.sql, así que el usuario ya existe.
SET NAMES utf8mb4;
USE studentwallet;

SET @uid          := (SELECT id FROM usuarios WHERE username = 'demo');
SET @cat_comida   := (SELECT id FROM categorias WHERE usuario_id IS NULL AND nombre = 'Comida');
SET @cat_trans    := (SELECT id FROM categorias WHERE usuario_id IS NULL AND nombre = 'Transporte');
SET @cat_mat      := (SELECT id FROM categorias WHERE usuario_id IS NULL AND nombre = 'Materiales');
SET @cat_subs     := (SELECT id FROM categorias WHERE usuario_id IS NULL AND nombre = 'Suscripciones');
SET @cat_salidas  := (SELECT id FROM categorias WHERE usuario_id IS NULL AND nombre = 'Salidas');
SET @cat_salud    := (SELECT id FROM categorias WHERE usuario_id IS NULL AND nombre = 'Salud');
SET @cat_renta    := (SELECT id FROM categorias WHERE usuario_id IS NULL AND nombre = 'Renta');
SET @cat_otros    := (SELECT id FROM categorias WHERE usuario_id IS NULL AND nombre = 'Otros');

-- Anchors temporales: primer día del mes actual y del mes anterior
SET @mes_actual := DATE_FORMAT(CURDATE(), '%Y-%m-01');
SET @mes_prev   := DATE_SUB(@mes_actual, INTERVAL 1 MONTH);

-- =====================================================================
-- INGRESOS — mes actual y mes anterior
-- =====================================================================
INSERT INTO ingresos (usuario_id, fuente, monto, fecha, es_recurrente, notas) VALUES
-- Mes actual
(@uid, 'Mesada',          3500.00, DATE_ADD(@mes_actual, INTERVAL 2  DAY), TRUE,  'Quincena 1'),
(@uid, 'Beca CONACYT',    2000.00, DATE_ADD(@mes_actual, INTERVAL 5  DAY), TRUE,  NULL),
(@uid, 'Freelance web',   1200.00, DATE_ADD(@mes_actual, INTERVAL 8  DAY), FALSE, 'Logo para María'),
(@uid, 'Venta libros',     350.00, DATE_ADD(@mes_actual, INTERVAL 12 DAY), FALSE, 'Libros usados'),
-- Mes anterior
(@uid, 'Mesada',          3500.00, DATE_ADD(@mes_prev,   INTERVAL 2  DAY), TRUE,  NULL),
(@uid, 'Beca CONACYT',    2000.00, DATE_ADD(@mes_prev,   INTERVAL 5  DAY), TRUE,  NULL),
(@uid, 'Freelance web',    800.00, DATE_ADD(@mes_prev,   INTERVAL 18 DAY), FALSE, NULL);

-- =====================================================================
-- PRESUPUESTOS — mes actual (deja Materiales/Salud/Otros sin presupuesto)
-- =====================================================================
INSERT INTO presupuestos (usuario_id, categoria_id, monto_limite, mes, anio) VALUES
(@uid, @cat_comida,   2500.00, MONTH(CURDATE()), YEAR(CURDATE())),
(@uid, @cat_subs,      900.00, MONTH(CURDATE()), YEAR(CURDATE())),
(@uid, @cat_salidas,  1500.00, MONTH(CURDATE()), YEAR(CURDATE())),
(@uid, @cat_trans,    1200.00, MONTH(CURDATE()), YEAR(CURDATE())),
(@uid, @cat_renta,    3500.00, MONTH(CURDATE()), YEAR(CURDATE()));

-- =====================================================================
-- METAS — variedad de estados (a tiempo, tarde, sin fecha, etc.)
-- =====================================================================
INSERT INTO metas_ahorro (usuario_id, nombre, monto_objetivo, monto_actual, fecha_objetivo) VALUES
(@uid, 'Viaje graduación',  5000.00,  1500.00, '2026-12-15'),
(@uid, 'Laptop nueva',     20000.00,  7500.00, '2027-06-01'),
(@uid, 'Curso AWS',         3500.00,   500.00, '2026-08-31'),
(@uid, 'Fondo emergencia', 10000.00,  2000.00, NULL);

-- =====================================================================
-- RECURRENTES — incluye varias suscripciones para mostrar el desglose
-- =====================================================================
INSERT INTO gastos_recurrentes (usuario_id, categoria_id, monto, descripcion, dia_del_mes, fecha_inicio) VALUES
(@uid, @cat_renta, 3500.00, 'Renta del depa',  5,  DATE_SUB(CURDATE(), INTERVAL 90 DAY)),
(@uid, @cat_subs,   115.00, 'Spotify',         15, DATE_SUB(CURDATE(), INTERVAL 90 DAY)),
(@uid, @cat_subs,   229.00, 'Netflix',         20, DATE_SUB(CURDATE(), INTERVAL 60 DAY)),
(@uid, @cat_subs,   380.00, 'ChatGPT Plus',    10, DATE_SUB(CURDATE(), INTERVAL 30 DAY)),
(@uid, @cat_subs,    19.00, 'iCloud 50GB',     1,  DATE_SUB(CURDATE(), INTERVAL 180 DAY)),
(@uid, @cat_salud,  450.00, 'Gimnasio',        7,  DATE_SUB(CURDATE(), INTERVAL 45 DAY)),
(@uid, @cat_otros,  599.00, 'Internet casa',   12, DATE_SUB(CURDATE(), INTERVAL 120 DAY));

-- =====================================================================
-- GASTOS — mes actual (variedad alta, incluye anomalías y cats sin presupuesto)
-- =====================================================================
INSERT INTO gastos (usuario_id, categoria_id, monto, descripcion, metodo_pago, fecha) VALUES
-- Comida (frecuente, días variados)
(@uid, @cat_comida,    180.50, 'Super de la semana',     'tarjeta',  DATE_ADD(@mes_actual, INTERVAL 1  DAY)),
(@uid, @cat_comida,     65.00, 'Café con Diana',         'efectivo', DATE_ADD(@mes_actual, INTERVAL 3  DAY)),
(@uid, @cat_comida,    220.00, 'Pizza del viernes',      'tarjeta',  DATE_ADD(@mes_actual, INTERVAL 4  DAY)),
(@uid, @cat_comida,     85.00, 'Comida en cafetería',    'efectivo', DATE_ADD(@mes_actual, INTERVAL 6  DAY)),
(@uid, @cat_comida,    340.00, 'Super semana 2',         'tarjeta',  DATE_ADD(@mes_actual, INTERVAL 8  DAY)),
(@uid, @cat_comida,     45.00, 'Café Starbucks',         'tarjeta',  DATE_ADD(@mes_actual, INTERVAL 10 DAY)),
(@uid, @cat_comida,    155.00, 'Sushi con amigos',       'tarjeta',  DATE_ADD(@mes_actual, INTERVAL 12 DAY)),

-- Transporte
(@uid, @cat_trans,     120.00, 'Uber al campus',         'tarjeta',  DATE_ADD(@mes_actual, INTERVAL 2  DAY)),
(@uid, @cat_trans,      55.00, 'Camión a casa',          'efectivo', DATE_ADD(@mes_actual, INTERVAL 4  DAY)),
(@uid, @cat_trans,      95.00, 'Uber regreso fiesta',    'tarjeta',  DATE_ADD(@mes_actual, INTERVAL 7  DAY)),
(@uid, @cat_trans,     450.00, 'Gasolina',               'tarjeta',  DATE_ADD(@mes_actual, INTERVAL 9  DAY)),
(@uid, @cat_trans,      45.00, 'Camión universidad',     'efectivo', DATE_ADD(@mes_actual, INTERVAL 11 DAY)),

-- Salidas (con una anomalía: fiesta cara)
(@uid, @cat_salidas,   350.00, 'Tacos y cervezas',       'efectivo', DATE_ADD(@mes_actual, INTERVAL 3  DAY)),
(@uid, @cat_salidas,   180.00, 'Cine + palomitas',       'tarjeta',  DATE_ADD(@mes_actual, INTERVAL 5  DAY)),
(@uid, @cat_salidas,  1200.00, 'Fiesta cumpleaños Diana','tarjeta',  DATE_ADD(@mes_actual, INTERVAL 7  DAY)),
(@uid, @cat_salidas,   240.00, 'Bar con compas',         'efectivo', DATE_ADD(@mes_actual, INTERVAL 11 DAY)),

-- Materiales (sin presupuesto -> dispara recomendación)
(@uid, @cat_mat,       420.00, 'Libro de cálculo',       'tarjeta',  DATE_ADD(@mes_actual, INTERVAL 2  DAY)),
(@uid, @cat_mat,        85.00, 'Cuaderno + plumas',      'efectivo', DATE_ADD(@mes_actual, INTERVAL 6  DAY)),
(@uid, @cat_mat,       150.00, 'Impresiones tesis',      'efectivo', DATE_ADD(@mes_actual, INTERVAL 13 DAY)),

-- Salud (anomalía probable + sin presupuesto)
(@uid, @cat_salud,    1500.00, 'Consulta médica',        'tarjeta',  DATE_ADD(@mes_actual, INTERVAL 9  DAY)),
(@uid, @cat_salud,     180.00, 'Medicinas',              'efectivo', DATE_ADD(@mes_actual, INTERVAL 10 DAY)),

-- Renta (un solo cargo mensual)
(@uid, @cat_renta,    3500.00, 'Renta del depa',         'transferencia', DATE_ADD(@mes_actual, INTERVAL 4 DAY)),

-- Suscripciones (cargo extra de juego/app)
(@uid, @cat_subs,      299.00, 'Steam: juego nuevo',     'tarjeta',  DATE_ADD(@mes_actual, INTERVAL 8  DAY)),
(@uid, @cat_subs,      115.00, 'Spotify',                'tarjeta',  DATE_ADD(@mes_actual, INTERVAL 14 DAY)),

-- Otros (sin presupuesto)
(@uid, @cat_otros,     250.00, 'Corte de cabello',       'efectivo', DATE_ADD(@mes_actual, INTERVAL 5  DAY));

-- =====================================================================
-- GASTOS — mes anterior (para que la comparación tenga datos)
-- Generalmente menores para que el delta sea positivo (gastaste más este mes)
-- =====================================================================
INSERT INTO gastos (usuario_id, categoria_id, monto, descripcion, metodo_pago, fecha) VALUES
-- Comida (menos que mes actual)
(@uid, @cat_comida,    150.00, 'Super',                  'tarjeta',  DATE_ADD(@mes_prev, INTERVAL 3  DAY)),
(@uid, @cat_comida,    280.00, 'Super semana 2',         'tarjeta',  DATE_ADD(@mes_prev, INTERVAL 10 DAY)),
(@uid, @cat_comida,    195.00, 'Comida fin de semana',   'tarjeta',  DATE_ADD(@mes_prev, INTERVAL 15 DAY)),
(@uid, @cat_comida,     50.00, 'Café',                   'efectivo', DATE_ADD(@mes_prev, INTERVAL 18 DAY)),
(@uid, @cat_comida,    320.00, 'Cena con familia',       'tarjeta',  DATE_ADD(@mes_prev, INTERVAL 22 DAY)),

-- Transporte (similar)
(@uid, @cat_trans,     110.00, 'Uber',                   'tarjeta',  DATE_ADD(@mes_prev, INTERVAL 4  DAY)),
(@uid, @cat_trans,     450.00, 'Gasolina',               'tarjeta',  DATE_ADD(@mes_prev, INTERVAL 14 DAY)),
(@uid, @cat_trans,      75.00, 'Camión',                 'efectivo', DATE_ADD(@mes_prev, INTERVAL 20 DAY)),

-- Salidas (mucho menos: no hubo fiesta cara)
(@uid, @cat_salidas,   180.00, 'Cine',                   'tarjeta',  DATE_ADD(@mes_prev, INTERVAL 8  DAY)),
(@uid, @cat_salidas,   220.00, 'Bar viernes',            'efectivo', DATE_ADD(@mes_prev, INTERVAL 16 DAY)),

-- Materiales (poco)
(@uid, @cat_mat,        95.00, 'Cuaderno',               'efectivo', DATE_ADD(@mes_prev, INTERVAL 5  DAY)),

-- Salud (sin anomalía este mes)
(@uid, @cat_salud,      80.00, 'Vitaminas',              'efectivo', DATE_ADD(@mes_prev, INTERVAL 12 DAY)),

-- Renta
(@uid, @cat_renta,    3500.00, 'Renta del depa',         'transferencia', DATE_ADD(@mes_prev, INTERVAL 4 DAY)),

-- Suscripciones
(@uid, @cat_subs,      115.00, 'Spotify',                'tarjeta',  DATE_ADD(@mes_prev, INTERVAL 14 DAY)),
(@uid, @cat_subs,      229.00, 'Netflix',                'tarjeta',  DATE_ADD(@mes_prev, INTERVAL 19 DAY)),

-- Otros
(@uid, @cat_otros,     200.00, 'Corte de cabello',       'efectivo', DATE_ADD(@mes_prev, INTERVAL 6  DAY));
