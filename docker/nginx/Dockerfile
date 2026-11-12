FROM nginx:1.27-alpine-slim
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
# nginx only needs the document root to exist for try_files; every request goes to php-fpm.
COPY public /app/public
