FROM php:8.3-apache

# تثبيت الحزم المطلوبة لقاعدة البيانات وملفات اللارافيل
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libsqlite3-dev \
    libzip-dev

# تنظيف الذاكرة المؤقتة
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# تثبيت إضافات PHP الضرورية
RUN docker-php-ext-install pdo_sqlite mbstring exif pcntl bcmath gd zip

# تحميل أداة Composer لإدارة حزم لارافيل
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# تحديد مسار العمل داخل السيرفر
WORKDIR /var/www/html

# نسخ ملفات المشروع إلى السيرفر
COPY . /var/www/html

# حل جذري: إنشاء ملف .env نظيف وتوليد مفتاح التشفير وقاعدة البيانات تلقائياً
RUN rm -f .env bootstrap/cache/*.php && \
    cp .env.example .env || touch .env && \
    echo "APP_NAME=Laravel" > .env && \
    echo "APP_ENV=production" >> .env && \
    echo "APP_KEY=base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=" >> .env && \
    echo "APP_DEBUG=true" >> .env && \
    echo "DB_CONNECTION=sqlite" >> .env && \
    touch database/database.sqlite

# توجيه سيرفر Apache إلى مجلد public الخاص بلارافيل
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# تفعيل خاصية الـ Rewrite في Apache لروابط لارافيل
RUN a2enmod rewrite

# تثبيت الحزم عبر Composer
RUN composer install --no-dev --optimize-autoloader

# ضبط الصلاحيات لمجلدات التخزين والكاش وقاعدة البيانات
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database
RUN chmod -R 775 storage bootstrap/cache database

# فتح المنفذ 80 للاتصال
EXPOSE 80

# تشغيل السيرفر وتجهيز الجداول تلقائياً
CMD php artisan config:clear && php artisan migrate --force && apache2-foreground
