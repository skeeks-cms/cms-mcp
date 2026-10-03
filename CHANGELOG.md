# Changelog

## 1.0.8 — 2026-10-03

- В `shop_payment_list` и `shop_payment_stats` добавлен фильтр `available_for_user_id`: платежи, доступные сотруднику через его компании и клиентов, с учётом подчинённых.
- Другого сотрудника может выбирать только администратор. Права OAuth-пользователя всегда сохраняются; сотрудник не может получить платежи администратора ни через фильтр, ни по ID.
- Список и статистика используют общие ограничения и диапазон дат. Поиск платежей выполняется по комментарию и внешнему ID.
- Добавлены 44 интеграционные проверки видимости, пагинации и запрета доступа к чужим платежам.
- Для отбора по доступу сотрудника обновите `skeeks/cms-shop` до 3.2.7.31 или новее. OAuth scope прежний: `cms.finance.read`; миграции базы данных не требуются.

## 1.0.6

- Task comments: `cms_task_comment_create` (`is_result=true` stores a task
  result) and `cms_task_comment_list` with result flags and count.
- `cms_log_comment_create` accepts `cms_task_id`; a task comment is bound to
  the task model exactly as in administration.
- `cms_log_comment_pin` pins or unpins comments of tasks, companies and
  clients with the administration toggle-pin rules; other log types are
  rejected.
- `cms_log_comment_update`: text and files now follow the administration
  `cms/admin-cms-log/update-delete` permission (`CmsLogRule` for workers:
  own, not pinned, within 24 hours); a pin-only update behaves as
  `cms_log_comment_pin`.
- `cms_task_get` returns `results` (count and latest task results);
  `cms_log_list` filters by `cms_task_id`; serialized logs expose
  `is_task_result`.
- Explicit per-invocation HTTP mode for user-approved test sites in the OAuth
  and REST clients (`-AllowInsecureHttp`).
- Test: `tests/task-comments.php`.
