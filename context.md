NovaBuild — Context File

Вставь этот файл в начало нового чата чтобы я восстановил контекст.

Проект

Фриланс-маркетплейс для строительной отрасли. Мы (юр. лицо) — посредник между заказчиками и фрилансерами, предоставляем верификацию исполнителей.

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
      cabinet.html        — личный кабинет (общий для обеих ролей, поля разные)
      dashboard.html       — рабочий дашборд (офферы клиента / лента фрилансера)
    /js
      common.js           — apiFetch/apiPost, esc/val, showError/hideError, requireAuth() (guard), logout(), setVerificationBadge()
      auth.js             — doLogin(), doRegister(), selectRole()
      cabinet.js           — загрузка/сохранение профиля
      dashboard.js         — офферы клиента, лента фрилансера, ставки
    /css
      common.css, auth.css, cabinet.css, dashboard.css — по одному файлу на страницу + общий

  ПРИМЕЧАНИЕ: раньше фронтенд был SPA на #hash-роутинге (index.html + app.js + style.css).
  Переведено на классическую многостраничную схему (реальная навигация между .html), т.к. без React
  использовать SPA-подход избыточно для демки. Старые app.js/style.css удалены, их логика
  разнесена по /js и /css выше.

База данных — 5 таблиц

sqlusers                  — id, email, password_hash, role ENUM(client|freelancer|admin), status, created_at
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


Регистрация: создаёт запись в users + пустой профиль в freelancer_profiles или client_profiles
Логин: проверка password_verify(), возвращает JWT
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