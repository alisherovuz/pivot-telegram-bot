FROM composer:2 AS composer
FROM php:8.3-cli-bookworm
RUN --mount=type=secret,id=proxy_ca,required=true \
    apt-get -o Acquire::http::Proxy="$http_proxy" -o Acquire::https::Proxy="$https_proxy" -o Acquire::https::CaInfo=/run/secrets/proxy_ca update && \
    apt-get -o Acquire::http::Proxy="$http_proxy" -o Acquire::https::Proxy="$https_proxy" -o Acquire::https::CaInfo=/run/secrets/proxy_ca install -y --no-install-recommends libzip-dev libpng-dev libonig-dev libxml2-dev unzip git && \
    docker-php-ext-install -j2 pdo_mysql mbstring gd zip && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
