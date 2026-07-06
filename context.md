NovaBuild — Context File

Вставь этот файл в начало нового чата чтобы я восстановил контекст.

Проект

Фриланс-маркетплейс для строительной отрасли. Мы (юр. лицо) — посредник между заказчиками и фрилансерами, предоставляем верификацию исполнителей.

Работаем в Украине. Никаких российских дефолтов в проекте: валюта — гривна (₴, локаль uk-UA), телефон — формат +380, не +7. Интерфейс на русском языке остаётся (это язык общения с пользователями), но любые "российские" константы/плейсхолдеры (код страны, валюта, локаль форматирования чисел) недопустимы.

Стек


Фронт: HTML / CSS / JS + Bootstrap 5 (визуал — демка, подтянем позже)
Бэк: PHP (без фреймворков, без Composer)
БД: MySQL
Авторизация: JWT вручную на HMAC-SHA256, токен в localStorage


Структура проекта

/project
  /api
    auth.php            — регистрация + логин (action: register | login)
    me.php              — GET текущего юзера + профиль по токену
    offers.php          — GET список офферов / POST создать оффер
    bids.php            — GET мои заявки / POST подать заявку
    profile.php         — POST обновить профиль (поля зависят от роли)
  /includes
    db.php              — подключение PDO к MySQL (get_db())
    jwt.php             — jwt_encode() / jwt_decode(), секрет в JWT_SECRET
    auth_middleware.php — require_auth() → возвращает payload или 401
  /frontend
    index.html          — редирект-заглушка: смотрит localStorage token → pages/dashboard.html или pages/login.html
    /pages
      login.html         — форма входа
      register.html      — форма регистрации с выбором роли
      verify.html         — ввод 6-значного кода подтверждения email после регистрации/при логине
      cabinet.html        — личный кабинет (общий для обеих ролей, поля разные)
      dashboard.html       — рабочий дашборд (офферы клиента / лента фрилансера)
    /js
      common.js           — apiFetch/apiPost (кидают Error с полными полями ответа, не только .message),
                            esc/val, showError/hideError, requireAuth() (guard), logout(), setVerificationBadge()
      auth.js             — doLogin(), doRegister(), selectRole() — при pending_verification редиректят на verify.html
      verify.js            — doVerify(), doResend() — работают с localStorage.pending_email
      cabinet.js           — загрузка/сохранение профиля
      dashboard.js         — офферы клиента, лента фрилансера, ставки
    /css
      common.css, auth.css, cabinet.css, dashboard.css — по одному файлу на страницу + общий

  ПРИМЕЧАНИЕ: раньше фронтенд был SPA на #hash-роутинге (index.html + app.js + style.css).
  Переведено на классическую многостраничную схему (реальная навигация между .html), т.к. без React
  использовать SPA-подход избыточно для демки. Старые app.js/style.css удалены, их логика
  разнесена по /js и /css выше.

Известный баг и фикс: Apache/OSPanel не прокидывает Authorization

  Симптом: после логина дашборд на секунду отрисовывался и тут же кидало обратно на login.
  Причина: Apache под OSPanel по умолчанию не передаёт заголовок Authorization в $_SERVER —
  require_auth() видел пустую строку и всегда отдавал 401, /me.php падал сразу после логина.
  Подтверждено curl'ом напрямую к /api/me.php.
  Фикс (оба слоя, на случай разных конфигураций Apache у разных разработчиков/хостингов):
    1) includes/auth_middleware.php → get_authorization_header() пробует HTTP_AUTHORIZATION,
       REDIRECT_HTTP_AUTHORIZATION, getallheaders(), apache_request_headers() по очереди.
    2) project/api/.htaccess → RewriteRule прокидывает %{HTTP:Authorization} в HTTP_AUTHORIZATION.

