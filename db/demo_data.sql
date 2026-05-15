-- Datos de prueba para usuario demo. Se ejecuta como 04-demo-data.sql
-- DESPUÉS de demo_user.sql, así que el usuario ya existe.

USE studentwallet;

SET @uid := (SELECT id FROM usuarios WHERE username = 'demo');
SET @cat_renta := (SELECT id FROM categorias WHERE usuario_id IS NULL AND nombre = 'Renta');
SET @cat_subs  := (SELECT id FROM categorias WHERE usuario_id IS NULL AND nombre = 'Suscripciones');
SET @cat_food  := (SELECT id FROM categorias WHERE usuario_id IS NULL AND nombre = 'Comida');
SET @cat_trans := (SELECT id FROM categorias WHERE usuario_id IS NULL AND nombre = 'Transporte');
SET @cat_salidas := (SELECT id FROM categorias WHERE usuario_id IS NULL AND nombre = 'Salidas');

INSERT INTO ingresos (usuario_id, fuente, monto, fecha, es_recurrente) VALUES
(@uid, 'Mesada',    3500.00, CURDATE() - INTERVAL 10 DAY, TRUE),
(@uid, 'Beca',      2000.00, CURDATE() - INTERVAL 5  DAY, TRUE),
(@uid, 'Freelance',  800.00, CURDATE() - INTERVAL 2  DAY, FALSE);

INSERT INTO presupuestos (usuario_id, categoria_id, monto_limite, mes, anio) VALUES
(@uid, @cat_food,    2500.00, MONTH(CURDATE()), YEAR(CURDATE())),
(@uid, @cat_subs,     500.00, MONTH(CURDATE()), YEAR(CURDATE())),
(@uid, @cat_salidas, 1500.00, MONTH(CURDATE()), YEAR(CURDATE()));

INSERT INTO metas_ahorro (usuario_id, nombre, monto_objetivo, monto_actual, fecha_objetivo) VALUES
(@uid, 'Viaje graduación', 5000.00,  1500.00, '2026-12-15'),
(@uid, 'Laptop nueva',    20000.00,  7500.00, '2027-06-01');

INSERT INTO gastos_recurrentes (usuario_id, categoria_id, monto, descripcion, dia_del_mes, fecha_inicio) VALUES
(@uid, @cat_renta, 3500.00, 'Renta del depa', 5,  CURDATE() - INTERVAL 60 DAY),
(@uid, @cat_subs,   115.00, 'Spotify',        15, CURDATE() - INTERVAL 90 DAY),
(@uid, @cat_subs,   229.00, 'Netflix',        20, CURDATE() - INTERVAL 30 DAY);

INSERT INTO gastos (usuario_id, categoria_id, monto, descripcion, metodo_pago, fecha) VALUES
(@uid, @cat_food,    180.50, 'Super de la semana',   'tarjeta',      CURDATE() - INTERVAL 1 DAY),
(@uid, @cat_food,     65.00, 'Café con Diana',       'efectivo',     CURDATE() - INTERVAL 3 DAY),
(@uid, @cat_trans,   120.00, 'Uber al campus',       'tarjeta',      CURDATE() - INTERVAL 2 DAY),
(@uid, @cat_salidas, 350.00, 'Tacos y cervezas',     'efectivo',     CURDATE() - INTERVAL 4 DAY),
(@uid, @cat_food,    220.00, 'Pizza del viernes',    'tarjeta',      CURDATE() - INTERVAL 6 DAY),
(@uid, @cat_trans,    55.00, 'Camión a casa',        'efectivo',     CURDATE() - INTERVAL 7 DAY);
