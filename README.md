# Registry Platform API for registrars

## Install

1. Namingo Registry must be already installed

```bash
mkdir /opt/registry/api
git clone https://github.com/getnamingo/registry-api-beta /opt/registry/api
cd /opt/registry/api
composer install
mv config.php.dist config.php
```

2. Configure database and rate limit deatils in config.php

3. Open port 8080 in firewall

4. Run with `php /opt/registry/api/api.php`

## How to use

`GET YOUR_IP:8080/availability?domain=test.name`

`GET YOUR_IP:8080/droplist`

## TODO

- Tests.

- More features.

- Service file for easier start.

## Acknowledgements

We extend our gratitude to:
- **ChatGPT** for invaluable assistance with code and text writing.