Редизайн dashboard.html/cabinet.html в стиле Upwork (референс от пользователя)

  Навбар (общий для dashboard.html и cabinet.html): бренд + ссылка "Дашборд" + аватарка-кружок
  (инициал имени/email) справа — по клику Bootstrap dropdown с email, ссылкой "Профиль" и "Выйти".
  common.js: requireAuth() дополнительно проставляет инициалы в #nav-avatar.
  dashboard.html — двухколоночный layout (Bootstrap row): слева/по центру (col-lg-8) лента офферов,
  справа (col-lg-4) сайдбар — карточка профиля (аватар, имя, специализация/компания), кнопка
  "Редактировать профиль" → cabinet.html, для клиента кнопка "+ Разместить оффер", для фрилансера —
  бейдж верификации + прогресс-бар "Профиль заполнен" (реальный расчёт: доля заполненных полей
  full_name/phone/specialization/about), плюс отдельная карточка со статами.
  На мобильных сайдбар/карточка профиля показывается выше ленты (order-1/order-2 + order-lg-1/2).
  Карточки офферов (offerCardClient/offerCardFreelancer в dashboard.js) переверстаны под
  upwork-стиль: строка "Опубликовано X назад · Заявок: N" сверху (timeAgo() в common.js — простое
  относительное время без библиотек), затем заголовок/описание, затем бейджи бюджета/срока/статуса.
  Решение (уточнили с пользователем): никаких decorative placeholder-виджетов (Connects/Consultations
  и т.п. из Upwork) — только то, что реально работает. Верхний навбар — без доп. ссылок, только "Дашборд".
  Навбар: бренд + "Дашборд" сгруппированы слева у лого (flex-контейнер), аватарка-дропдаун — справа
  (изначально "Дашборд" был рядом с аватаркой — перенесли по правкам пользователя).

Редактирование и закрытие оффера владельцем

  api/offers.php получил PUT (Access-Control-Allow-Methods обновлён). Только role=client, и только
  если offers.client_id (через client_profiles.user_id) принадлежит текущему JWT-юзеру — иначе 404
  (не палим существование чужого оффера). Тело PUT — частичное: title/description/budget/deadline/status,
  обновляются только переданные поля (array_key_exists, не isset — чтобы можно было явно затирать
  description пустой строкой). status валидируется по ENUM (open|in_progress|closed).
  Фронтенд: модалка #offerModal теперь одна на создание И редактирование — скрытый #offer-id,
  заголовок/текст кнопки переключаются в openCreateOffer()/editOffer(id) (dashboard.js). editOffer()
  берёт данные не из DOM, а из module-level массива clientOffers (заполняется в loadClientDashboard()) —
  так безопаснее, чем сериализовать description с кавычками/переносами строк в HTML-атрибут onclick.
  closeOffer(id) — confirm() → PUT {id, status:'closed'}. Кнопки "Редактировать"/"Закрыть" в
  offerCardClient() скрываются, если offer.status === 'closed' (уже закрытый оффер не трогаем).
  common.js получил apiPut() (зеркало apiPost(), метод PUT).

Email-верификация при регистрации (код на почту)

  users получил 3 новых поля: email_verified_at DATETIME NULL, verification_code CHAR(6) NULL,
  verification_code_expires_at DATETIME NULL (код живёт 10 минут).
  Регистрация (auth.php action=register) больше НЕ выдаёт токен сразу — генерирует 6-значный код
  (random_int(100000,999999)), пишет его в users, шлёт письмо через send_verification_email() (PHP mail())
  и отвечает {pending_verification:true, email}. Фронтенд (auth.js doRegister) кладёт email в
  localStorage.pending_email и редиректит на pages/verify.html.
  Логин (action=login) блокируется, если email_verified_at IS NULL → 403 {pending_verification:true, email}.
  auth.js doLogin() это ловит и точно так же редиректит на verify.html вместо показа ошибки.
  Новые action'ы в auth.php: verify_email (email+code → проверка hash_equals + срок годности → ставит
  email_verified_at=NOW(), чистит код, выдаёт JWT; если уже верифицирован — просто перевыдаёт JWT,
  идемпотентно) и resend_code (email → перегенерирует код, шлёт письмо заново; 400 если уже подтверждён).
  ВАЖНО про mail(): без Composer/библиотек — используется встроенная функция PHP `mail()`.
  На этой машине (OSPanel) php.ini → sendmail_path указывает на modules/sendmail/sendmail.exe -local —
  это локальный "перехватчик", который НЕ отправляет письма в интернet, а сохраняет каждое как .txt
  в userdata/temp/email/. Для демо это удобно (код виден в файле), но в проде sendmail_path/SMTP
  нужно будет настроить на реальный почтовый релей (или переходить на PHPMailer/SMTP-библиотеку).

