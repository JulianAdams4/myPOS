# myPOS

## Pre-requisites

- [Docker](https://docs.docker.com/install/#desktop)

## Setup

1. Copy and rename:
  - `docker-compose-dev.yml` to `docker-compose.yml`
  - `db.env.template` to `db.env`
  - `app.env.template` to `.env`
  - `DB_HOST` => Name of the mysql service in `docker-compose.yml`

3. Put dump file (`dump.sql`) in root directory.

4. If you wanna activate Search methods, you need to add `SCOUT_DRIVER=tntsearch` to the environment file and run:
  - php artisan vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"
  - php artisan cache:clear
  - php artisan route:clear
  - php artisan config:cache
  - composer dump-autoload

5. For indexing models run(Product, Component, SpecificationCategory, Customer):
  - php artisan scout:import 'App\\{MODEL_NAME}'

  - Note: If Scout lib errors when installing in Windows stating that is missing the sqlite dependency, go to php.ini in your PHP folder and enable:
    - extension=php_sqlite3.dll
    - extension=php_pdo_sqlite.dll

6. To spin up your server, using the command line, place yourself inside the folder where the file `Dockerfile` is, and execute:
   ```bash
   docker-compose up -d
   ```

7. After the process is finished, you can access:
  - The web app on http://localhost:8000

