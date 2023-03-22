FROM node:10-alpine

RUN apk --no-cache add curl

RUN apk --no-cache add zip php7 php7-json php7-pdo_mysql php7-tokenizer \
            php7-phar php7-fileinfo php7-mbstring php7-curl php7-dom php7-xmlwriter \
            php7-xml php7-session php7-ctype php7-gd php7-iconv php7-xmlreader \
            php7-zip php7-pdo php7-pdo_sqlite php7-sqlite3 php7-simplexml php7-intl php7-sockets php7-pdo_pgsql

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer 

WORKDIR /usr/src/app

COPY . .

RUN composer install

# Config supervisor
RUN apk add --no-cache supervisor
RUN mkdir -p /var/log/supervisor
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

EXPOSE 8000 3306 6001 6002 9001 631

CMD sh config_server.sh
