-- Password = "Demo2026!"
-- Hash generado UNA vez con:
--   docker compose run --rm --no-deps app php -r "echo password_hash('Demo2026!', PASSWORD_DEFAULT) . PHP_EOL;"
-- Regenera el hash con tu propio password antes de commitear.
SET NAMES utf8mb4;
USE studentwallet;

INSERT INTO usuarios (username, password_hash, nombre, email) VALUES
('demo', '$2y$12$e/BxLVl6e2OcW6zjFgxJ5eO6BQA5fuBolZj52PiyXQVOQabr3iyFS', 'Usuario Demo', 'demo@local');
