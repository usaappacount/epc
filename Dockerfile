FROM php:8.3-apache

# 1. تثبيت متطلبات النظام وإضافة nodejs و npm لبناء ملفات الواجهة
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

# 5. تجهيز ملف .env وإنشاء مجلدات الكاش وملف قاعدة البيانات
RUN rm -f .env bootstrap/cache/*.php && \
    cp .env.example .env && \
    mkdir -p database storage/framework/sessions storage/framework/views storage/framework/cache && \
    touch database/database.sqlite

# 6. تثبيت حزم لارافيل وتوليد مفتاح التشفير
RUN composer install --no-dev --optimize-autoloader
RUN php artisan key:generate --force

# 7. بناء ملفات التصميم (Vite)
RUN npm install && npm run build

# 8. ضبط إعدادات Apache ليقرأ من مجلد public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
RUN a2enmod rewrite

# 9. منح صلاحيات القراءة والكتابة الكاملة للمجلدات وقاعدة البيانات
