# Teltonika FM-XXXX GPS to InfluxDB importer

This program reads GPS location and speed from Teltonika GPS-trackers, and saves them into InfluxDB.

Run this program somewhere, and configure your Teltonika fleet tracker to send data to your host.

## Usage

Example `docker-compose.yml` file:

```
version: '2'

services:

  importer:
    build: .
    container_name: teltonika-importer
    restart: unless-stopped
    ports:
      - "12050:12050"
    environment:
      - TIMEZONE=Europe/Helsinki
      - LISTEN_PORT=12050
      - ALLOWED_DEVICES=12345678901234 23456789012345 345678901234567
      - DB_SCHEME=https+influxdb
      - DB_HOST=influxdb.example.com
      - DB_PORT=443
      - DB_NAME=influxdbname
      - DB_USER=influxdbuser
      - DB_PASS=influxdbpass
```
