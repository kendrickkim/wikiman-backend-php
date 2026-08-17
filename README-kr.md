[English](README.md)

# Wikiman PHP Backend

Node.js를 실행할 수 없는 호스팅 환경을 위한
[Wikiman](https://github.com/kendrickkim/wikiman) PHP 백엔드입니다.

[PHAST API v2](https://github.com/kendrickkim/phastapiv2)의 사용자 정의 앱으로
동작하며, [Node 백엔드](https://github.com/kendrickkim/wikiman-backend)와 같은
API 계약을 구현합니다. SQLite와 MySQL/MariaDB를 지원합니다.

## 설치 전 확인

필요한 환경:

- PHP 8.1 이상
- Apache와 `mod_rewrite`, 또는 Nginx와 PHP-FPM
- [PHAST API v2](https://github.com/kendrickkim/phastapiv2)
- `mbstring`, `fileinfo`, `json`, `openssl`, `curl`, `dom`, `zlib`, `gd`
- SQLite용 `pdo_sqlite` 또는 MySQL/MariaDB용 `pdo_mysql`

Node.js를 사용할 수 없는 호스팅이라면 이 백엔드를 선택하세요. Node.js를 실행할
수 있다면 기준 구현인 [Node 백엔드](https://github.com/kendrickkim/wikiman-backend)가
배포하기 더 간단합니다.

## 설치

### 1. 프로젝트 내려받기

```bash
git clone https://github.com/kendrickkim/wikiman-backend-php.git
git clone https://github.com/kendrickkim/phastapiv2.git
git clone https://github.com/kendrickkim/wikiman-frontend.git
```

Git을 사용할 수 없는 공유 호스팅에서는 GitHub의 **Download ZIP**을 사용해도 됩니다.

### 2. 프론트엔드 빌드

```bash
cd wikiman-frontend
npm install
npm run build:php
```

PWA 결과를 `wikiman-backend-php/public/`으로 복사합니다. 이때 PHP 전면
컨트롤러, 웹 설치 화면, `.htaccess`는 그대로 보존됩니다.

### 3. 웹 서버와 PHAST 설정

문서 루트는 `wikiman-backend-php/public/`으로 지정하세요. 같은 도메인의 `/api`에는
PHAST API v2를 연결하고, PHAST가 이 저장소를 사용자 정의 앱으로 읽도록 설정합니다.

```php
// phastapiv2/config.phastapi.php
$G_PHASTAPI_CUSTOM_DIR = "../wikiman-backend-php";
```

Apache와 Nginx 모두 사용할 수 있습니다. 어느 쪽이든 규칙은 같습니다.

- `/api`는 PHAST로 전달
- 사이트 페이지와 정적 파일은 `public/index.php`를 거침
- SPA fallback으로 정적 `index.html`을 직접 반환하지 않음
- `data/`는 웹 문서 루트 밖에 둠

#### Apache

최소 VirtualHost:

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

저장소에 포함된 `public/.htaccess`:

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

PHP-FPM을 사용하는 server 예입니다. 경로와 PHP 소켓은 서버에 맞게 바꿉니다.

```nginx
server {
    listen 80;
    server_name wiki.example.com;
    root /var/www/wikiman-backend-php/public;
    index index.php;

    # 설치 상태 파일은 웹에 노출하지 않습니다.
    location ~* /\.(?:wikiman-installed|htaccess)$ {
        deny all;
    }

    # 동일 오리진 API는 PHAST가 담당합니다.
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

    # 전면 컨트롤러가 설치 게이트, 정적 파일, SPA 라우트, OG를 처리합니다.
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

`data/`가 문서 루트 아래로 노출될 수 있는 배치라면 명시적으로 막습니다.

```nginx
location ^~ /data/ {
    deny all;
}
```

### 4. 웹에서 설치 완료

사이트에 처음 접속하면 다음 값을 입력하는 설치 화면이 열립니다.

- 사이트 제목
- 첫 관리자 계정
- SQLite 또는 MySQL/MariaDB
- MySQL을 선택한 경우 데이터베이스 연결 정보

설정은 문서 루트 밖의 `data/config.php`에 원자적으로 저장됩니다. 정상 설치가
끝나면 재설치는 차단됩니다. DB 설정이 깨졌을 때는 설치 화면에서 복구할 수 있습니다.

## 데이터와 보안

`data/`는 반드시 웹 문서 루트 밖에 두세요. 설정, SQLite DB, 업로드, 로그,
자동 생성된 JWT 비밀키가 저장됩니다.

운영에서 확인할 주요 설정:

| 설정 | 용도 |
| --- | --- |
| `JWT_SECRET` | JWT 서명 키. 없으면 안전한 임의 값 자동 생성 |
| `PUBLIC_URL` | canonical·Open Graph에 사용할 외부 주소 |
| `ALLOW_REGISTER` | 첫 작성자 가입 허용 여부 |
| `WIKIMAN_INSTALL_TOKEN` | 신규 설치 보호 및 복구 인증 |
| `WIKIMAN_DATA_DIR` | 데이터 폴더 위치 변경 |
| `WIKIMAN_DB_DRIVER` | `sqlite` 또는 `mysql` 선택 |

DB별 연결 값은 예제 설정과 설치 화면에서 안내합니다. `data/config.php`, JWT
비밀키, DB, 업로드 파일은 외부에 공개하거나 커밋하면 안 됩니다.

## 백업

SQLite에서는 Node 백엔드와 같은 `.wkmbak` 형식을 사용합니다. 복원 전에 체크섬,
경로, 스키마, 무결성, 외래 키를 검사한 뒤 현재 DB와 업로드를 교체합니다.

현재 `.wkmbak` 형식은 SQLite DB를 담습니다. MySQL/MariaDB에서는 호스팅 업체의
스냅샷이나 `mysqldump`를 사용하세요. Wikiman 백업 API는
`501 BACKUP_DRIVER_UNSUPPORTED`를 반환합니다.

## 구현 범위

- 인증과 최초 작성자 가입
- 카테고리, 글, 검색, 키워드, 휴지통, 간단 포스트
- 업로드, 보호된 파일, 설정, 상단 메뉴
- SSRF를 방어하는 링크 미리보기
- 요청·응답 크기를 제한하는 PlantUML 프록시
- SQLite용 `.wkmbak` 검사·다운로드·복원
- 공개 글의 서버 사이드 Open Graph·Twitter 메타 정보

API 오류는 안정적인 코드를 반환하고, 프론트엔드가 선택한 사이트 언어로 번역합니다.

## 디렉터리 구성

| 경로 | 역할 |
| --- | --- |
| `domain/` | 도메인별 API 처리 |
| `attribute/`, `filter/` | 로그인과 작성자 권한 검사 |
| `libs/common/` | 설정, DB, JWT, 업로드, 응답 공통 기능 |
| `libs/installer/` | 설치 검증과 설정 저장 |
| `public/` | 웹 문서 루트, PWA, 설치 화면, 전면 컨트롤러 |
| `data/` | 비공개 실행 데이터. 웹에 노출하면 안 됨 |

## 관련 저장소

- [Wikiman 허브](https://github.com/kendrickkim/wikiman)
- [프론트엔드](https://github.com/kendrickkim/wikiman-frontend)
- [Node 백엔드](https://github.com/kendrickkim/wikiman-backend)
- [PHAST API v2](https://github.com/kendrickkim/phastapiv2)
- [Android·iOS 앱](https://github.com/kendrickkim/wikiman-flutter)
