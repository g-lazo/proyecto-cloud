CREATE DATABASE IF NOT EXISTS studentwallet
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE studentwallet;

CREATE TABLE usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  nombre VARCHAR(100),
  email VARCHAR(100),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE intentos_login (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(50),
  exito BOOLEAN NOT NULL,
  intentado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_intentos_ip_fecha (ip, intentado_en)
);

CREATE TABLE categorias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NULL,
  nombre VARCHAR(50) NOT NULL,
  icono VARCHAR(20),
  color VARCHAR(7),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE KEY uk_categoria_usuario (usuario_id, nombre)
);

CREATE TABLE ingresos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  fuente VARCHAR(100) NOT NULL,
  monto DECIMAL(10,2) NOT NULL CHECK (monto > 0),
  fecha DATE NOT NULL,
  es_recurrente BOOLEAN DEFAULT FALSE,
  notas VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_ingresos_usuario_fecha (usuario_id, fecha)
);

CREATE TABLE gastos_recurrentes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  categoria_id INT NOT NULL,
  monto DECIMAL(10,2) NOT NULL CHECK (monto > 0),
  descripcion VARCHAR(255),
  frecuencia ENUM('mensual') DEFAULT 'mensual',
  dia_del_mes TINYINT NOT NULL CHECK (dia_del_mes BETWEEN 1 AND 31),
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE NULL,
  activo BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE RESTRICT,
  INDEX idx_recurrentes_activos (activo, fecha_inicio)
);

CREATE TABLE gastos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  categoria_id INT NOT NULL,
  gasto_recurrente_id INT NULL,
  monto DECIMAL(10,2) NOT NULL CHECK (monto > 0),
  monto_total DECIMAL(10,2) NULL,
  numero_personas TINYINT NOT NULL DEFAULT 1 CHECK (numero_personas >= 1),
  descripcion VARCHAR(255),
  metodo_pago VARCHAR(30) DEFAULT 'efectivo',
  fecha DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE RESTRICT,
  FOREIGN KEY (gasto_recurrente_id) REFERENCES gastos_recurrentes(id) ON DELETE SET NULL,
  INDEX idx_gastos_usuario_fecha (usuario_id, fecha),
  INDEX idx_gastos_categoria (categoria_id),
  CONSTRAINT chk_division CHECK (
    monto_total IS NULL OR
    ABS(monto - ROUND(monto_total / numero_personas, 2)) < 0.01
  )
);

CREATE TABLE presupuestos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  categoria_id INT NOT NULL,
  monto_limite DECIMAL(10,2) NOT NULL CHECK (monto_limite > 0),
  mes TINYINT NOT NULL CHECK (mes BETWEEN 1 AND 12),
  anio SMALLINT NOT NULL CHECK (anio BETWEEN 2020 AND 2100),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE RESTRICT,
  UNIQUE KEY uk_presupuesto (usuario_id, categoria_id, mes, anio)
);

CREATE TABLE metas_ahorro (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  monto_objetivo DECIMAL(10,2) NOT NULL CHECK (monto_objetivo > 0),
  monto_actual DECIMAL(10,2) DEFAULT 0 CHECK (monto_actual >= 0),
  fecha_objetivo DATE,
  completada BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);
