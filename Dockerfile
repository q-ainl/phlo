# Phlo engine image: FrankenPHP with the engine baked at /phlo.
# The app lives in /app (mount or copy it); /app/www is served.
#
#   Scaffold:  docker run -it --rm -v ./myapp:/app <image> php /phlo/install.php /app
#   Serve:     docker run -v ./myapp:/app -p 80:80 <image>
#
# Set SERVER_NAME=<host> for automatic HTTPS (defaults to :80, plain HTTP).
FROM dunglas/frankenphp:1-php8.5

RUN install-php-extensions gd pdo_mysql pdo_pgsql sqlite3 pdo_sqlite

# The engine calls `php-zts` for CLI work (build, lint) under a thread-safe runtime.
# The FrankenPHP image ships its ZTS php as `php`, so expose it under that name too.
RUN ln -sf "$(command -v php)" /usr/local/bin/php-zts

COPY . /phlo
COPY docker/Caddyfile /etc/frankenphp/Caddyfile

WORKDIR /app
EXPOSE 80 443 443/udp
