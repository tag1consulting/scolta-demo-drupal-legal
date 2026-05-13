ARG PHP_VERSION="8.4"

FROM debian:trixie-slim AS php-base

ARG PHP_VERSION

RUN apt-get update && \
    apt-get install -y \
      ca-certificates \
      curl \
      lsb-release && \
    curl -sL -o /etc/apt/keyrings/php.gpg https://packages.sury.org/php/apt.gpg && \
    echo "deb [signed-by=/etc/apt/keyrings/php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" | tee /etc/apt/sources.list.d/php.list

RUN apt-get update && \
    apt-get install -y \
      php${PHP_VERSION} \
      php${PHP_VERSION}-apcu \
      php${PHP_VERSION}-cli \
      php${PHP_VERSION}-common \
      php${PHP_VERSION}-curl \
      php${PHP_VERSION}-dom \
      php${PHP_VERSION}-gd \
      php${PHP_VERSION}-gmp \
      php${PHP_VERSION}-intl \
      php${PHP_VERSION}-mbstring \
      php${PHP_VERSION}-mysqlnd \
      php${PHP_VERSION}-opcache \
      php${PHP_VERSION}-pdo \
      php${PHP_VERSION}-soap \
      php${PHP_VERSION}-xmlwriter \
      php${PHP_VERSION}-zip && \
    rm -rf /var/lib/apt/lists/*

RUN update-alternatives --set php /usr/bin/php${PHP_VERSION}

FROM php-base AS composer

RUN apt-get update && \
    apt-get install -y \
      git \
      patch \
      zip && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /app

RUN curl -o /tmp/composer-setup.php https://getcomposer.org/installer && \
    curl -o /tmp/composer-setup.sig https://composer.github.io/installer.sig && \
    php -r "if (hash('SHA384', file_get_contents('/tmp/composer-setup.php')) !== trim(file_get_contents('/tmp/composer-setup.sig'))) { unlink('/tmp/composer-setup.php'); echo 'Invalid installer' . PHP_EOL; exit(1); }" && \
    php /tmp/composer-setup.php --filename composer --install-dir /usr/local/bin && rm /tmp/composer-setup.php

COPY . .

RUN composer install --no-ansi --no-dev --no-interaction --no-progress --optimize-autoloader

FROM php-base

ARG PHP_VERSION
ARG PAGEFIND_VERSION=1.5.2

ENV OPCACHE_MAX_FILES=4000
ENV OPCACHE_MEMORY_CONSUMPTION=128
ENV OPCACHE_REVALIDATE_FREQ=0
ENV OPCACHE_VALIDATE_TIMESTAMPS=0

ENV PHP_MEMORY_LIMIT=256M
ENV PHP_POST_MAX_SIZE=50M
ENV PHP_UPLOAD_MAX_FILESIZE=50M
ENV PHP_MAX_INPUT_VARS=1500

ENV HTTPD_START_SERVERS=5
ENV HTTPD_MIN_SPARE_SERVERS=5
ENV HTTPD_MAX_SPARE_SERVERS=10
ENV HTTPD_MAX_REQUEST_WORKERS=200
ENV HTTPD_MAX_CONNECTIONS_PER_CHILD=2000

WORKDIR /var/www/html

RUN apt-get update && \
    apt-get install -y \
      default-mysql-client \
      tini && \
    rm -rf /var/lib/apt/lists/*

RUN curl -sL "https://github.com/CloudCannon/pagefind/releases/download/v${PAGEFIND_VERSION}/pagefind-v${PAGEFIND_VERSION}-x86_64-unknown-linux-musl.tar.gz" \
    | tar -xz -C /usr/local/bin pagefind \
    && chmod +x /usr/local/bin/pagefind

# Read XFF headers, note this is insecure if you are not sanitizing
# XFF in front of the container
RUN { \
    echo '<IfModule mod_remoteip.c>'; \
    echo '  RemoteIPHeader X-Forwarded-For'; \
    echo '</IfModule>'; \
} > /etc/apache2/conf-enabled/remoteip.conf

# Correctly set SSL if we are terminated by it
RUN { \
    echo 'SetEnvIf X-Forwarded-Proto "https" HTTPS=on'; \
} > /etc/apache2/conf-enabled/remote_ssl.conf


# Configure prefork mpm
RUN { \
    echo '<IfModule mpm_prefork_module>'; \
    echo '    StartServers           ${HTTPD_START_SERVERS}'; \
    echo '    MinSpareServers        ${HTTPD_MIN_SPARE_SERVERS}'; \
    echo '    MaxSpareServers        ${HTTPD_MAX_SPARE_SERVERS}'; \
    echo '    MaxRequestWorkers      ${HTTPD_MAX_REQUEST_WORKERS}'; \
    echo '    MaxConnectionsPerChild ${HTTPD_MAX_CONNECTIONS_PER_CHILD}'; \
    echo '</IfModule>'; \
} > /etc/apache2/conf-enabled/mpm.conf

RUN { \
    echo '<VirtualHost *:8080>'; \
    echo 'DocumentRoot /var/www/html/web'; \
    echo '<Directory /var/www/html/web>'; \
    echo '    Options FollowSymLinks'; \
    echo '    AllowOverride All'; \
    echo '    Require all granted'; \
    echo '</Directory>'; \
    echo 'ErrorLog /dev/stderr'; \
    echo 'CustomLog /dev/stdout combined'; \
    echo '</VirtualHost>'; \
} > /etc/apache2/sites-enabled/000-default.conf

# PHP opcache settings
RUN { \
    echo 'zend_extension=opcache'; \
    echo 'opcache.validate_timestamps=${OPCACHE_VALIDATE_TIMESTAMPS}'; \
    echo 'opcache.revalidate_freq=${OPCACHE_REVALIDATE_FREQ}'; \
    echo 'opcache.memory_consumption=${OPCACHE_MEMORY_CONSUMPTION}'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=${OPCACHE_MAX_FILES}'; \
    echo 'opcache.enable_cli=1'; \
} > /etc/php/${PHP_VERSION}/apache2/conf.d/10-opcache.ini

#  PHP settings
RUN { \
    echo 'expose_php=Off'; \
    echo 'memory_limit=${PHP_MEMORY_LIMIT}'; \
    echo 'post_max_size=${PHP_POST_MAX_SIZE}'; \
    echo 'upload_max_filesize=${PHP_UPLOAD_MAX_FILESIZE}'; \
    echo 'max_input_vars=${PHP_MAX_INPUT_VARS}'; \
    echo 'log_errors=On'; \
    echo 'display_errors=Off'; \
    echo 'date.timezone=UTC'; \
} > /etc/php/${PHP_VERSION}/apache2/conf.d/20-php-settings.ini

RUN sed -i "s/^Listen 80/Listen 0.0.0.0:8080/" /etc/apache2/ports.conf && \
      chown -R 1001:0 /run/apache2 /var/log/apache2 && \
      chmod -R g+wX /run/apache2 /var/log/apache2

RUN a2enmod rewrite remoteip

# Copy site from composer output
COPY --from=composer /app .

RUN ln -sf /var/www/html/web/sites/default/instances/docker/settings.local.php web/sites/default/settings.local.php && \
    ln -sf /var/www/html/vendor/drush/drush/drush /usr/local/bin/drush && \
    ln -sf /var/www/html/vendor/drush/drush/drush.php /usr/local/bin/drush.php

RUN mkdir -p web/sites/default/private && \
    mkdir -p pagefind-site && \
    chown -R 1001:0 web/sites/default/private && \
    chown -R 1001:0 pagefind-site && \
    chmod -R g+w web/sites/default/private && \
    chmod -R g+w pagefind-site

USER 1001

EXPOSE 8080

ENTRYPOINT ["tini", "-g", "--", "/usr/sbin/apache2ctl", "-DFOREGROUND"]
