NovaBuild — Context File

Вставь этот файл в начало нового чата чтобы я восстановил контекст.

Проект

Фриланс-маркетплейс для строительной отрасли. Мы (юр. лицо) — посредник между заказчиками и фрилансерами, предоставляем верификацию исполнителей.

Работаем в Украине. Никаких российских дефолтов в проекте: валюта — гривна (₴, локаль uk-UA), телефон — формат +380, не +7. Интерфейс на русском языке остаётся (это язык общения с пользователями), но любые "российские" константы/плейсхолдеры (код страны, валюта, локаль форматирования чисел) недопустимы.

Стек


Фронт: HTML / CSS / JS + Bootstrap 5 (визуал — демка, подтянем позже)
Бэк: PHP 8.2 (без фреймворков, без Composer)
БД: MySQL / MariaDB 10.11 (на тестовом хостинге)
Авторизация: JWT вручную на HMAC-SHA256, токен в localStorage
Пароли: Argon2id + серверный pepper (includes/password.php), fallback на bcrypt
Антибрутфорс: DB-based rate limiting (таблица rate_limit_hits, includes/rate_limit.php) — Redis на шаред-хостинге нет
Реалтайм-чат: Pusher Channels через REST вручную (HMAC-подпись, без SDK), фолбэк на polling
Почта: PHP mail() (на хостинге — реальная отправка, From: no-reply@test2.2ezweb.tech)


Деплой и окружение — ВАЖНО (2026-09-10)

Проект льётся сразу на тестовый сервак по FTP (test2.2ezweb.tech), локального прогона обычно нет.
Из-за этого настройки «весёлые»: боевые для тест-сервера значения секретов лежат прямо в репозитории —
осознанный компромисс для одноразового тест-стенда, НЕ баг, не «чинить» и не паниковать:
  - includes/db.php       — DB kotegg007_novabuild / kotegg007_gospodin / only4test (шаред-хостинг, cPanel-префикс)
  - includes/jwt.php      — реальный 64-hex JWT_SECRET (плейсхолдер убран)
  - includes/password.php — реальный 64-hex PASSWORD_PEPPER
  - includes/pusher.php   — реальные Pusher app_id / key / secret (кластер eu)
