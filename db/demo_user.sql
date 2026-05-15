-- Password = "Demo2026!"
-- Hash generado UNA vez con:
--   docker compose run --rm --no-deps app php -r "echo password_hash('Demo2026!', PASSWORD_DEFAULT) . PHP_EOL;"
-- Regenera el hash con tu propio password antes de commitear.

USE studentwallet;

INSERT INTO usuarios (username, password_hash, nombre, email) VALUES
('demo', '$2y$10$REEMPLAZA_CON_HASH_GENERADO_LOCAL', 'Usuario Demo', 'demo@local');
