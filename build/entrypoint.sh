#!/bin/bash
echo "$PGPASS" > /var/www/.pgpass &&\
    chmod 600 /var/www/.pgpass &&\
    chown www-data:www-data /var/www/.pgpass

docker-php-entrypoint apache2-foreground
