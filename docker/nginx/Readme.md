# Fibrasan — Backend (Laravel)

Backend del sistema Fibrasan, dockerizado. Incluye Laravel, Nginx, MySQL, Redis, Reverb (websockets) y un worker de colas.

## Servicios que corren en Docker

| Servicio | Contenedor        | Puerto host | Descripción                                      |
| -------- | ----------------- | ----------- | ------------------------------------------------ |
| app      | `fibrasan_app`    | -           | PHP-FPM 8.4, corre el código Laravel             |
| nginx    | `fibrasan_nginx`  | 8000        | Servidor web, entrada principal                  |
| mysql    | `fibrasan_mysql`  | 3306        | Base de datos principal                          |
| redis    | `fibrasan_redis`  | 6379        | Cache / sesiones                                 |
| reverb   | `fibrasan_reverb` | 8080        | Websockets (broadcasting en tiempo real)         |
| queue    | `fibrasan_queue`  | -           | Worker que procesa jobs en cola                  |
| front    | `fibrasan_front`  | 4200        | Angular (build de producción, servido por Nginx) |

Firebird (`192.168.100.59`) **no** vive en Docker — es un servidor externo en la red local al que la app se conecta directamente.

El frontend (proyecto Angular, carpeta hermana `../Front`) se construye y levanta **desde este mismo `docker-compose.yml`** — no hace falta correrlo aparte con `docker run`.

## Requisitos

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) instalado y corriendo
- Virtualización habilitada en BIOS (VT-x/AMD-V)
- WSL2 actualizado (en Windows)

## Primera vez que levantas el proyecto

```bash
# 1. Construye las imágenes (tarda varios minutos la primera vez)
docker compose build

# 2. Levanta todos los contenedores en segundo plano
docker compose up -d

# 3. Verifica que los 7 contenedores estén "Up" (app, nginx, mysql, redis, reverb, queue, front)
docker compose ps

# 4. Corre las migraciones (crea las tablas en MySQL)
docker compose exec app php artisan migrate
```

Abre **http://localhost:8000** para la API/backend y **http://localhost:4200** para el frontend Angular.

## Comandos del día a día

```bash
# Prender todo
docker compose up -d

# Apagar todo (no borra la base de datos)
docker compose down

# Ver logs en vivo de un servicio
docker compose logs -f app
docker compose logs -f queue
docker compose logs -f reverb

# Correr cualquier comando de artisan
docker compose exec app php artisan <comando>

# Ejemplo: entrar a Tinker
docker compose exec app php artisan tinker

# Reiniciar un solo servicio (útil si el queue worker se cayó)
docker compose restart queue

# Reconstruir imágenes después de cambiar el Dockerfile o composer.json
docker compose build
docker compose up -d

# Reconstruir solo el front (después de cambios en el código Angular)
docker compose up -d --build front
```

## Variables de entorno clave para Docker

Estas líneas del `.env` deben apuntar a los **nombres de los contenedores**, no a `127.0.0.1`:

```env
DB_HOST=mysql
DB_PASSWORD=root_password      # debe coincidir con MYSQL_ROOT_PASSWORD en docker-compose.yml
REDIS_HOST=redis
REVERB_HOST=0.0.0.0            # para que Reverb escuche dentro del contenedor
VITE_REVERB_HOST=localhost     # este SÍ se queda así, lo usa el navegador
```

Firebird (`FB_HOST`, `DB_HOST_SRVNOI`, etc.) se queda igual, apuntando a `192.168.100.59`.

## Estructura de archivos Docker

```
Back/
├── Dockerfile                    # imagen PHP 8.4-fpm + extensiones + composer install
├── docker-compose.yml            # orquesta los 6 servicios
├── .dockerignore
└── docker/
    └── nginx/
        └── default.conf          # config de Nginx, redirige PHP a app:9000
```

## Notas y troubleshooting conocido

- **PHP debe ser 8.4**, no 8.2 — el `composer.lock` requiere `symfony/clock` y `symfony/translation` que piden PHP >= 8.4.1.
- Si `queue` aparece como `Exited` después de un `up`, normalmente es porque arrancó antes de que existieran las tablas en MySQL. Corre las migraciones y luego `docker compose restart queue`.
- La migración `2026_01_06_000010_create_citas_table.php` tenía un bug: usaba `->after('con_vehiculo')` dentro de un `Schema::create()`, lo cual no es válido en MySQL (`AFTER` solo aplica en `Schema::table` / ALTER). Ya se corrigió quitando ese `->after()`.
- Si cambias algo en `composer.json` o el `Dockerfile`, necesitas correr `docker compose build` de nuevo — los cambios de código normal (`app/`, `routes/`, etc.) sí se reflejan en vivo gracias al volumen montado, sin rebuild.
- El `Dockerfile` del `app` ya incluye las extensiones `pdo_firebird` (para la conexión a Firebird) y `redis` (vía pecl), además de las básicas de Laravel.

## Pendiente / próximos pasos

- [ ] Preparar `docker-compose.prod.yml` para el servidor de IONOS (sin volúmenes en vivo, SSL, Supervisor)
- [ ] Rotar credenciales que se compartieron en texto plano durante la configuración inicial (Gmail, JWT_SECRET, Resend, WhatsApp)
- [ ] Confirmar que `.env` esté en `.gitignore`
