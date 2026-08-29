# Stocuro — Docker image
#
# Alternative to the One.com/FTP deployment described in README.md, for
# anyone running Stocuro on their own Linux server instead. Deliberately
# thin: this image only provides the PHP/Apache runtime. The application
# code itself is NOT copied into the image (see docker-compose.yml) --
# it's bind-mounted from the host at runtime, exactly like the existing
# "unzip the release and point Apache at it" flow. That keeps upgrades
# identical to the non-Docker path (drop in new files, no rebuild), and
# lets install.php write config/database.local.php, config/instance.local.php,
# install.lock, and uploads/ straight onto the host filesystem, where they
# survive `docker compose down`/rebuilds without extra volume bookkeeping.
#
# See DOCKER.md for the full walkthrough.

FROM php:8.2-apache

# pdo_mysql: the only DB extension the app uses (config/database.php --
# plain PDO, no mysqli anywhere in the codebase). curl: used to fetch
# product photos from external links (see README.md "Requirements").
RUN docker-php-ext-install pdo pdo_mysql \
    && a2enmod headers

# config/, includes/, sql/, and migratie/ each ship their own .htaccess
# with "Require all denied" to block direct web access to anything that
# isn't meant to be public (most importantly config/database.local.php).
# Apache's default vhost in this image has AllowOverride None, which
# would silently make those .htaccess files do nothing -- so this
# switches it on for the whole document root. Without this, a fresh
# Docker install would serve the DB credentials file to anyone who asks.
RUN { \
      echo '<Directory /var/www/html>'; \
      echo '    AllowOverride All'; \
      echo '    Require all granted'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-available/stocuro-htaccess.conf \
    && a2enconf stocuro-htaccess

WORKDIR /var/www/html
