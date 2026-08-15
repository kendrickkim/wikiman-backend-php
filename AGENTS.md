# Wikiman PHP Backend

Node.js `wikiman-backend`를 일반 PHP 호스팅용으로 포팅하는 프로젝트다.
[PHAST API v2](https://github.com/kendrickkim/phastapiv2) 프레임워크의 **사용자 정의 앱**으로 동작한다.

## 환경

- PHP 8.1+ · PDO SQLite · Apache mod_rewrite
- 앱 코드: 이 저장소 루트
- 프레임워크: phastapiv2 (`config.phastapi.php`의 `$G_PHASTAPI_CUSTOM_DIR`로 이 앱을 지정)
- API 주소: 동일 오리진 `/api`
- DB와 업로드: `data/` (커밋 및 직접 웹 접근 금지)

## 계약

- 기준 구현은 [wikiman-backend](https://github.com/kendrickkim/wikiman-backend).
- [wikiman-frontend](https://github.com/kendrickkim/wikiman-frontend)가 기대하는 경로와 JSON 형태를 유지한다.
- 성공 응답을 `success/data`로 감싸지 않는다.
- 오류는 `{ "error": "한국어 메시지" }`와 정확한 HTTP 상태 코드를 사용한다.
- 인증은 `Authorization: Bearer` 우선, `wikiman_token` 쿠키를 보조로 사용한다.
- draft/private는 작성자만, 비공개 카테고리 트리는 익명에게 숨긴다.

## 구조 (PHAST 규약)

- `config.phastapi.override.php` — 도메인 경로, 로그, 응답 포매터, pre-router 등록
- `attribute/attribute.php` — `WIKI_LOGIN`, `WIKI_WRITER` 권한 속성
- `filter/filter.php` — 속성을 읽어 인증을 강제하는 IN 필터
- `libs/common/wiki.*.php` — 설정·응답·JWT·DB·요청·도메인 공용 함수 (요청마다 자동 로드)
- `domain/<이름>/<이름>.php` — `#[_GET_]` 등 속성으로 선언한 API 핸들러

`/api/posts/...` 요청이 오면 프레임워크가 `domain/posts` 만 include 하므로,
여러 도메인이 함께 쓰는 함수는 반드시 `libs/common`에 둔다.

## 응답과 오류 처리

- 성공: `return wiki_ok($body, $status)`
- 실패: 어디서든 `wiki_abort($status, "메시지")` (즉시 출력 후 종료)
- 프레임워크의 `PReturn()`은 `$G_PHASTAPI_RESPONSE_FORMATTER`(= `wiki_format_response`)를 거쳐
  라우팅 실패·예외까지 모두 Wikiman 규격으로 나간다.
- 트랜잭션이 필요하면 `wiki_transaction(function (PDO $db) { ... })`를 쓴다.

## 프레임워크를 고칠 때

앱 전용 로직은 절대 프레임워크에 넣지 않는다. 현재 추가된 공통 기능은 다음뿐이다.

- `PATCH` 메서드 지원 (`_PATCH_` 속성/함수, `PHASTAPI_CORE::patch`)
- `$G_PHASTAPI_RESPONSE_FORMATTER` 훅 (앱 고유 응답 규격)

## 금지

- `.jwt-secret`, `data/wiki.db`, `data/uploads/`를 커밋하지 않는다.
- SQL 문자열에 사용자 입력을 직접 결합하지 않는다. (항상 바인딩한다)
- PHAST의 자체 토큰(`PHASTAPI_AUTH`)을 Wikiman 인증에 사용하지 않는다.
- 사용자가 요청하지 않은 커밋·푸시를 하지 않는다.
