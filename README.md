# netpulse

[![CI](https://github.com/Raphael-Santi/netpulse/actions/workflows/ci.yml/badge.svg)](https://github.com/Raphael-Santi/netpulse/actions/workflows/ci.yml)

Лёгкий монитор доступности сетевых узлов на чистом PHP 8.3 — без единой
runtime-зависимости. Проверяет хосты, порты, веб-приложения и DNS, ведёт
журнал инцидентов в БД и шлёт алерты в Telegram.

```
$ bin/netpulse check
gateway              ping 192.168.1.1         UP     0.4 ms
dns                  dns example.com          UP     18.2 ms
site                 http example.com:443     UP     142.7 ms
db                   tcp 127.0.0.1:3306       DOWN   connect failed: Connection refused (errno 111)
```

## Возможности

- **4 типа проверок**: TCP-порт (полный handshake), HTTP/HTTPS (статус ответа),
  DNS-резолв (время ответа резолвера), ICMP ping.
- **Инциденты вместо шума**: алерт открывается только после N подряд неудач
  (одна потеря пакета — не авария), закрывается при восстановлении с указанием
  длительности простоя. Одно событие — один алерт, без дублей.
- **Состояние в БД**: SQLite без настройки или MySQL — любой PDO DSN.
  Перезапуск монитора не теряет открытые инциденты и не алертит повторно.
- **Уведомления**: Telegram Bot API; без токена — stderr (journald под systemd).
- **Скриптуемость**: `check` возвращает код 0 (всё живо) / 1 (есть проблемы) —
  удобно для cron и CI.
- **Ретенция истории**: результаты старше `history_days` удаляются автоматически
  на каждом цикле — таблица не растёт бесконечно у долго живущего демона.

## Быстрый старт

```bash
git clone https://github.com/Raphael-Santi/netpulse.git && cd netpulse
composer install
cp config/targets.example.php config/targets.php   # отредактировать под свою сеть
bin/netpulse check
```

Режимы: `check` — один проход; `watch` — цикл с паузой `interval`;
`status` — последнее известное состояние и открытые инциденты.

## Конфигурация

```php
return [
    'storage' => ['dsn' => 'sqlite:' . __DIR__ . '/../var/netpulse.db'],
    'failure_threshold' => 3,   // неудач подряд до открытия инцидента
    'interval' => 60,           // пауза между проходами в watch, сек
    'history_days' => 30,       // хранить сырые результаты столько дней
    'telegram' => ['token' => '', 'chat_id' => ''],  // пусто — алерты в stderr
    'targets' => [
        ['name' => 'gateway', 'type' => 'ping', 'host' => '192.168.1.1'],
        ['name' => 'site',    'type' => 'http', 'host' => 'example.com', 'tls' => true, 'path' => '/health'],
        ['name' => 'db',      'type' => 'tcp',  'host' => '10.0.0.5', 'port' => 3306, 'timeout' => 1.5],
        ['name' => 'dns',     'type' => 'dns',  'host' => 'example.com'],
    ],
];
```

Поля цели: `name`, `type` (`tcp`/`http`/`dns`/`ping`), `host`; опционально
`port`, `timeout` (сек, по умолчанию 3.0), для http — `tls` и `path`.
Конфиг валидируется при загрузке: опечатка падает сразу с понятной ошибкой,
а не всплывает позже внутри проверки.

## Архитектура

```
bin/netpulse            тонкий CLI (аргументы, коды выхода)
src/
├── Cli/Application     разбор команд, сборка графа объектов, вывод
├── Monitor             один цикл: проверить → сохранить → оценить
├── Check/              интерфейс Check + реализации (Strategy)
│   ├── TcpConnectCheck   stream_socket_client, полный TCP-handshake
│   ├── HttpCheck         HTTP-запрос руками поверх TCP/TLS-сокета
│   ├── DnsResolveCheck   системный резолвер (gethostbyname)
│   ├── PingCheck         системный ping через CommandRunner
│   └── CheckFactory      тип из конфига → реализация (Factory)
├── Incident/IncidentDetector   порог, дедупликация, восстановление
├── Notifier/           Telegram / stderr за одним интерфейсом
├── Storage/PdoStorage  prepared statements, диалекты SQLite и MySQL
└── Model/              readonly DTO: Target, CheckResult, Incident, ...
```

Ключевые решения:

- **TCP connect vs ICMP ping.** ICMP требует raw-сокета и root-прав, поэтому
  ping делегирован системной утилите (у iputils capability выдана на уровне
  системы). Но для сервиса важнее ответ на вопрос «принимает ли порт
  соединения?» — это и делает TcpConnectCheck полным трёхэтапным handshake
  без каких-либо привилегий.
- **HTTP-запрос написан руками поверх сокета** — для health-check'а не нужен
  HTTP-клиент: пишем `GET ... HTTP/1.1`, читаем только статусную строку,
  полностью контролируем таймауты соединения и чтения. TLS прозрачно даёт
  обёртка `tls://` (handshake происходит при connect).
- **Внешние команды — без shell.** `proc_open` получает массив аргументов,
  поэтому имя хоста из конфига физически не может стать shell-инъекцией.
- **Недоступность — это результат, а не исключение**: проверки не бросают
  исключений по сетевым причинам, вся дальнейшая логика едина.
- **SQL только через prepared statements**; миграции ветвятся по диалекту
  (у MySQL нет `CREATE INDEX IF NOT EXISTS`, у SQLite — inline-индексов).
- Известное ограничение: у системного резолвера (`gethostbyname`) нет
  управляемого таймаута на запрос — принято осознанно, задокументировано.

## Деплой

**systemd** (рекомендуется) — юнит в [systemd/netpulse.service](systemd/netpulse.service):

```bash
sudo cp -r . /opt/netpulse && sudo mkdir -p /etc/netpulse
sudo cp config/targets.example.php /etc/netpulse/targets.php  # отредактировать
sudo cp systemd/netpulse.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now netpulse
journalctl -u netpulse -f
```

Юнит работает от непривилегированного динамического пользователя
(`DynamicUser`) с hardening-директивами (`ProtectSystem=strict`,
`NoNewPrivileges`, `PrivateTmp`); SIGTERM обрабатывается корректно —
текущий цикл завершается, процесс выходит чисто.

**cron** — если демон не нужен:

```
* * * * * /usr/bin/php /opt/netpulse/bin/netpulse check --config=/etc/netpulse/targets.php >/dev/null
```

## Качество

- Тесты: PHPUnit, 35 тестов — проверки гоняются по-настоящему на локальных
  сокетах (эфемерные порты, одноразовый HTTP-сервер в отдельном процессе),
  ping — через подменяемый CommandRunner без реальных процессов.
- Статанализ: PHPStan **level max** (+ phpstan-phpunit), 0 ошибок.
- Стиль: PSR-12 (PHP-CS-Fixer), `declare(strict_types=1)` в каждом файле.
- CI: GitHub Actions — стиль, статанализ, тесты на каждый push.

```bash
composer test   # phpunit
composer stan   # phpstan analyse
composer cs     # php-cs-fixer --dry-run
```

## Планы

- UDP syslog-коллектор (приём событий с сетевых устройств).
- SNMP-опрос интерфейсов.

## Лицензия

MIT.