На хостинге НЕТ shell / mysqldump / cron. Следствия: слепок схемы через mysqldump больше не делаем
(см. «Слепок БД» ниже), а все «отложенные» операции (грейс-период оффера, разморозка холда, чистка
rate_limit_hits) считаются на лету в запросах или чистятся оппортунистически, без крона.
Если проект поедет в прод — все 4 файла с секретами перегенерить и вынести из репы.


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
    admin.php           — admin-only: GET users|verification_requests|document|withdrawal_requests|disputes;
                          POST approve|reject (верификация) | approve_withdrawal|reject_withdrawal |
                          resolve_dispute {hold_id, resolution:to_client|to_freelancer}
    offer_attachments.php — GET список файлов оффера (любая роль) / POST загрузка (владелец-клиент,
                          multipart, поле files[]) / DELETE удалить один файл (владелец-клиент)
    conversations.php  — GET мои переписки (+ последнее сообщение / непрочитанные) / GET ?id=N одна /
                          POST {action:start, other_user_id} — client-only, find-or-create по паре клиент+фрилансер
    messages.php       — GET ?conversation_id=N (&after_id=M для polling) — только участник; помечает
                          входящие read_at при чтении / POST {conversation_id, body} — INSERT + pusher + уведомление
    contracts.php      — GET мои контракты (?conversation_id / ?status) / POST propose (client) |
                          accept|reject (freelancer, статус proposed) | close {outcome:success|failure}
    contract_change_requests.php — GET ?contract_id (последний pending) / POST propose (любая сторона,
                          активный контракт) | respond {request_id, accept} (только вторая сторона)
    escrow.php         — POST {action:deposit, contract_id, amount, method} — client-only, активный контракт,
                          method card|gpay|liqpay (заглушки, карту на бэк не шлём); плюсует escrowed_amount
    disputes.php       — GET ?contract_id (текущий холд) / POST {action:raise, contract_id} — freelancer-only,
                          пока холд pending и release_at в будущем; notify_admins
    wallet.php         — freelancer-only: GET {balance, requests[]} / POST {action:request_withdrawal, amount}
                          (резерв средств сразу, возврат при отклонении админом)
    finance.php        — GET ?period=month|year|all — ряд «пришло/ушло» по wallet_transactions, бакеты с нулями
    connects.php       — POST {action:purchase, amount, method} — freelancer-only, докупка коннектов
                          (1 ₴ = 1 коннект, заглушка оплаты). Отменяет заметку «докупку коннектов не делали»
    freelancer_profile.php — GET ?user_id — client-only, peer-view профиля фрилансера (фамилия только инициалом)
    pusher_config.php  — GET (авторизованный) — публичный Pusher key + cluster + флаг configured
    pusher_auth.php    — POST — подпись подписки на private-conversation-{id}, проверяет что вызывающий участник
  /includes
    db.php              — PDO к MySQL (get_db()); тут же глушится display_errors + кастомный error_handler
                          (чтобы PHP-warning не ломал JSON-ответ), боевые креды тест-сервера
    jwt.php             — jwt_encode() / jwt_decode(), JWT_SECRET (реальный 64-hex, не плейсхолдер)
    auth_middleware.php — require_auth() → payload или 401; require_admin() → payload или 403
    image.php           — resize_square_image() — GD-хелпер: center-crop + resample до NxN, сохраняет JPEG
    notifications.php   — create_notification(), notify_admins() — INSERT в таблицу notifications
    password.php        — hash_password() / verify_and_upgrade_password() — Argon2id + pepper, прозрачный
                          апгрейд старых голых bcrypt-хешей при успешном входе
    rate_limit.php      — rate_limit_check/record/clear(), client_ip() — скользящее окно по rate_limit_hits
    pusher.php          — pusher_trigger() (fire-and-forget, глотает все Throwable, timeout 3с),
                          pusher_channel_auth() — REST Pusher вручную, без SDK
  /uploads
    avatars/            — публично отдаётся веб-сервером напрямую (аватарки не приватные)
    verification/       — .htaccess (Require all denied) — НЕ отдаётся напрямую, только через
                          admin.php?action=document (стримится PHP после require_admin() + finfo)
    offers/             — публично отдаётся напрямую (файлы офферов не приватные — их и так видят
                          все фрилансеры в открытых офферах)
    .htaccess          — НОВЫЙ: Options -Indexes + запрет исполнения .php/.pl/.py/... во всём uploads/
                          (defense-in-depth против upload→RCE)
  /sql
    (пусто) — schema.sql удалён: на FTP-хостинге нет mysqldump. Схема теперь синкается так — пользователь
    экспортит БД из phpMyAdmin тест-сервера и кидает .sql в чат (последний: kotegg007_novabuild.sql)
  /frontend
    index.html          — редирект-заглушка: смотрит localStorage token → pages/dashboard.html или pages/login.html
    /pages
      login.html         — форма входа (поле теперь type=text — админ логинится не email'ом, а логином "vito")
      register.html      — форма регистрации с выбором роли
      verify.html         — ввод 6-значного кода подтверждения email после регистрации/при логине
      cabinet.html        — личный кабинет (client/freelancer/admin — поля разные), аватар, верификация
      dashboard.html       — рабочий дашборд (офферы клиента / лента фрилансера); редиректит admin → admin.html
      admin.html           — админка: юзеры + заявки на верификацию + заявки на вывод + спорные контракты
      offer.html            — client-only: детали оффера + ранжированный список заявок фрилансеров
      chats.html           — инбокс переписок (client/freelancer)
      chat.html            — переписка + ХАБ всего жизненного цикла контракта (баннер контракта, меню
                             «Доп. функции»: предложить оффер / accept-reject / оплатить эскроу /
                             изменить условия / закрыть / оспорить)
      contracts.html       — мои контракты: предложенные / активные / история (client/freelancer)
      connects.html        — докупка коннектов (freelancer): выбор суммы + демо-форма карты
      finance.html         — финансовый отчёт: график пришло/ушло по периодам (client/freelancer)
      freelancer-profile.html — client-only: профиль фрилансера (открывается из списка заявок / из чата)
    /js
      common.js           — apiFetch/apiPost/apiPut/apiDelete/apiUpload (кидают Error с полными полями
                            ответа, не только .message), esc/val/initials/displayName/assetUrl/
                            truncateText/renderAvatarEl/roleBadgeHtml/timeAgo/formatFileSize/fileIcon,
                            renderAttachments() (превью картинок + строки файлов), галерея
                            (openGallery/closeGallery/galleryStep), showError/hideError,
                            requireAuth() (guard + navbar/bell init), logout(), setVerificationBadge(),
                            initNotificationBell()/loadNotifications()/markNotificationRead()
      auth.js             — doLogin() (редирект admin→admin.html по res.role), doRegister(), selectRole()
      verify.js            — doVerify(), doResend() — работают с localStorage.pending_email
      cabinet.js           — профиль (все 3 роли), загрузка аватара, подача заявки на верификацию
      dashboard.js         — офферы клиента, лента фрилансера (+ бейдж/компания клиента), модалка ставки
                            (+ сообщение + файлы оффера), модалка деталей оффера (openOfferDetail),
                            загрузка/удаление файлов оффера в модалке редактирования
      admin.js             — список юзеров, заявки на верификацию, approve/reject, просмотр документа
      offer.js              — загрузка оффера + ранжированного списка заявок (bidCard()) — карточки теперь
                            ссылаются на freelancer-profile.html + кнопка «✉ Написать» (старт переписки)
      chats.js             — список переписок (chatRow())
      chat.js              — сообщения (Pusher или polling на 4с) + весь UI контракта / эскроу / споров /
                            изменения условий (баннер + меню «Доп. функции» + модалки propose/change/deposit)
      contracts.js         — три секции контрактов (pending / active / history), contractCard()
      connects.js          — шаги «сумма → способ оплаты → подтверждение», демо-карта
      finance.js           — рисует график по /finance.php своим SVG, без библиотек
      freelancer-profile.js — грузит /freelancer_profile.php, кнопка «Написать»
      ДОБАВЛЕНО в common.js: formatUAH()/formatCardNumber()/formatCardExpiry(), setupCabinetTabs(),
                            переключение nav-ссылок «Чат»/«Контракты» (только client/freelancer)
    /css
      common.css, auth.css, cabinet.css, dashboard.css, admin.css, chat.css, connects.css, finance.css
      — по файлу на страницу + общий. Кэш-бастинг ?v=N сейчас на v=8 (см. конвенцию ниже)

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
  ОБНОВЛЕНО 2026-09-10: проект живёт на FTP-хостинге (test2.2ezweb.tech), где mail() реально
  отправляет письма (From: NovaBuild <no-reply@test2.2ezweb.tech>). Абзац ниже про OSPanel-перехватчик
  актуален только для редкого локального прогона.
  На машине OSPanel php.ini → sendmail_path указывает на modules/sendmail/sendmail.exe -local —
  это локальный "перехватчик", который НЕ отправляет письма в интернet, а сохраняет каждое как .txt
  в userdata/temp/email/. Для демо это удобно (код виден в файле), но в проде sendmail_path/SMTP
  нужно будет настроить на реальный почтовый релей (или переходить на PHPMailer/SMTP-библиотеку).

Слепок БД — БОЛЬШЕ НЕ ВЕДЁТСЯ (2026-09-10)

  project/sql/schema.sql удалён. Причина: проект теперь живёт на FTP-хостинге, где нет доступа к
  mysqldump / shell, старая команда `mysqldump --no-data ...` неприменима. Схема синкается вручную:
  пользователь экспортит БД из phpMyAdmin тест-сервера и кидает .sql-файл в чат, когда он нужен
  (последний — kotegg007_novabuild.sql, MariaDB 10.11, PHP 8.2). Прежняя договорённость «обновлять
  schema.sql перед каждым коммитом со схемой» отменена — файла больше нет.

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

Медиафайлы офферов + сообщение фрилансера при отклике — 2026-07-08

  Новая таблица offer_attachments (offer_id FK ON DELETE CASCADE, file_path, original_name, mime_type,
  size, created_at) — у оффера может быть много файлов, отдельная таблица, не колонка. Файлы лежат в
  uploads/offers/ — отдаются публично напрямую (не приватные, их и так видит любой фрилансер по
  открытому офферу), в отличие от uploads/verification/.

  api/offer_attachments.php: GET (любая роль, просто по offer_id — фрилансеру тоже нужно смотреть
  файлы перед тем как решить подавать заявку) / POST multipart (только владелец-клиент, поле files[] —
  можно сразу несколько файлов, каждый валидируется независимо и не прошедшие проверку молча
  пропускаются, а не роняют весь запрос) / DELETE (только владелец-клиент, по id одного файла).
  Разрешены: png/jpg (image/png, image/jpeg через finfo), pdf (application/pdf через finfo), docx/xlsx —
  это ZIP-контейнеры, finfo на них честно отвечает "application/zip" почти всегда, поэтому валидация
  двухступенчатая: (1) finfo MIME должен быть каноничным OOXML-типом ИЛИ application/zip, (2) ZipArchive
  реально открывает файл как валидный zip (расширение PHP `zip`, проверил что установлено) — так
  переименованный в .docx текстовый файл гарантированно отсеивается, проверено вручную (curl не умеет
  нормально грузить multipart с полем files[] на этой машине — баг конкретно тестового curl.exe
  8.12.1 mingw64 на брекетах в имени поля, обошёл тестированием через PHP CURLFile напрямую; реальный
  браузер это поле шлёт без проблем, это не баг приложения). Лимит 15МБ на файл.

  Фронтенд: модалка оффера (dashboard.html/js) — file input (multiple, accept png/jpg/pdf/docx/xlsx),
  при редактировании существующего оффера подгружается список уже прикреплённых файлов с кнопкой
  удаления каждого; при сохранении оффера (create или edit) новые выбранные файлы грузятся отдельным
  запросом сразу после успешного создания/обновления самого оффера (offer_id уже известен).
  Бейдж "📎 N" на карточке оффера в обеих лентах (offers.php отдаёт attachment_count подзапросом).
  Модалка ставки (bidModal) при открытии подгружает и показывает файлы оффера через общий
  renderAttachments() (см. раздел "Модалка деталей оффера + галерея" ниже) — фрилансер видит, что
  приложил клиент, прежде чем решать ставку.

  Сообщение фрилансера при отклике: bids.cover_note — колонка была в схеме с самого начала, но
  фронтенд её никогда не заполнял. Теперь в bidModal есть textarea "Сообщение заказчику", уходит в
  POST /api/bids.php как есть (бэкенд уже принимал это поле, менять не пришлось).
  Усечение до 3 абзацев на стороне клиента: offer.js coverNoteHtml() режет note.split('\n') до первых
  3 непустых строк, остальное прячет за display:none и ссылкой "Показать полностью" (toggleCoverNote()
  меняет местами .d-none у preview/full блоков). ВАЖНО: усечение чисто визуальное на фронте — API
  (GET /api/bids.php?offer_id=N) всегда отдаёт cover_note целиком, проверено вручную через curl
  5-абзацным сообщением. Решение отрезать по переносам строк (не по символам) — это буквальная
  трактовка "первые три абзаца" из ТЗ.

Модалка деталей оффера + галерея картинок — 2026-07-08

  Проблема: фрилансер мог увидеть прикреплённые файлы только открыв bidModal (т.е. уже на пути
  подать заявку) — негде было просто посмотреть оффер целиком. Плюс описание в карточках ленты
  было нигде не ограничено по длине, хотя по ТЗ должно быть.

  Карточки офферов (offerCardClient/offerCardFreelancer, dashboard.js): описание обрезается
  truncateText(o.description, 150) (common.js — режет по символам, добавляет "…"), заголовок стал
  кликабельной ссылкой (.offer-title-link, common.css) → openOfferDetail(offerId).
  openOfferDetail() — новая модалка #offerDetailModal (dashboard.html): полное описание (white-space:
  pre-wrap — сохраняет переносы строк, в отличие от карточки), статусные бейджи, у фрилансера ещё и
  блок "Заказчик: company_name/Частное лицо + бейдж верификации" (у клиента на своих офферах этот блок
  пустой — незачем показывать самому себе кто он), кнопка "Подать заявку" (только фрилансеру и только
  если ещё не подавал — bidOfferIds теперь module-level Set, не локальная переменная внутри
  loadFreelancerDashboard(), чтобы modal мог её читать), и список файлов через общий renderAttachments().
  Клик "Подать заявку" в этой модалке закрывает offerDetailModal и открывает bidModal только после
  события hidden.bs.modal (не сразу) — иначе Bootstrap путается с двумя backdrop'ами от двух модалок
  подряд.

  renderAttachments(container, attachments) (common.js) — общая функция для offerDetailModal, bidModal
  и client-facing offer.html: картинки (image/*) рендерятся превьюшками 84×84 (.attachment-thumb) в
  сетке, клик открывает fullscreen-галерею; остальные файлы (pdf/docx/xlsx) — как раньше, ссылки-строки
  со скачиванием. Галерея — НЕ bootstrap-модалка (стекинг двух bootstrap-модалок даёт двойной backdrop
  и путаницу с прокруткой body), а простой fixed-position оверлей (#image-gallery-overlay, z-index 1090,
  выше обычных модалок) с картинкой на весь экран, кнопками ‹/› (мульти-фото — скрыты если фото одно),
  счётчиком "N / M" и закрытием по крестику/клавише Escape/стрелками ←→ (keydown-листенер в common.js) —
  ровно "открывается в полный экран с возможностью закрыть и вернуться назад" из ТЗ: закрытие просто
  прячет оверлей, offerDetailModal/offer.html остаются под ним как были.
  Разметка оверлея продублирована в dashboard.html и offer.html (как навбар — общий паттерн проекта),
  JS-логика (openGallery/closeGallery/galleryStep/renderGalleryImage) — только в common.js.

  Заодно переименовал avatarUrl() → assetUrl() в common.js (и все вызовы в offer.js/dashboard.js) —
  функция никогда не была специфична для аватарок, уже использовалась для путей файлов офферов через
  неудобные ручные "../../" + path в паре мест; теперь один явно-общий хелпер для любого пути,
  сохранённого относительно /project/.

ВАЖНО — исправлена утечка загруженных файлов в git (2026-07-08)

  При коммите личных кабинетов (`git add -A`) в репозиторий случайно попали 6 реальных загруженных
  файлов (3 аватарки + 3 "документа" верификации реальных аккаунтов) — они не были test-артефактами,
  которые я чищу перед каждым коммитом, а настоящими файлами, которые пользователь параллельно
  загрузил через приложение, тестируя фичи. Проверил содержимое каждого — все 6 оказались шуточными
  картинками (коты, скриншот рекламного сайта), не паспортами/PII, но сам факт, что пользовательский
  контент попал в публичный репозиторий, противоречит уже принятому правилу "структура папок в git,
  содержимое — нет" (см. schema.sql — только структура БД, без данных, по той же причине).
  Фикс: .gitignore теперь игнорирует содержимое uploads/avatars/, uploads/verification/, uploads/offers/
  (кроме .gitkeep/.htaccess — структура папок и защита остаются в репозитории), 6 файлов убраны из
  git-трекинга (git rm --cached — сами файлы остаются на диске, ими продолжает пользоваться приложение).
  Решение с пользователем: из истории git НЕ вычищать (контент безобидный, а переписывание истории +
  force-push — риск ради косметики) — если понадобится позже, это git filter-repo + force push.
  На будущее: перед `git add -A` в этом проекте — проверять `git status`, не попадает ли что-то из
  uploads/, если .gitignore почему-то не сработал.

Ограничение размеров медиафайлов в модалках + кэш-бастинг статики (2026-07-08)

  Жалоба: картинка вылезала за пределы попапа. Разметка/CSS для этого уже были правильные
  (.attachment-thumb — фикс 84×84 + object-fit:cover; #gallery-image — inline max-width:90vw;
  max-height:85vh; object-fit:contain), но всё это появилось буквально в предыдущем коммите —
  наиболее вероятная причина: браузер отдавал старый закэшированный common.css/common.js (обычная
  многостраничная навигация, а не SPA, но common.css/js подключались без query-параметра версии,
  так что кэш/bfcache браузера мог пережить деплой).
  Фикс (сделал оба слоя, не только диагноз):
    1) Кэш-бастинг: все локальные <link>/<script> на все css/js (common/auth/cabinet/dashboard/admin +
       все *.js) теперь ...css?v=2 / ...js?v=2 на всех 7 страницах. КОНВЕНЦИЯ НА БУДУЩЕЕ: при любом
       изменении общих или страничных css/js — поднимать ?v=N сразу на всех тегах во всех страницах
       (не только на изменённом файле — так проще не забыть и не гадать, где именно нужно).
       CDN-теги (bootstrap с jsdelivr) не трогать, у них своя версия в самом URL.
    2) Доп. страховка в common.css на случай, если проблема была не только в кэше: .attachment-thumb
       получил явные max-width/max-height 84px (дублирует width/height, но не помешает); новое правило
       ".modal-body img { max-width: 100% }" — любая случайно забытая без класса картинка внутри
       модалки теперь физически не может вылезти за её границы; новый класс .attachment-file-link
       (overflow-wrap: anywhere) на строках файлов в renderAttachments()/attachmentRow() — длинное имя
       файла без пробелов раньше могло вытолкнуть контейнер вбок, теперь переносится.

════════════════════════════════════════════════════════════════════════════
Крупный заход 2026-09-10: чат, контракты, эскроу, споры, кошелёк, безопасность
(делалось в другой сессии на другом устройстве — этот блок восстанавливает контекст)
════════════════════════════════════════════════════════════════════════════

Общая картина нового флоу сделки:
  лента офферов → фрилансер ставит заявку коннектами → клиент открывает список заявок (offer.html),
  смотрит профиль фрилансера (freelancer-profile.html) и жмёт «✉ Написать» → создаётся conversation →
  весь дальнейший цикл идёт в chat.html: клиент «Предлагает оффер» (contract proposed) → фрилансер
  accept/reject → active → клиент вносит депозит в эскроу (escrow.php) → любая сторона может
  предложить изменение цены/срока (contract_change_requests) → закрытие: success (деньги фрилансеру)
  или failure (возврат клиенту + холд на 7 дней, который фрилансер может оспорить).

Чат (conversations + messages; api/conversations.php, api/messages.php)
  Одна переписка на пару client+freelancer (UNIQUE). Начать может только клиент (POST {action:start,
  other_user_id} — find-or-create). messages.php: GET отдаёт историю (или ?after_id=N для polling-
  дозагрузки) и тут же помечает входящие read_at=NOW(); POST шлёт сообщение (body ≤ 5000), пишет
  уведомление 'new_message' второй стороне и дёргает pusher_trigger('new-message'). Доступ — строго
  участник переписки, иначе 403. Страницы: chats.html (инбокс: последнее сообщение + счётчик
  непрочитанных коррелированными подзапросами), chat.html (переписка + хаб контракта).

Реалтайм — Pusher Channels вручную (includes/pusher.php)
  Без SDK/Composer: pusher_trigger() сам собирает REST-запрос к api-eu.pusher.com и подписывает
  HMAC-SHA256. Сбой отправки не фатален (try/catch(\Throwable) — отсутствующий curl_* в PHP 8 это
  \Error; timeout 3с): чат продолжает работать на polling. api/pusher_config.php — GET
  (авторизованный) отдаёт публичный key+cluster и флаг configured. api/pusher_auth.php — POST,
  подписывает подписку на private-conversation-{id}, предварительно проверив что вызывающий участник.
  Фронт (chat.js): если configured и подключён Pusher-JS — подписка на канал, иначе setInterval 4с
  с ?after_id. Ключи Pusher реально лежат в pusher.php (тест-стенд, см. «Деплой и окружение»).

Контракты (contracts; api/contracts.php)
  status: proposed → active → completed | failed | rejected.
  ОТМЕНЕНО 2026-10-13: «один proposed/active контракт на переписку» — теперь между одним клиентом
  и фрилансером может идти сколько угодно контрактов параллельно (см. «Параллельные контракты»).
  - propose: только клиент, в своей переписке. offer_id — необязательный контекст (проставляется
    если чат начали из заявки на конкретный оффер); чужой/битый id молча обнуляется.
  - accept/reject: только фрилансер, только из proposed. accept → active и штампует
    offers.outdated_at = NOW() у оффера-источника.
  - close {outcome: success|failure}: права асимметричны. Клиент закрывает когда угодно единолично.
    Фрилансер — только если последнее сообщение в переписке его И ему ≥ 14 дней
    (FREELANCER_CLOSE_SILENCE_DAYS, «клиент пропал»), проверяется вживую по messages, без крона.
    success → escrowed_amount уходит на freelancer_profiles.balance + 2 строки wallet_transactions
    (contract_payment у клиента, contract_earning у фрилансера), контракт completed, эскроу обнулён.
    failure → escrowed_amount возвращается на client_profiles.balance + строка 'deposit' + строка
    в balance_holds (release_at = NOW() + 7 дней, HOLD_DAYS), контракт failed.

Изменение условий (contract_change_requests; api/contract_change_requests.php)
  Любая сторона активного контракта предлагает new_price и/или new_deadline (один pending за раз).
  Отвечает только ВТОРАЯ сторона (свой запрос подтвердить нельзя, 403). На accept новые значения
  применяются к contracts. GET ?contract_id отдаёт последний pending (для баннера в чате).

Эскроу (api/escrow.php)
  POST {action:deposit, contract_id, amount, method}. Только клиент, только активный контракт.
  method ∈ card|gpay|liqpay — все ЗАГЛУШКИ: реального процессинга нет, поля карты на бэкенд не
  отправляются (фронт держит их у себя). MIN 10 / MAX 1 000 000 ₴. Плюсует contracts.escrowed_amount,
  пишет 'deposit' в wallet_transactions, шлёт 'escrow_deposit' фрилансеру. UI — кнопка «💳 Оплатить
  контракт» на баннере активного контракта в чате (chat.js openDepositModal/submitDeposit).
  С 2026-10-13: сначала списывается доступный баланс клиента, остаток — картой (см. «Заход 2026-10-13
  (вторая половина)», п.1). UI теперь в contract-actions.js cmOpenDeposit/cmSubmitDeposit.

Кошелёк и вывод (freelancer_profiles.balance / client_profiles.balance, wallet_transactions,
withdrawal_requests; api/wallet.php)
  У фрилансера и клиента появился денежный баланс (DECIMAL). wallet.php (freelancer-only):
  GET → {balance, requests[]}; POST {action:request_withdrawal, amount} — MIN 1 ₴, одна pending-заявка
  за раз, amount ≤ balance. Деньги списываются с баланса СРАЗУ (резерв), возвращаются автоматически
  если админ отклонит. Одобрение/отклонение — в админке (admin.php approve_withdrawal /
  reject_withdrawal): approve финализирует и пишет 'withdrawal' в wallet_transactions, reject
  возвращает сумму на баланс + уведомление. me.php: фрилансер теперь получает fp.balance; клиент —
  cp.balance и frozen_amount (SUM холдов ещё в 7-дневном окне ИЛИ под спором). Кабинет (cabinet.js):
  блок баланса для обеих ролей, модалка вывода у фрилансера.

Споры и холды (balance_holds; api/disputes.php)
  Контракт закрыт как failure → депозит клиента вернулся ему, но «подвис» холдом на 7 дней.
  disputes.php: GET ?contract_id — текущий pending-холд (участник); POST {action:raise, contract_id}
  — только фрилансер, пока холд pending, release_at в будущем и disputed_at пуст; ставит
  disputed_at=NOW() и notify_admins('contract_disputed'). Спор блокирует разморозку и после 7 дней
  (в me.php frozen_amount учитывает disputed_at IS NOT NULL бессрочно). Админка: GET disputes
  (холды pending + disputed), POST resolve_dispute {hold_id, resolution: to_client|to_freelancer};
  to_freelancer перекидывает сумму client.balance → freelancer.balance + wallet_transactions, обе
  стороны получают уведомление.
  ИСПРАВЛЕНО 2026-10-13: resolve_dispute писал в balance_holds.resolution 'to_client'/'to_freelancer',
  которых нет в ENUM ('pending','released_to_client','released_to_freelancer'). Теперь API по-прежнему
  принимает to_client|to_freelancer, а в БД пишет 'released_' . $resolution. Все остальные места
  (me.php frozen_amount, disputes.php, список споров) читают только 'pending' — их не задело.

Финансовый отчёт (api/finance.php; finance.html/js)
  GET ?period=month|year|all → временной ряд «пришло/ушло» из wallet_transactions (фрилансер:
  contract_earning vs withdrawal; клиент: deposit vs contract_payment), бакеты предзаполнены нулями
  чтобы разрывы не рисовались прямой линией. finance.js рисует график своим SVG, без библиотек.
  (Комментарий в шапке finance.php про «только withdrawal реально пишется» устарел — эскроу-флоу
  уже пишет все типы.)

Докупка коннектов (api/connects.php; connects.html/js)
  POST {action:purchase, amount, method} — freelancer-only. 1 ₴ = 1 коннект, MIN 10 / MAX 100 000 ₴,
  method — та же заглушка card|gpay|liqpay, карта на бэк не уходит. Плюсует connects_balance,
  уведомление 'connects_purchased'. ОТМЕНЯЕТ прежнюю заметку в разделе «Ставки коннектами»
  («никакой монетизации/докупки коннектов не делали»).

Профиль фрилансера для клиента (api/freelancer_profile.php; freelancer-profile.html/js)
  GET ?user_id — только клиент. Peer-view: first_name + last_name_initial (только инициал!), avatar,
  verification_status, specialization, about. Открывается из карточек заявок в offer.html и из чата.
  bids.php GET теперь дополнительно отдаёт freelancer_user_id для этих ссылок.

«Неактуальные» офферы (offers.outdated_at; offers.php)
  По офферу активировался контракт (contracts.php accept) → в оффер пишется outdated_at=NOW().
  offers.php GET для фрилансера: OUTDATED_GRACE_HOURS = 24 — первые сутки оффер показывается с
  флагом is_outdated=true (бейдж «Неактуален» в dashboard.js), потом вообще выпадает из ленты.
  Всё считается в SQL на каждом запросе, крона нет. Клиент свои офферы всегда видит целиком.

Безопасность (новые includes/)
  - password.php: Argon2id (fallback bcrypt) + серверный PEPPER (HMAC-SHA256 до password_hash).
    verify_and_upgrade_password() прозрачно переупаковывает старые голые bcrypt-хеши при успешном
    входе, без принудительного сброса. auth.php register/login переведены на эти функции.
  - rate_limit.php: скользящее окно по таблице rate_limit_hits (Redis на шаред-хостинге нет).
    Бакеты в auth.php: register_ip 10/1ч, login_email 5/15м, login_ip 20/15м, verify_email 5/10м,
    resend_code 3/10м. Чистка таблицы оппортунистическая (1 шанс из 50 при записи удаляет строки
    старше суток), крона нет.
  - db.php: display_errors=0 + log_errors=1 + кастомный set_error_handler, который логирует и
    ГЛОТАЕТ warning/notice — иначе случайный PHP-warning печатается в тело ответа и ломает
    res.json() на фронте (и светит пути). Плюс боевые креды тест-БД (см. «Деплой»).
  - jwt.php: JWT_SECRET заменён с плейсхолдера на реальный 64-hex.
  - project/api/.htaccess: аккуратнее пробрасывает Authorization (E=HTTP_AUTHORIZATION).
  - project/uploads/.htaccess (новый): Options -Indexes + Require all denied на .php/.pl/.py/...
    во всём uploads/ — defense-in-depth против upload→RCE.

Админка расширена (admin.html/js)
  В сайдбаре два новых раздела помимо «Все пользователи» / «Заявки на верификацию»: «Заявки на
  вывод» и «Спорные контракты», у каждого бейдж-счётчик pending. Грузятся при входе в админку.

Навбар: ссылки «Чат» (chats.html) и «Контракты» (contracts.html, изначально была подписана «В
работе», переименована 2026-09-16) — только client/freelancer, для админа скрыты (common.js
requireAuth()). Кэш-бастинг статики поднят до ?v=8.


Полноценная вкладка контрактов — 2026-09-10 (позже в тот же день)

  Раньше contracts.html был просто списком карточек, каждая вела в chat.html — весь функционал
  контракта жил только в чате (chat.js renderExtraActions/renderContractBanner). Переделали в
  самостоятельную страницу:
  - Вверху nav-pills «Активные» (status proposed + active) / «Завершённые» (completed + failed +
    rejected), у каждой вкладки бейдж-счётчик. Ниже — список карточек контрактов (contractCard()).
  - Клик по карточке → модалка #contractModal: статус, вторая сторона, цена, срок, эскроу
    (только для active), дата создания, ссылка на оффер (только клиенту), + контекстные блоки
    (предложенное изменение условий с «Принять/Отклонить», статус холда/спора, кнопка «Оплатить
    контракт» клиенту). В футере — «Открыть переписку» (chat.html?conversation_id=…).
  - В шапке модалки СЛЕВА кнопка «⋮» (dropdown, #contract-actions-menu) — контекстные действия:
    подтвердить/отклонить (freelancer, proposed), «Изменить условия (цена/срок)» → #changeModal,
    «Закрыть: выполнен/не выполнен» (клиент всегда; фрилансер — только если его последнее
    сообщение в переписке и ему ≥ 14 дней), «Оспорить закрытие» (freelancer, failed + активный
    холд). Если действий нет (completed/rejected, proposed у клиента) — кнопка «⋮» скрыта.
  - Бэкенд: contracts.php GET дополнен подзапросами last_message_sender_id / last_message_at
    (по conversation), чтобы фронт мог показать доступность одностороннего закрытия фрилансером.
    Больше НИЧЕГО в API не менялось — все действия (accept/reject/close, change propose/respond,
    escrow deposit, dispute raise) уже существовали, contracts.js их переиспользует.
  - Модалки #changeModal и #depositModal — копии из chat.html (Bootstrap не умеет вложенные
    модалки, поэтому contracts.js прыгает между ними: openChild() прячет contractModal и по его
    hidden.bs.modal показывает дочернюю; дочерняя по своему hidden возвращает contractModal).
    submitChange()/submitDeposit() — локальные аналоги функций из chat.js, после успеха зовут
    refreshOpenContract() (перезапрос списка + перерисовка открытой модалки).
  - Кэш-бастинг статики поднят ?v=8 → ?v=9 на всех 13 страницах.
  - Дублирование логики контракта между chat.js и contracts.js осознанное; если разрастётся —
    вынести в общий contracts-actions.js.


Номер контракта + контракты как «окошки» в чате — 2026-09-10 (ещё позже в тот же день)

  Проблема: в чате контракт был singleton (chat.js currentContract = самый новый), а между двумя
  людьми контрактов за жизнь переписки может быть много. Реальный баг: после failed-контракта #1
  (с холдом, оспоримым 7 дней) клиент предлагает #2 — и фрилансер терял доступ к «Оспорить» для #1,
  потому что баннер показывал только #2.

  Номер контракта (6 цифр). includes/contract_number.php — contract_number(int $id): string.
  Детерминированный скрэмблинг id (Кнут: (id * 2654435761) % 900000 + 100000), НЕ хранимая колонка,
  без миграции. Множитель взаимно прост с 900000 → биекция, коллизий нет. Отдаётся как поле
  `number` в contracts.php GET и подставляется во ВСЕ человекочитаемые строки (описания
  wallet_transactions, тексты уведомлений) в contracts.php / escrow.php / disputes.php /
  contract_change_requests.php / admin.php (+ admin.js disputeCard). Старые записи в БД остаются
  со старым «#<id>» — это только историческая косметика.

  Общий модуль frontend/js/contract-actions.js (грузится и chat.html, и contracts.html, ПЕРЕД
  их page-скриптами). Владеет #contractModal + #changeModal + #depositModal. API:
    ContractModal.init({ me, getContract, reload, showChatLink })  — вызвать раз на DOMContentLoaded
    openContractModal(id)  — глобалка, вешается на onclick карточек
  Хост держит свой массив контрактов; getContract(id) достаёт объект из него, reload() —
  перезапрос+перерисовка списка хоста. После любого действия модуль зовёт reload() и заново
  читает контракт через getContract() → перерисовывает открытую модалку. Экшены-глобалки для
  inline onclick: cmAccept/cmReject/cmClose/cmRespondChange/cmRaiseDispute/cmOpenChange/
  cmSubmitChange/cmOpenDeposit/cmSubmitDeposit. Константы CONTRACT_STATUS_LABELS/CONTRACT_STATUS_BADGE
  тоже отсюда. contracts.js похудел до списка+вкладок; вся модалочная логика ушла в модуль.

  chat.js переписан: вместо currentContract — массив contracts (ВСЕ по переписке). #contract-banner
  → #contract-cards: стопка компактных «окошек» (contractChatCard()), по одному на контракт,
  новые сверху: «📄 Контракт #834721 [статус] · цена · срок», подсказка (proposed → «ждёт вашего
  решения»; active+client с недоплатой → «требуется оплата»), кнопка «Открыть» → openContractModal.
  Все действия — в модалке, привязаны к КОНКРЕТНОМУ контракту. Хедерный dropdown «Доп. функции»
  убран → кнопка #propose-contract-btn (client, только если нет proposed/active — совпадает с
  409-гардом). «Предложить другой контракт» для фрилансера = отклонить текущий → клиент предложит
  новый (у фрилансера своего propose в API нет). loadContracts() крутится на setInterval 10с
  (события контракта не приходят по message-каналу). chat.js больше НЕ трогает контракты из
  appendMessage; freelancerCanClose теперь в модуле, по полям last_message_* из API.

  Кэш-бастинг ?v=9 → ?v=10 на всех 13 страницах. Схема БД не менялась (номер — производный).


Правки по фидбеку 2026-09-10 (вечер)

  - Чат: контракты занимали слишком много места сверху. Перенёс #contract-cards из полосы над
    сообщениями в правый сайдбар (chat.html теперь row g-4 → col-lg-8 чат + col-lg-4 карточка
    «Контракты»). Кнопка «＋ Контракт» переехала из хедера чата в шапку этого сайдбара. Карточки
    контракта стали mini: p-2, короткий бейдж статуса (MINI_STATUS в chat.js), без кнопки
    «Открыть» — кликается вся карточка. Список скроллится сам (.chat-contracts-list max-height
    60vh в chat.css). Пустое состояние — «Контрактов пока нет.».
  - Офферы на дашборде: кликом открывалась деталь только по тайтлу (была ссылка .offer-title-link).
    Теперь вся карточка role="button" onclick="offerCardClick(event, id)" — offerCardClick
    открывает openOfferDetail, но пропускает клики, попавшие в реальный контрол
    (event.target.closest('a, button')), чтобы «Подать заявку»/«Редактировать»/«Заявки (N)»
    работали как раньше. Тайтл снова обычный <h6>. Класс .offer-title-link удалён.
  - Оверфлоу текста в модалке деталей оффера (длинная строка без пробелов вылезала за диалог):
    common.css — .modal-body { overflow-wrap: break-word } как общий предохранитель +
    #offer-detail-description { overflow-wrap: anywhere }. Плюс .card[role="button"] — курсор и
    hover-тень (общее для офферных и контрактных карточек).
  - Кэш-бастинг ?v=10 → ?v=11 на всех 13 страницах.


Смета + типы контрактов + пункт по материалам — 2026-09-10 (поздний вечер)

  ⚠️ ТРЕБУЕТ МИГРАЦИИ БД: project/sql/2026-09-10_staged_contracts_estimates.sql — прогнать один раз
  в phpMyAdmin тест-сервера. Без неё contracts.php GET падает (нет колонок type/parent_contract_id).

  СМЕТА (contract_estimates) — документ фрилансера с материалами, до контракта, привязан к
  conversation. api/contract_estimates.php: GET ?conversation_id (участник) / POST submit (freelancer,
  items[{name,qty,unit,unit_price}] + note, total считается на бэке, один 'submitted' за раз) /
  POST respond (client, accept|reject). Уведомления estimate_submitted/accepted/rejected. UI —
  карточка «Смета» в сайдбаре чата + #estimateModal (редактор строк у фрилансера / просмотр +
  Утвердить/Отклонить у клиента). Утверждённая смета передаётся в contracts.php propose как
  estimate_id (хранится в contracts.estimate_id, чисто справочно). Только в чате, не на contracts.html.

  Два типа контракта. contracts.type ENUM('full','staged'). Этап = обычная строка contracts с
  parent_contract_id (self-FK, ON DELETE CASCADE) + seq + title — переиспользует ВЕСЬ жизненный цикл
  (accept/reject/close/escrow/dispute/change) без нового кода.
    - propose type=full: как раньше.
    - propose type=staged: создаётся parent (status=proposed, price=0, свой offer_id/estimate_id) +
      этап 1 (proposed). Фрилансер жмёт accept на ЭТАПЕ → каскад: parent → active (и бейдж оффера).
      reject единственного этапа → parent → rejected.
    - add_stage (новый action, client): следующий этап можно сформировать только когда предыдущий
      completed/failed/rejected. seq = max+1, status=proposed.
    - close на staged-parent (новый ветвь): каскад — proposed-этапы → rejected, active-этапы →
      failure (возврат клиенту + 7-дневный холд через refund_with_hold()), parent → completed/failed.
    - escrow.php и contract_change_requests.php: депозит и смена условий по staged-parent
      заблокированы (409) — только по этапам.
    - GET /contracts.php: WHERE parent_contract_id IS NULL (этапы не в списке), для staged-parent
      подшивается c.stages[] + агрегаты c.price (сумма не-rejected этапов) и c.escrowed_amount.
    pay_freelancer()/refund_with_hold() вынесены в хелперы contracts.php (шарятся close + каскад).

  Пункт «кто покупает материалы» — ВЫПИЛЕН ИЗ КОДА 2026-10-13, см. раздел «Наработки на будущее:
  кто покупает материалы» ниже. Сейчас материалы всегда на заказчике.

  Фронт: contract-actions.js — модалка теперь знает про staged-parent (агрегат + список этапов
  cmStagesSection + «＋ Сформировать этап» → #stageModal, cmSubmitStage) и про этап (заголовок
  «Этап N · #num», «↑ открыть» к parent). getContract хостов ищет и во вложенных c.stages
  (getContractById в chat.js и contracts.js). #proposeModal получил радио типа контракта + поле
  названия 1-го этапа (onProposeTypeChange переключает). #stageModal и #estimateModal —
  продублированы в chat.html (+ #stageModal в contracts.html). contracts.js: бейдж «поэтапный · N эт.».

  Кэш-бастинг ?v=11 → ?v=12 на всех 13 страницах.


Тупик после закрытия этапа — 2026-09-15

  Миграцию пользователь прогнал на test2.2ezweb.tech, всё завелось. Баг-репорт: «после закрытия
  этапа не могу открыть новый». Функционал add_stage работал и до этого (client, последний этап
  terminal → можно добавить) — проблема была чисто в UX: закрыв этап, попадаешь на карточку
  ЭТОГО закрытого этапа, а на ней не было ни одной кнопки — чтобы увидеть «＋ Сформировать
  следующий этап» надо было вручную нажать мелкую ссылку «↑ открыть» наверх к родителю
  (cmStagesSection рисует кнопку только на карточке ПАРЕНТА).

  Правка в contract-actions.js:
  - cmRenderExtra: для completed/failed СТАДИИ (isStage) теперь всегда рисует
    cmStageNextStepPanel(c) — но только если это ПОСЛЕДНИЙ этап родителя и сам родитель ещё active
    (иначе у более раннего завершённого этапа уже есть преемник — панель не нужна).
  - Панель показывает то, что реально доступно роли: client → «＋ Сформировать следующий этап»
    и «Закрыть контракт: выполнен/не выполнен»; freelancer → те же кнопки закрытия, только если
    freelancerCanClose(parent) (14 дней тишины клиента), иначе просто текст «ждите клиента».
  - cmAddStageFromStage() / cmCloseParentFromStage(outcome) — обе сначала переключают _cmOpen
    на родителя через openContractModal(parent_contract_id) (модалка уже открыта, это просто
    перерисовка), потом дёргают cmOpenStage()/cmClose() как обычно — так contract_id в
    add_stage/close всегда уходит родительский, а не id закрытого этапа.
  - Заодно добавил на failed-этапе/контракте общий алерт «❌ закрыт как невыполненный» для ОБЕИХ
    сторон — раньше такое сообщение видел только фрилансер (через _cmHold, который клиенту не
    грузится), клиент при просмотре failed-контракта не видел вообще никакого текста.
  - «Закрыть контракт» / «Закрыть этап» как отдельные действия уже существовали и работали
    (⋮-меню на родителе / на этапе) — тут не баг, просто их тоже не было видно с карточки
    закрытого этапа, теперь дублируются в новой панели для скорости.

  Кэш-бастинг ?v=12 → ?v=13 на всех 13 страницах.


Бейдж «Уже найден работник» — 2026-09-15

  Механизм offers.outdated_at уже существовал (ставится в contracts.php::accept при активации
  полного контракта ИЛИ первого этапа поэтапного — через $offerToBadge), просто назывался иначе
  и жил только 24 часа. Поправил под формулировку задачи:
  - offers.php: OUTDATED_GRACE_HOURS 24 → 48 (2 дня) — после этого оффер пропадает из ленты
    фрилансера целиком (условие в SQL, без cron, считается на каждый GET).
  - is_outdated теперь считается для ОБЕИХ ролей (раньше — только для фрилансерской ленты);
    клиент тоже видит бейдж на своих карточках, хотя фильтрации по нему для клиента как не было,
    так и нет — он продолжает видеть все свои офферы, включая закрытые контрактом.
  - dashboard.js: бейдж переименован «Неактуален» → «Уже найден работник» и перенесён наверх
    карточки (offerCardClient, offerCardFreelancer) отдельной строкой над «Опубликовано/Заявок»,
    вместо того, как было — вперемешку с бейджами бюджета/срока внизу. Тот же текст в модалке деталей
    оффера (offer-detail-badges).

  Кэш-бастинг ?v=13 → ?v=14 на всех 13 страницах.


Смета — после подписания контракта, не до — 2026-09-15

  Изначально построил смету как pre-контрактный артефакт (свободно составляется в переписке,
  утверждённая смета опционально прицеплялась к contracts.estimate_id при propose). Пользователь
  поправил порядок: смета составляется ПОСЛЕ подписания контракта, не до.

  - api/contract_estimates.php action=submit: теперь сначала ищет активный контракт/этап в этой
    переписке (SELECT ... WHERE conversation_id=? AND status='active' ORDER BY (parent_contract_id
    IS NULL) ASC — этап предпочтительнее родителя, если оба active), и без него — 409 «Смету можно
    составить только после подписания контракта». После вставки сметы делает
    UPDATE contracts SET estimate_id=? WHERE id=<найденный активный контракт> — так colonна
    contracts.estimate_id наконец получила осмысленное значение («смета ПОД этот контракт»,
    проставляется бэкендом, а не клиентом) вместо мёртвого поля, которое я сначала добавлял
    как forward-reference при propose.
  - api/contracts.php propose: убрал целиком приём/валидацию/запись estimate_id (клиент больше
    его не передаёт — на момент propose никакой сметы физически ещё быть не может, раз она теперь
    строго после подписания).
  - frontend/js/chat.js: убрал acceptedEstimateId и его прокидывание в submitPropose(). Добавил
    hasSignedContract() (contracts.some(c => c.status==='active') — для поэтапного контракта это
    сам parent, статус которого становится active после принятия этапа 1). canComposeEstimate()
    теперь требует hasSignedContract() в дополнение к роли freelancer и «нет сметы или последняя
    отклонена». renderEstimatePanel() без подписанного контракта показывает «Смета составляется
    после подписания контракта» и прячет кнопку «Составить» — независимо от роли. Просмотр уже
    поданных смет (даже после того как контракт закрылся) остаётся доступен как есть — это
    историческая запись, её не блокирую.
  - Убрал мёртвый #propose-estimate-hint из chat.html (никогда не был подключен, а теперь и
    подавно не нужен — смета к моменту propose просто не существует).

  Кэш-бастинг ?v=14 → ?v=15 на всех 13 страницах. Схема БД не менялась.


Оффер-пикер при предложении контракта + смета явно привязана к контракту — 2026-09-16

  ⚠️ ТРЕБУЕТ МИГРАЦИИ БД: project/sql/2026-09-16_estimate_contract_link.sql (добавляет
  contract_estimates.contract_id) — прогнать в phpMyAdmin перед деплоем этих правок.

  1) Оффер-пикер в «Предложить контракт». Раньше offer_id прокидывался в контракт молча — только
     если чат был открыт по ссылке из отклика на конкретный оффер (?offer_id= в URL), без какого-
     либо выбора в самой форме. Теперь:
     - #proposeModal получил <select id="propose-offer-select"> со списком ОТКРЫТЫХ офферов этого
       клиента (status='open' И !is_outdated — офферы, под которые уже есть подписанный контракт
       с кем-то другим, из списка исключены, даже если формально ещё не закрыты руками).
     - Выбор оффера подтягивает его budget → propose-price и deadline → propose-deadline
       (applyOfferToProposeForm) — пользователь может их потом поправить, это не залипает.
     - Кнопка «＋ Контракт» больше не data-bs-toggle, теперь onclick="openProposeModal()" — эта
       функция сбрасывает форму, дёргает /offers.php (chat.js: loadClientOffers(), только для
       role client), рисует опции и, если чат открыт из контекста конкретного оффера
       (contextOfferId) и тот всё ещё в списке открытых — предвыбирает его.
     - submitPropose() теперь берёт offer_id из значения селекта, а не из contextOfferId напрямую.

  2) Смета явно привязана к контракту, а не только к переписке. Раньше «текущий» контракт для
     сметы каждый раз ВЫЧИСЛЯЛСЯ заново на submit (активный этап/контракт в переписке), а на
     самой записи contract_estimates это нигде не сохранялось — только forward-указатель
     contracts.estimate_id в одну сторону. Проблема: «уже висит одна смета на рассмотрении»
     проверялась по conversation_id — просроченная неотвеченная смета на уже ЗАКРЫТОМ контракте
     блокировала бы подачу новой сметы под СЛЕДУЮЩИЙ контракт в той же переписке.
     - sql/2026-09-16_estimate_contract_link.sql: contract_estimates.contract_id INT NULL
       (FK → contracts.id, ON DELETE CASCADE).
     - api/contract_estimates.php submit: тот же поиск активного контракта/этапа (этап
       предпочтительнее родителя), но теперь contract_id пишется прямо в саму запись сметы, и
       проверка «есть необработанная смета» теперь WHERE contract_id = ? (не conversation_id).
     - frontend/js/chat.js: добавил currentActiveContractId() (JS-зеркало того же предпочтения
       backend'а: активный этап > родитель) и estimateForCurrentContract() — ищет в estimates[]
       именно ту, что contract_id === текущий активный, а не просто estimates[0] (это был
       отдельный баг, который я сам внёс и тут же поймал при доработке: без этого фильтра
       принятая смета по СТАРОМУ закрытому контракту не давала фрилансеру составить новую под
       свежеподписанный). canComposeEstimate()/openEstimateModal()/renderEstimatePanel() теперь
       все идут через currentEstimate() = estimateForCurrentContract() пока контракт активен,
       иначе — последняя в переписке (для просмотра истории). Панель и модалка сметы показывают
       «контракт #NNNNNN» / «этап #NNNNNN» рядом с суммой (estimateContractRef(), резолвит через
       уже загруженный contracts[] на клиенте, без похода на бэкенд).

  Кэш-бастинг ?v=15 → ?v=16 на всех 13 страницах.


Смета — часть карточки контракта/этапа + статус оплаты для фрилансера — 2026-09-16 (вечер)

  Пользователь поправил прошлую версию: смета не должна быть отдельной панелью в чате,
  «привязанной» к переписке — она должна быть ЧАСТЬЮ конкретного контракта (полный) или этапа
  (поэтапный), т.е. видна и управляема прямо внутри карточки ЭТОГО контракта/этапа. Плюс отдельный
  запрос: фрилансер должен явно видеть, оплатил ли клиент депозит (резерв) — чтобы понимать,
  можно ли начинать работу.

  1) СМЕТА переехала внутрь contract-actions.js (модуль карточки контракта, общий для chat.html
     и contracts.html) — убрана как самостоятельная сущность «по переписке».
     - api/contract_estimates.php переписан вокруг contract_id как единственного ключа:
       GET ?contract_id=, submit {contract_id, items, note}, участник проверяется напрямую по
       contracts.client_user_id/freelancer_user_id (contract_participant_or_404, без похода через
       conversations). submit отклоняет попытку составить смету на САМ поэтапный контракт
       (parent_contract_id IS NULL && type=staged) — только на конкретный этап или полный
       контракт — и требует status='active' у этой конкретной записи. Больше никакого поиска
       «активного контракта в переписке» — вызывающий уже открыл нужную карточку, id известен.
       Уведомления теперь называют номер контракта/этапа (contract_number()).
     - contract-actions.js: новое состояние _cmEstimates (история сметы ИМЕННО этого contract_id,
       грузится в cmLoadDetails() вместе с change/hold, только для status !== 'proposed' и не для
       staged-parent). cmEstimateSection(c) рисуется в cmRenderExtra() для active/completed/failed
       под алертом статуса оплаты — статус, кнопки «Составить»/«Открыть смету»/«Утвердить»/
       «Отклонить», всё завязано на _cmOpen, а не на какой-то «текущий контракт переписки».
       cmOpenEstimate/cmSubmitEstimate/cmRespondEstimate + перенесённые без изменений
       estimateRowHtml/addEstimateRow/recalcEstimate/estimateReadonlyHtml (были в chat.js).
       #estimateModal добавлен в hop-list contractModalInit (наравне с change/deposit/stage) —
       модалка сметы открывается тем же приёмом (contractModal прячется → estimateModal
       показывается → при закрытии обратно).
     - #estimateModal-разметка (была только в chat.html) продублирована в contracts.html — смету
       теперь можно вести и через страницу «Контракты», не только из чата.
     - chat.html: убрана отдельная карточка «Смета» из сайдбара (estimate-panel/estimate-btn) —
       весь блок этого функционала выпилен и из chat.js (loadEstimates, currentActiveContractId,
       estimateForCurrentContract, currentEstimate, canComposeEstimate, estimateContractRef,
       renderEstimatePanel, submitEstimate, respondEstimate и т.д. — единственное, что осталось
       в chat.js по теме сметы, это комментарий-указатель на contract-actions.js).

  2) Статус оплаты (резерва) виден фрилансеру. Новые шаренные хелперы в contract-actions.js:
     - paymentStatus(c) → null (не активен / staged-родитель / нет цены) | 'full' | 'partial' |
       'none' — по c.escrowed_amount vs c.price, только для активных full-контрактов/этапов
       (у поэтапного родителя своего эскроу нет, см. агрегаты у него самого выше по карточке).
     - paymentBadgeHtml(c) → компактный бейдж «✅ оплачено» / «⚠️ частично оплачено» /
       «❌ не оплачено» для карточек-строк списка.
     - Внутри модалки контракта (cmRenderExtra, active-ветка) — заметный alert прямо над кнопкой
       «Оплатить»: полностью/частично/не оплачено, фрилансеру отдельным предложением "не
       рекомендуем начинать работу до оплаты" при 'none' и "уточните остаток" при 'partial'.
     - chat.js contractChatHint(): фрилансер вместо пустоты теперь видит paymentBadgeHtml() на
       мини-карточке контракта в сайдбаре (клиент по-прежнему видит «💳 к оплате: X» — это более
       информативно для того, кто платит).
     - contracts.js contractCard(): тот же paymentBadgeHtml() строкой под карточкой в списке
       «Контракты» — видно обеим сторонам, не только фрилансеру, лишним не посчитал.

  Кэш-бастинг ?v=16 → ?v=17 на всех 13 страницах. Схема БД не менялась.


«Вызов администратора» — рычаг решения конфликтов — 2026-09-16 (поздний вечер)

  ⚠️ ТРЕБУЕТ МИГРАЦИИ БД: project/sql/2026-09-16_admin_escalations.sql — прогнать один раз в
  phpMyAdmin. Добавляет contracts.frozen_at + новую таблицу admin_escalations (17-я таблица).

  Смысл: любая сторона активного контракта/этапа может позвать администрацию — та видит контракт,
  всю переписку и смету и решает конфликт напрямую, в обход обычного «обе стороны согласны».

  Рефакторинг перед фичей: pay_freelancer()/refund_with_hold() (были приватными функциями внутри
  contracts.php) вынесены в includes/contract_payouts.php — теперь их же использует admin.php для
  принудительного закрытия. HOLD_DAYS переименован в CONTRACT_HOLD_DAYS и живёт в этом инклюде.

  1) Сторона зовёт администрацию — api/escalations.php (новый):
     - POST action=raise {contract_id, reason}: только по АКТИВНОМУ контракту/этапу (не по
       поэтапному родителю целиком — parent_contract_id IS NULL && type=staged отклоняется),
       не более одного открытого обращения на контракт одновременно. notify_admins().
     - GET ?contract_id=: последняя эскалация по контракту (любого статуса) — для отображения
       статуса «отправлено» / «рассмотрено: …» у сторон.
  2) contract-actions.js: кнопка «🛎 Позвать администратора» — обычная кнопка в теле карточки
     активного контракта/этапа (НЕ в ⋮-меню, чтобы была на виду), через #escalateModal (текстовое
     поле «в чём проблема», добавлен в hop-list contractModalInit наравне с change/deposit/stage/
     estimate). cmEscalationSection(c) показывает: кнопку (если ничего не отправлено), «отправлено,
     ожидайте» (status=open), или итог решения (status=resolved, ESCALATION_RESOLUTION_LABELS +
     resolution_note). Плюс отдельно — бейдж «🧊 заморожен администрацией …» если contracts.frozen_at
     задан, рисуется в начале блока независимо от статуса эскалации. Модалка продублирована в
     chat.html и contracts.html (как change/deposit/stage/estimate).
  3) Админка решает — api/admin.php (расширен):
     - GET action=escalations — список открытых обращений (карточка: номер контракта/этапа,
       стороны, обрезанная причина, кнопка «Рассмотреть»), бейдж-счётчик в сайдбаре как у споров.
     - GET action=escalation_detail&id= — полный пакет для разбора: сама эскалация (+ кто
       обратился), контракт целиком (+ клиент/фрилансер с email), ВСЯ переписка конверсации
       (m.* + имя отправителя, без ограничения по правам участника — админ видит чужой диалог
       напрямую через SQL, не через messages.php), последняя смета с позициями.
     - POST action=resolve_escalation {id, resolution, new_price?, new_deadline?, note}` — 4 исхода:
       • terms_changed — UPDATE contracts SET price/deadline напрямую, без согласия сторон;
       • closed_success — pay_freelancer() + status=completed (как обычное закрытие «выполнен»);
       • closed_failure — refund_with_hold() + status=failed («в резерв клиенту», тот же 7-дневный
         холд, что и у обычного расторжения / у фрилансерского оспаривания);
       • frozen — ЗАГЛУШКА по прямому указанию: contracts.frozen_at = NOW(), уведомление о «тикете
         в поддержку и внутрибиржевом расследовании» — сам тикет/расследование не реализованы,
         frozen_at НИГДЕ не блокирует другие действия (contracts.php/escrow.php/contract_estimates.php
         не тронуты) — это чисто видимый маркер «идёт разбирательство», без принуждения.
       Все 4 исхода требуют contract.status='active' на момент решения (иначе 409 — вдруг кто-то
       успел закрыть его сам, пока обращение висело). Уведомление уходит ОБЕИМ сторонам с одним и
       тем же текстом решения. terms_changed/closed_*/если раньше был frozen — сбрасывают
       frozen_at = NULL заодно.
     - admin.html: новый пункт сайдбара «🛎 Обращения к администрации» + секция списка +
       #escalationDetailModal (contract summary / переписка в скролл-боксе / смета / три кнопки
       решения + форма цена/срок для terms_changed + общий textarea-комментарий, видимый обеим
       сторонам).

  Кэш-бастинг ?v=17 → ?v=18 на всех 13 страницах.


