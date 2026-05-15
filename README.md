# StudentWallet

Sistema de gestión de gastos estudiantil. Proyecto final Cloud Computing.

## Arranque local con Docker

```bash
# 1. Build solo de la imagen app (sin levantar db)
docker compose build app

# 2. Generar hash del usuario demo (--no-deps evita arrancar db con placeholder)
docker compose run --rm --no-deps app \
  php -r "echo password_hash('Demo2026!', PASSWORD_DEFAULT) . PHP_EOL;"

# 3. Pegar el hash en db/demo_user.sql reemplazando $2y$10$REEMPLAZA_CON_HASH_GENERADO_LOCAL

# 4. Arrancar todo (db inicializa con schema + seed + demo user + demo data)
docker compose up -d

# 5. Abrir http://localhost:8000/login.php
#    Usuario: demo
#    Password: Demo2026!
```

## Comandos útiles

```bash
docker compose logs -f app          # logs Apache + PHP
docker compose logs -f db           # logs MySQL
docker compose exec app bash        # shell en contenedor app
docker compose exec db mysql -u sw_app -plocalpass studentwallet
docker compose down                 # parar (datos persisten)
docker compose down -v              # parar + borrar volumen MySQL
```

## Recargar BD desde cero

```bash
docker compose down -v
docker compose up -d
```

## Cambios en código

Los archivos `.php` se montan vía bind-mount. Hot-reload, solo refresca navegador.
Cambios en `Dockerfile` o vhost requieren `docker compose up -d --build`.

## Fase 2 (AWS)

Cuando esté listo, ver `infra/bootstrap.sh` y secciones 16-18 del plan en
`/Users/lazo/.claude/plans/antes-de-nada-ve-scalable-cray.md`.
