FROM php:8-cli
ENV PORT=389

ENV ldap_port=${PORT}
ENV remote_soap_user='ispremoteuser'
ENV remote_soap_pass='ispremotepass'
ENV soap_url='https://localhost:8080/remote/'
ENV soap_location='https://localhost:8080/remote/index.php'
ENV soap_validate_cert=false


RUN apt-get update -yq &&\
apt-get install libxml2-dev libldap2-dev git -yq &&\
docker-php-ext-configure pcntl --enable-pcntl  &&\
docker-php-ext-install pcntl &&\
docker-php-ext-configure soap --enable-soap  &&\
docker-php-ext-install soap &&\
docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu/ &&\
docker-php-ext-install ldap


RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" &&\
php -r "if (hash_file('sha384', 'composer-setup.php') === '906a84df04cea2aa72f40b5f787e49f22d4c2f19492ac310e8cba5b96ac8b64115ac402c8cd292b8a03482574915d1a8') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;" &&\
php composer-setup.php &&\
php -r "unlink('composer-setup.php');" &&\
mv composer.phar /usr/local/bin/composer

COPY ./src /app
COPY ./start.sh /start.sh 
RUN chmod u+x /start.sh

WORKDIR /app
RUN composer install --prefer-source --no-interaction

EXPOSE ${ldap_port}
ENTRYPOINT [ "/start.sh" ]
