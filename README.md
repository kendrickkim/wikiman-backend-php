# Wikiman PHP Backend

Node.js Wikiman 백엔드를 일반 PHP 호스팅에서 실행하기 위한 변환 프로젝트입니다.
PHAST API v2 프레임워크의 사용자 정의 앱으로 동작합니다.

## 요구 사항

- PHP 8.1 이상
- PDO SQLite 및 SQLite3 확장
- mbstring, fileinfo, json, openssl
- Apache `mod_rewrite`

## 배치

일반적인 배치 예시는 다음과 같습니다.

| 항목 | 설명 |
| --- | --- |
| 문서 루트 | 프론트 PWA 빌드 결과 (`index.html`, `assets/`, `icons/` 등) |
| API 진입점 | [phastapiv2](https://github.com/kendrickkim/phastapiv2)를 `/api`로 배치 |
| 앱 코드 | 이 저장소(클론 경로)를 PHAST custom 디렉터리로 연결 |
| API URL | 동일 오리진 `/api` |

프레임워크는 `config.phastapi.php`의 `$G_PHASTAPI_CUSTOM_DIR`로 이 앱을 찾습니다.

```php
// 예: phastapiv2의 config.phastapi.php
$G_PHASTAPI_CUSTOM_DIR = "../wikiman-backend-php";
```

## 설정

환경 변수:

- `APP_ENV`: `development` 또는 `production`
- `JWT_SECRET`: 운영 환경에서 반드시 안전한 값 지정
- `ALLOW_REGISTER`: `false`이면 최초 작성자 가입도 차단
- `WIKIMAN_DATA_DIR`: 기본값 `data/` (앱 루트 기준)

`JWT_SECRET`이 없으면 `data/.jwt-secret`에 64자리 임의 비밀키를 최초 생성합니다.

## 디렉터리

| 경로 | 역할 |
| --- | --- |
| `config.phastapi.override.php` | 프레임워크 설정 덮어쓰기 |
| `attribute/` | 권한 속성 (`WIKI_LOGIN`, `WIKI_WRITER`) |
| `filter/` | 요청 진입 시 인증 검사 |
| `libs/common/` | 설정·응답·JWT·DB·업로드 등 공용 함수 |
| `domain/` | 도메인별 API 핸들러 |
| `data/` | SQLite DB, 업로드 실물, 로그 (웹 접근 차단) |

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

`.wkmbak`은 Node 원본과 같은 형식 버전 1 구조를 사용합니다. 복구 시 체크섬,
압축 해제 크기, 경로, 필수 테이블·컬럼, SQLite 무결성과 외래 키를 검사한 뒤
현재 DB와 업로드를 안전 복사하고 교체합니다.
