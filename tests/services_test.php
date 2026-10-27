<?php
// Test serwisów na minimalnych atrapach Doctrine i HttpClient – bez vendor/: php tests/services_test.php

namespace Doctrine\Common\Collections {
    if (!interface_exists(Collection::class)) {
        interface Collection extends \IteratorAggregate {}
        class ArrayCollection implements Collection
        {
            public function __construct(private array $items = []) {}
            public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->items); }
            public function add($e): void { $this->items[] = $e; }
            public function contains($e): bool { return in_array($e, $this->items, true); }
            public function removeElement($e): void { $this->items = array_values(array_filter($this->items, fn ($x) => $x !== $e)); }
            public function filter(\Closure $f): self { return new self(array_values(array_filter($this->items, $f))); }
            public function toArray(): array { return $this->items; }
        }
    }
}

namespace Doctrine\ORM {
    if (!interface_exists(EntityManagerInterface::class)) {
        interface EntityManagerInterface {}
    }
}

namespace Doctrine\Bundle\DoctrineBundle\Repository {
    if (!class_exists(ServiceEntityRepository::class)) {
        class ServiceEntityRepository {}
    }
}

namespace Symfony\Contracts\HttpClient {
    if (!interface_exists(HttpClientInterface::class)) {
        interface HttpClientInterface { public function request(string $method, string $url, array $options = []); }
    }
}
namespace Symfony\Contracts\HttpClient\Exception {
    if (!interface_exists(TransportExceptionInterface::class)) {
        interface TransportExceptionInterface extends \Throwable {}
    }
}

namespace {
    use App\Entity\PlannedChange;
    use App\Entity\WorkDay;
    use App\Entity\WorklogEntry;
    use App\Service\JiraClient;
    use App\Service\ReportBuilder;
    use App\Service\Settings;
    use App\Service\WorklogSync;
    use Symfony\Contracts\HttpClient\HttpClientInterface;

    spl_autoload_register(static function (string $class): void {
        $file = __DIR__.'/../src/'.str_replace(['App\\', '\\'], ['', '/'], $class).'.php';
        if (is_file($file)) {
            require $file;
        }
    });
    date_default_timezone_set('Europe/Warsaw');

    // Settings bez bazy: nadpisujemy all() przez refleksję na polu cache
    $settings = (new ReflectionClass(Settings::class))->newInstanceWithoutConstructor();
    $values = Settings::defaultsFor('f');
    $values['jira_url'] = 'https://firma.atlassian.net/';
    $values['jira_email'] = 'ja@firma.pl';
    $values['jira_token'] = 'tok';
    $values['mail_over_to'] = 'szef@firma.pl, hr@firma.pl';
    $values['mail_plan_to'] = 'szef@firma.pl';
    $values['signature'] = 'Anna';
    (new ReflectionProperty(Settings::class, 'cache'))->setValue($settings, $values);

    final class FakeHttp implements HttpClientInterface
    {
        public array $calls = [];
        public array $fail = [];
        private int $nextId = 100;
        public function request(string $method, string $url, array $options = [])
        {
            $this->calls[] = [$method, $url, $options['json'] ?? null, isset($options['auth_basic'])];
            $status = 200;
            $body = '';
            foreach ($this->fail as $needle => $code) {
                if (str_contains($url, $needle)) {
                    $status = $code;
                    $body = json_encode(['errorMessages' => ['Issue does not exist']]);
                }
            }
            if (200 === $status && 'POST' === $method) {
                $body = json_encode(['id' => (string) $this->nextId++]);
            }
            return new class($status, $body) {
                public function __construct(private int $s, private string $b) {}
                public function getStatusCode(): int { return $this->s; }
                public function getContent(bool $throw = true): string { return $this->b; }
            };
        }
    }

    $fail = 0;
    function check(string $name, mixed $actual, mixed $expected): void
    {
        global $fail;
        if ($actual !== $expected) {
            ++$fail;
            echo "FAIL $name:\n  oczekiwano ".var_export($expected, true)."\n  jest       ".var_export($actual, true)."\n";
        }
    }
    function setId(object $o, int $id): void { (new ReflectionProperty($o, 'id'))->setValue($o, $id); }

    // kalendarz dni wolnych na atrapie repozytorium – nieobecności ustawia się w $absenceRepo->items
    $absenceRepo = new class extends App\Repository\AbsenceRepository {
        public array $items = [];
        public function __construct() {}
        public function findOverlapping(\DateTimeImmutable $from, \DateTimeImmutable $to): array
        {
            return array_values(array_filter($this->items, fn ($a) => $a->getDateFrom() <= $to && $a->getDateTo() >= $from));
        }
    };
    $reports = new ReportBuilder($settings, new App\Service\WorkCalendar($absenceRepo));
    $day = new WorkDay(new DateTimeImmutable('2026-10-13'));
    $day->setStartTime('08:00');
    $day->setEndTime('17:00');
    $a = new WorklogEntry($day, 'ABC-1', 240, 'Logowanie SSO', 0);
    $b = new WorklogEntry($day, 'ABC-2', 180, 'Code review', 1);
    $c = new WorklogEntry($day, 'ABC-1', 120, 'Testy SSO', 2);
    setId($a, 1); setId($b, 2); setId($c, 3);

    check('slack', $reports->slackText($day), "Raport z 13.10.2026 (wtorek):\n• ABC-1 – 6h – Logowanie SSO; Testy SSO\n• ABC-2 – 3h – Code review\nRazem: 9h");

    // tytuł wpisany przy wpisie: na Slacku i w {lista}; bez tytułu bez podwójnej spacji
    $c->setIssueTitle('  Logowanie przez SSO ');
    check('tytuł przycięty', $c->getIssueTitle(), 'Logowanie przez SSO');
    check('slack z tytułem', $reports->slackText($day), "Raport z 13.10.2026 (wtorek):\n• ABC-1 Logowanie przez SSO – 6h – Logowanie SSO; Testy SSO\n• ABC-2 – 3h – Code review\nRazem: 9h");
    $saved = $values;
    $values['mail_over_body'] = '{lista}';
    (new ReflectionProperty(Settings::class, 'cache'))->setValue($settings, $values);
    check('lista z tytułem', $reports->presenceMail($day)?->body, "ABC-1 Logowanie przez SSO – 6h – Logowanie SSO; Testy SSO\nABC-2 – 3h – Code review");
    $values = $saved;
    (new ReflectionProperty(Settings::class, 'cache'))->setValue($settings, $values);
    $c->setIssueTitle('');

    // linia Slacka zapisana w bazie jako dawny domyślny tekst przechodzi na nowy (z tytułem); własna zostaje
    $fakeEm = new class implements Doctrine\ORM\EntityManagerInterface {
        public array $rows = [];
        public function getRepository($class) { return new class($this->rows) { public function __construct(private array $r) {} public function findAll(): array { return $this->r; } }; }
        public function getConnection() { return new class { public function getParams(): array { return []; } }; }
    };
    $fakeEm->rows = [new App\Entity\Setting('slack_line', '• {ticket} – {czas} – {opis}')];
    check('stara domyślna linia Slacka → nowa', (new Settings($fakeEm))->get('slack_line'), '• {ticket} {tytul} – {czas} – {opis}');
    $fakeEm->rows = [new App\Entity\Setting('slack_line', '{ticket}: {opis}')];
    check('własna linia Slacka zostaje', (new Settings($fakeEm))->get('slack_line'), '{ticket}: {opis}');

    $mail = $reports->presenceMail($day);
    check('mail over', $mail?->subject, 'Nadgodziny – 13.10.2026');
    check('mail to', $mail?->to, ['szef@firma.pl', 'hr@firma.pl']);
    check('mail body', $mail?->body, "Dzień dobry,\n\nw dniu 13.10.2026 (wtorek) pracowałam dłużej o 1h – w godzinach 08:00–17:00.\n\nPozdrawiam\nAnna");

    $plan = new PlannedChange(new DateTimeImmutable('2026-10-15'), PlannedChange::TYPE_LATE, '10:00', null, '');
    $pm = $reports->plannedMail($plan);
    // planowane zmiany: domyślnie temat i treść to sama data – dla każdego rodzaju
    foreach ([[PlannedChange::TYPE_LATE, '10:00', null], [PlannedChange::TYPE_EARLY, null, '14:00'], [PlannedChange::TYPE_SHORT, '09:00', '13:00']] as [$type, $from, $to]) {
        $pd = $reports->plannedMail(new PlannedChange(new DateTimeImmutable('2026-10-15'), $type, $from, $to, 'lekarz'));
        check('plan '.$type.' – sama data', [$pd->subject, $pd->body, $pd->to], ['15.10.2026', '15.10.2026', ['szef@firma.pl']]);
    }
    // własny szablon ze zdaniem {opis} nadal działa
    $cache = new ReflectionProperty(Settings::class, 'cache');
    $saved = $cache->getValue($settings);
    $cache->setValue($settings, ['mail_plan_subject' => '{typ} – {data}', 'mail_plan_body' => "w dniu {data} ({dzien}) {opis}.\n{powod_linia}"] + $saved);
    $pm = $reports->plannedMail(new PlannedChange(new DateTimeImmutable('2026-10-15'), PlannedChange::TYPE_EARLY, null, '14:00', 'lekarz'));
    check('plan własny szablon', [$pm->subject, $pm->body], ['Wcześniejsze wyjście – 15.10.2026', "w dniu 15.10.2026 (czwartek) wyjdę wcześniej, ok. godz. 14:00.\nPowód: lekarz"]);
    $cache->setValue($settings, $saved);
    check('notice', round($reports->hoursOfNotice($plan, new DateTimeImmutable('2026-10-14 08:00')), 1), 24.0);

    // --- synchronizacja ---
    $http = new FakeHttp();
    $sync = new WorklogSync(new JiraClient($http, $settings), $settings);

