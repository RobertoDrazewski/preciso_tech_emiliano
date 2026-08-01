# Sirve para dos cosas:
#
# 1) Correr el proyecto en tu máquina sin instalar PHP (útil en Macs viejas
#    donde Homebrew no tiene binarios precompilados y obliga a compilar todo
#    desde cero):
#
#      docker build -t preciso-backend .
#      docker run --rm -it -v "$(pwd)":/app -p 8000:8000 --env-file .env \
#          preciso-backend
#
# 2) Desplegar en Railway: Railway detecta este Dockerfile solo (con "Root
#    Directory" = backend/ en la config del servicio) y usa el CMD de abajo,
#    que ya lee el puerto que Railway asigna dinámicamente via $PORT.
#
# Usamos el instalador de mlocati (https://github.com/mlocati/docker-php-extension-installer)
# en vez de armar la compilación a mano con apt-get + docker-php-ext-install:
# es un script mantenido específicamente para evitar los problemas de
# phpize/config.m4 que dan las imágenes oficiales de PHP cuando se instala
# más de una extensión que necesita compilarse.

FROM php:8.3-cli

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_sqlite sqlite3 curl

WORKDIR /app
COPY . .

# Railway inyecta $PORT en runtime; en local (docker run manual de arriba) no
# está seteada, así que usamos 8000 como default.
ENV PORT=8000

# Formato exec + sh -c para que la variable de entorno $PORT se expanda bien.
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t public"]
