[한국어](README-kr.md)

# Wikiman PHP Backend

The PHP backend for [Wikiman](https://github.com/kendrickkim/wikiman), intended
for hosting environments where Node.js is unavailable.

It implements the same API contract as the
[Node backend](https://github.com/kendrickkim/wikiman-backend) as a custom app
for [PHAST API v2](https://github.com/kendrickkim/phastapiv2). It supports
SQLite and MySQL/MariaDB.

## Before you install

You need:

- PHP 8.1 or newer
- Apache with `mod_rewrite`, or Nginx with PHP-FPM
- [PHAST API v2](https://github.com/kendrickkim/phastapiv2)
- `mbstring`, `fileinfo`, `json`, `openssl`, `curl`, `dom`, and `zlib`
- `pdo_sqlite` for SQLite, or `pdo_mysql` for MySQL/MariaDB

Use this PHP backend when your host cannot run Node.js. If Node.js is available,
the [Node backend](https://github.com/kendrickkim/wikiman-backend) is the
reference implementation and has the simpler deployment path.

## Install

### 1. Download the projects

```bash
git clone https://github.com/kendrickkim/wikiman-backend-php.git
git clone https://github.com/kendrickkim/phastapiv2.git
git clone https://github.com/kendrickkim/wikiman-frontend.git
```

GitHub **Download ZIP** archives also work on shared hosting without Git.

### 2. Build the frontend

```bash
cd wikiman-frontend
npm install
npm run build:php
```

This copies the PWA into `wikiman-backend-php/public/` while preserving the PHP
front controller, web installer, and `.htaccess`.

### 3. Configure the web server and PHAST

Use `wikiman-backend-php/public/` as the document root. Expose PHAST API v2 at
the same origin under `/api`, then point its custom app directory at this
repository:

```php
// phastapiv2/config.phastapi.php
$G_PHASTAPI_CUSTOM_DIR = "../wikiman-backend-php";
```

Both Apache and Nginx work. Keep these rules the same on either server:

- `/api` goes to PHAST
- Site pages and static assets go through `public/index.php`
- Do not serve a static `index.html` as the SPA fallback
- Keep `data/` outside the web document root

#### Apache

Minimal VirtualHost:

```apache
Alias /api /var/www/phastapiv2
<Directory /var/www/phastapiv2>
    AllowOverride All
    Require all granted
</Directory>

DocumentRoot /var/www/wikiman-backend-php/public
<Directory /var/www/wikiman-backend-php/public>
    AllowOverride All
    Require all granted
</Directory>
```

The included `public/.htaccess`:

```apache
DirectoryIndex index.php
Options -Indexes

<IfModule mod_rewrite.c>
    RewriteEngine On

    RewriteCond %{REQUEST_URI} ^/api(?:/|$) [NC]
    RewriteRule ^ - [L]

    RewriteRule ^(?:\.wikiman-installed|\.htaccess)$ - [F,L]

    RewriteRule ^index\.php$ - [L]
    RewriteRule ^install\.php$ - [L]

    RewriteRule ^ index.php [L,QSA]
</IfModule>
```

#### Nginx

Example server block with PHP-FPM. Adjust paths and the PHP socket to match the
host:

```nginx
server {
    listen 80;
    server_name wiki.example.com;
    root /var/www/wikiman-backend-php/public;
    index index.php;

    # Keep private installer markers off the public web.
    location ~* /\.(?:wikiman-installed|htaccess)$ {
        deny all;
    }

    # Same-origin API served by PHAST.
    location /api/ {
        alias /var/www/phastapiv2/;
        index index.php;
        try_files $uri $uri/ @phast;

        location ~ \.php$ {
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $request_filename;
            fastcgi_param HTTP_AUTHORIZATION $http_authorization;
            fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        }
    }

    location = /api {
        return 301 /api/;
    }

    location @phast {
        rewrite ^/api/(.*)$ /api/index.php?/$1 last;
    }

    # Front controller handles install gating, static files, SPA routes, and OG.
    location / {
        rewrite ^ /index.php last;
    }

    location ~ ^/(index|install)\.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    }

    location ~ \.php$ {
        return 404;
    }
}
```

If `data/` is somehow reachable from the web root, deny it explicitly:

```nginx
location ^~ /data/ {
    deny all;
}
```

### 4. Finish in the browser

Open the site. The installer asks for:

- Site title
- First administrator account
- SQLite or MySQL/MariaDB
- Database connection details when MySQL is selected

Settings are written atomically to `data/config.php`, outside the public
document root. Reinstallation is blocked after a healthy setup. If the database
configuration becomes invalid, the installer can be used to repair it.

## Data and security

Keep `data/` outside the web document root. It contains configuration, SQLite
data, uploads, logs, and the generated JWT secret.

Important production settings:

| Setting | Purpose |
| --- | --- |
| `JWT_SECRET` | Explicit JWT signing key; otherwise a random key is generated |
| `PUBLIC_URL` | Public base URL for canonical and Open Graph links |
| `ALLOW_REGISTER` | Controls whether the first writer can register |
| `WIKIMAN_INSTALL_TOKEN` | Protects new installation and is required for recovery |
| `WIKIMAN_DATA_DIR` | Moves the data directory |
| `WIKIMAN_DB_DRIVER` | Selects `sqlite` or `mysql` |

Database-specific variables are documented in the sample configuration and
installer. Never expose or commit `data/config.php`, the JWT secret, the
database, or uploads.

## Backup

The `.wkmbak` format is compatible with the Node backend when SQLite is used.
Restore validates checksums, paths, schema, integrity, and foreign keys before
replacing the current database and uploads.

The current archive format contains a SQLite database. With MySQL/MariaDB, use
your hosting provider's snapshots or `mysqldump`; the Wikiman backup API returns
`501 BACKUP_DRIVER_UNSUPPORTED`.

## What is implemented

- Authentication and first-writer registration
- Categories, posts, search, keywords, trash, and Quick Posts
- Uploads, protected files, settings, and top menu
- Link previews with SSRF protection
- PlantUML proxy with request and response limits
- `.wkmbak` inspection, download, and restore for SQLite
- Server-side Open Graph and Twitter metadata for public posts

API errors use stable codes so the frontend can translate messages into the
selected site language.

## Project layout

| Path | Role |
| --- | --- |
| `domain/` | API handlers grouped by domain |
| `attribute/`, `filter/` | Authentication and writer permissions |
| `libs/common/` | Configuration, database, JWT, upload, and response helpers |
| `libs/installer/` | Installer validation and configuration |
| `public/` | Web document root, PWA, installer, and front controller |
| `data/` | Private runtime data; never expose to the web |

## Related repositories

- [Wikiman hub](https://github.com/kendrickkim/wikiman)
- [Frontend](https://github.com/kendrickkim/wikiman-frontend)
- [Node backend](https://github.com/kendrickkim/wikiman-backend)
- [PHAST API v2](https://github.com/kendrickkim/phastapiv2)
- [Android·iOS app](https://github.com/kendrickkim/wikiman-flutter)
