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
    auth.php            — регистрация + логин + email-верификация (action: register|login|verify_email|resend_code)
    me.php              — GET текущего юзера + профиль по токену
    offers.php          — GET список офферов / POST создать / PUT редактировать-закрыть (владелец)
    bids.php            — GET мои заявки (freelancer) / список заявок на оффер с ранжированием (client)
                          POST подать заявку со ставкой коннектов
    profile.php         — POST обновить профиль (поля зависят от роли: client/freelancer/admin)
    avatar.php          — POST multipart-загрузка аватара (любая роль), резайз через GD до 200×200
    verification.php    — POST заявка на верификацию (client/freelancer), multipart-загрузка PDF/PNG/JPEG
    notifications.php   — GET мои уведомления / POST mark_read|mark_all_read
    admin.php           — admin-only: GET users|verification_requests|document, POST approve|reject
  /includes
    db.php              — подключение PDO к MySQL (get_db())
    jwt.php             — jwt_encode() / jwt_decode(), секрет в JWT_SECRET
    auth_middleware.php — require_auth() → payload или 401; require_admin() → payload или 403
    image.php           — resize_square_image() — GD-хелпер: center-crop + resample до NxN, сохраняет JPEG
    notifications.php   — create_notification(), notify_admins() — INSERT в таблицу notifications
  /uploads
    avatars/            — публично отдаётся веб-сервером напрямую (аватарки не приватные)
    verification/       — .htaccess (Require all denied) — НЕ отдаётся напрямую, только через
                          admin.php?action=document (стримится PHP после require_admin() + finfo)
  /sql
    schema.sql          — полный слепок структуры БД, см. ниже
  /frontend
    index.html          — редирект-заглушка: смотрит localStorage token → pages/dashboard.html или pages/login.html
    /pages
      login.html         — форма входа (поле теперь type=text — админ логинится не email'ом, а логином "vito")
      register.html      — форма регистрации с выбором роли
      verify.html         — ввод 6-значного кода подтверждения email после регистрации/при логине
      cabinet.html        — личный кабинет (client/freelancer/admin — поля разные), аватар, верификация
      dashboard.html       — рабочий дашборд (офферы клиента / лента фрилансера); редиректит admin → admin.html
      admin.html           — админка: список юзеров + заявки на верификацию (навигация в сайдбаре справа)
      offer.html            — client-only: детали оффера + ранжированный список заявок фрилансеров
    /js
      common.js           — apiFetch/apiPost/apiPut (кидают Error с полными полями ответа, не только .message),
                            esc/val/initials/displayName/avatarUrl/renderAvatarEl/roleBadgeHtml/timeAgo,
                            showError/hideError, requireAuth() (guard + navbar/bell init), logout(),
                            setVerificationBadge(), initNotificationBell()/loadNotifications()/markNotificationRead()
      auth.js             — doLogin() (редирект admin→admin.html по res.role), doRegister(), selectRole()
      verify.js            — doVerify(), doResend() — работают с localStorage.pending_email
      cabinet.js           — профиль (все 3 роли), загрузка аватара, подача заявки на верификацию
      dashboard.js         — офферы клиента, лента фрилансера (+ бейдж/компания клиента), модалка ставки
      admin.js             — список юзеров, заявки на верификацию, approve/reject, просмотр документа
      offer.js              — загрузка оффера + ранжированного списка заявок (bidCard())
    /css
      common.css, auth.css, cabinet.css, dashboard.css, admin.css — по файлу на страницу + общий

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
  closeOffer(id) — confirm() → PUT {id, status:'closed'}. Закрытые офферы (status==='closed') совсем
  пропадают из списка "Мои офферы" (loadClientDashboard() фильтрует offers перед рендером карточек) —
  по правке пользователя. Статы "Офферов размещено"/"Заявок получено" по-прежнему считаются по полному
  списку offers (включая закрытые) — фильтруется только видимая лента, не агрегаты.
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

Личные кабинеты, верификация, уведомления, админка (2026-07-06)

  Большой рефакторинг по запросу пользователя. Ключевое архитектурное решение: имя/фамилия/аватар/
  верификация раньше жили порознь в freelancer_profiles и client_profiles (и админ вообще не имел
  профиля) — перенесены на users как общие для ВСЕХ ролей поля (first_name, last_name, avatar_path,
  verification_status, verification_doc_path, verified_at). Профильные таблицы остались только для
  того, что реально уникально под роль (freelancer: phone/website/specialization/about;
  client: company_name/phone). Существующие full_name/contact_name у 3 реальных аккаунтов на момент
  миграции разнесены по first_name/last_name наивным сплитом по первому пробелу (проверено вручную).

  Видимость полей (правила из ТЗ пользователя):
    - Фамилия клиента — видна только администратору.
    - Фамилия фрилансера — другим показывается как "Имя Ф." (первая буква фамилии + точка),
      администратору — полностью.
    - Email — виден только администратору (пока что).
    - Компания клиента — опциональна; если не указана, фрилансер видит клиента как "Частное лицо".
    ВАЖНО: сейчас в приложении НЕТ ни одной страницы, где один юзер смотрит полный профиль другого
    юзера (кроме админского списка юзеров, где всё показывается без масок по определению) — только
    сам себе (me.php всегда отдаёт полные данные владельцу) и клиент/компания в ленте офферов
    (offers.php: client_company_name / client_verification_status для фрилансера — единственное
    реальное место, где чужие данные сейчас показываются). Поэтому маскировка фамилии фрилансера
    ("Иван И.") пока НЕ реализована — применять её негде, это будет естественная часть будущей
    "детальной страницы оффера / списка заявок" (freelancer-профиль, который увидит клиент).

  Аватар: 200×200, загрузка через POST /api/avatar.php (multipart, поле "avatar"). Валидация через
  finfo (реальный MIME, не расширение/Content-Type от клиента) — только image/jpeg и image/png,
  до 5МБ. includes/image.php: resize_square_image() — center-crop до квадрата (по меньшей стороне),
  imagecopyresampled() до 200×200, сохраняется как JPEG (это GD, встроенное расширение PHP, не
  Composer-библиотека). Если аватара нет — на клиенте рисуется кружок с первой буквой имени на фоне
  (renderAvatarEl() в common.js). Старый файл аватара удаляется при загрузке нового.

  Верификация (галочка "Verificated"): теперь есть у client И freelancer (не только у фрилансера,
  как было раньше) — verification_status ENUM('none','pending','verified','rejected') на users.
  Кнопка "Подать заявку на верификацию" (cabinet.html) грузит паспорт PDF/PNG/JPEG через
  POST /api/verification.php (multipart, поле "document", до 10МБ, MIME-проверка через finfo).
  Файл сохраняется в /project/uploads/verification/ — эта папка закрыта от прямого веб-доступа
  через .htaccess (Require all denied, проверено curl'ом — 403), т.к. паспортные сканы это PII и
  им не место за угадываемым URL. Просмотр документа — только админом, через
  GET /api/admin.php?action=document&user_id=N, который стримит файл после require_admin() +
  finfo-проверки MIME. На фронте (admin.js viewDocument()) это тоже нетривиально: обычный <img src>
  не понесёт наш Bearer-токен, поэтому картинка/PDF грузится через fetch() с заголовком Authorization,
  оборачивается в blob → URL.createObjectURL() и уже так подставляется в <img>/<iframe> модалки.
  При подаче заявки всем админам создаётся уведомление (notify_admins(), type=verification_submitted) —
  это и есть "заявка улетает в консоль админа" из ТЗ: в терминологии этого приложения "консоль" —
  это раздел "Заявки на верификацию" в admin.html + бейдж-счётчик количества + колокольчик уведомлений,
  а не буквальный серверный терминал/лог.
  Одобрение/отклонение — POST /api/admin.php {action: approve|reject, user_id}. approve → verified_at=NOW(),
  уведомление "Ваш профиль верифицирован!". reject → verified_at=NULL, уведомление "Ваша заявка на
  верификацию была отклонена." Повторная подача после отклонения разрешена (протестировано).

  Уведомления: таблица notifications (user_id FK ON DELETE CASCADE, type, message, is_read, created_at).
  Колокольчик в навбаре (общий для dashboard/cabinet/admin) — Bootstrap dropdown, красная точка если
  есть непрочитанные, список подгружается по клику через GET /api/notifications.php (последние 20),
  клик по уведомлению — POST action=mark_read. Никаких других триггеров уведомлений (например на
  новую ставку) пока не заведено — только вокруг верификации, чтобы не тащить недоделанный функционал.

  Админка: логин "vito" / пароль "qwerty12345" (роль admin, email_verified_at проставлен сразу —
  сидировался напрямую в БД, не через публичную регистрацию, т.к. auth.php не разрешает role=admin
  при регистрации). Поле логина на login.html теперь type="text", не type="email" — иначе браузер
  блокирует отправку формы, т.к. "vito" не проходит HTML5-валидацию email-инпута.
  admin.html — двухколоночный layout как у dashboard.html: слева список юзеров или заявок на
  верификацию (переключение — admin.js showSection()), справа сайдбар с профилем админа (аватар,
  имя + золотой бейдж "★ Админ" — roleBadgeHtml() в common.js) и навигацией "Все пользователи" /
  "Заявки на верификацию" (с счётчиком pending) под профилем — как и просил пользователь.
  Админ правит свои Имя/Логin/Аватар через cabinet.html (третья ветка полей, #profile-fields-admin) —
  переиспользует общий профильный флоу, а не отдельную форму внутри admin.html.
  require_admin() в auth_middleware.php — require_auth() + проверка role==='admin', иначе 403.

  Известный тестовый артефакт данных: 3 "боевых" аккаунта пользователя из ручного тестирования
  (vitotestinovich@gmail.com, ragul21412@gmail.com + пара офферов с нецензурными тестовыми
  заголовками) сохранены как есть при миграции — не мои, трогать не стал.

Ставки коннектами (принцип Upwork Connects) — 2026-07-08

  У каждого фрилансера есть баланс коннектов (freelancer_profiles.connects_balance, стартует с 10000 —
  чисто демо-число, никакой монетизации/докупки коннектов не делали, это не спрашивали). Подача заявки
  на оффер (POST /api/bids.php) требует ставку от 10 коннектов (MIN_CONNECTS в bids.php) — можно
  поставить и больше, чтобы подняться в списке у клиента. Ставка списывается с баланса сразу и
  безвозвратно (INSERT в bids + UPDATE баланса — в одной транзакции $db->beginTransaction()/commit()/
  rollBack(), чтобы не списать коннекты без реально созданной заявки при сбое). Тело POST: {offer_id,
  connects, cover_note?} — если connects меньше 10 или больше текущего баланса, 422 с понятной ошибкой.

  Ранжирование для клиента: GET /api/bids.php?offer_id=N (role=client, только владелец оффера — иначе
  404) отдаёт список заявок ORDER BY connects_spent DESC, created_at ASC, дальше в PHP: первые 5 строк
  остаются как есть (топ по ставке), array_slice() от 6-й и дальше — shuffle() (перемешивается заново
  на каждый запрос, стабильного seed'а нет). Протестировано вручную через curl с 7 заявками — топ-5
  стабильно по убыванию ставки, 6-е и 7-е места реально меняются местами от запроса к запросу.

  Наконец-то реальный consumer для маскировки фамилии фрилансера, о котором в прошлой сессии писали
  "применять негде": в этом же GET-ответе last_name режется до "Фамилия" → "Ф." (mb_substr до 1 символа
  + точка) — это ровно тот случай, когда клиент (не админ) смотрит чужой профиль фрилансера.

  Фронтенд: dashboard.html/js — кнопка "Подать заявку" у фрилансера открывает bidModal (вместо прямого
  POST одним кликом) с полем ставки (default 10, min 10) и текущим балансом; после успеха баланс и
  статы обновляются на лету. В сайдбаре фрилансера — статья "Коннектов: N". У клиента на карточке
  оффера — ссылка "Заявки (N)" → pages/offer.html?id=N (это и есть "Детальная страница оффера" из
  прошлого бэклога, реализована здесь заодно, т.к. без неё ранжирование негде было бы показывать).
  offer.html/offer.js: сводка оффера + список заявок карточками (ранг #1-#5 синим бейджем, дальше —
  серым), аватар/имя+инициал фамилии/бейдж верификации/специализация/ставка/cover_note/время подачи.
  Принятие/отклонение конкретной заявки клиентом — НЕ реализовано в этом заходе (bids.status остаётся
  pending всегда, ENUM accepted/rejected пока не используется) — separate scope, не просили в этот раз.

База данных — 6 таблиц

sqlusers                  — id, email (он же логин админа), password_hash, role ENUM(client|freelancer|admin),
                         first_name, last_name, avatar_path, status ENUM(active|banned),
                         verification_status ENUM(none|pending|verified|rejected), verification_doc_path,
                         verified_at, email_verified_at, verification_code, verification_code_expires_at,
                         created_at, updated_at
freelancer_profiles    — id, user_id FK, phone, website, specialization, about (до 3000 символов),
                         connects_balance INT DEFAULT 10000
client_profiles        — id, user_id FK, company_name (опционально), phone
offers                 — id, client_id FK→client_profiles, title, description,
                         budget DECIMAL, deadline DATE, status ENUM(open|in_progress|closed), created_at
bids                   — id, offer_id FK, freelancer_id FK→freelancer_profiles, cover_note,
                         connects_spent INT DEFAULT 10, status ENUM(pending|accepted|rejected), created_at
                         UNIQUE KEY (offer_id, freelancer_id)
notifications          — id, user_id FK ON DELETE CASCADE, type, message, is_read, created_at

Что реализовано (ТАСК 1 + ТАСК 2 + ТАСК 3)

Бэкенд


Регистрация: создаёт запись в users + пустой профиль в freelancer_profiles или client_profiles,
  требует подтверждения email кодом (см. раздел "Email-верификация" ниже) прежде чем выдать JWT
Логин: проверка password_verify(), блокирует неподтверждённые email, возвращает JWT
JWT без библиотек: base64url + HMAC-SHA256, TTL 7 дней
require_auth() — middleware, читает Authorization: Bearer <token>
offers.php: клиент видит свои офферы + счётчик заявок; фрилансер видит все открытые
bids.php: фрилансер подаёт заявку; дубли блокируются на уровне UNIQUE KEY в БД
profile.php: POST обновляет users (first_name/last_name/логин у админа) + профильную таблицу под роль
avatar.php / verification.php / notifications.php / admin.php — см. раздел "Личные кабинеты..." выше
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


 Принятие/отклонение конкретной заявки клиентом (bids.status accepted|rejected сейчас не используется,
   см. раздел "Ставки коннектами" выше — офферы.php/оффер.html показывают заявки, но выбрать
   исполнителя пока нельзя)
 Чат между заказчиком и фрилансером
 Платежи / эскроу


Соглашения в коде


get_db() — singleton PDO, PDO::ERRMODE_EXCEPTION
respond(int $code, array $data) — хелпер для JSON-ответа с exit
val('id') — читает и трimmит input по id
esc(s) — экранирование HTML перед вставкой в DOM
Все FK ссылаются на profile id, не на users.id (важно для джоинов)