FROM php:8.1-fpm-bookworm

# Environment variables for APT
ENV DEBIAN_FRONTEND=noninteractive

# Optimization for apt
RUN echo "APT::Install-Recommends \"0\";" > /etc/apt/apt.conf.d/01norecommend && \
    echo "APT::Install-Suggests \"0\";" >> /etc/apt/apt.conf.d/01norecommend

# Dependencies for Moodle
RUN apt-get update && apt-get install -y --no-install-recommends \
    apt-transport-https \
    gettext \
    gnupg \
    locales \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    libxml2-dev \
    libzip-dev \
    libonig-dev \
    libxslt1-dev \
    libsodium-dev \
    libpq-dev \
    libmemcached-dev \
    libuuid1 \
    uuid-dev \
    unzip \
    git \
    postgresql-client \
    curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

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
    zip \
    mysqli \
    pdo_pgsql \
    mbstring \
    bcmath

# GD extension
RUN docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/ && \
    docker-php-ext-install -j$(nproc) gd

# PECL extensions
RUN pecl install memcached redis apcu igbinary uuid && \
    docker-php-ext-enable memcached redis apcu igbinary uuid

# Moodle data directories
RUN mkdir -p /var/www/moodledata && \
    chown -R www-data:www-data /var/www/moodledata && \
    chmod -R 777 /var/www/moodledata

WORKDIR /var/www/html

CMD ["php-fpm"]