Слепок БД в /project/sql/schema.sql (обновляется перед каждым коммитом)

  Команда: mysqldump --no-data --routines --triggers --databases novabuild | sed -E 's/ AUTO_INCREMENT=[0-9]+//'
  Только структура (CREATE DATABASE + CREATE TABLE), без данных — репозиторий публичный, реальные
  email/bcrypt-хеши в git-историю не кладём. AUTO_INCREMENT-счётчики вырезаны sed'ом, иначе дамп
  "мигал" бы диффом на каждый закоммиченный тестовый инсерт без реальных изменений схемы.
  Цель: `mysql -uroot < project/sql/schema.sql` на чистой машине — и вся схема (5 таблиц, FK, ENUM'ы)
  разворачивается за один шаг. Обновляется вручную перед каждым коммитом, где менялась схема (а не
  автоматически хуком) — эта договорённость с пользователем действует на все следующие сессии.

База данных — 5 таблиц

sqlusers                  — id, email, password_hash, role ENUM(client|freelancer|admin), status,
                         email_verified_at, verification_code, verification_code_expires_at, created_at
freelancer_profiles    — id, user_id FK, full_name, phone, specialization, about,
                         verification_status ENUM(pending|verified|rejected), doc_path, verified_at
client_profiles        — id, user_id FK, company_name, contact_name, phone
offers                 — id, client_id FK→client_profiles, title, description,
                         budget DECIMAL, deadline DATE, status ENUM(open|in_progress|closed), created_at
bids                   — id, offer_id FK, freelancer_id FK→freelancer_profiles, cover_note,
                         status ENUM(pending|accepted|rejected), created_at
                         UNIQUE KEY (offer_id, freelancer_id)

Что реализовано (ТАСК 1 + ТАСК 2 + ТАСК 3)

Бэкенд


Регистрация: создаёт запись в users + пустой профиль в freelancer_profiles или client_profiles,
  требует подтверждения email кодом (см. раздел "Email-верификация" ниже) прежде чем выдать JWT
Логин: проверка password_verify(), блокирует неподтверждённые email, возвращает JWT
JWT без библиотек: base64url + HMAC-SHA256, TTL 7 дней
require_auth() — middleware, читает Authorization: Bearer <token>
offers.php: клиент видит свои офферы + счётчик заявок; фрилансер видит все открытые
bids.php: фрилансер подаёт заявку; дубли блокируются на уровне UNIQUE KEY в БД
profile.php (ТАСК 3): POST обновляет freelancer_profiles или client_profiles в зависимости от роли из JWT
Все ответы JSON, CORS-заголовки открыты (для демки)
Все файлы api/*.php лежат в /project/api — исправлена ошибка расположения (offers.php и bids.php раньше лежали в /includes и были недоступны фронтенду)


Фронтенд (многостраничный, ТАСК 4 — разбивка на /pages)


pages/login.html + pages/register.html: реальные отдельные страницы (не hash-роутинг), связаны ссылками
Каждая защищённая страница (cabinet.html, dashboard.html) сама вызывает requireAuth() из common.js:
  нет токена или /me.php вернул 401 → редирект на login.html
pages/cabinet.html — личный кабинет: форма профиля, поля переключаются по currentUser.role,
  сохранение через POST /profile.php, после успеха — рефреш currentUser + alert-success
pages/dashboard.html — рабочий дашборд: клиент видит статы + список своих офферов + модалку создания;
  фрилансер видит статы + ленту открытых офферов + кнопку "Подать заявку" + бейдж верификации
Навбар (статический HTML, продублирован в cabinet.html/dashboard.html) со ссылками Дашборд/Профиль/Выйти
Утилиты в js/common.js: apiFetch(), apiPost(), esc() (XSS-защита), showError()/hideError(), requireAuth(), logout()


Что НЕ сделано (следующие таски)


 Загрузка документов на верификацию (фрилансер)
 Детальная страница оффера + список заявок на него
 Admin-панель: просмотр заявок на верификацию, смена статуса
 Чат между заказчиком и фрилансером
 Платежи / эскроу


Соглашения в коде


get_db() — singleton PDO, PDO::ERRMODE_EXCEPTION
respond(int $code, array $data) — хелпер для JSON-ответа с exit
val('id') — читает и трimmит input по id
esc(s) — экранирование HTML перед вставкой в DOM
Все FK ссылаются на profile id, не на users.id (важно для джоинов)