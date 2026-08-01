# Sirve el frontend estático (HTML/CSS/JS) + assets/js/config.php (que
# necesita PHP para leer la variable de entorno API_BASE).
#
# Local:
#   docker build -t preciso-frontend .
#   docker run --rm -it -p 3000:3000 preciso-frontend
#
# Railway: "Root Directory" = frontend/ en la config del servicio. Seteá la
# variable de entorno API_BASE con la URL pública del servicio backend
# (Railway te la da apenas lo desplegás, algo como
# https://preciso-backend-production.up.railway.app/api).

FROM php:8.3-cli

WORKDIR /app
COPY . .

ENV PORT=3000
CMD php -S 0.0.0.0:${PORT}
