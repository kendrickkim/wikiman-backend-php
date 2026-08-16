# Wikiman PHP Backend

Node.js Wikiman 백엔드를 일반 PHP 호스팅에서 실행하기 위한 변환 프로젝트입니다.
PHAST API v2 프레임워크의 사용자 정의 앱으로 동작합니다.

## 요구 사항

- PHP 8.1 이상
- SQLite 사용 시 `pdo_sqlite`(SQLite 3.27 이상, `VACUUM INTO` 지원)
- MySQL/MariaDB 사용 시 `pdo_mysql`(MySQL 8 또는 MariaDB 10.5 이상 권장)
- mbstring, fileinfo, json, openssl, curl, dom, zlib
- Apache `mod_rewrite`

## 내려받기와 설치

```bash
git clone https://github.com/kendrickkim/wikiman-backend-php.git
git clone https://github.com/kendrickkim/phastapiv2.git
```

Git을 사용할 수 없는 공유 호스팅은 두 저장소의 GitHub **Download ZIP**을 내려받아
압축을 푼 뒤 같은 방식으로 배치할 수 있습니다.

프론트엔드를 빌드할 수 있는 환경이라면:

```bash
git clone https://github.com/kendrickkim/wikiman-frontend.git
cd wikiman-frontend
npm install
npm run build:php
```

`build:php`는 PWA 결과를 `wikiman-backend-php/public/`에 복사하면서 전면 컨트롤러
`index.php`, 설치 UI `install.php`, `.htaccess`를 보존합니다. 미리 빌드된 PWA를 받았다면 그 내용만
`public/`에 복사해도 됩니다.

Apache 문서 루트는 `wikiman-backend-php/public/`로 설정하고 PHAST API는
동일 오리진의 `/api`에 배치합니다.

