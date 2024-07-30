# Namingo Registry API for registrars

## Install

1. Namingo Registry must be already installed.

2. Install the API component:

```bash
mkdir /opt/registry/api
git clone https://github.com/getnamingo/registry-api-beta /opt/registry/api
cd /opt/registry/api
composer install
mv config.php.dist config.php
```

3. Configure database and rate limit deatils in config.php

4. Edit `/etc/caddy/Caddyfile` and add as new record the following, then replace the values with your own.

```bash
    api.YOUR_DOMAIN {
        bind YOUR_IP_V4 YOUR_IP_V6
        reverse_proxy localhost:8500
        encode gzip
        file_server
        tls YOUR_EMAIL
        header -Server
        header * {
            Referrer-Policy "no-referrer"
            Strict-Transport-Security max-age=31536000;
            X-Content-Type-Options nosniff
            X-Frame-Options DENY
            X-XSS-Protection "1; mode=block"
            Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'none'; form-action 'self'; worker-src 'none'; frame-src 'none';"
            Feature-Policy "accelerometer 'none'; autoplay 'none'; camera 'none'; encrypted-media 'none'; fullscreen 'self'; geolocation 'none'; gyroscope 'none'; magnetometer 'none'; microphone 'none'; midi 'none'; payment 'none'; picture-in-picture 'self'; usb 'none';"
            Permissions-Policy: accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=();
        }
    }
```

5. Restart Caddy with `systemctl restart caddy`

6. Copy `api.service` to `/etc/systemd/system/`. Change only User and Group lines to your user and group.

```bash
systemctl daemon-reload
systemctl start api.service
systemctl enable api.service
```

After that you can manage API via systemctl as any other service.

## How to use

`GET https://api.YOUR_DOMAIN/availability?domain=test.name`

`GET https://api.YOUR_DOMAIN/droplist`

## TODO

- Authentication. In the meantime, whitelist access to registrar IPs only in Caddy.

- Caching.

## Acknowledgements

We extend our gratitude to:
- **ChatGPT** for invaluable assistance with code and text writing.
