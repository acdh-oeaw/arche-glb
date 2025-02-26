FROM php:8.3-apache
# VARIANT must be "production" or "development"
ARG VARIANT
RUN cp "$PHP_INI_DIR/php.ini-$VARIANT" "$PHP_INI_DIR/php.ini"
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions
RUN apt update &&\
    apt install -y npm &&\
    install-php-extensions yaml pdo_pgsql @composer &&\
    a2enmod rewrite &&\
    npm install --global @gltf-transform/cli@3.10.1 &&\
    curl -L https://github.com/zeux/meshoptimizer/releases/download/v0.22/gltfpack-ubuntu.zip > /tmp/gltfpack.zip &&\
    unzip -d /usr/local/bin /tmp/gltfpack.zip &&\
    rm /tmp/gltfpack.zip
COPY docroot /var/www/html
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN cd /var/www/html &&\
    composer update -o --no-dev &&\
    chown -R www-data:www-data /var/www/html
EXPOSE 80
ENTRYPOINT entrypoint.sh
