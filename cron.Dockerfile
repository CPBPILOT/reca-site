FROM alpine:3.20
RUN apk add --no-cache curl tzdata
ENV TZ=America/Chicago
# crond runs as root and reads /etc/crontabs/root
COPY crontab /etc/crontabs/root
CMD ["crond", "-f", "-l", "8"]
