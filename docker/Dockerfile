# Définition de l'image du registre : utilisation de Php avec Apache ou en cli
# Benoit DAVID - 4/7/2022

# Use an official Php runtime as a parent image
FROM php:8.1-apache

# Locales
ENV LC_ALL C.UTF-8
ENV LANG en_US.UTF-8
ENV LANGUAGE en_US.UTF-8
RUN apt-get update \
  && apt-get install -y locales \
  && dpkg-reconfigure locales \
  && locale-gen C.UTF-8 \
  && /usr/sbin/update-locale LANG=C.UTF-8 \
  && echo 'en_US.UTF-8 UTF-8' >> /etc/locale.gen \
	&& locale-gen \

# Common
  && apt-get update \
  && apt-get install -y openssl autoconf pkg-config libssl-dev zlib1g-dev zip unzip p7zip-full git wget \

# PHP
# intl
  && apt-get install -y libicu-dev \
  && docker-php-ext-configure intl \
  && docker-php-ext-install intl \

# pgsql
  && apt-get update && apt-get install -y libpq-dev && docker-php-ext-install pgsql pdo pdo_pgsql \

# Install composer and put binary into $PATH
  && curl -sS https://getcomposer.org/installer | php \
	&& mv composer.phar /usr/local/bin/ \
	&& ln -s /usr/local/bin/composer.phar /usr/local/bin/composer \

# Clean
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /var/cache/* \

  # Attribution de /var/www à www-data:www-data
  && chown www-data:www-data /var/www

# modification de configuration Apache
COPY config/apache.conf /etc/apache2/sites-available/000-default.conf

# modification de la configuration Php
COPY config/php.ini /usr/local/etc/php/

USER www-data:www-data

# installe le composant Yaml de Symfony
RUN composer require symfony/yaml easyrdf/easyrdf ml/json-ld

# copie des scripts Php dans /var/www/html/
ADD --chown=www-data ./index.php ./pgsql.inc.php ./api.yaml /var/www/html/

# Make port 80 available to the world outside this container
EXPOSE 80

ENTRYPOINT ["apache2-foreground"]
