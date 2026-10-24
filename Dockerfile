FROM php:8.3-apache

# 1. تثبيت متطلبات النظام وإضافة nodejs و npm لبناء ملفات التصميم (Vite)
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
    nodejs \
    npm \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 2. تثبيت إضافات PHP الضرورية
RUN docker-php-ext-install pdo_sqlite mbstring exif pcntl bcmath gd zip

# 3. جلب أداة Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. تحديد مسار العمل ونسخ ملفات المشروع
WORKDIR /var/www/html
COPY . /var/www/html

# 5. تحويل ملف .env.example إلى .env الحقيقي وتجهيز قاعدة البيانات
RUN rm -f .env bootstrap/cache/*.php && \
    cp .env.example .env && \
    touch database/database.sqlite

# 6. تثبيت حزم لارافيل وتوليد المفتاح
RUN composer install --no-dev --optimize-autoloader
RUN php artisan key:generate --force

# 7. تثبيت وبناء ملفات الواجهة والتصميم (Vite) لحل مشكلة manifest.json
RUN npm install && npm run build

# 8. ضبط إعدادات Apache ليقرأ من مجلد public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
RUN a2enmod rewrite

# 9. إعطاء صلاحيات القراءة والكتابة للمجلدات وقاعدة البيانات
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database
RUN chmod -R 775 storage bootstrap/cache database

EXPOSE 80

# 10. مسح الكاش وتشغيل الجداول والسيرفر
CMD php artisan config:clear && php artisan migrate --force && apache2-foreground
