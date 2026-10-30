ARG PHP_VERSION=8.5
 
FROM php:${PHP_VERSION}-apache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
 
# Install PDO MySQL
RUN docker-php-ext-install pdo pdo_mysql
 
# Enable Apache mod_rewrite
RUN a2enmod rewrite
 
# Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
 
# Copy app files
WORKDIR /var/www/html
COPY . /var/www/html/

# Dependencies are excluded from Git, so install them in the image.
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
 
# Fix permissions
RUN chown -R www-data:www-data /var/www/html \
&& chmod -R 755 /var/www/html
 
# Point Apache document root to public/
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
 
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot ${APACHE_DOCUMENT_ROOT}|g' /etc/apache2/sites-available/000-default.conf \
&& sed -i 's|<Directory /var/www/html>|<Directory ${APACHE_DOCUMENT_ROOT}>|g' /etc/apache2/apache2.conf
 
EXPOSE 80