| 항목 | 설명 |
| --- | --- |
| 문서 루트 | 이 저장소의 `public/` |
| API 진입점 | [phastapiv2](https://github.com/kendrickkim/phastapiv2)를 `/api`로 배치 |
| 앱 코드 | 이 저장소(클론 경로)를 PHAST custom 디렉터리로 연결 |
| API URL | 동일 오리진 `/api` |

프레임워크는 `config.phastapi.php`의 `$G_PHASTAPI_CUSTOM_DIR`로 이 앱을 찾습니다.

```php
// 예: phastapiv2의 config.phastapi.php
$G_PHASTAPI_CUSTOM_DIR = "../wikiman-backend-php";
```

배치 후 사이트에 접속하면 `install.php`가 다음 항목을 받습니다.

- 사이트 제목
- 첫 관리자 아이디·비밀번호
- SQLite 또는 MySQL/MariaDB
- MySQL 선택 시 호스트·포트·DB 이름·사용자·비밀번호

설치 전이거나 DB 설정이 잘못된 동안에는 `index.html`과 정적 자산을 직접 제공하지
않고 설치 화면을 표시합니다. 설치 완료 후 게이트가 PWA 파일과 history route를
제공합니다. 설정은 웹에서 접근할 수 없는 `data/config.php`에 원자적으로 저장됩니다.
정상 동작 중(DB 접속 가능 + 관리자 계정 존재)에는 웹 재설치를 차단하지만, 설정이
깨진 경우에는 같은 화면으로 설정을 다시 저장할 수 있습니다.

## Apache `.htaccess` 예

문서 루트는 `public/`이어야 합니다. 저장소에 포함된 `public/.htaccess`는 다음과
같습니다.

```apache
DirectoryIndex index.php
Options -Indexes

<IfModule mod_rewrite.c>
    RewriteEngine On

    # PHAST API는 Alias 또는 Proxy로 /api에 연결한 뒤, 이 규칙으로 그대로 통과시킵니다.
    RewriteCond %{REQUEST_URI} ^/api(?:/|$) [NC]
    RewriteRule ^ - [L]

    RewriteRule ^(?:\.wikiman-installed|\.htaccess)$ - [F,L]

    RewriteRule ^index\.php$ - [L]
    RewriteRule ^install\.php$ - [L]

    # 정적 파일·index.html·SPA history route는 모두 index.php를 거칩니다.
    RewriteRule ^ index.php [L,QSA]
</IfModule>

<IfModule !mod_rewrite.c>
    DirectoryIndex index.php
</IfModule>
```

`/api`를 같은 VirtualHost에서 PHAST로 연결하는 예:

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

`data/`는 문서 루트 밖에 두는 것이 원칙입니다. 앱 루트의 `data/`가 웹에 노출될
수 있는 배치라면 `data/.htaccess`로 차단합니다.

```apache
Require all denied

<IfModule mod_access_compat.c>
    Deny from all
</IfModule>
```

## 설정 파일과 환경 변수

환경 변수:

- `APP_ENV`: `development` 또는 `production`
- `JWT_SECRET`: 운영 환경에서 반드시 안전한 값 지정
- `ALLOW_REGISTER`: `false`이면 최초 작성자 가입도 차단
- `WIKIMAN_DATA_DIR`: 기본값 `data/` (앱 루트 기준)
- `WIKIMAN_DB_DRIVER`: `sqlite` 또는 `mysql` (설정 파일 대신 환경 변수 사용 시)
- `WIKIMAN_SQLITE_PATH`
- `WIKIMAN_MYSQL_HOST`, `WIKIMAN_MYSQL_PORT`, `WIKIMAN_MYSQL_DATABASE`
- `WIKIMAN_MYSQL_USERNAME`, `WIKIMAN_MYSQL_PASSWORD`, `WIKIMAN_MYSQL_CHARSET`
- `WIKIMAN_APP_DIR`: 문서 루트의 설치 게이트에서 앱 경로를 찾을 때 사용
- `WIKIMAN_FRONTEND_DIR`: PWA 파일이 `public/` 외부에 있을 때 사용
- `WIKIMAN_INSTALL_TOKEN`: 설정하면 신규 설치에도 동일 토큰 입력을 요구하며, 기존 설치 복구에는 필수
- `PUBLIC_URL`: `og:url`, `og:image`, canonical의 외부 기준 URL (예: `https://wiki.example.com`)

`JWT_SECRET`이 없으면 `data/.jwt-secret`에 64자리 임의 비밀키를 최초 생성합니다.
기존 `data/wiki.db`가 있는 배포는 별도 설치 파일 없이 SQLite 설치로 인식합니다.

## 디렉터리

| 경로 | 역할 |
| --- | --- |
| `config.phastapi.override.php` | 프레임워크 설정 덮어쓰기 |
| `attribute/` | 권한 속성 (`WIKI_LOGIN`, `WIKI_WRITER`) |
| `filter/` | 요청 진입 시 인증 검사 |
| `libs/common/` | 설정·응답·JWT·DB·업로드 등 공용 함수 |
| `libs/installer/` | 설치 검증·설정 저장·설치 화면 |
| `domain/` | 도메인별 API 핸들러 |
| `public/index.php` | 설치 상태 검사, 안전한 정적 파일 제공, SPA fallback, OG/Twitter 메타 주입 |
| `public/install.php` | 독립 실행되는 설치 화면 진입점 |
| `public/` | 프론트 PWA와 PHP 전면 컨트롤러(문서 루트) |
| `data/` | 설정, SQLite DB, 업로드 실물, 로그 (웹 접근 차단) |

## 변환 완료 API

| 도메인 | 엔드포인트 |
| --- | --- |
| health | `GET /health` |
| auth | `GET /auth/status`, `POST /auth/register`, `POST /auth/login`, `GET /auth/me` |
| categories | `GET/POST /categories`, `PATCH/DELETE /categories/{id}` |
| posts | 목록·검색·키워드·홈페이지·상세·CRUD·휴지통·복원·영구삭제·첨부 다운로드 |
| quick-posts | 목록·상세·생성·수정·삭제·정식 글로 승격 |
| uploads | 이미지·파비콘·첨부 업로드, 미사용 파일 조회·정리 |
| files | `GET /files/{name}` |
| settings | `GET/PATCH /settings`, `GET/PUT /settings/top-menu` |
| link-preview | 미리보기 조회, SSRF 방어, 캐시 통계·삭제 |
| backup | `.wkmbak` 내려받기·검사·전체 복원 |
| plantuml | 소스/인코딩 경로 SVG 프록시, 요청·응답 크기 제한 |

카테고리 관리에는 `GET /categories/{id}/post-stats`와
`POST /categories/{id}/reassign-posts`가 포함됩니다. 글 목록은 `pageSize` 1~100과
`includeContent=true|1`을 지원합니다.

설정 API는 사이트 언어, 블로그 모드·페이지 크기·홈페이지 선표시, 코드 줄 번호,
오른쪽 메뉴 기본 열림, 간단 포스트 에디터를 포함합니다. 신규 설치의 간단 포스트
기본 에디터는 `tui`입니다.

## Open Graph / 소셜 공유

문서 루트의 `index.php`가 정적 자산과 SPA HTML을 직접 제공합니다. `/posts/{id}`를
요청하면 익명 사용자에게 공개된 발행 글에 한해 제목, 본문 요약, 첫 본문 이미지 또는
첫 이미지 첨부를 `og:*`, Twitter Card, article 시간, canonical 태그로 `index.html`에
주입합니다. 비공개·초안·삭제 글과 로그인이 필요한 카테고리 글은 사이트 기본 메타만
노출합니다.

리버스 프록시에서는 정적 `index.html`로 직접 fallback하지 말고 PHP 문서 루트로
요청을 전달해야 합니다. 절대 URL이 정확해야 하는 운영 환경에서는 `PUBLIC_URL`을
지정하세요.

오류 응답은 프론트엔드가 사이트 언어에 맞게 번역할 수 있도록 안정 코드와 선택적
매개변수를 반환합니다.

```json
{ "error": "CATEGORY_NOT_FOUND" }
```

`.wkmbak`은 Node 원본과 같은 형식 버전 1 구조를 사용합니다. 복구 시 체크섬,
압축 해제 크기, 경로, 필수 테이블·컬럼, SQLite 무결성과 외래 키를 검사한 뒤
현재 DB와 업로드를 안전 복사하고 교체합니다.
백업 DB는 WAL 파일을 직접 복사하지 않고 SQLite `VACUUM INTO`로 일관된
스냅샷을 만든 뒤 아카이브에 넣습니다.

현재 `.wkmbak` 형식 버전 1은 SQLite 파일을 담는 형식이므로 MySQL에서는 백업
다운로드·검사·복원 API가 `501 BACKUP_DRIVER_UNSUPPORTED`를 반환합니다. MySQL은
호스팅 업체의 스냅샷 또는 `mysqldump`를 함께 사용하세요.