Состояние на 2026-10-13

  - Всё, что было сделано с июля по 16 сентября, закоммичено одним коммитом c1d1f8c «Backup 13.10».
  - Все три миграции (2026-09-10_staged_contracts_estimates, 2026-09-16_estimate_contract_link,
    2026-09-16_admin_escalations) прогнаны на тест-сервере — подтверждено свежим дампом
    kotegg007_novabuild.sql от 2026-10-13 (17 таблиц, все новые колонки и FK на месте).
  - Пункт «кто покупает материалы» выпилен из кода (см. «Наработки на будущее» ниже). Кэш ?v=18 → ?v=19.


Заход 2026-10-13 (вторая половина): баланс клиента, даты, бан, сдача-приёмка, файлы в чате, отзывы

  МИГРАЦИЯ project/sql/2026-10-13_submissions_chat_files_reviews.sql — ПРОГНАТЬ ДО ВЫКЛАДКИ КОДА
  (contracts.php close, bids.php, messages.php и др. уже читают новые таблицы). Безопасно перезапускать.
  Добавляет: wallet_transactions.type += 'balance_payment'; таблицы work_submissions,
  work_submission_files, message_attachments, reviews (→ 21 таблица).

  1) Баланс клиента в приоритете (api/escrow.php, includes/client_balance.php)
     Депозит по контракту/этапу: сначала списывается ДОСТУПНЫЙ баланс клиента
     (client_profiles.balance − frozen, где frozen — та же формула, что в me.php: холды в 7-дневном
     окне или под спором), остаток — способом оплаты (card|gpay|liqpay, всё ещё заглушки). Если баланс
     покрывает всё — method не нужен. Строка баланса блокируется SELECT … FOR UPDATE. В
     wallet_transactions: часть с баланса — новый тип 'balance_payment' (НЕ 'deposit', чтобы
     finance.php не посчитал её как новый приход), часть картой — 'deposit' как раньше. Ответ:
     escrowed_amount, from_balance, from_card, available_balance. me.php для клиента отдаёт
     available_balance. UI: модалка оплаты (chat.html/contracts.html #depositModal) показывает
     раскладку «с баланса / доплатить картой»; если баланса хватает — вместо вкладок оплаты одна
     кнопка «Оплатить с баланса на сайте» (contract-actions.js cmRenderDepositSplit).
     Вывод для клиента НЕ делали: текст CLIENT_WITHDRAW_NOTICE (common.js) — «Вывод денег для
     клиента возможно осуществить только с помощью технической поддержки…» — в модалке оплаты и в
     блоке баланса кабинета; короткая версия под балансом на дашборде клиента.

  2) Холды по-прежнему не отпускаются (решение: позже).

  3) Проверка дат (includes/dates.php validate_deadline) — диапазон СЕГОДНЯ … СЕГОДНЯ + 15 ЛЕТ
     (DEADLINE_MAX_YEARS), формат строго Y-m-d, иначе 422 с понятным текстом. Подключено:
     offers.php POST/PUT, contracts.php propose/add_stage, contract_change_requests.php propose,
     admin.php resolve_escalation (new_deadline). Поле срока по-прежнему НЕОБЯЗАТЕЛЬНОЕ —
     проверяется только если заполнено. Фронт: common.js проставляет min/max всем
     <input type="date"> на загрузке и при фокусе (так ловится и динамическая форма в админке).
     Старые кривые даты в БД (0000-00-00, 1222-12-12) не трогали.

  4) Бан (admin.php POST set_user_status {user_id, status: active|banned}; админа забанить нельзя).
     Кнопки «🚫 Заблокировать / Разблокировать» в таблице пользователей админки. require_auth()
     (auth_middleware.php) теперь на КАЖДОМ запросе перечитывает users.status: banned → 403
     {banned:true}, удалённый пользователь → 401. Т.е. бан действует сразу, даже по уже выданному
     JWT. requireAuth() на фронте при banned уводит на login.html?banned=1 с сообщением.

  5) Сдача-приёмка работы (api/work_submissions.php; таблицы work_submissions, work_submission_files)
     Только полный контракт или этап (не поэтапный родитель), только active.
     - submit (multipart: contract_id, comment, files[]) — фрилансер; нужен комментарий или файл;
       одна сдача в статусе submitted за раз. Файлы → uploads/submissions/. Уведомление клиенту.
     - decide (JSON: submission_id, accept, comment) — клиент. ДА = то же, что close:success
       (pay_freelancer, completed). НЕТ = rejected, комментарий ОБЯЗАТЕЛЕН («что доработать»).
     - GET ?contract_id — история сдач с файлами.
     Обычное «⋮ Закрыть» осталось как запасной путь. Если контракт закрыт любым другим способом
     (close, каскад поэтапного, принудительно админом), висящая сдача закрывается тем же исходом —
     contract_payouts.php settle_pending_submissions(). UI — секция «📦 Сдача работы» в модалке
     контракта (contract-actions.js cmSubmissionSection). Админ видит сдачи в разборе эскалации.

  6) Файлы в чате (messages.php; таблица message_attachments)
     POST multipart (conversation_id, body, files[]) — body может быть пустым, если есть файлы
     (в messages.body тогда ''). GET и Pusher-событие отдают attachments[]. Файлы → uploads/chat/.
     chat.html: кнопка 📎, превью выбранных файлов, картинки открываются в полноэкранной галерее.
     conversations.php превью «📎 Файл» для сообщения без текста. Админ видит файлы в переписке
     эскалации (обычными ссылками).

  7) Отзывы и рейтинг (api/reviews.php; таблица reviews)
     Один отзыв клиента на закрытый (completed ИЛИ failed) контракт верхнего уровня — полный или
     поэтапный целиком, не на этап. 1–5 звёзд + комментарий до 2000, не редактируется.
     UI — блок «⭐» в модалке закрытого контракта. Рейтинг (среднее + кол-во) — в карточках заявок
     offer.html (bids.php) и на freelancer-profile.html (+ «выполнено контрактов» и последние 20 отзывов).

  Общее: includes/uploads.php store_uploaded_files() — единая проверка файлов (png/jpg/pdf/docx/xlsx,
  15 МБ, 10 шт., finfo + ZipArchive для docx/xlsx) для офферов, сдачи работы и чата. common.js:
  attachmentsHtml(), checkUploadSelection(), starsHtml(), ratingBadgeHtml(). Галерея (#image-gallery-
  overlay) добавлена на chat.html и contracts.html. .gitignore: uploads/submissions/*, uploads/chat/*.
  Файлы чата и сдач публично доступны по прямой ссылке (случайные имена) — как и вложения офферов.
  Кэш ?v=19 → ?v=20.


Перевод интерфейса на украинский (2026-10-13)

  ВЕСЬ пользовательский текст теперь украинский: страницы (lang="uk"), JS-тексты, ошибки API
  (включая бывшие английские 'Contract not found' и т.п.), уведомления, описания транзакций,
  письмо с кодом подтверждения. Комментарии в коде и этот context.md — не переводились.
  Глоссарий: контракт / етап / офер / заявка / замовник / виконавець / кошторис / ескроу /
  конекти / термін (срок) / сповіщення / виведення коштів / здача роботи / доопрацювання / відгук.
  admin.php resolve_escalation: $noun — родовий («умови етапу»), $nounAcc — знахідний («закрила етап»).

  АПОСТРОФ (ім'я, обов'язково, з'ясування) — обычный ASCII ', поэтому:
    - в PHP/JS-строках в ОДИНАРНЫХ кавычках — экранировать \'  ('Вкажіть ім\'я');
    - в PHP-строках в двойных кавычках, JS-шаблонах `...`, HTML-тексте и атрибутах в "..." — как есть;
    - никогда не класть украинский текст внутрь onclick='...' / JS-строки внутри onclick="...'...'".
  Проверка: php -l, node --check, плюс inline-скрипты/обработчики страниц через new Function().

  (Параллельные контракты — см. раздел ниже.)

  Старые данные в БД НЕ переводились: уже созданные уведомления, описания wallet_transactions,
  title «Поэтапный контракт» у старых поэтапных контрактов остаются на русском.
  Кэш ?v=20 → ?v=21.


Параллельные контракты с одним человеком (2026-10-13)

  contracts.php propose больше НЕ запрещает второй proposed/active контракт в переписке. Запрет
  остался только на дубль по одному оферу: два открытых контракта с одинаковым offer_id в одной
  переписке → 409. Всё остальное (кошторис, зміни умов, ескроу, здача роботи, спори, звернення,
  відгуки) и так работало по contract_id — менять не пришлось.
  Чтобы параллельные контракты различались — НАЗВА: propose принимает необязательный title (≤150),
  он пишется в contracts.title (у полного контракта — его название, у поэтапного — название
  контейнера; раньше там было авто «Поетапний контракт», теперь NULL, если не задано). GET отдаёт
  offer_title. contract-actions.js contractName(c) = title || offer_title (старые авто-названия
  поэтапных игнорируются). Название показывается: в карточках чата, на странице «Контракти»,
  строкой «Назва» в модалке контракта, в списке и разборе обращений в админке, в уведомлении
  фрилансеру о новом предложении.
  Чат (chat.js): кнопка «＋ Контракт» у клиента видна всегда; сайдбар разбит на «В роботі (N)»
  (сначала запропоновані, потом активні) и свёрнутые «Завершені (N)» (состояние сворачивания
  переживает 10-секундный опрос). В форме предложения — поле «Назва контракту», подставляется из
  выбранного офера, пока пользователь не ввёл своё; оферы, по которым в этом чате уже есть
  открытый контракт, из списка убраны. Свободное правило фрилансера «закрыть после 14 дней
  тишины» — по-прежнему по последнему сообщению переписки (общей для всех её контрактов).
  Кэш ?v=21 → ?v=22.


Поэтапный контракт: «поступово» или «весь план одразу» (2026-10-13)

  МИГРАЦИЯ project/sql/2026-10-13_planned_stages.sql — ПРОГНАТЬ ДО ВЫКЛАДКИ КОДА:
  contracts.status += 'planned' («За планом»); contracts.stage_mode ENUM(incremental|planned) NULL
  (только у поэтапного родителя; NULL у старых = incremental).

  При выборе «Поетапний» в форме предложения (chat.html #proposeModal) — выбор режима:
  - incremental «Поступово» — как раньше: сейчас только этап 1, следующие клиент формирует
    (add_stage) после завершения текущего.
  - planned «Одразу весь план» — таблица этапов (назва, ціна, термін; 2–30 шт., сроки этапов
    не могут идти раньше предыдущего). propose {stage_mode:'planned', stages:[...]} создаёт
    родителя + этап 1 'proposed' + этапы 2..N 'planned'.
  Жизненный цикл плана (includes/staged.php):
  - фрилансер подтверждает этап 1 = соглашается со ВСЕМ планом (родитель → active);
    отклоняет этап 1 = отклоняется весь план (этапы 'planned' и родитель → rejected).
  - этап завершён УСПЕШНО (close success / «Так, прийняти роботу» / админ closed_success) →
    after_stage_completed → activate_next_planned_stage: следующий 'planned' → 'active',
    уведомления обеим сторонам («внесіть депозит за етап»).
  - этап завершён НЕУДАЧНО → план ждёт клиента: «▶ Розпочати етап N» (contracts.php
    start_next_stage) или закрыть весь контракт.
  - закрытие родителя: 'planned' этапы → rejected (как и proposed).
  - add_stage запрещён, пока в плане есть 'planned' этапы; после конца плана — «Додати етап понад план».
  - На этапах 'planned' (при принятом плане) уже можно: составлять/утверждать КОШТОРИС
    (contract_estimates.php), предлагать «Змінити умови етапу» (contract_change_requests.php).
    Нельзя: депозит, сдача работы, закрытие, вызов админа — только когда этап активен.
  UI (contract-actions.js): статус/бейдж «За планом», строка «Етапи: весь план одразу / поступово»
  у родителя, плашки для этапа 1 плана («підтвердження = згода з усім планом»), меню «Підтвердити
  план (усі етапи)», панель после этапа («За планом уже розпочато етап N» / «▶ Розпочати етап N»).
  Карточки: «план · N ет.» в чате, «поетапний (план)» на странице «Контракти».
  Кэш ?v=22 → ?v=23.


Підтримка: «Звернутися до підтримки» + екстрені заявки (2026-10-13)

  МИГРАЦИЯ project/sql/2026-10-13_support_tickets.sql — ПРОГНАТЬ ДО ВЫКЛАДКИ КОДА (→ 24 таблицы):
  support_tickets (user_id, conversation_id NULL — откуда обратились, category
  ENUM(scam|transactions|admin_call), status ENUM(open|closed|expired), created_at, expires_at
  = +3 дня, closed_at, closed_by_user_id), support_ticket_admins (кто из админов подключился,
  PK ticket+admin), support_messages (sender_id NULL = системное сообщение).

  Пользователь (chat.html): кнопка «🆘 Звернутися до підтримки» в шапке чата → плавающее окно
  справа внизу (js/support-widget.js, SupportWidget.init({me, conversationId}) / .open()).
  Варианты: «🕵️ Мене обманули», «💳 Проблема з транзакціями» — ЗАГЛУШКИ (текст «в розробці» +
  кнопка вызова админа); «🛎 Виклик адміністратора» — необязательное описание → создаётся
  временный чат на 3 дня. Одна живая заявка на пользователя: повторное открытие окна
  возвращает в тот же чат. Красная точка на кнопке — новые ответы, пока окно свёрнуто.
  Виджет самодостаточен (сам вставляет разметку) — можно подключить на любую страницу.

  API (api/support.php): GET mine | messages (ticket_id, after_id) | list (админ) | detail
  (админ: чат + кто обратился + последние 50 сообщений переписки, откуда обратились);
  POST create {category:'admin_call', conversation_id, body} | send {ticket_id, body} | join
  (админ) | close (админ). Номер: №ДДММРР-ГГХХ-ID по created_at, id дополнен до 3 цифр
  (131026-1545-001) — support_ticket_number(). При создании notify_admins:
  «ТЕРМІНОВА ЗАЯВКА №…». Админ пишет → уведомление пользователю; пользователь пишет →
  уведомления подключившимся админам. Срок 3 дня — без крона: expire_support_tickets()
  на каждом вызове помечает просроченные 'expired'; писать в них нельзя, пользователь их
  больше не видит, данные остаются в БД.

  Админ (admin.html): раздел «🚨 Екстрені заявки» первым в меню, пульсирующий счётчик,
  счётчик в заголовке вкладки «(N) 🚨»; список обновляется каждые 15 с. «Підключитися» —
  join (системное сообщение пользователю «Адміністратор … підключився») и окно
  «🚨 Екстрена заявка №…»: слева чат, справа контекст (кто, с кем переписка, последние
  сообщения). Подключиться может любой админ; написать = тоже подключиться.
  «✔️ Завершити заявку» — закрыть досрочно.
  Realtime: опрос раз в 4 с, пока чат открыт (Pusher здесь не используется — на админке его нет).
  Кэш ?v=23 → ?v=24.


Кошторис: роботи + матеріали, клиенту — документ .docx (2026-10-13)

  МИГРАЦИЯ project/sql/2026-10-13_estimate_documents.sql — ПРОГНАТЬ ДО ВЫКЛАДКИ КОДА:
  contract_estimates.document_path VARCHAR(500) NULL.

  Позиции кошториса (contract_estimates.items JSON) теперь {kind: work|material, name, qty,
  unit, unit_price}. «Роботи» — справочно, ни на что не влияют; «Матеріали» — как было
  (закупает замовник). Старые позиции без kind считаются матеріалами. GET отдаёт ещё
  works_total / materials_total; total = общий итог.
  Фрилансер: форма в #estimateModal (chat.html / contracts.html) из двух разделов
  «🛠 Роботи» и «🧱 Матеріали», подитоги + «Разом».
  Клиент: в карточке контракта/этапа — итог «Роботи · Матеріали · Разом», статус, кнопка
  «📄 Завантажити кошторис (.docx)», «Затвердити / Відхилити». HTML-таблицы у клиента больше нет.
  Документ: includes/estimate_document.php — .docx собирается вручную (WordprocessingML +
  ZipArchive, без библиотек): заголовок «КОШТОРИС № {id} від {дата}», к какому контракту/
  этапу, виконавець/замовник (имя + инициал фамилии), таблицы «1. Роботи» и «2. Матеріали»
  с подитогами, итоги, примітка, строки подписей. Генерируется ПРИ ОТПРАВКЕ (снимок) в
  uploads/estimates/<random>.docx, путь в document_path; у старых — при первом GET.
  Проверено: открывается в Word (2 таблицы), XML валиден. Вид документа — «простейший»,
  улучшить позже (логотип, реквизиты, PDF и т.п.). Если на хостинге нет ZipArchive —
  документ не создаётся, в UI «Документ ще формується».
  Админ в разборе звернення: таблица с колонкой «Розділ», итоги по разделам, ссылка на .docx.
  .gitignore: uploads/estimates/*. Кэш ?v=24 → ?v=25.


Депозит — только после кошториса (2026-10-13)

  Клиент вносит депозит по контракту/этапу, только если (1) он активен (подписан) И (2) у него
  есть кошторис, который НЕ відхилено (надіслано или затверджено) и в котором есть И роботи,
  И матеріали. Затверджения клиентом НЕ требуется (оплата = согласие) — если нужно строже,
  в deposit_block_reason() поменять условие на status === 'accepted'.
  - includes/estimate_document.php: estimate_covers_both(items), deposit_block_reason($db,
    $contractId) → null или текст причины (укр.).
  - escrow.php — жёсткая проверка (409 с причиной). contracts.php GET — deposit_ready /
    deposit_block_reason у активных полных контрактов и этапов.
  - contract_estimates.php submit: без одного из разделов — 422 «має містити і роботи, і
    матеріали». Старый кошторис (без робіт) на розгляді не блокирует: фрилансер может сразу
    «Скласти заново», старый помечается rejected (superseded).
  - UI: у клиента вместо «💳 Оплатити» — плашка с причиной; у фрилансера — «📋 Складіть
    кошторис… без нього клієнт не може внести депозит». В чате на карточке: клиенту
    «📋 чекає на кошторис — потім депозит», фрилансеру «📋 складіть кошторис…».
    contract-actions.js cmDepositBlockReason() считает по свежему _cmEstimates (зеркало PHP).
  Миграции не нужно. Кэш ?v=25 → ?v=26.


Наработки на будущее: кто покупает материалы (выпилено из кода 2026-10-13)

  Решение 2026-10-13: пока материалы ВСЕГДА на заказчике — он сам решает вопрос закупки, биржа в
  этом не участвует. Пункт выбора убран из UI и API, сейчас в форме предложения контракта только
  подсказка «Материалы закупает заказчик самостоятельно».

  Что было спроектировано и жило в коде с 2026-09-10 по 2026-10-13 (заглушкой — на деньги не влияло):
  выбор при заключении контракта ИЛИ каждого этапа, одно из трёх:
    1) exchange_reserve — «Резерв биржи»: биржа резервирует у себя деньги по смете и выплачивает их
       фрилансеру, когда тот сам закупил материалы.
    2) client — «Оплачивает заказчик»: заказчик сам покупает/оплачивает материалы (это и есть
       текущее поведение по умолчанию для всех контрактов).
    3) exchange_service — «Закупка силами биржи»: клиент платит бирже, человек от биржи едет,
       закупает всё по смете и передаёт фрилансеру.
  Как было устроено: contracts.materials_paid_by ENUM('exchange_reserve','client','exchange_service')
  NULL — на полном контракте или на каждом этапе свой; contracts.php принимал materials_paid_by в
  propose и add_stage (whitelist MATERIALS_OPTIONS); радио в #proposeModal, селект в #stageModal;
  ярлык «Материалы: …» в карточке контракта (MATERIALS_LABELS/MATERIALS_SHORT, cmMaterialsRow в
  contract-actions.js) и в админском разборе эскалации.

  Колонка contracts.materials_paid_by В БД ОСТАВЛЕНА (дремлет, всегда NULL у новых контрактов,
  у старых тестовых строк остались значения) — чтобы при возврате к фиче не нужна была миграция.
  Если решим не возвращаться: ALTER TABLE contracts DROP COLUMN materials_paid_by;

  При возврате к фиче продумать: реальное движение денег для варианта 1 (отдельный резерв на сумму
  сметы, выплата фрилансеру по подтверждению закупки — чеки?) и для варианта 3 (заказ услуги закупки,
  кто подтверждает передачу материалов); связь со сметой (contract_estimates.total как база резерва).


База данных — 21 таблица после миграции 2026-10-13_submissions_chat_files_reviews (до неё — 17, см. kotegg007_novabuild.sql от 2026-10-13)
  2026-09-10: 15 таблиц. Миграция 2026-09-10 → 16: + contract_estimates; contracts += type /
  parent_contract_id / seq / title / materials_paid_by / estimate_id (этапы живут в этой же таблице).
  Миграции 2026-09-16 → 17: + admin_escalations; contracts += frozen_at; contract_estimates += contract_id.
  Миграция 2026-10-13 → 21: + work_submissions, work_submission_files, message_attachments, reviews;
  wallet_transactions.type += balance_payment.

work_submissions       — id, contract_id FK (полный контракт или этап), freelancer_user_id FK, comment TEXT NULL,
                         status ENUM(submitted|accepted|rejected), client_comment TEXT NULL, created_at, decided_at
work_submission_files  — id, submission_id FK ON DELETE CASCADE, file_path, original_name, mime_type, size, created_at
message_attachments    — id, message_id FK ON DELETE CASCADE, file_path, original_name, mime_type, size, created_at
reviews                — id, contract_id FK UNIQUE (контракт верхнего уровня), client_user_id FK,
                         freelancer_user_id FK, rating TINYINT 1–5, comment TEXT NULL, created_at

admin_escalations      — id, contract_id FK (контракт или этап, не поэтапный родитель), raised_by_user_id FK,
                         reason TEXT, status ENUM(open|resolved),
                         resolution ENUM(terms_changed|closed_success|closed_failure|frozen) NULL,
                         resolution_note, resolved_by_user_id FK NULL, created_at, resolved_at
contract_estimates     — id, conversation_id FK, contract_id FK NULL (к какому контракту/этапу — у новых
                         всегда заполнен), freelancer_user_id FK, items LONGTEXT (JSON [{name,qty,unit,
                         unit_price}]), note, total DECIMAL, status ENUM(submitted|accepted|rejected),
                         created_at, decided_at

users                  — id, email (он же логин админа), password_hash (Argon2id+pepper),
                         role ENUM(client|freelancer|admin), first_name, last_name, avatar_path,
                         status ENUM(active|banned),
                         verification_status ENUM(none|pending|verified|rejected), verification_doc_path,
                         verified_at, email_verified_at, verification_code, verification_code_expires_at,
                         created_at, updated_at
freelancer_profiles    — id, user_id FK, phone, website, specialization, about (до 3000 символов),
                         connects_balance INT DEFAULT 10000, balance DECIMAL(12,2) — вывод заработка
client_profiles        — id, user_id FK, company_name (опционально), phone,
                         balance DECIMAL(12,2) — возвраты по невыполненным контрактам
offers                 — id, client_id FK→client_profiles, title, description, budget DECIMAL, deadline DATE,
                         status ENUM(open|in_progress|closed),
                         outdated_at DATETIME NULL (штампуется когда по офферу активируется контракт —
                         бейдж «Уже найден работник», грейс 48ч, потом оффер уходит из ленты), created_at
bids                   — id, offer_id FK, freelancer_id FK→freelancer_profiles, cover_note,
                         connects_spent INT DEFAULT 10, status ENUM(pending|accepted|rejected) — по факту
                         всегда pending (выбор исполнителя идёт через чат→контракт, не через accept заявки),
                         created_at; UNIQUE KEY (offer_id, freelancer_id)
offer_attachments      — id, offer_id FK ON DELETE CASCADE, file_path, original_name, mime_type, size, created_at
notifications          — id, user_id FK ON DELETE CASCADE, type, message, is_read, created_at
conversations          — id, client_user_id FK, freelancer_user_id FK, created_at
                         UNIQUE KEY (client_user_id, freelancer_user_id) — одна переписка на пару
messages               — id, conversation_id FK, sender_id FK, body TEXT (≤5000), created_at, read_at DATETIME NULL
contracts              — id, conversation_id FK, client_user_id FK, freelancer_user_id FK, offer_id FK NULL,
                         type ENUM(full|staged), parent_contract_id FK NULL (self — строка-этап),
                         seq NULL (номер этапа), title NULL, price DECIMAL, deadline DATE NULL,
                         status ENUM(proposed|active|completed|failed|rejected),
                         frozen_at DATETIME NULL (заморозка админом — только отметка, ничего не блокирует),
                         escrowed_amount DECIMAL DEFAULT 0,
                         materials_paid_by ENUM NULL (ДРЕМЛЕТ — фича выпилена, см. «Наработки на будущее»),
                         estimate_id FK NULL (последняя смета под этот контракт/этап), created_at, updated_at
contract_change_requests — id, contract_id FK, proposed_by FK, new_price DECIMAL NULL, new_deadline DATE NULL,
                         status ENUM(pending|accepted|rejected), created_at, decided_at DATETIME NULL
wallet_transactions    — id, user_id FK, type ENUM(deposit|withdrawal|contract_payment|contract_earning|balance_payment),
                         amount DECIMAL, description, related_offer_id FK NULL, related_contract_id FK NULL, created_at
withdrawal_requests    — id, user_id FK, amount DECIMAL, status ENUM(pending|approved|rejected),
                         created_at, decided_at DATETIME NULL
balance_holds          — id, user_id FK (клиент, получивший возврат), contract_id FK, amount DECIMAL,
                         release_at DATETIME (NOW()+7 дней), disputed_at DATETIME NULL,
                         resolution ENUM(pending|released_to_client|released_to_freelancer) DEFAULT pending,
                         created_at (API решения спора маппит to_* → released_to_*, см. «Споры и холды»)
rate_limit_hits        — id, bucket, identifier, created_at; KEY (bucket, identifier, created_at)

Что реализовано (ТАСК 1 + ТАСК 2 + ТАСК 3)

Бэкенд


Регистрация: создаёт запись в users + пустой профиль в freelancer_profiles или client_profiles,
  требует подтверждения email кодом (см. раздел "Email-верификация" ниже) прежде чем выдать JWT
Логин: verify_and_upgrade_password() (Argon2id+pepper, апгрейд старых bcrypt), rate limiting,
  блокирует неподтверждённые email и banned, возвращает JWT
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


Что НЕ сделано — план на 2026-10-13 (порядок = предлагаемый приоритет)

 СТАТУС на конец 2026-10-13: 1 ✅ (баланс в приоритете; вывод клиенту — только через поддержку),
   2 ⏸ позже, 3 ✅, 4 ✅, 5 ✅, 6 ✅, 7 ✅, 8–9 ⏸ «на потом». Детали — «Заход 2026-10-13 (вторая половина)».

 A. Мелкие дыры (быстро, деньги и данные):
  1. Деньги клиента — тупик. client_profiles.balance копит возвраты (в дампе 1231 ₴), но вывести их
     нельзя (wallet.php — только freelancer), а оплатить депозит с баланса тоже нельзя (escrow.php —
     только card|gpay|liqpay). Нужно: оплата депозита с баланса + вывод для клиента.
  2. Холды не «отпускаются» — balance_holds остаётся 'pending' навсегда после release_at, frozen_amount
     просто перестаёт его считать. Крона нет → ленивое отпускание при любом чтении (me.php и т.п.):
     UPDATE ... SET resolution='released_to_client' WHERE pending AND release_at<NOW() AND disputed_at IS NULL.
  3. Нет серверной валидации дат: в БД оффер с deadline '0000-00-00', этап со сроком 1222-12-12,
     контракт до 2100 года. Проверять формат и «не в прошлом / не дальше N лет» в offers.php,
     contracts.php (propose/add_stage), contract_change_requests.php.
  4. Бан пользователей: users.status ENUM(active|banned) есть и логин банных блокирует, но в админке
     кнопки бана/разбана нет.

 B. Основной продукт:
  5. Сдача-приёмка работы: фрилансер «Сдать работу» (комментарий + файлы/фото) → клиент «Принять»
     (= закрыть с выплатой) или «Вернуть на доработку». Сейчас клиент просто закрывает контракт сам.
  6. Файлы и фото в чате (фото объекта, документы).
  7. Отзывы и рейтинг фрилансера после закрытия контракта/этапа.
  8. Клиент не может отозвать своё предложение контракта, пока фрилансер не ответил; фрилансер не
     может предложить встречные условия или следующий этап (в ТЗ было «стороны могут добавлять этапы»).
  9. События контракта приходят опросом раз в 10 с — перевести на Pusher, как сообщения.

 C. Крупное / позже:
  10. Реальный платёжный шлюз (LiqPay) вместо заглушек.
  11. «Заморозка» по-настоящему: блокировка действий по замороженному контракту + тикеты поддержки
      и внутрибиржевое расследование.
  12. Пункт «кто покупает материалы» — см. «Наработки на будущее».
  13. Email-уведомления (сейчас только колокольчик на сайте).
  14. Поиск и фильтры в ленте офферов.

 Ранее «не сделано», теперь ГОТОВО (заход 2026-09-10): чат (Pusher + polling), контракты, эскроу,
   споры/холды, кошелёк и вывод средств, докупка коннектов, финансовый отчёт. Выбор исполнителя
   через accept заявки так и не делали — вместо этого клиент пишет фрилансеру и предлагает контракт
   в чате.


Соглашения в коде


get_db() — singleton PDO, PDO::ERRMODE_EXCEPTION
respond(int $code, array $data) — хелпер для JSON-ответа с exit
val('id') — читает и трimmит input по id
esc(s) — экранирование HTML перед вставкой в DOM
Все FK ссылаются на profile id, не на users.id (важно для джоинов)

Бригади (2026-10-14)

  МИГРАЦИЯ project/sql/2026-10-14_brigades.sql — ПРОГНАТЬ ДО ВЫКЛАДКИ КОДА (→ 27 таблиц):
  brigades (owner_user_id UNIQUE = бригадир, name, description), brigade_members (brigade_id,
  user_id, role — свободный текст, status ENUM(invited|active)), contracts.brigade_id (FK, SET NULL),
  contract_allocations (contract_id, user_id, amount) — розподіл оплати.

  Модель: бригаду создаёт фрилансер → он бригадир. Человек состоит максимум в ОДНОЙ бригаде
  (как владелец или active-участник); приглашений может быть несколько, при принятии одного
  остальные удаляются. Бригадир: приглашает по email (только фрилансеров), назначает роли,
  исключает, редактирует, распускает (только без незавершённых замовлень).
  Контракт бригаде: клиент в чате с бригадиром отмечает «👷 Запропонувати бригаді «X»»
  (contracts.php propose to_brigade) → brigade_id проставляется родителю и всем этапам
  (add_stage тоже наследует). Сторона контракта — по-прежнему бригадир (freelancer_user_id);
  после подтверждения (accept) все участники получают уведомление «Бригада отримала замовлення».
  Розподіл оплати: по каждому полному контракту/этапу бригадир задаёт суммы участникам
  (сумма ≤ ціна), остаток — себе. Редактируется до закрытия (proposed/active/planned).
  Выплата: contract_payouts.php pay_freelancer — если brigade_id, эскроу делится по
  contract_allocations; при неполном депозите все доли × (escrow / price); участникам —
  contract_earning «(частка в бригаді)» + уведомление, бригадиру — остаток. Проверено
  тестом (SQLite): 10000 → 4500/3000/2500; 5000 → 2250/1500/1250; не-бригада → всё бригадиру.
  Ушедший участник: его уже назначенная доля сохраняется (видно «вийшов з бригади»), новую
  дать нельзя. resolve_dispute to_freelancer в админке — по-прежнему всё бригадиру.

  API: api/brigades.php — GET mine | of_user (публичная карточка, для клиента) | contracts
  (замовлення бригади + моя частка / розподіл) | allocations; POST create | update | invite |
  cancel_invite | respond_invite | set_role | remove_member | leave | dissolve | set_allocations.
  includes/brigades.php — хелперы. freelancer_profile.php отдаёт brigade.
  UI: вкладка кабинета «👷 Бригада» (brigade.html / brigade.js, только фрилансерам;
  setupCabinetTabs) — создание, приглашения, состав и роли, замовлення с частками, кнопка
  «💰 Розподіл». brigade-allocation.js — редактор розподілу (BrigadeAllocation.render), он же
  встроен в окно контракта/этапа у бригадира («💰 Розподіл оплати в бригаді»). Чат: галочка
  бригаде в форме предложения, метка «👷 бригада «X»» на карточках; профиль фрилансера —
  «Бригадир бригади «X»». Кэш ?v=26 → ?v=27.
