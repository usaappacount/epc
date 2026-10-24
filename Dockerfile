FROM php:8.3-apache

# تثبيت متطلبات النظام وإضافة الـ zip و sqlite
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libsqlite3-dev \
    libzip-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# تثبيت إضافات PHP الضرورية
RUN docker-php-ext-install pdo_sqlite mbstring exif pcntl bcmath gd zip

# جلب أداة Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# تحديد مسار العمل ونسخ ملفات المشروع
WORKDIR /var/www/html
COPY . /var/www/html

# تحويل ملف .env.example إلى .env الحقيقي وتجهيز قاعدة البيانات
RUN rm -f .env bootstrap/cache/*.php && \
    cp .env.example .env && \
    touch database/database.sqlite

# تثبيت حزم لارافيل
RUN composer install --no-dev --optimize-autoloader

# توليد مفتاح تشفير رسمي للتأكيد
RUN php artisan key:generate --force

# ضبط إعدادات Apache ليقرأ من مجلد public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
RUN a2enmod rewrite

# إعطاء صلاحيات القراءة والكتابة للمجلدات وقاعدة البيانات
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database
RUN chmod -R 775 storage bootstrap/cache database

EXPOSE 80

# مسح أي كاش وتشغيل الجداول والسيرفر
CMD php artisan config:clear && php artisan migrate --force && apache2-foreground
