# Image épinglée : la mettre à jour volontairement (nouvelle étiquette 8.3.x),
# puis reconstruire avec `docker compose build --pull`.
FROM php:8.3.36-cli-alpine3.24

COPY php.ini /usr/local/etc/php/conf.d/zz-tremouille.ini
COPY index.php /app/index.php
WORKDIR /app

# Serveur intégré de PHP avec plusieurs processus : nginx (en frontal sur le VPS)
# met en tampon les corps de requête, et 4 processus servent en parallèle.
# Migration prévue vers une image FPM ou FrankenPHP : voir la PR des durcissements.
ENV PHP_CLI_SERVER_WORKERS=4

# Utilisateur non root (www-data, uid 82 dans les images Alpine).
USER www-data

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "index.php"]
