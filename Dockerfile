FROM php:8.4-cli-alpine

WORKDIR /installer

COPY entrypoint.sh   ./entrypoint.sh
COPY commands/       ./commands/
COPY scaffold/       ./scaffold/

RUN chmod +x entrypoint.sh commands/*.sh

ENTRYPOINT ["sh", "/installer/entrypoint.sh"]
CMD ["help"]
