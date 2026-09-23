# onlineStore

[![Tests](https://github.com/Omnoya/onlineStore/actions/workflows/tests.yml/badge.svg)](https://github.com/Omnoya/onlineStore/actions/workflows/tests.yml)

onlineStore is a Laravel e-commerce application developed as a backend portfolio project. It provides a public product catalogue, customer registration and login, a session-based cart, orders paid with a virtual account balance, and an administrator area for managing products and stock. It does not process real payments.

## Features

- Browse products and view their details and availability.
- Register, sign in, and add available products to a cart.
- Place an order using a virtual balance.
- Create, edit, and delete products from the administrator area, including price, image, and stock.
- Store uploaded product images on Laravel's public disk.

Checkout uses an HTTP POST form with Laravel CSRF protection. The server validates quantities, checks that every requested product still exists, and checks current stock and the customer's balance. Within one database transaction it reloads and locks the user and products, calculates the total from current product prices, creates the order and items, reduces stock, and debits the balance.

A server-generated UUID identifies each checkout intention. The UUID is included in the form, persisted on the order, and protected by a unique database constraint. A retry with the same token for the same authenticated user returns the existing order without a second debit or stock reduction. The cart and token are cleared after a successful checkout; failed business checks leave them available for correction or retry.

These are implementation and automated-test guarantees, not a claim that concurrent checkouts have been verified against MySQL. Real row-lock behavior and concurrent retries still need dedicated MySQL tests.

## Stack

- PHP 8.2 and Laravel 9.52.6
- MySQL 8 for the Docker application
- SQLite in memory for the fast PHPUnit suite
- PHP/Apache, Docker Compose, PHPUnit, and GitHub Actions

The current application and PHPUnit suite do not require a Node build.

## Run locally with Docker

Prerequisites: Docker with the Compose plugin, a POSIX-compatible shell, and an available port 8080 on 127.0.0.1. Run these commands from the root of a fresh clone. The application port is bound to loopback; MySQL is not exposed on the host.

First, create a private local .env from .env.example. This command uses an ephemeral PHP 8.2 CLI container to generate a random Laravel APP_KEY and local MySQL password. It sets APP_URL to the exposed Docker address, prints neither generated value, and refuses to overwrite an existing .env.

~~~sh
docker run --rm --user "$(id -u):$(id -g)" --mount "type=bind,source=$PWD,target=/workspace" --workdir /workspace php:8.2-cli php -r '
  umask(0077);
  $env = file_get_contents(".env.example");
  if ($env === false) { throw new RuntimeException("Cannot read .env.example"); }
  $values = [
      "APP_KEY" => "base64:" . base64_encode(random_bytes(32)),
      "DB_PASSWORD" => bin2hex(random_bytes(24)),
      "APP_URL" => "http://127.0.0.1:8080",
  ];
  foreach ($values as $name => $value) {
      $env = preg_replace("/^" . $name . "=.*$/m", $name . "=" . $value, $env, 1, $count);
      if ($env === null || $count !== 1) {
          throw new RuntimeException("Missing or invalid template entry: " . $name);
      }
  }
  $file = @fopen(".env", "x");
  if ($file === false) { throw new RuntimeException(".env already exists or cannot be created; it was not overwritten"); }
  $written = fwrite($file, $env);
  fclose($file);
  if ($written !== strlen($env)) {
      unlink(".env");
      throw new RuntimeException("Could not write the complete .env file");
  }
'
~~~

On a typical Linux host, the generated file has restrictive permissions. Review its non-secret settings locally if your environment differs. The example database name and username are for local development; the generated password replaces the demonstration value from .env.example. APP_KEY stays empty in the committed example.

Then check the Compose configuration, build and start the services, inspect their status, apply the migrations explicitly, and create the first administrator interactively:

~~~sh
docker compose config --quiet
docker compose up -d --build
docker compose ps
docker compose exec -T app php artisan migrate --no-interaction
docker compose exec app php artisan app:create-admin
~~~

Do not use -T for the administrator command: it needs an interactive terminal. It prompts for Name and Email, then requests Password and Confirm password with hidden input. There is no default administrator password or credential in the repository.

Open [http://127.0.0.1:8080](http://127.0.0.1:8080). Compose passes .env values to the application through env_file; it does not mount .env in the container. Do not run php artisan key:generate in the container expecting it to update the host file. The entrypoint does not run migrations automatically.

## Inspect MySQL with Docker phpMyAdmin

The application stores its local data in the Docker MySQL service `db`. To inspect that database in a browser, run a separate phpMyAdmin container on the **same Docker network**. This is distinct from any phpMyAdmin installation already running on the Ubuntu host.

First, start the application and database if they are stopped:

~~~sh
docker compose up -d db app
docker compose ps
~~~

If the `onlinestore-phpmyadmin` container already exists and is stopped, restart it:

~~~sh
docker start onlinestore-phpmyadmin
~~~

Otherwise, create it using the network of the running database container:

~~~sh
DB_CONTAINER="$(docker compose ps -q db)"
DB_NETWORK="$(docker inspect "$DB_CONTAINER" \
  --format '{{range $name, $_ := .NetworkSettings.Networks}}{{println $name}}{{end}}' | head -n 1)"

docker run -d \
  --name onlinestore-phpmyadmin \
  --network "$DB_NETWORK" \
  -p 127.0.0.1:8081:80 \
  -e PMA_HOST=db \
  -e PMA_PORT=3306 \
  phpmyadmin:5.2
~~~

Verify that phpMyAdmin is running and bound to the local port:

~~~sh
docker ps --filter name=onlinestore-phpmyadmin \
  --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
~~~

Open [http://127.0.0.1:8081](http://127.0.0.1:8081). Sign in using the local `DB_USERNAME` and `DB_PASSWORD` values from your private `.env` file; do not share those values or use the MySQL root password. Select the database named by `DB_DATABASE`, then open a table such as `users`, `products`, `orders`, or `items` and choose **Browse** to inspect its records. The `users` table contains password hashes: do not publish screenshots or exports of that column.

To stop this standalone phpMyAdmin container without stopping Laravel or MySQL:

~~~sh
docker stop onlinestore-phpmyadmin
~~~

Stopping this standalone phpMyAdmin container does not remove or modify any MySQL data. It is not managed by `docker compose stop`. Restart it later with `docker start onlinestore-phpmyadmin`. Do not use `docker compose down -v` for routine cleanup: that command removes the project's named data volumes.

## Tests and CI

Run the complete fast suite in a disposable, network-isolated container. Do not run PHPUnit inside the normal Compose `app` container: tests that reset the database must never have access to the application's MySQL data.

~~~sh
docker run --rm \
  --network none \
  --entrypoint php \
  --mount "type=bind,source=$PWD,target=/var/www/html" \
  --workdir /var/www/html \
  -e APP_ENV=testing \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=:memory: \
  -e DATABASE_URL= \
  -e SESSION_DRIVER=array \
  -e CACHE_DRIVER=array \
  onlinestore-app \
  vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result
~~~

This test container has no network connection and does not mount the MySQL data volume. The application running under Docker Compose continues to use MySQL independently.

The PHPUnit configuration forces SQLite :memory: and a fixed, non-secret test-only APP_KEY. The tests do not need the local MySQL database. The latest validated local, network-isolated Docker run passed 47 tests with 240 assertions. Coverage now includes rejection of an injected administrator role during public registration, first-administrator creation, refusal of an existing email or an existing administrator, and password validation. SQLite exercises application and transactional invariants, but cannot establish MySQL row-lock behavior under concurrent requests.

The GitHub Actions Tests workflow runs PHPUnit on pushes and pull requests with PHP 8.2 and SQLite in memory. It requires no GitHub secrets or MySQL service. The workflow has passed successfully on GitHub.

## Administrator access

The /admin routes are protected by the administrator middleware. Public registration always creates customer accounts with role=client; a submitted role=admin value is ignored. After migrating a fresh installation, create the first administrator with the interactive app:create-admin command shown above. It refuses to create a second administrator and never promotes or changes an existing customer account, including one with the requested email. The administrator password is entered with hidden prompts; no default administrator credentials are provided.

## Storage and local data

Docker Compose uses a named uploads volume for storage/app/public and a separate named volume for MySQL data. The entrypoint prepares Laravel's writable runtime directories and creates the public/storage link to the upload volume. Uploaded images and database records survive ordinary container recreation. Do not use docker compose down -v as routine cleanup: it removes both named volumes and their data.

## Known limitations and next steps

- Verify actual concurrent checkouts, row locks, and unique-token races against MySQL 8; the SQLite suite cannot prove these properties.
- Checkout uses a virtual balance only; there is no live payment or shipping integration.

Never commit .env or expose credentials. Enter the administrator password only through the interactive, hidden prompts; there is no default administrator password. MySQL has no host-published port, but its initialization logs may contain a generated root password: do not publish or request those logs as part of setup support.
