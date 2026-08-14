# Drupal Commerce 3.x requires ext-bcmath, which the official Drupal image does
# not ship: without it composer cannot resolve Commerce at all. unzip is also
# absent, and is needed to unpack the module the way a site builder would.
FROM drupal:11-apache

RUN docker-php-ext-install -j"$(nproc)" bcmath \
 && apt-get update && apt-get install -y --no-install-recommends unzip \
 && rm -rf /var/lib/apt/lists/*