    check('statusy przed', $sync->statuses($day), [1 => 'new', 2 => 'new', 3 => 'new']);
    $res = $sync->sync($day);
    check('3 POST-y', array_map(fn ($c) => [$c[0], $c[1]], $http->calls), [
        ['POST', 'https://firma.atlassian.net/rest/api/2/issue/ABC-1/worklog'],
        ['POST', 'https://firma.atlassian.net/rest/api/2/issue/ABC-2/worklog'],
        ['POST', 'https://firma.atlassian.net/rest/api/2/issue/ABC-1/worklog'],
    ]);
    check('started kolejne', array_map(fn ($c) => $c[2]['started'], $http->calls), [
        // 4h od 8:00 kończy się o 12:00 → kolejny wpis startuje po obiedzie
        '2026-10-13T08:00:00.000+0200', '2026-10-13T13:00:00.000+0200', '2026-10-13T16:00:00.000+0200',
    ]);
    check('seconds', $http->calls[1][2]['timeSpentSeconds'], 10800);
    check('comment', $http->calls[1][2]['comment'], 'Code review');
    check('basic auth cloud', $http->calls[0][3], true);
    check('statusy po', $sync->statuses($day), [1 => 'synced', 2 => 'synced', 3 => 'synced']);
    check('fully synced', $sync->isFullySynced($day), true);

    // drugi sync bez zmian = nic nie wysyła
    $http->calls = [];
    $res = $sync->sync($day);
    check('bez zmian', [count($http->calls), $res[0]['type']], [0, 'info']);

    // zmiana czasu wpisu 2 → aktualizacja jego i przesunięcie startu wpisu 3
    $b->update('ABC-2', 120, 'Code review', 1);
    check('statusy po edycji', $sync->statuses($day), [1 => 'synced', 2 => 'changed', 3 => 'changed']);
    $http->calls = [];
    $sync->sync($day);
    check('PUT x2', array_map(fn ($c) => $c[0].' '.basename($c[1]), $http->calls), ['PUT 101', 'PUT 102']);

    // zmiana ticketu → DELETE ze starego, POST do nowego
    $b->update('XYZ-9', 120, 'Code review', 1);
    $http->calls = [];
    $sync->sync($day);
    check('przeniesienie', array_map(fn ($c) => $c[0].' '.$c[1], $http->calls), [
        'DELETE https://firma.atlassian.net/rest/api/2/issue/ABC-2/worklog/101',
        'POST https://firma.atlassian.net/rest/api/2/issue/XYZ-9/worklog',
    ]);

    // usunięcie zsynchronizowanego wpisu → DELETE i zniknięcie z dnia
    $c->markDeleted();
    check('nie domknięte', $sync->isFullySynced($day), false);
    $http->calls = [];
    $sync->sync($day);
    check('delete', $http->calls[0][0].' '.basename($http->calls[0][1]), 'DELETE 102');
    check('usunięty z dnia', count($day->getEntries()->toArray()), 2);

    // błąd Jiry
    $d = new WorklogEntry($day, 'NOPE-1', 30, '', 3);
    setId($d, 4);
    $http->fail = ['NOPE-1' => 404];
    $res = $sync->sync($day);
    check('błąd 404', str_contains(end($res)['message'], 'Nie znaleziono (404)') && str_contains(end($res)['message'], 'Issue does not exist'), true);
    check('status error', $sync->statuses($day)[4], 'error');
    check('bez komentarza gdy pusty', array_key_exists('comment', end($http->calls)[2]), false);

    // wpis przechodzący przez obiad: 11:00 + 2h → następny o 14:00
    $day2 = new WorkDay(new DateTimeImmutable('2026-10-14'));
    $day2->setStartTime('11:00');
    $x = new WorklogEntry($day2, 'ABC-5', 120, '', 0);
    $y = new WorklogEntry($day2, 'ABC-6', 30, '', 1);
    $http->calls = []; $http->fail = [];
    $sync->sync($day2);
    check('przez obiad', array_map(fn ($c) => substr($c[2]['started'], 11, 5), $http->calls), ['11:00', '14:00']);
    // awaria w porze obiadu: obiad nieodliczany, przerwa wzięta później na raty (20m + 40m = 1h)
    $day3 = new WorkDay(new DateTimeImmutable('2026-10-15'));
    $day3->setStartTime('08:00');
    $day3->setEndTime('16:00');
    $day3->setBreakMinutes(App\Domain\TimeFormat::parseBreak('20m + 40m'));
    $day3->setWorkedThroughFixedBreak(true);
    $z1 = new WorklogEntry($day3, 'OPS-1', 240, 'Awaria', 0);
    $z2 = new WorklogEntry($day3, 'OPS-1', 180, 'Awaria c.d.', 1);
    $sum3 = $reports->summary($day3);
    check('awaria: obecność', [$sum3->presenceMinutes, $sum3->breakMinutes, $sum3->kind()], [420, 60, 'ok']);
    $http->calls = [];
    $sync->sync($day3);
    check('awaria: worklog o 12:00', array_map(fn ($c) => substr($c[2]['started'], 11, 5), $http->calls), ['08:00', '12:00']);
    $day3->setBreakMinutes(0);
    check('awaria bez przerwy = nadgodzina', [$reports->summary($day3)->presenceMinutes, $reports->summary($day3)->kind()], [480, 'over']);

    // awaria + przerwa wzięta godzinami: 13:30–13:50 i 15:00–15:40
    $day4 = new WorkDay(new DateTimeImmutable('2026-10-16'));
    $day4->setStartTime('08:00');
    $day4->setEndTime('16:00');
    $day4->setWorkedThroughFixedBreak(true);
    $day4->setBreakText('13:30-13:50, 15:00-15:40');
    $w1 = new WorklogEntry($day4, 'OPS-2', 330, 'Awaria', 0);   // 8:00 → 13:30
    $w2 = new WorklogEntry($day4, 'OPS-2', 60, 'Awaria c.d.', 1); // 13:50 → 14:50
    $w3 = new WorklogEntry($day4, 'ABC-9', 30, 'Review', 2);      // 14:50 → 15:00, przerwa 15:00–15:40, → 16:00
    $w4 = new WorklogEntry($day4, 'ABC-9', 15, 'Review c.d.', 3); // 16:00
    $sum4 = $reports->summary($day4);
    check('godziny przerw: obecność', [$sum4->presenceMinutes, $sum4->breakMinutes, $sum4->kind()], [420, 60, 'ok']);
    $http->calls = [];
    $sync->sync($day4);
    check('godziny przerw: worklogi omijają przerwy', array_map(fn ($c) => substr($c[2]['started'], 11, 5), $http->calls), ['08:00', '13:50', '14:50', '16:00']);

    // ręczna godzina rozpoczęcia: wpis startuje dokładnie o niej, pozostałe omijają jego czas
    $day5 = new WorkDay(new DateTimeImmutable('2026-10-19'));
    $day5->setStartTime('08:00');
    $v1 = new WorklogEntry($day5, 'ABC-1', 60, '', 0);   // 8:00 → 9:00
    $v2 = new WorklogEntry($day5, 'ABC-2', 90, '', 1);   // ręcznie 9:30 → 11:00
    $v2->setStartClock('09:30');
    $v3 = new WorklogEntry($day5, 'ABC-3', 60, '', 2);   // 9:00 → 9:30, przeskakuje 9:30–11:00, → 11:30
    $v4 = new WorklogEntry($day5, 'ABC-4', 30, '', 3);   // ręcznie w obiedzie 12:15
    $v4->setStartClock('12:15');
    foreach ([$v1, $v2, $v3, $v4] as $n => $v) { setId($v, 50 + $n); }
    check('koniec z godziny i czasu', [$sync->endClock($v2), $sync->endClock($v1)], ['11:00', null]);
    $http->calls = [];
    $sync->sync($day5);
    check('ręczny start', array_map(fn ($c) => basename(dirname($c[1])).' '.substr($c[2]['started'], 11, 5), $http->calls),
        ['ABC-1 08:00', 'ABC-2 09:30', 'ABC-3 09:00', 'ABC-4 12:15']);
    check('ręczny start: bez zmian po wysłaniu', array_values($sync->statuses($day5)), ['synced', 'synced', 'synced', 'synced']);
    $v2->setStartClock('10:00');
    check('zmiana godziny = do ponownego wysłania', array_values($sync->statuses($day5)), ['synced', 'changed', 'synced', 'synced']);

    // ręczny start przez obiad: 11:00 + 2h kończy się o 14:00, a kolejny wpis startuje po nim
    $day6 = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $day6->setStartTime('08:00');
    $u1 = new WorklogEntry($day6, 'ABC-1', 180, '', 0);  // 8:00 → 11:00
    $u2 = new WorklogEntry($day6, 'ABC-2', 120, '', 1);  // ręcznie 11:00, obiad w środku → 14:00
    $u2->setStartClock('11:00');
    $u3 = new WorklogEntry($day6, 'ABC-3', 30, '', 2);   // 14:00
    check('koniec ręcznego wpisu przez obiad', $sync->endClock($u2), '14:00');
    $http->calls = [];
    $sync->sync($day6);
    check('po ręcznym przez obiad', array_map(fn ($c) => substr($c[2]['started'], 11, 5), $http->calls), ['08:00', '11:00', '14:00']);

    // --- stoper: pełne minuty, bez zaokrąglania, sekundy przechodzą na kolejną sesję ---
    $tday = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $t = new WorklogEntry($tday, 'TIM-1', 0, '', 0);
    $t->startTimer(new DateTimeImmutable('2026-10-20 09:00:00'));
    $t->stopTimer(new DateTimeImmutable('2026-10-20 09:12:30'));   // 12m 30s
    check('stoper sesja 1', [$t->getMinutes(), $t->getCarrySeconds()], [12, 30]);
    $t->startTimer(new DateTimeImmutable('2026-10-20 10:00:00'));
    $t->stopTimer(new DateTimeImmutable('2026-10-20 10:17:30'));   // 17m 30s → razem 30m 0s
    check('stoper 12.5 + 17.5 = 30', [$t->getMinutes(), $t->getCarrySeconds()], [30, 0]);
    $t->startTimer(new DateTimeImmutable('2026-10-20 11:00:00'));
    $t->stopTimer(new DateTimeImmutable('2026-10-20 11:36:59'));   // 36m 59s – bez zaokrąglania w górę
    check('stoper bez zaokrąglania', [$t->getMinutes(), $t->getCarrySeconds()], [66, 59]);
    check('stoper zatrzymany', $t->isTimerRunning(), false);
    check('stop bez startu', $t->stopTimer(new DateTimeImmutable()), 0);
    $t->startTimer(new DateTimeImmutable('2026-10-20 12:00:00'));
    $t->startTimer(new DateTimeImmutable('2026-10-20 12:30:00'));  // ponowny start nie resetuje
    check('start idempotentny', $t->getTimerStartedAt()->format('H:i'), '12:00');
    check('czas na żywo', $t->liveSeconds(new DateTimeImmutable('2026-10-20 12:00:02')), 66 * 60 + 59 + 2);
    $t->stopTimer(new DateTimeImmutable('2026-10-20 11:00:00'));   // godzina przed startem → nic nie dolicza
    check('stop przed startem', $t->getMinutes(), 66);
    $t->update('TIM-1', 90, '', 0);
    check('ręczna zmiana czasu kasuje resztę sekund', [$t->getMinutes(), $t->getCarrySeconds()], [90, 0]);
    $t->update('TIM-1', 90, 'opis', 0);
    $t->addTrackedSeconds(45);
    $t->update('TIM-1', 90, 'opis 2', 0);
    check('zmiana opisu nie kasuje sekund', $t->getCarrySeconds(), 45);

