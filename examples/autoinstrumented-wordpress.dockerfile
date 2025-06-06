# Pull in dependencies with composer
FROM composer:2.7.4 as build
COPY composer.json ./
RUN composer install --ignore-platform-reqs

FROM wordpress:6.5.2
# Install the opentelemetry, protobuf and grpc extensions
RUN apt-get update && apt-get install -y zlib1g-dev vim && apt-get clean
RUN pecl install opentelemetry protobuf grpc
COPY otel.php.ini $PHP_INI_DIR/conf.d/.

# Copy in the composer vendor files and autoload.php
COPY --from=build /app/vendor /var/www/otel
COPY service1.php /var/www/html
COPY service2.php /var/www/html
COPY service3.php /var/www/html
COPY sdk.php /var/www/html
# Install JSON Basic Authentication Wordpress plugin
RUN mkdir -p /var/www/html/wp-content/plugins/basic-auth && curl -q -o /var/www/html/wp-content/plugins/basic-auth/basic-auth.php https://raw.githubusercontent.com/WP-API/Basic-Auth/master/basic-auth.php
