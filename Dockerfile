FROM php:8.1-fpm-bookworm

ENV DEBIAN_FRONTEND=noninteractive

# Optimization for apt
RUN echo "APT::Install-Recommends \"0\";" > /etc/apt/apt.conf.d/01norecommend && \
    echo "APT::Install-Suggests \"0\";" >> /etc/apt/apt.conf.d/01norecommend

# Dependencies for Moodle
RUN apt-get update && apt-get install -y --no-install-recommends \
    apt-transport-https \
    gettext \
    gnupg \
    libcurl4-openssl-dev \
    libfreetype6-dev \
    libicu-dev \
    libjpeg62-turbo-dev \
    libldap2-dev \
    libmemcached-dev \
    libpng-dev \
    libpq-dev \
    libxml2-dev \
    libxslt-dev \
    unixodbc-dev \
    uuid-dev \
    libmcrypt-dev \
    libzip-dev \
    libsodium-dev \
    ghostscript \
    libaio1 \
    libcurl4 \
    libgss3 \
    libicu72 \
    libxslt1.1 \
    sassc \
    unzip \
    zip \
    locales \
    && rm -rf /var/lib/apt/lists/*

# Generate locales
RUN echo 'en_US.UTF-8 UTF-8' > /etc/locale.gen && \
    echo 'es_ES.UTF-8 UTF-8' >> /etc/locale.gen && \
    echo 'es_CL.UTF-8 UTF-8' >> /etc/locale.gen && \
    locale-gen

# Install PHP extensions
RUN docker-php-ext-install -j$(nproc) \
    exif \
    intl \
    opcache \
    pgsql \
    soap \
    xsl \
    sodium \
    zip

# GD extension
RUN docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/ && \
    docker-php-ext-install -j$(nproc) gd

# PECL extensions
RUN pecl install memcached redis apcu igbinary uuid && \
    docker-php-ext-enable memcached redis apcu igbinary uuid && \
    echo 'apc.enable_cli = On' >> /usr/local/etc/php/conf.d/docker-php-ext-apcu.ini && \
    pecl clear-cache

# Moodle data directories
RUN mkdir -p /var/www/moodledata && \
    chown -R www-data:www-data /var/www/moodledata && \
    chmod -R 777 /var/www/moodledata

# Copy configurations (if any)
# COPY conf/php.ini /usr/local/etc/php/php.ini
# COPY conf/www.conf /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

# The application code will be mounted via docker-compose for development
# or copied for production
# COPY ./src /var/www/html

EXPOSE 9000
CMD ["php-fpm"]