    // --- dni wolne: święto > nieobecność > weekend; norma 0 ---
    $absenceRepo->items = [new App\Entity\Absence(new DateTimeImmutable('2026-11-09'), new DateTimeImmutable('2026-11-13'), 'urlop', 'Zakopane')];
    $cal = new App\Service\WorkCalendar($absenceRepo);
    $kind = static fn (string $d) => ($cal->dayOff(new DateTimeImmutable($d)) ?? ['kind' => '-', 'label' => '-'])['kind'].': '.($cal->dayOff(new DateTimeImmutable($d)) ?? ['label' => '-'])['label'];
    check('kalendarz: urlop', $kind('2026-11-10'), 'absence: Urlop – Zakopane');
    check('kalendarz: święto w trakcie urlopu', $kind('2026-11-11'), 'holiday: Narodowe Święto Niepodległości');
    check('kalendarz: weekend', $kind('2026-11-14'), 'weekend: Sobota');
    check('kalendarz: dzień roboczy', $cal->dayOff(new DateTimeImmutable('2026-11-16')), null);
    check('kalendarz: zakres – tylko dni wolne', array_keys($cal->daysOff(new DateTimeImmutable('2026-11-12'), new DateTimeImmutable('2026-11-16'))), ['2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15']);
    $holiday = new WorkDay(new DateTimeImmutable('2026-11-11'));
    new WorklogEntry($holiday, 'OPS-9', 120, 'Awaria', 0);
    $hs = $reports->summary($holiday);
    check('święto: norma 0, praca = nadgodziny', [$hs->normMinutes, $hs->difference(), $hs->kind(), null !== $reports->presenceMail($holiday)], [0, 120, 'over', true]);
    $normal = new WorkDay(new DateTimeImmutable('2026-11-16'));
    new WorklogEntry($normal, 'OPS-9', 120, 'Awaria', 0);
    check('dzień roboczy: norma z ustawień', [$reports->summary($normal)->normMinutes, $reports->summary($normal)->kind()], [420, 'under']);
    $absenceRepo->items = [];

