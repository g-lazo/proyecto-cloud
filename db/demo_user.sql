-- Password = "Demo2026!"
-- Hash generado UNA vez con:
--   docker compose run --rm --no-deps app php -r "echo password_hash('Demo2026!', PASSWORD_DEFAULT) . PHP_EOL;"
-- Regenera el hash con tu propio password antes de commitear.
SET NAMES utf8mb4;
USE studentwallet;

INSERT INTO usuarios (username, password_hash, nombre, email) VALUES
('demo', '$2y$10$gsOkVLYce2o4tyotxUrHze1WOs/fzO..a5WC8q6qo7FTYr0rNpyE.', 'Usuario Demo', 'demo@local');