    // --- stoper od podanej godziny ---
    $runningRepo = new class extends App\Repository\WorklogEntryRepository {
        public array $entries = [];
        public function __construct() {}
        public function findRunning(): array { return array_values(array_filter($this->entries, fn ($e) => $e->isTimerRunning())); }
        public function findLastStopped(): ?WorklogEntry
        {
            $stopped = array_filter($this->entries, fn ($e) => !$e->isDeleted() && null !== $e->getTimerStoppedAt());
            usort($stopped, fn ($a, $b) => $b->getTimerStoppedAt() <=> $a->getTimerStoppedAt());

            return $stopped[0] ?? null;
        }
    };
    $flushEm = new class implements Doctrine\ORM\EntityManagerInterface { public function flush(): void {} };
    $timer = new App\Service\TimerService($runningRepo, $flushEm, $settings);
    $sday = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $s1 = new WorklogEntry($sday, 'STA-1', 0, '', 0);
    $s2 = new WorklogEntry($sday, 'STA-2', 0, '', 1);
    $runningRepo->entries = [$s1, $s2];
    $now = new DateTimeImmutable('2026-10-20 11:00:00');
    $timer->start($s1, new DateTimeImmutable('2026-10-20 09:15'), $now);
    check('start od godziny', $s1->getTimerStartedAt()->format('H:i'), '09:15');
    check('start od godziny: czas od tej godziny', $s1->liveSeconds($now), 105 * 60);
    $timer->start($s2, new DateTimeImmutable('2026-10-20 10:30'), $now);
    check('przełączenie od godziny: poprzedni zatrzymany o tej godzinie', [$s1->isTimerRunning(), $s1->getMinutes()], [false, 75]);
    check('przełączenie od godziny: nowy działa od niej', $s2->getTimerStartedAt()->format('H:i'), '10:30');
    $err = null;
    try { $timer->start($s1, new DateTimeImmutable('2026-10-20 10:00'), $now); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    check('start przed startem działającego stopera – odmowa', [str_contains((string) $err, '10:30'), $s2->isTimerRunning(), $s1->isTimerRunning()], [true, true, false]);
    $err = null;
    try { $timer->start($s1, new DateTimeImmutable('2026-10-20 10:30'), $now); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    check('start równo ze startem działającego stopera – odmowa (koniec = początek)', [$err, $s2->isTimerRunning()], ['Stoper STA-2 działa od 20.10 10:30 – nowy musi zacząć się później.', true]);
    $err = null;
    try { $timer->start($s1, new DateTimeImmutable('2026-10-20 11:05'), $now); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    check('start w przyszłości – odmowa', [$err, $s1->isTimerRunning()], ['Stoper nie może zacząć się w przyszłości.', false]);
    $timer->start($s1, null, $now);
    check('start bez godziny = teraz', [$s1->getTimerStartedAt()->format('H:i'), $s2->getMinutes()], ['11:00', 30]);

    // --- koniec stopera musi być późniejszy niż start ---
    foreach (['2026-10-20 10:59' => 'przed startem', '2026-10-20 11:00' => 'równo ze startem'] as $at => $label) {
        $err = null;
        try { $timer->stop(new DateTimeImmutable($at), new DateTimeImmutable('2026-10-20 12:00')); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
        check("stop $label – odmowa", [$err, $s1->isTimerRunning(), $s1->getMinutes()], ['Godzina zatrzymania musi być późniejsza niż start stopera (20.10 11:00).', true, 75]);
    }
    $s3 = new WorklogEntry($sday, 'STA-3', 0, '', 2);
    $s3->startTimer(new DateTimeImmutable('2026-10-20 11:00:40'));
    $runningRepo->entries = [$s3];
    $err = null;
    try { $timer->stop(new DateTimeImmutable('2026-10-20 11:00'), new DateTimeImmutable('2026-10-20 12:00')); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    check('stop w tej samej minucie co start z sekundami – odmowa', $err, 'Godzina zatrzymania musi być późniejsza niż start stopera (20.10 11:00:40).');
    $timer->stop(new DateTimeImmutable('2026-10-20 11:30'), new DateTimeImmutable('2026-10-20 12:00'));
    check('stop po starcie – dolicza', [$s3->isTimerRunning(), $s3->getMinutes()], [false, 29]);
    $s3->startTimer(new DateTimeImmutable('2026-10-20 12:00'));
    $timer->stop(null, new DateTimeImmutable('2026-10-20 12:00'));
    check('stop „teraz” w sekundzie startu – bez błędu', $s3->isTimerRunning(), false);

    // --- nowy stoper nie może nachodzić na czas zmierzony wcześniej ---
    $o1 = new WorklogEntry($sday, 'OVL-1', 0, '', 3);
    $o2 = new WorklogEntry($sday, 'OVL-2', 0, '', 4);
    $runningRepo->entries = [$o1, $o2];
    $now = new DateTimeImmutable('2026-10-20 15:00:00');
    $timer->start($o1, new DateTimeImmutable('2026-10-20 13:00'), $now);
    $timer->stop(new DateTimeImmutable('2026-10-20 14:00'), $now);
    $err = null;
    try { $timer->start($o2, new DateTimeImmutable('2026-10-20 13:30'), $now); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    check('start w środku zmierzonego czasu – odmowa', [$err, $o2->isTimerRunning()], ['Stoper OVL-1 był włączony do 20.10 14:00 – nowy może zacząć się najwcześniej o 14:00, żeby czas się nie nakładał.', false]);
    $err = null;
    try { $timer->start($o1, new DateTimeImmutable('2026-10-20 13:59'), $now); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    check('ten sam ticket też nie może nachodzić na siebie', [str_contains((string) $err, 'OVL-1 był włączony do 20.10 14:00'), $o1->getMinutes()], [true, 60]);
    $timer->start($o2, new DateTimeImmutable('2026-10-20 14:00'), $now);
    check('start dokładnie o zatrzymaniu poprzedniego – dozwolony', $o2->getTimerStartedAt()->format('H:i'), '14:00');
    $timer->stop(null, new DateTimeImmutable('2026-10-20 14:20:25'));
    $err = null;
    try { $timer->start($o1, new DateTimeImmutable('2026-10-20 14:20'), $now); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    check('zatrzymanie z sekundami – najwcześniej następna minuta', $err, 'Stoper OVL-2 był włączony do 20.10 14:20:25 – nowy może zacząć się najwcześniej o 14:21, żeby czas się nie nakładał.');
    $o2->markDeleted();
    $timer->start($o1, new DateTimeImmutable('2026-10-20 14:10'), $now);
    check('czas usuniętego wpisu nie blokuje', $o1->getTimerStartedAt()->format('H:i'), '14:10');
    $timer->stop(null, $now);
    $timer->start($o1, null, new DateTimeImmutable('2026-10-20 15:00:00'));
    check('start „teraz” bez sprawdzania (teraz jest zawsze po zatrzymaniu)', $o1->isTimerRunning(), true);
    check('najwcześniejszy stop: pełna minuta po starcie', App\Service\TimerService::earliestStop(new DateTimeImmutable('2026-10-20 11:00:40'))->format('H:i'), '11:01');

    // podejrzany stoper
    check('stale: inny dzień', str_contains((string) App\Service\TimerService::staleReason(new DateTimeImmutable('2026-10-19 15:00'), new DateTimeImmutable('2026-10-20 08:10')), 'innego dnia'), true);
    check('stale: >6h', str_contains((string) App\Service\TimerService::staleReason(new DateTimeImmutable('2026-10-20 08:00'), new DateTimeImmutable('2026-10-20 14:30')), 'ponad 6h'), true);
    check('stale: normalnie', App\Service\TimerService::staleReason(new DateTimeImmutable('2026-10-20 08:00'), new DateTimeImmutable('2026-10-20 11:00')), null);
    check('podpowiedź: koniec dnia startu', App\Service\TimerService::suggestedStop(new DateTimeImmutable('2026-10-19 15:00'), new DateTimeImmutable('2026-10-20 08:10'), '16:00')->format('Y-m-d H:i'), '2026-10-19 16:00');
    check('podpowiedź: po końcu dnia = minuta po starcie', App\Service\TimerService::suggestedStop(new DateTimeImmutable('2026-10-19 17:20'), new DateTimeImmutable('2026-10-20 08:10'), '16:00')->format('Y-m-d H:i'), '2026-10-19 17:21');
    check('podpowiedź: nie w przyszłości', App\Service\TimerService::suggestedStop(new DateTimeImmutable('2026-10-20 08:00'), new DateTimeImmutable('2026-10-20 14:30'), '16:00')->format('H:i'), '14:30');

    // wpis bez czasu (pod stoper) nie idzie do Jiry i nie blokuje „wszystko wysłane”
    $eday = new WorkDay(new DateTimeImmutable('2026-10-21'));
    $e1 = new WorklogEntry($eday, 'ABC-1', 60, 'x', 0); setId($e1, 51);
    $e0 = new WorklogEntry($eday, 'ABC-2', 0, '', 1); setId($e0, 52);
    $http->calls = []; $http->fail = [];
    $res = $sync->sync($eday);
    check('pusty wpis pominięty', [count($http->calls), $sync->statuses($eday)], [1, [51 => 'synced', 52 => 'empty']]);
    check('pusty nie blokuje', $sync->isFullySynced($eday), true);
    check('komunikat o pominięciu', in_array('warning', array_column($res, 'type'), true), true);
    check('pusty nie trafia na Slacka', str_contains($reports->slackText($eday), 'ABC-2'), false);

    check('norma 7h domyślnie', Settings::DEFAULTS['norm_minutes'], '420');
    check('tolerancja 0 domyślnie', Settings::DEFAULTS['tolerance_minutes'], '0');

    // --- podsumowanie miesiąca (listopad 2026: 1.11 niedziela, 11.11 środa, urlop 9–13.11, dziś 18.11) ---
    $absenceRepo->items = [new App\Entity\Absence(new DateTimeImmutable('2026-11-09'), new DateTimeImmutable('2026-11-13'), 'urlop', 'Zakopane')];
    $mcal = new App\Service\WorkCalendar($absenceRepo);
    $months = new App\Service\MonthReport(new ReportBuilder($settings, $mcal), $mcal, $settings);
    $mdays = [];
    foreach (['2026-11-02' => [['ABC-1', 480, 'a']], '2026-11-03' => [['ABC-1', 300, '=SUM(A1)'], ['ABC-2', 60, '']],
              '2026-11-04' => [['ABC-2', 420, 'b']], '2026-11-07' => [['OPS-1', 120, 'sobota']]] as $date => $list) {
        $mdays[$date] = new WorkDay(new DateTimeImmutable($date));
        foreach ($list as $i => [$k, $min, $desc]) {
            new WorklogEntry($mdays[$date], $k, $min, $desc, $i);
        }
    }
    $mdays['2026-11-05'] = new WorkDay(new DateTimeImmutable('2026-11-05')); // zapisany dzień bez wpisów = brak raportu
    $m = $months->build(new DateTimeImmutable('2026-11-01'), $mdays, new DateTimeImmutable('2026-11-18'));
    $mt = $m['totals'];
    check('miesiąc: dni robocze i norma', [$mt['workingDays'], $mt['normMonth']], [16, 16 * 420]);
    check('miesiąc: bilans, nadgodziny, krócej', [$mt['balance'], $mt['over'], $mt['under'], $mt['overDays'], $mt['underDays']], [120, 180, 60, 2, 1]);
    check('miesiąc: czas pracy i norma dni z raportem', [$mt['worked'], $mt['logged'], $mt['normReported'], $mt['reported']], [1380, 1380, 1260, 4]);
    check('miesiąc: brak raportu (bez dziś i przyszłości)', $mt['missing'], 4);
    check('miesiąc: dni wolne w tygodniu', $m['offCounts'], ['Urlop' => 4, 'Święto' => 1]);
    $mstatus = array_column(array_map(static fn (array $r) => [$r['date']->format('j'), $r['status']], $m['rows']), 1, 0);
    check('miesiąc: wiersze', [count($m['rows']), $mstatus['7'], $mstatus['11'], $mstatus['18'], $mstatus['19'], isset($mstatus['8'])],
        [22, 'reported', 'off', 'today', 'future', false]);
    check('miesiąc: tickety od największego', array_map(static fn (array $t) => [$t['minutes'], $t['days']], $m['tickets']),
        ['ABC-1' => [780, 2], 'ABC-2' => [480, 2], 'OPS-1' => [120, 1]]);
    $dcsv = $months->daysCsv($m);
    $dlines = explode("\r\n", rtrim($dcsv));
    check('CSV dni: BOM i nagłówek', $dlines[0], "\u{FEFF}Data;Dzień;Od;Do;Przerwa;\"Czas pracy\";\"W ticketach\";Norma;Bilans;Uwagi");
    check('CSV dni: wiersz', $dlines[1], '2026-11-02;poniedziałek;;;0:00;8:00;8:00;7:00;+1:00;');
    check('CSV dni: urlop i brak raportu', [$dlines[4], $dlines[7]], ['2026-11-05;czwartek;;;;;;;;"brak raportu"', '2026-11-09;poniedziałek;;;;;;;;"Urlop – Zakopane"']);
    check('CSV dni: razem', end($dlines), 'Razem;;;;;23:00;23:00;21:00;+2:00;"dni bez raportu: 4"');
    $ecsv = explode("\r\n", rtrim($months->entriesCsv($m)));
    check('CSV wpisy', [count($ecsv), $ecsv[2]], [6, "2026-11-03;ABC-1;;5:00;300;'=SUM(A1);nie"]);
    check('miesiąc z „2026-13”', [App\Service\MonthReport::parseMonth('2026-13'), App\Service\MonthReport::parseMonth('2026-02')?->format('Y-m-d')], [null, '2026-02-01']);
    check('nazwa miesiąca', App\Domain\Template::monthName(new DateTimeImmutable('2026-10-14')), 'październik 2026');
    $absenceRepo->items = [];

    // --- przypomnienie o niewysłanym mailu o planowanej zmianie ---
    $rtoday = new DateTimeImmutable('2026-10-14 15:45');
    check('dzień względny', array_map(static fn (string $d) => App\Domain\Template::relativeDay(new DateTimeImmutable($d), $rtoday), ['2026-10-14', '2026-10-15', '2026-10-16', '2026-10-13']),
        ['dziś', 'jutro', 'piątek 16.10', 'wtorek 13.10']);
    check('bez maili do wysłania', App\Domain\ReminderSchedule::plannedTodo([], $rtoday), null);
    $late = new PlannedChange(new DateTimeImmutable('2026-10-15'), PlannedChange::TYPE_LATE, '10:00', null, '');
    check('jeden mail', App\Domain\ReminderSchedule::plannedTodo([$late], $rtoday), 'wyślij mail o planowanej zmianie (jutro: późniejsze przyjście)');
    $early = new PlannedChange(new DateTimeImmutable('2026-10-16'), PlannedChange::TYPE_EARLY, null, '14:00', '');
    check('dwa maile', App\Domain\ReminderSchedule::plannedTodo([$late, $early], $rtoday),
        'wyślij maile o planowanych zmianach (jutro: późniejsze przyjście; piątek 16.10: wcześniejsze wyjście)');

    // --- ustawienie „bilans dnia liczony z”: obecność (domyślnie) albo czas w ticketach ---
    check('balance_source domyślnie presence', [Settings::DEFAULTS['balance_source'], array_keys(Settings::BALANCE_SOURCES)], ['presence', ['presence', 'tickets']]);
    $setBalance = static function (string $source) use ($settings): void {
        $c = new ReflectionProperty(Settings::class, 'cache');
        $c->setValue($settings, ['balance_source' => $source] + $c->getValue($settings));
    };
    $bday = new WorkDay(new DateTimeImmutable('2026-10-14')); // środa
    $bday->setStartTime('08:00');
    $bday->setEndTime('16:00');
    new WorklogEntry($bday, 'ABC-1', 480, 'dużo', 0);
    $setBalance('presence');
    $bs = $reports->summary($bday);
    check('bilans z obecności: obiad odliczony, w normie', [$bs->presenceMinutes, $bs->basisMinutes(), $bs->difference(), $bs->kind(), $bs->loggedVsPresence(), $reports->presenceMail($bday)], [420, 420, 0, 'ok', 60, null]);
    $setBalance('tickets');
    $bs = $reports->summary($bday);
    check('bilans z ticketów: nadgodziny, obecność nadal liczona', [$bs->presenceMinutes, $bs->basisMinutes(), $bs->difference(), $bs->kind(), $bs->basedOnPresence()], [420, 480, 60, 'over', false]);
    check('bilans z ticketów: bez ostrzeżenia o różnicy', $bs->loggedVsPresence(), null);
    $bm = $reports->presenceMail($bday);
    check('bilans z ticketów: mail o nadgodzinach z {roznica} z ticketów', [$bm?->subject, str_contains((string) $bm?->body, 'dłużej o 1h')], ['Nadgodziny – 14.10.2026', true]);
    $vv = (new ReflectionMethod(ReportBuilder::class, 'dayVars'))->invoke($reports, $bday, $bs);
    check('bilans z ticketów: {obecnosc} bez zmian', [$vv['obecnosc'], $vv['roznica']], ['7h', '1h']);
    $bdayEmpty = new WorkDay(new DateTimeImmutable('2026-10-15'));
    $bdayEmpty->setStartTime('08:00');
    $bdayEmpty->setEndTime('16:00');
    check('bilans z ticketów: godziny bez wierszy to pusty dzień', $reports->summary($bdayEmpty)->kind(), 'empty');
    // miesiąc: bilans sumuje się z ticketów
    $mb = new WorkDay(new DateTimeImmutable('2026-11-02'));
    $mb->setStartTime('08:00');
    $mb->setEndTime('16:00');
    new WorklogEntry($mb, 'ABC-1', 480, 'a', 0);
    $setBalance('presence');
    $mp = $months->build(new DateTimeImmutable('2026-11-01'), ['2026-11-02' => $mb], new DateTimeImmutable('2026-11-18'))['totals'];
    $setBalance('tickets');
    $mtk = $months->build(new DateTimeImmutable('2026-11-01'), ['2026-11-02' => $mb], new DateTimeImmutable('2026-11-18'))['totals'];
    check('miesiąc z obecności', [$mp['balance'], $mp['worked'], $mp['logged']], [0, 420, 480]);
    check('miesiąc z ticketów', [$mtk['balance'], $mtk['worked'], $mtk['logged'], $mtk['overDays']], [60, 480, 480, 1]);
    $setBalance('presence');

    // --- forma w tekstach ---
    check('forma domyślnie neutralna', [Settings::DEFAULTS['forma'], Settings::DEFAULTS['mail_over_body']], ['n', Settings::GENDERED['mail_over_body']['n']]);
    check('mail w formie męskiej', str_contains(Settings::defaultsFor('m')['mail_under_body'], 'pracowałem krócej o {roznica}'), true);
    check('mail w formie neutralnej', str_contains(Settings::defaultsFor('n')['mail_over_body'], 'mój czas pracy był dłuższy o {roznica}'), true);
    check('wszystkie formy mają warianty', array_map(static fn (array $v) => array_keys($v), Settings::GENDERED), ['mail_over_body' => ['f', 'm', 'n'], 'mail_under_body' => ['f', 'm', 'n']]);
    foreach (['f' => 'Pracowałam', 'm' => 'Pracowałem', 'n' => 'Praca'] as $f => $expected) {
        (new ReflectionProperty(Settings::class, 'cache'))->setValue($settings, Settings::defaultsFor($f));
        check('form() dla '.$f, $settings->form('Pracowałam', 'Pracowałem', 'Praca'), $expected);
    }

    // --- Toggl Track ---
    final class TogglFakeHttp implements HttpClientInterface
    {
        public array $calls = [];
        /** fragment URL → [status, treść]; wygrywa pierwszy pasujący */
        public array $routes = [];
        public function request(string $method, string $url, array $options = [])
        {
            $this->calls[] = [$method, $url, $options['query'] ?? null, $options['auth_basic'] ?? null];
            [$status, $body] = [404, ''];
            foreach ($this->routes as $needle => $route) {
                if (str_contains($url, $needle)) {
                    [$status, $body] = $route;
                    break;
                }
            }
            return new class($status, is_string($body) ? $body : json_encode($body)) {
                public function __construct(private int $s, private string $b) {}
                public function getStatusCode(): int { return $this->s; }
                public function getContent(bool $throw = true): string { return $this->b; }
            };
        }
    }
    $te = static fn (int $id, string $desc, string $start, int $duration, ?int $project = null): array => [
        'id' => $id, 'description' => $desc, 'start' => $start, 'stop' => $duration < 0 ? null : $start,
        'duration' => $duration, 'project_id' => $project, 'workspace_id' => 1, 'tags' => [], 'billable' => false,
    ];
    $togglEntries = static fn (int $firstDuration): array => [
        $te(1, 'ABC-123 poprawka logowania', '2026-10-20T07:00:00+00:00', $firstDuration),
        $te(2, 'abc-123 poprawka logowania', '2026-10-20T08:00:00+00:00', 1050),
        $te(3, 'ABC-9 review', '2026-10-20T09:00:00+00:00', 3600),
        $te(4, 'praca', '2026-10-20T10:00:00+00:00', 900, 77),
        $te(5, 'spotkanie', '2026-10-20T11:00:00+00:00', 1800),
        $te(6, 'mail', '2026-10-20T11:30:00+00:00', 900, 88),
        $te(8, 'ABC-77 wczoraj', '2026-10-19T21:30:00+00:00', 600),   // 23:30 poprzedniej doby lokalnie
        $te(9, 'ABC-78 jutro', '2026-10-20T22:30:00+00:00', 600),     // 00:30 następnej doby lokalnie
    ];
    $tvalues = $values;
    $tvalues['toggl_token'] = 'ttok';
    $tsettings = (new ReflectionClass(Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Settings::class, 'cache'))->setValue($tsettings, $tvalues);
    $tHttp = new TogglFakeHttp();
    $tHttp->routes = [
        '/me/time_entries' => [200, $togglEntries(750)],
        '/me/projects' => [200, [['id' => 77, 'name' => 'XYZ-5 Projekt'], ['id' => 88, 'name' => 'Inne']]],
        '/me' => [200, ['fullname' => 'Anna Nowak', 'email' => 'anna@firma.pl']],
    ];
    $tClient = new App\Service\TogglClient($tHttp, $tsettings);
    $titleRepo = new class extends App\Repository\WorkDayRepository {
        public function __construct() {}
        public function knownIssueTitles(?\DateTimeImmutable $since = null): array { return ['ABC-9' => 'Moduł raportów']; }
    };
    $import = new App\Service\TogglImport($tClient, $tsettings, $titleRepo, new ReportBuilder($tsettings, $cal));

    // komunikaty importu bez informacji o godzinach obecności (te mają osobne testy)
    $msgs = static fn (array $res): array => array_values(array_map(fn ($r) => $r['type'].': '.$r['message'],
        array_filter($res, fn ($r) => !str_starts_with($r['message'], 'Godziny obecności z Toggla'))));
    check('Toggl me()', $tClient->me(), ['name' => 'Anna Nowak', 'email' => 'anna@firma.pl']);
    check('Toggl: Basic auth token + api_token', end($tHttp->calls)[3], ['ttok', 'api_token']);

    $tday = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $manual = new WorklogEntry($tday, 'ABC-9', 10, 'review', 0); // ręczny – bez togglIds, nie może się połączyć
    $tHttp->calls = [];
    $res = $import->import($tday);
    check('import: zakres dnia w RFC3339', $tHttp->calls[0][2], ['start_date' => '2026-10-20T00:00:00+02:00', 'end_date' => '2026-10-21T00:00:00+02:00']);
    check('import: projekty raz', count(array_filter($tHttp->calls, fn ($c) => str_contains($c[1], '/me/projects'))), 1);
    check('import: komunikaty', $msgs($res), [
        'success: Pobrano z Toggla: 3 wpisy (3 nowe) – razem 1h 45m.',
        'warning: Pominięto wpisy bez numeru ticketu (w opisie ani w nazwie projektu): „spotkanie” 30m, „mail” 15m – dodaj je ręcznie.',
    ]);
    check('import: godziny z wszystkich wpisów (także pominiętych)', [$tday->getStartTime(), $tday->getEndTime(), $tday->getTogglHours(), end($res)['message']],
        ['09:00', '13:45', ['start' => '09:00', 'end' => '13:45'], 'Godziny obecności z Toggla: 09:00–13:45.']);
    $rows = $tday->getActiveEntries();
    check('import: wierszy (ręczny + 3)', count($rows), 4);
    [, $r1, $r2, $r3] = $rows;
    check('import: grupa ABC-123 – suma z resztą', [$r1->getIssueKey(), $r1->getDescription(), $r1->getMinutes(), $r1->getCarrySeconds(), $r1->getTogglIds(), $r1->getStartClock()],
        ['ABC-123', 'poprawka logowania', 30, 0, [1, 2], '09:00']);
    check('import: godzina lokalna, tytuł ze znanych', [$r2->getIssueKey(), $r2->getMinutes(), $r2->getStartClock(), $r2->getIssueTitle(), $r2->getPosition()], ['ABC-9', 60, '11:00', 'Moduł raportów', 2]);
    check('import: numer z nazwy projektu, cały opis', [$r3->getIssueKey(), $r3->getDescription(), $r3->getMinutes(), $r3->getStartClock()], ['XYZ-5', 'praca', 15, '12:00']);
    check('import: ręczny wiersz nietknięty', [$manual->getMinutes(), $manual->getTogglIds()], [10, []]);

    // ponowny import: czas się aktualizuje, ticket i opis zmienione w aplikacji zostają, nic się nie dubluje
    $r1->update('ABC-124', $r1->getMinutes(), 'zmieniony opis', $r1->getPosition());
    $r1->setIssueTitle('Własny tytuł');
    $tHttp->routes['/me/time_entries'] = [200, $togglEntries(900)];
    $res = $import->import($tday);
    check('reimport: komunikat', $res[0], ['type' => 'success', 'message' => 'Pobrano z Toggla: 3 wpisy (3 zaktualizowane) – razem 1h 48m.']);
    check('reimport: bez duplikatów', count($tday->getActiveEntries()), 4);
    check('reimport: czas zaokrąglony (900+1050 s = 32m 30s → 33m)', [$r1->getMinutes(), $r1->getCarrySeconds()], [33, -30]);
    check('reimport: ticket/opis/tytuł z aplikacji zostają', [$r1->getIssueKey(), $r1->getDescription(), $r1->getIssueTitle()], ['ABC-124', 'zmieniony opis', 'Własny tytuł']);

    // stoper aplikacji przy wierszu: czasu nie ruszamy
    $r2->startTimer(new DateTimeImmutable('2026-10-20 10:00'));
    $r2->setTrackedSeconds(60);
    $tHttp->routes['/me/time_entries'] = [200, $togglEntries(750)];
    $res = $import->import($tday);
    check('reimport: stoper aplikacji', [$r2->getMinutes(), in_array(['type' => 'warning', 'message' => 'Przy tickecie ABC-9 działa stoper aplikacji – czasu z Toggla nie zmieniono. Zatrzymaj stoper i pobierz ponownie.'], $res, true)], [1, true]);

    // poniższe testy sprawdzają samo zaokrąglanie – domykanie do obecności ma osobną sekcję niżej
    $setClose = static function (string $minutes) use ($tsettings): void {
        $c = new ReflectionProperty(Settings::class, 'cache');
        $c->setValue($tsettings, ['toggl_close_minutes' => $minutes] + $c->getValue($tsettings));
    };
    $setClose('0');

    // działający stoper w Togglu (duration < 0, brak stop) jest doliczany do chwili pobrania
    $tHttp->routes['/me/time_entries'] = [200, [
        $te(40, 'TER-379 workery kolejek', '2026-10-20T12:00:00+00:00', 1200),   // 14:00–14:20
        $te(41, 'TER-379 workery kolejek', '2026-10-20T14:00:00+00:00', -1),     // 16:00, nadal działa
    ]];
    $dRun = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $res = $import->import($dRun, new DateTimeImmutable('2026-10-20 16:17:30'));
    [$rr] = $dRun->getActiveEntries();
    check('stoper Toggla – komunikaty', $msgs($res), [
        'success: Pobrano z Toggla: 1 wpis (1 nowy) – razem 38m.',
        'warning: Stoper w Togglu nadal działa („TER-379 workery kolejek”) – doliczono 18m do tej chwili; po zatrzymaniu pobierz ponownie.',
    ]);
    check('stoper Toggla – 20m + 17m 30s w jednej grupie', [$rr->getMinutes(), $rr->getCarrySeconds(), $rr->getTogglIds(), $rr->getStartClock()], [38, -30, [40, 41], '14:00']);
    $import->import($dRun, new DateTimeImmutable('2026-10-20 16:25:00'));
    check('stoper Toggla – kolejne pobranie podnosi czas bez duplikatu', [count($dRun->getActiveEntries()), $rr->getMinutes(), $rr->getCarrySeconds()], [1, 45, 0]);
    check('stoper Toggla – obecność do „teraz”', [$dRun->getStartTime(), $dRun->getEndTime()], ['14:00', '16:25']);
    // krócej niż minuta: zaokrąglenie do najbliższej minuty, różnica w carrySeconds
    $tHttp->routes['/me/time_entries'] = [200, [$te(42, 'TER-380 nowe', '2026-10-20T14:00:00+00:00', -1)]];
    $dShort = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $import->import($dShort, new DateTimeImmutable('2026-10-20 16:00:40'));
    [$rs] = $dShort->getActiveEntries();
    check('stoper Toggla – poniżej minuty', [$rs->getMinutes(), $rs->getCarrySeconds()], [1, -20]);

    // zaokrąglanie do najbliższej minuty: 47m 55s → 48m, carry −5; ponowny import to samo; stoper aplikacji liczy dokładnie
    $tHttp->routes['/me/time_entries'] = [200, [$te(50, 'TER-381 zaokrąglenie', '2026-10-20T14:00:00+00:00', 2875)]];
    $dRound = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $res = $import->import($dRound);
    [$ro] = $dRound->getActiveEntries();
    check('zaokrąglanie: 2875 s → 48m, carry -5', [$ro->getMinutes(), $ro->getCarrySeconds(), $ro->liveSeconds(new DateTimeImmutable())], [48, -5, 2875]);
    check('zaokrąglanie: komunikat „razem” = minuty wiersza', $msgs($res)[0], 'success: Pobrano z Toggla: 1 wpis (1 nowy) – razem 48m.');
    $import->import($dRound);
    check('zaokrąglanie: ponowny import bez zmian', [count($dRound->getActiveEntries()), $ro->getMinutes(), $ro->getCarrySeconds()], [1, 48, -5]);
    $ro->addTrackedSeconds(5);
    check('zaokrąglanie: stoper +5 s → dokładnie 48m', [$ro->getMinutes(), $ro->getCarrySeconds()], [48, 0]);
    $ro->setTrackedSeconds(2875);
    $ro->addTrackedSeconds(5);
    $ro->addTrackedSeconds(65);
    check('zaokrąglanie: stoper +5 s +65 s → 49m 5s', [$ro->getMinutes(), $ro->getCarrySeconds()], [49, 5]);
    // kilka wierszy: „razem” to suma zaokrąglonych wierszy
    $tHttp->routes['/me/time_entries'] = [200, [
        $te(51, 'TER-1 a', '2026-10-20T07:00:00+00:00', 1790),
        $te(52, 'TER-2 b', '2026-10-20T08:00:00+00:00', 1790),
        $te(53, 'TER-3 c', '2026-10-20T09:00:00+00:00', 1790),
    ]];
    $dSum = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $res = $import->import($dSum);
    check('zaokrąglanie: „razem” = suma minut wierszy', [array_sum(array_map(fn ($r) => $r->getMinutes(), $dSum->getActiveEntries())), $msgs($res)[0]],
        [90, 'success: Pobrano z Toggla: 3 wpisy (3 nowe) – razem 1h 30m.']);

    // --- domykanie czasu do obecności ---
    $setClose('5');
    check('toggl_close_minutes domyślnie 5', Settings::DEFAULTS['toggl_close_minutes'], '5');
    check('toggl_close_minutes: walidacja liczb całkowitych jak tolerancja', str_contains(file_get_contents(__DIR__.'/../src/Controller/SettingsController.php'), "'tolerance_minutes', 'toggl_close_minutes', 'plan_notice_hours'] as \$k"), true);
    // 7 wpisów ciągiem od 08:34:50 (lokalnie), razem 3h 6m 12s; obecność 08:34–11:41 = 187 min, obiad 12–13 poza zakresem
    $closeDurations = [1114, 2094, 509, 3163, 1044, 787, 2461];
    $closeEntries = static function (array $durations, int $gapAfterFirst = 0) use ($te): array {
        $t = new DateTimeImmutable('2026-10-20T06:34:50+00:00');
        $out = [];
        foreach ($durations as $i => $d) {
            if (1 === $i) {
                $t = $t->modify('+'.$gapAfterFirst.' seconds');
            }
            $out[] = $te(200 + $i, 'CLS-'.($i + 1).' zadanie', $t->format('c'), $d);
            $t = $t->modify('+'.$d.' seconds');
        }
        return $out;
    };
    $minutesOf = static fn (WorkDay $d): array => array_map(fn ($r) => $r->getMinutes(), $d->getActiveEntries());
    $tHttp->routes['/me/time_entries'] = [200, $closeEntries($closeDurations)];
    $dc1 = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $res = $import->import($dc1);
    check('domykanie: minuty trafiają do wierszy z największą resztą (54, 43, 34, 29)', $minutesOf($dc1), [19, 35, 9, 53, 17, 13, 41]);
    check('domykanie: suma = obecność, komunikaty', [array_sum($minutesOf($dc1)), $dc1->getStartTime(), $dc1->getEndTime(), $msgs($res)], [187, '08:34', '11:41', [
        'success: Pobrano z Toggla: 7 wpisów (7 nowych) – razem 3h 7m.',
        'info: Domknięto czas do obecności (3h 7m): +1 min w wierszach z Toggla.',
    ]]);
    check('domykanie: minutes*60 + carry = sekundy grupy', array_map(fn ($r) => $r->getMinutes() * 60 + $r->getCarrySeconds(), $dc1->getActiveEntries()), $closeDurations);
    $before = array_map(fn ($r) => [$r->getMinutes(), $r->getCarrySeconds()], $dc1->getActiveEntries());
    $res = $import->import($dc1);
    check('domykanie: ponowny import nic nie zmienia i nie dubluje', [count($dc1->getActiveEntries()), array_map(fn ($r) => [$r->getMinutes(), $r->getCarrySeconds()], $dc1->getActiveEntries())], [7, $before]);
    check('domykanie: ponowny import – ten sam komunikat', $msgs($res)[1], 'info: Domknięto czas do obecności (3h 7m): +1 min w wierszach z Toggla.');
    // różnica 0 – wynik identyczny ze zwykłym zaokrągleniem, bez komunikatu
    $tHttp->routes['/me/time_entries'] = [200, [$te(250, 'CLS-1 a', '2026-10-20T07:00:00+00:00', 1829), $te(251, 'CLS-2 b', '2026-10-20T07:30:29+00:00', 1771)]]; // 09:00–10:00 = 60 min, 30m29s + 29m31s
    $dz = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $res = $import->import($dz);
    check('domykanie: różnica 0 = zaokrąglenie, bez komunikatu', [$minutesOf($dz), count($msgs($res))], [[30, 30], 1]);

    // przerwa 15 min bez stopera – różnica poza progiem, zostaje zaokrąglenie
    $tHttp->routes['/me/time_entries'] = [200, $closeEntries($closeDurations, 900)];
    $dgap = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $res = $import->import($dgap);
    check('domykanie: duża różnica (przerwa 15 min) – bez domykania i bez komunikatu', [$minutesOf($dgap), $dgap->getEndTime(), count($msgs($res))], [[19, 35, 8, 53, 17, 13, 41], '11:56', 1]);
    // próg 0 – wyłączone
    $setClose('0');
    $tHttp->routes['/me/time_entries'] = [200, $closeEntries($closeDurations)];
    $doff = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $res = $import->import($doff);
    check('domykanie: próg 0 – bez domykania', [$minutesOf($doff), count($msgs($res))], [[19, 35, 8, 53, 17, 13, 41], 1]);
    $setClose('5');

    // różnica ujemna: wiersz zaokrąglony w górę oddaje minutę
    $tHttp->routes['/me/time_entries'] = [200, [$te(260, 'CLS-1 a', '2026-10-20T06:00:00+00:00', 1830)]]; // 08:00–08:30 = 30 min, wiersz 30m30s
    $dneg = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $res = $import->import($dneg);
    [$rn] = $dneg->getActiveEntries();
    check('domykanie: ujemna różnica cofa zaokrąglenie w górę', [$rn->getMinutes(), $rn->getCarrySeconds(), $msgs($res)[1]], [30, 30, 'info: Domknięto czas do obecności (30m): -1 min w wierszach z Toggla.']);
    // ujemna różnica bez wierszy zaokrąglonych w górę – bez domykania
    $tHttp->routes['/me/time_entries'] = [200, [$te(261, 'CLS-1 a', '2026-10-20T06:00:00+00:00', 1800)]]; // 30 min
    $dneg2 = new WorkDay(new DateTimeImmutable('2026-10-20'));
    new WorklogEntry($dneg2, 'CLS-9', 1, 'ręczny', 0);
    $res = $import->import($dneg2);
    check('domykanie: ujemna różnica bez zaokrągleń w górę – bez domykania', [$minutesOf($dneg2), count($msgs($res))], [[1, 30], 1]);

    // wiersz ręczny liczy się do sumy, ale nie dostaje minut
    $tHttp->routes['/me/time_entries'] = [200, $closeEntries($closeDurations)];
    $dman = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $manualRow = new WorklogEntry($dman, 'CLS-9', 3, 'ręczny', 0);
    $res = $import->import($dman);
    check('domykanie: wiersz ręczny nietknięty, reszta dopełniona do obecności', [$manualRow->getMinutes(), array_sum($minutesOf($dman)), array_slice($minutesOf($dman), 1)], [3, 187, [18, 35, 8, 52, 17, 13, 41]]);
    check('domykanie: komunikat z ręcznym wierszem', $msgs($res)[1], 'info: Domknięto czas do obecności (3h 7m): -2 min w wierszach z Toggla.');

    // stoper aplikacji w imporcie – bez domykania
    $tHttp->routes['/me/time_entries'] = [200, $closeEntries($closeDurations)];
    $dtm = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $import->import($dtm);
    $dtm->getActiveEntries()[0]->startTimer(new DateTimeImmutable('2026-10-20 12:00'));
    $dtm->getActiveEntries()[0]->setTrackedSeconds(1114);
    foreach (array_slice($dtm->getActiveEntries(), 1) as $r) { $r->setTrackedSeconds(60); }
    $res = $import->import($dtm);
    check('domykanie: stoper aplikacji – pozostałe wiersze tylko zaokrąglone', array_slice($minutesOf($dtm), 1), [35, 8, 53, 17, 13, 41]);
    check('domykanie: stoper aplikacji – brak komunikatu o domknięciu', count(array_filter($msgs($res), fn ($m) => str_contains($m, 'Domknięto'))), 0);
    $setClose('0');

    // wiersz usunięty w aplikacji wraca przy ponownym imporcie jako nowy; wiersz deleted=true (czeka na skasowanie z Jiry) nie jest dopasowywany ani wskrzeszany
    $tHttp->routes['/me/time_entries'] = [200, [
        $te(10, 'ABC-1 alfa', '2026-10-20T07:00:00+00:00', 1800),
        $te(11, 'ABC-2 beta', '2026-10-20T08:00:00+00:00', 1200),
        $te(12, 'ABC-3 gamma', '2026-10-20T09:00:00+00:00', 600),
    ]];
    $dd = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $import->import($dd);
    [$da, $db, $dc] = $dd->getActiveEntries();
    $dd->removeEntry($db);
    $res = $import->import($dd);
    check('usunięty w aplikacji wraca jako nowy wiersz', [count($dd->getActiveEntries()), $msgs($res)], [3, ['success: Pobrano z Toggla: 3 wpisy (1 nowy, 2 zaktualizowane) – razem 1h.']]);
    $back = array_values(array_filter($dd->getActiveEntries(), fn ($r) => 'ABC-2' === $r->getIssueKey()));
    check('wrócił z id i czasem z Toggla', [count($back), $back[0]->getMinutes(), $back[0]->getTogglIds()], [1, 20, [11]]);
    $dc->markSynced('55', 'h');
    $dc->markDeleted();
    $res = $import->import($dd);
    $gamma = array_values(array_filter($dd->getActiveEntries(), fn ($r) => 'ABC-3' === $r->getIssueKey()));
    check('deleted=true nie jest dopasowany ani wskrzeszony – grupa daje nowy wiersz', [count($dd->getPendingDeletions()), $dc->isDeleted(), $dc->getMinutes(), count($gamma), $gamma[0]->getTogglIds(), $gamma[0] === $dc], [1, true, 10, 1, [12], false]);
    check('deleted=true – komunikat', $msgs($res)[0], 'success: Pobrano z Toggla: 3 wpisy (1 nowy, 2 zaktualizowane) – razem 1h.');

    // godziny obecności dnia z pierwszego i ostatniego wpisu Toggla (także pominiętych, np. projekt „Przerwa”)
    $hoursEntries = static fn (int $lastDuration): array => [
        $te(50, 'ABC-1 a', '2026-10-20T05:23:30+00:00', 14400),             // 07:23–11:23
        $te(51, 'przerwa', '2026-10-20T09:23:00+00:00', 1800),              // bez ticketu – pominięty, ale liczy się do obecności
        $te(52, 'ABC-1 b', '2026-10-20T11:23:00+00:00', $lastDuration),     // do 15:23
    ];
    $tHttp->routes['/me/time_entries'] = [200, $hoursEntries(7200)];
    // nowy dzień z domyślnymi godzinami (jak go tworzy kontroler) i przerwą, której import nie rusza
    $dh = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $dh->setStartTime('08:00');
    $dh->setEndTime('16:00');
    $dh->setBreakMinutes(20);
    $res = $import->import($dh);
    check('godziny: nowy dzień dostaje godziny z Toggla (pełne minuty w dół)', [$dh->getStartTime(), $dh->getEndTime(), $dh->getTogglHours(), $dh->getBreakMinutes(), $dh->getBreakText()],
        ['07:23', '15:23', ['start' => '07:23', 'end' => '15:23'], 20, null]);
    check('godziny: komunikat info', array_column(array_filter($res, fn ($r) => 'info' === $r['type']), 'message'), ['Godziny obecności z Toggla: 07:23–15:23.']);
    // ponowny import: godziny równe poprzednim z toggl_hours są aktualizowane
    $tHttp->routes['/me/time_entries'] = [200, $hoursEntries(9000)];
    $res = $import->import($dh);
    check('godziny: ponowny import aktualizuje', [$dh->getStartTime(), $dh->getEndTime(), $dh->getTogglHours()['end'], array_column(array_filter($res, fn ($r) => 'info' === $r['type']), 'message')],
        ['07:23', '15:53', '15:53', ['Godziny obecności z Toggla: 07:23–15:53.']]);
    // te same godziny przy kolejnym imporcie – bez komunikatu
    $res = $import->import($dh);
    check('godziny: bez zmiany godzin brak komunikatu', array_column(array_filter($res, fn ($r) => 'info' === $r['type']), 'message'), []);
    // flaga hoursByUser (godziny wpisał użytkownik): zostają, toggl_hours bez zmian, bez komunikatu
    $dh->markHoursByUser();
    $dh->setEndTime('17:00');
    $tHttp->routes['/me/time_entries'] = [200, $hoursEntries(10800)];
    $res = $import->import($dh);
    check('godziny: hoursByUser – zostają', [$dh->getStartTime(), $dh->getEndTime(), $dh->getTogglHours()['end'], array_column(array_filter($res, fn ($r) => 'info' === $r['type']), 'message')],
        ['07:23', '17:00', '15:53', []]);
    // flaga false + dowolne godziny (także inne niż domyślne) → nadpisane
    $dl = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $dl->setStartTime('08:30');
    $dl->setEndTime('16:45');
    check('godziny: domyślnie hoursByUser = false, toggl_hours = null', [$dl->isHoursByUser(), $dl->getTogglHours()], [false, null]);
    $import->import($dl);
    check('godziny: bez flagi nadpisane z Toggla', [$dl->getStartTime(), $dl->getEndTime()], ['07:23', '16:23']);
    // flaga true na dniu bez toggl_hours – zostają
    $du = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $du->setStartTime('08:00');
    $du->setEndTime('16:00');
    $du->markHoursByUser();
    $res = $import->import($du);
    check('godziny: hoursByUser bez toggl_hours zostają', [$du->getStartTime(), $du->getEndTime(), $du->getTogglHours(), array_column(array_filter($res, fn ($r) => 'info' === $r['type']), 'message')], ['08:00', '16:00', null, []]);
    // puste godziny (np. dzień wolny) – import ustawia
    $de = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $import->import($de);
    check('godziny: puste → z Toggla', [$de->getStartTime(), $de->getEndTime()], ['07:23', '16:23']);
    // brak wpisów – godziny bez zmian
    $tHttp->routes['/me/time_entries'] = [200, []];
    $dn = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $dn->setStartTime('08:00');
    $dn->setEndTime('16:00');
    $res = $import->import($dn);
    check('godziny: brak wpisów → bez zmian', [$dn->getStartTime(), $dn->getEndTime(), $dn->getTogglHours(), $msgs($res)], ['08:00', '16:00', null, ['info: Toggl nie ma wpisów z tego dnia.']]);
    // koniec po północy przycięty do 23:59
    $tHttp->routes['/me/time_entries'] = [200, [$te(60, 'ABC-1 noc', '2026-10-20T20:00:00+00:00', 7200)]]; // 22:00–00:00
    $dm0 = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $import->import($dm0);
    check('godziny: koniec po północy → 23:59', [$dm0->getStartTime(), $dm0->getEndTime()], ['22:00', '23:59']);

    // startClock = lokalny start NAJWCZEŚNIEJSZEGO wpisu grupy, także gdy API zwraca wpisy od najnowszego
    $tHttp->routes['/me/time_entries'] = [200, [
        $te(72, 'ABC-6 trzy', '2026-10-20T11:05:59+00:00', 600),   // 13:05 (najnowszy, pierwszy w odpowiedzi)
        $te(71, 'ABC-6 trzy', '2026-10-20T08:40:30+00:00', 900),   // 10:40
        $te(70, 'ABC-6 trzy', '2026-10-20T07:15:00+00:00', 300),   // 09:15 (najwcześniejszy)
    ]];
    $dsc = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $import->import($dsc);
    [$sc] = $dsc->getActiveEntries();
    check('startClock = najwcześniejszy start grupy', [$sc->getMinutes(), $sc->getStartClock(), $sc->getTogglIds()], [30, '09:15', [70, 71, 72]]);
    // ponowny import aktualizuje startClock tą samą regułą (Toggl jest źródłem czasu)
    $sc->setStartClock('11:00');
    $tHttp->routes['/me/time_entries'] = [200, [
        $te(72, 'ABC-6 trzy', '2026-10-20T11:05:59+00:00', 600),
        $te(71, 'ABC-6 trzy', '2026-10-20T08:40:30+00:00', 900),
        $te(70, 'ABC-6 trzy', '2026-10-20T07:15:00+00:00', 300),
        $te(73, 'ABC-6 trzy', '2026-10-20T06:50:00+00:00', 60),     // 08:50 – nowy, wcześniejszy
    ]];
    $import->import($dsc);
    check('ponowny import aktualizuje startClock', [count($dsc->getActiveEntries()), $sc->getMinutes(), $sc->getStartClock()], [1, 31, '08:50']);

    // wiersz kończący się po północy nie dostaje godziny „od”
    $tHttp->routes['/me/time_entries'] = [200, [
        $te(80, 'ABC-1 późno', '2026-10-20T21:10:00+00:00', 3600),    // 23:10 + 60m → po północy
        $te(81, 'ABC-2 wcześniej', '2026-10-20T20:00:00+00:00', 3600), // 22:00 + 60m → 23:00
    ]];
    $dpm = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $res = $import->import($dpm);
    $byKey = [];
    foreach ($dpm->getActiveEntries() as $r) { $byKey[$r->getIssueKey()] = $r->getStartClock(); }
    ksort($byKey);
    check('północ: startClock tylko gdy wiersz się mieści', $byKey, ['ABC-1' => null, 'ABC-2' => '22:00']);
    check('północ: ostrzeżenie', array_values(array_filter($msgs($res), fn ($m) => str_contains($m, 'po północy'))), ['warning: „późno” (ABC-1, 1h): wpis kończyłby się po północy – bez godziny „od”.']);
    // ponowny import też zdejmuje godzinę „od”, gdy wiersz urósł ponad północ
    $tHttp->routes['/me/time_entries'] = [200, [$te(81, 'ABC-2 wcześniej', '2026-10-20T20:00:00+00:00', 7500)]]; // 22:00 + 2h05m
    $import->import($dpm);
    $r2 = array_values(array_filter($dpm->getActiveEntries(), fn ($r) => 'ABC-2' === $r->getIssueKey()))[0];
    check('północ: ponowny import – wiersz urósł', [$r2->getMinutes(), $r2->getStartClock()], [125, null]);

    // (c) dwa wiersze pasują do jednej grupy: pierwszy bierze sumę, drugi znika, czas nie liczy się podwójnie
    foreach ([false, true] as $inJira) {
        $tHttp->routes['/me/time_entries'] = [200, [
            $te(20, 'ABC-5 jedno', '2026-10-20T07:00:00+00:00', 900),
            $te(21, 'ABC-5 jedno', '2026-10-20T08:00:00+00:00', 1800),
        ]];
        $dm = new WorkDay(new DateTimeImmutable('2026-10-20'));
        $ma = new WorklogEntry($dm, 'ABC-5', 15, 'a', 0);
        $mb = new WorklogEntry($dm, 'ABC-5', 30, 'b', 1);
        $ma->setTogglIds([20]);
        $mb->setTogglIds([21]);
        if ($inJira) {
            $mb->markSynced('77', 'h');
        }
        $res = $import->import($dm);
        $tail = $inJira ? ' (z Jiry zniknie przy następnej wysyłce)' : '';
        check('scalenie – komunikaty (Jira: '.var_export($inJira, true).')', $msgs($res), [
            'success: Pobrano z Toggla: 1 wpis (1 zaktualizowany) – razem 45m.',
            'warning: Wpisy z Toggla połączyły się: czas wiersza „b” (ABC-5, 30m) przeszedł do „a”; wiersz b usunięto'.$tail.'.',
        ]);
        check('scalenie – suma w pierwszym, drugi usunięty', [$ma->getMinutes(), $ma->getTogglIds(), count($dm->getActiveEntries()), $dm->getEntries()->contains($mb), $mb->isDeleted()],
            [45, [20, 21], 1, $inJira, $inJira]);
        $import->import($dm);
        check('scalenie – ponowny import bez zmian i bez wskrzeszeń', [count($dm->getActiveEntries()), $ma->getMinutes()], [1, 45]);
    }

    // (d) wiersz, którego wpisów nie ma już w Togglu – bez zmian, z ostrzeżeniem
    $tHttp->routes['/me/time_entries'] = [200, [$te(30, 'ABC-8 inne', '2026-10-20T07:00:00+00:00', 600)]];
    $dg = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $gone = new WorklogEntry($dg, 'ABC-7', 60, 'x', 0);
    $gone->setTogglIds([999]);
    $res = $import->import($dg);
    check('wiersz bez wpisów w Togglu', [$gone->getMinutes(), $gone->getTogglIds(), $msgs($res)], [60, [999], [
        'success: Pobrano z Toggla: 1 wpis (1 nowy) – razem 10m.',
        'warning: Wiersz „x” (ABC-7, 1h) nie ma już wpisów w Togglu – czas zostawiono bez zmian.',
    ]]);

    // odpowiedź niebędąca listą wpisów
    $tHttp->routes['/me/time_entries'] = [200, ['error' => 'x']];
    check('odpowiedź nie-lista', $import->import(new WorkDay(new DateTimeImmutable('2026-10-21'))), [['type' => 'error', 'message' => 'Nieoczekiwana odpowiedź Toggla (spodziewano się listy wpisów).']]);

    // znacznik pobrania na dniu
    $stamp = new WorkDay(new DateTimeImmutable('2026-10-20'));
    check('togglSyncedAt domyślnie null', $stamp->getTogglSyncedAt(), null);
    $stamp->markTogglSynced(new DateTimeImmutable('2026-10-20 16:20'));
    check('markTogglSynced', $stamp->getTogglSyncedAt()?->format('H:i'), '16:20');

    // brak wpisów, błąd tokenu, brak tokenu
    $tHttp->routes['/me/time_entries'] = [200, []];
    check('import: brak wpisów', $import->import(new WorkDay(new DateTimeImmutable('2026-10-21'))), [['type' => 'info', 'message' => 'Toggl nie ma wpisów z tego dnia.']]);
    $tHttp->routes['/me/time_entries'] = [401, 'Incorrect username and/or password'];
    check('import: 401', $import->import(new WorkDay(new DateTimeImmutable('2026-10-21'))), [['type' => 'error', 'message' => 'Toggl odrzucił token (401) – sprawdź token API w Ustawieniach.']]);
    $tHttp->routes['/me/time_entries'] = [429, ''];
    check('import: 429', $import->import(new WorkDay(new DateTimeImmutable('2026-10-21')))[0]['message'], 'Toggl ogranicza liczbę zapytań (429) – spróbuj za chwilę.');
    (new ReflectionProperty(Settings::class, 'cache'))->setValue($tsettings, ['toggl_token' => ''] + $tvalues);
    check('import: brak tokenu', $import->import(new WorkDay(new DateTimeImmutable('2026-10-21'))), [['type' => 'error', 'message' => 'Wpisz token Toggla w Ustawieniach.']]);
    check('togglConfigured', [$tsettings->togglConfigured(), $settings->togglConfigured()], [false, false]);
    (new ReflectionProperty(Settings::class, 'cache'))->setValue($tsettings, $tvalues);
    check('togglConfigured z tokenem', $tsettings->togglConfigured(), true);

    // tokeny Jiry i Toggla trafiają do plików obok bazy, w bazie zostaje pusto; token z bazy przechodzi do pliku
    $dir = sys_get_temp_dir().'/worklog-tok-'.getmypid();
    mkdir($dir);
    $tokEm = new class($dir.'/worklog.db') implements Doctrine\ORM\EntityManagerInterface {
        public array $rows = [];
        public array $cleared = [];
        public function __construct(private string $path) {}
        public function getRepository($class)
        {
            $em = $this;
            return new class($em) {
                public function __construct(private $em) {}
                public function findAll(): array { return array_values($this->em->rows); }
                public function find($name) { return $this->em->rows[$name] ?? null; }
            };
        }
        public function persist($o): void { $this->rows[$o->getName()] = $o; }
        public function flush(): void {}
        public function getConnection()
        {
            $em = $this;
            return new class($this->path, $em) {
                public function __construct(private string $p, private $em) {}
                public function getParams(): array { return ['path' => $this->p]; }
                public function executeStatement(string $sql, array $params = []): int { $this->em->cleared[] = $params[0] ?? $sql; return 1; }
            };
        }
    };
    $real = new Settings($tokEm);
    $real->save(['toggl_token' => ' tt-1 ', 'jira_token' => 'jj-1', 'signature' => 'Ola']);
    check('token Toggla w pliku', [trim(file_get_contents($dir.'/.toggl_token')), substr(sprintf('%o', fileperms($dir.'/.toggl_token')), -3)], ['tt-1', '600']);
    check('token Jiry w pliku', file_get_contents($dir.'/.jira_token'), 'jj-1');
    check('tokeny nie trafiają do bazy', array_keys($tokEm->rows), ['signature']);
    check('odczyt tokenów z plików', [$real->get('toggl_token'), $real->get('jira_token'), $real->togglConfigured()], ['tt-1', 'jj-1', true]);
    unlink($dir.'/.toggl_token');
    $tokEm->rows = ['toggl_token' => new App\Entity\Setting('toggl_token', 'stary-z-bazy')];
    $migrated = new Settings($tokEm);
    check('token Toggla z bazy przechodzi do pliku', [$migrated->get('toggl_token'), trim(file_get_contents($dir.'/.toggl_token')), $tokEm->cleared], ['stary-z-bazy', 'stary-z-bazy', ['toggl_token']]);
    foreach (['.toggl_token', '.jira_token'] as $f) {
        @unlink($dir.'/'.$f);
    }
    @rmdir($dir);

    echo $fail ? "\n$fail błędów\n" : "OK – wszystkie testy serwisów przeszły\n";
    exit($fail ? 1 : 0);
}
