<?php
// Szybkie testy logiki domenowej – nie wymagają frameworka: php tests/domain_test.php

spl_autoload_register(static function (string $class): void {
    $file = __DIR__.'/../src/'.str_replace(['App\\', '\\'], ['', '/'], $class).'.php';
    if (is_file($file)) {
        require $file;
    }
});

use App\Domain\DayAxis;
use App\Domain\DaySummary;
use App\Domain\Holidays;
use App\Domain\MailDraft;
use App\Domain\ReminderSchedule;
use App\Domain\Template;
use App\Domain\Theme;
use App\Domain\TimeFormat;
use App\Service\Settings;

$fail = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $fail;
    if ($actual !== $expected) {
        ++$fail;
        echo "FAIL $name: oczekiwano ".var_export($expected, true).', jest '.var_export($actual, true)."\n";
    }
}

foreach ([
    '1h 30m' => 90, '1h30' => 90, '1h30m' => 90, '1.5' => 90, '1,5h' => 90, '90m' => 90, '90 min' => 90,
    '1:30' => 90, '2h' => 120, '2 godz' => 120, '0.25' => 15, '15m' => 15, ' 8 ' => 480,
    '' => null, 'abc' => null, '0' => null, '25h' => null, 'h' => null, '-1h' => null,
    // sama liczba = godziny, ale najwyżej 12 – „20” to raczej minuty, więc trzeba dopisać jednostkę
    '2' => 120, '12' => 720, '12.5' => 750, '13' => null, '20' => null, ' 15 ' => null, '45' => null, '20m' => 20, '20h' => 1200,
] as $in => $exp) {
    check("parse('$in')", TimeFormat::parse((string) $in), $exp);
}
check('format 90', TimeFormat::format(90), '1h 30m');
check('format 45', TimeFormat::format(45), '45m');
check('format 120', TimeFormat::format(120), '2h');
 foreach ([[29, 0], [30, 1], [89, 1], [90, 2], [2875, 48], [3484, 58], [0, 0], [-100, 0]] as [$sec, $exp]) { check("roundToMinutes $sec", TimeFormat::roundToMinutes($sec), $exp); }
check('format -75', TimeFormat::format(-75), '-1h 15m');
check('clock 8:05', TimeFormat::normalizeClock('8:05'), '08:05');
check('clock 8.05', TimeFormat::normalizeClock('8.05'), '08:05');
check('clock 24:00', TimeFormat::normalizeClock('24:00'), null);

$s = DaySummary::calculate([240, 300], '08:00', '17:30', 30, 480, 10);
check('presence', $s->presenceMinutes, 540);
check('diff', $s->difference(), 60);
check('kind over', $s->kind(), DaySummary::KIND_OVER);
check('logged vs presence', $s->loggedVsPresence(), 0);

$s = DaySummary::calculate([420], null, null, 0, 480, 10);
check('basis logged', $s->basisMinutes(), 420);
check('kind under', $s->kind(), DaySummary::KIND_UNDER);

$s = DaySummary::calculate([475], '08:00', '15:55', 0, 480, 10);
check('kind ok w tolerancji', $s->kind(), DaySummary::KIND_OK);
check('kind empty', DaySummary::calculate([], null, null, 0, 480, 10)->kind(), DaySummary::KIND_EMPTY);
check('po północy', DaySummary::calculate([], '22:00', '06:00', 0, 480, 0)->presenceMinutes, 480);

foreach (['' => 0, '30' => 30, '1h' => 60, '20m + 40m' => 60, '15, 15, 30' => 60, '10+5' => 15, 'abc' => null, '20m + x' => null] as $in => $exp) {
    check("parseBreak('$in')", TimeFormat::parseBreak((string) $in), $exp);
}

check('spec godziny', TimeFormat::parseBreakSpec('13:30-13:50, 15:00–15:40'), ['minutes' => 0, 'ranges' => [[810, 830], [900, 940]]]);
check('spec mieszany', TimeFormat::parseBreakSpec('13.30-13.50 + 40m'), ['minutes' => 40, 'ranges' => [[810, 830]]]);
check('spec suma', TimeFormat::parseBreak('13:30-13:50, 15:00-15:40'), 60);
check('spec odwrotny', TimeFormat::parseBreakSpec('14:00-13:00'), null);
check('merge', DaySummary::mergeRanges([[780, 800], [720, 780], [900, 910]]), [[720, 800], [900, 910]]);

// przerwy dnia, praca między godzinami i koniec pracy z przeskakiwaniem przerw
$ws = DaySummary::breakWindows([[810, 830]], false, '12:00', '13:00');   // obiad + 13:30–13:50
check('przerwy dnia', $ws, [[720, 780], [810, 830]]);
check('przerwy dnia: praca w obiedzie', DaySummary::breakWindows([[810, 830]], true, '12:00', '13:00'), [[810, 830]]);
check('od–do bez przerw w środku', DaySummary::workedBetween(540, 660, $ws), 120);           // 9:00–11:00
check('od–do przez obiad', DaySummary::workedBetween(660, 840, $ws), 100);                   // 11:00–14:00 − 1h − 20m
check('od–do z początkiem w obiedzie', DaySummary::workedBetween(750, 840, $ws), 40);        // 12:30–14:00
check('od–do sama przerwa', DaySummary::workedBetween(720, 780, $ws), 0);
check('koniec bez przerw', DaySummary::advance(540, 120, $ws), 660);
check('koniec przez obiad i przerwę', DaySummary::advance(660, 100, $ws), 840);              // odwrotność workedBetween
check('koniec z początkiem w obiedzie', DaySummary::advance(750, 40, $ws), 840);
check('koniec tuż przed obiadem', DaySummary::advance(660, 60, $ws), 720);
$s = DaySummary::calculate([], '08:00', '16:00', 0, 420, 10, null, null, [[810, 830], [900, 940]]);
check('przerwy godzinami', [$s->presenceMinutes, $s->breakMinutes], [420, 60]);
$s = DaySummary::calculate([], '08:00', '16:00', 0, 420, 10, '12:00', '13:00', [[750, 790]]);
check('przerwa częściowo w obiedzie liczona raz', $s->breakMinutes, 70);
$s = DaySummary::calculate([], '08:00', '15:00', 0, 420, 10, null, null, [[930, 960]]);
check('przerwa po wyjściu nie odlicza się', $s->breakMinutes, 0);

// stała przerwa obiadowa 12–13
$s = DaySummary::calculate([], '08:00', '16:00', 0, 420, 10, '12:00', '13:00');
check('obiad pełny dzień', [$s->presenceMinutes, $s->breakMinutes, $s->kind()], [420, 60, DaySummary::KIND_OK]);
$s = DaySummary::calculate([], '08:00', '11:30', 0, 420, 10, '12:00', '13:00');
check('wyjście przed obiadem', [$s->presenceMinutes, $s->breakMinutes], [210, 0]);
$s = DaySummary::calculate([], '12:30', '18:00', 0, 420, 10, '12:00', '13:00');
check('przyjście w trakcie obiadu', [$s->presenceMinutes, $s->breakMinutes], [300, 30]);
$s = DaySummary::calculate([], '08:00', '17:00', 15, 420, 10, '12:00', '13:00');
check('obiad + dodatkowa', [$s->presenceMinutes, $s->breakMinutes, $s->difference()], [465, 75, 45]);
check('bez stałej przerwy', DaySummary::calculate([], '08:00', '16:00', 0, 420, 10, null, null)->presenceMinutes, 480);
$s = DaySummary::calculate([480], '08:00', '16:00', 0, 420, 0, '12:00', '13:00', [], true);
check('bilans z ticketów', [$s->presenceMinutes, $s->basisMinutes(), $s->difference(), $s->kind(), $s->basedOnPresence(), $s->loggedVsPresence()], [420, 480, 60, DaySummary::KIND_OVER, false, null]);
$s = DaySummary::calculate([480], '08:00', '16:00', 0, 420, 0, '12:00', '13:00');
check('bilans z obecności (domyślnie)', [$s->basisMinutes(), $s->difference(), $s->kind(), $s->basedOnPresence(), $s->loggedVsPresence()], [420, 0, DaySummary::KIND_OK, true, 60]);
check('ticketowy bilans: godziny bez wierszy = pusty dzień', DaySummary::calculate([], '08:00', '16:00', 0, 420, 0, null, null, [], true)->kind(), DaySummary::KIND_EMPTY);
$empty = DaySummary::calculate([], '08:00', '16:00', 0, 420, 0, null, null, [], true);
check('pusty dzień nie ma bilansu (nie −norma)', [$empty->difference(), $empty->kind()], [0, DaySummary::KIND_EMPTY]);
check('pusty dzień bez godzin też', DaySummary::calculate([0], null, null, 0, 420, 0)->difference(), 0);

check('render', Template::render("A {x}\n\n\n\n{y}B {nieznana}", ['x' => '1', 'y' => '']), "A 1\n\nB {nieznana}");
check('day', Template::dayName(new DateTimeImmutable('2026-10-13')), 'wtorek');
check('addr', Template::parseAddresses('a@x.pl, b@y.pl; zly  c@z.pl a@x.pl'), ['a@x.pl', 'b@y.pl', 'c@z.pl']);
check('addr invalid', Template::invalidAddresses('a@x.pl, zly'), ['zly']);
check('key', Template::extractIssueKey('abc-123 – Poprawka logowania'), 'ABC-123');
check('key2', Template::extractIssueKey('PROJ2_X-7'), 'PROJ2_X-7');
check('key none', Template::extractIssueKey('123'), null);
check('tytuł za numerem', Template::issueTitleAfterKey('abc-123 Produkcja nie działa'), 'Produkcja nie działa');
check('tytuł za numerem z myślnikiem', Template::issueTitleAfterKey('ABC-123 – Produkcja: nie działa'), 'Produkcja: nie działa');
check('tytuł za numerem z dwukropkiem', Template::issueTitleAfterKey('ABC-123: Awaria'), 'Awaria');
check('sam numer bez tytułu', Template::issueTitleAfterKey(' ABC-123 '), '');
check('numer w środku – bez tytułu', Template::issueTitleAfterKey('zobacz ABC-123'), '');

$m = new MailDraft(['a@x.pl', 'b@x.pl'], ['c@x.pl'], 'Nadgodziny – 13.10', "Linia 1\nZażółć & ?");
check('mailto', $m->mailtoUrl(), 'mailto:a@x.pl,b@x.pl?cc=c@x.pl&subject=Nadgodziny%20%E2%80%93%2013.10&body=Linia%201%0D%0AZa%C5%BC%C3%B3%C5%82%C4%87%20%26%20%3F');
check('mailto bez treści (długi mail na Windowsie)', $m->mailtoUrl(false), 'mailto:a@x.pl,b@x.pl?cc=c@x.pl&subject=Nadgodziny%20%E2%80%93%2013.10');

// przypomnienie: raz dziennie, o godzinie z Ustawień albo przy pierwszym sprawdzeniu po niej
$tue = static fn (string $hm) => new DateTimeImmutable('2026-10-13 '.$hm); // wtorek
check('przypomnienie: przed godziną', ReminderSchedule::isDue('15:45', '', $tue('15:44')), false);
check('przypomnienie: o godzinie', ReminderSchedule::isDue('15:45', '', $tue('15:45')), true);
check('przypomnienie: nadrobione po włączeniu komputera', ReminderSchedule::isDue('15:45', '2026-10-12 15:45', $tue('18:10')), true);
check('przypomnienie: już było dziś', ReminderSchedule::isDue('15:45', ReminderSchedule::stamp('15:45', $tue('15:45')), $tue('15:50')), false);
check('przypomnienie: zmiana godziny uzbraja od nowa', ReminderSchedule::isDue('16:30', '2026-10-13 15:45', $tue('16:30')), true);
check('przypomnienie: wyłączone', ReminderSchedule::isDue('', '', $tue('16:00')), false);
check('przypomnienie: weekend', ReminderSchedule::isDue('15:45', '', new DateTimeImmutable('2026-10-17 16:00')), false);
check('przypomnienie: 8:05 = 08:05', ReminderSchedule::stamp('8:05', $tue('09:00')), '2026-10-13 08:05');
check('przypomnienie: zakres timera', [ReminderSchedule::inTimerWindow('06:00'), ReminderSchedule::inTimerWindow('21:59'), ReminderSchedule::inTimerWindow('22:00'), ReminderSchedule::inTimerWindow('05:59')], [true, true, false, false]);

check('niejednoznaczna liczba', array_map([TimeFormat::class, 'ambiguousNumber'], ['20', ' 15 ', '12', '1.5', '15m', '']), [20, 15, null, null, null, null]);

// --- święta i dni robocze ---
foreach (['1999-04-04', '2000-04-23', '2024-03-31', '2025-04-20', '2026-04-05', '2027-03-28', '2030-04-21', '2038-04-25'] as $e) {
    check("Wielkanoc $e", Holidays::easter((int) substr($e, 0, 4))->format('Y-m-d'), $e);
}
$h26 = Holidays::forYear(2026);
check('święta 2026: liczba (z Wigilią)', count($h26), 14);
check('święta 2026: ruchome', [$h26['2026-04-06'] ?? null, $h26['2026-05-24'] ?? null, $h26['2026-06-04'] ?? null], ['Poniedziałek Wielkanocny', 'Zielone Świątki', 'Boże Ciało']);
check('święta 2026: w kolejności dat', array_keys($h26) === array_values(array_unique(array_keys($h26))) && array_keys($h26)[0] === '2026-01-01' && array_key_last($h26) === '2026-12-26', true);
check('Wigilia wolna dopiero od 2025', [isset(Holidays::forYear(2024)['2024-12-24']), isset(Holidays::forYear(2025)['2025-12-24'])], [false, true]);
check('święto: nazwa', [Holidays::name(new DateTimeImmutable('2026-11-11')), Holidays::name(new DateTimeImmutable('2026-11-12'))], ['Narodowe Święto Niepodległości', null]);
check('dni robocze: tydzień z 11 listopada', Holidays::workingDays(new DateTimeImmutable('2026-11-09'), new DateTimeImmutable('2026-11-13')), 4);
check('dni robocze: przełom roku (Wigilia, święta, Nowy Rok)', Holidays::workingDays(new DateTimeImmutable('2026-12-21'), new DateTimeImmutable('2027-01-03')), 7);
check('dni robocze: sam weekend', Holidays::workingDays(new DateTimeImmutable('2026-10-17'), new DateTimeImmutable('2026-10-18')), 0);
check('odmiana', array_map(static fn (int $n) => Template::plural($n, 'dzień roboczy', 'dni robocze', 'dni roboczych'), [0, 1, 2, 5, 12, 22, 25]),
    ['0 dni roboczych', '1 dzień roboczy', '2 dni robocze', '5 dni roboczych', '12 dni roboczych', '22 dni robocze', '25 dni roboczych']);

// --- motywy ---
$luminance = static function (string $hex): float {
    $ch = static fn (int $v): float => ($v / 255) <= 0.03928 ? ($v / 255) / 12.92 : ((($v / 255) + 0.055) / 1.055) ** 2.4;
    [$r, $g, $b] = array_map('hexdec', str_split(substr($hex, 1), 2));

    return 0.2126 * $ch((int) $r) + 0.7152 * $ch((int) $g) + 0.0722 * $ch((int) $b);
};
$contrast = static function (string $a, string $b) use ($luminance): float {
    [$hi, $lo] = [max($luminance($a), $luminance($b)), min($luminance($a), $luminance($b))];

    return ($hi + 0.05) / ($lo + 0.05);
};
check('motywy: lista', array_keys(Theme::choices()), ['aurora', 'ocean', 'sunset', 'neon', 'mono', 'dawn', 'mint', 'peach', 'lavender', 'paper', 'bootstrap', 'settlers', 'custom']);
check('motywy: grupy', Theme::groups(), ['Ciemne' => ['aurora', 'ocean', 'sunset', 'neon', 'mono'], 'Jasne' => ['dawn', 'mint', 'peach', 'lavender', 'paper', 'bootstrap'], 'Z gier' => ['settlers'], 'Własny' => ['custom']]);
check('motywy: domyślny Papier', [Theme::DEFAULT, Settings::DEFAULTS['theme']], ['paper', 'paper']);
check('motywy: id do data-theme', [Theme::id('settlers'), Theme::id('custom'), Theme::id('auto'), Theme::id('')], ['settlers', 'custom', 'paper', 'paper']);
$names = ['bg', 'panel', 'ink', 'muted', 'line', 'accent', 'accent-2', 'accent-ink', 'ok', 'ok-bg', 'warn', 'warn-bg', 'err', 'err-bg', 'info', 'info-bg'];
// wbudowane motywy z kolorami stanów, które dostaną (dla jasnego albo ciemnego tła)
$cases = array_map(static fn (array $c): array => $c + (Theme::isLight($c['bg']) ? Theme::STATES_LIGHT : Theme::STATES_DARK), Theme::palettes());
foreach ($cases as $id => $c) {
    check("motyw $id: komplet kolorów", [array_diff($names, array_keys($c)), array_diff(array_keys($c), $names)], [[], []]);
    check("motyw $id: same #rrggbb", array_filter($c, static fn (string $v) => 1 !== preg_match('/^#[0-9a-f]{6}$/', $v)), []);
    // czytelność wg WCAG: tekst 4.5:1 (główny 7:1). Kolory stanów to też tekst: na swoim tle (plakietki, komunikaty),
    // na panelu i na tle strony (bilans w tabelach, błędy pod wierszami); tekst przycisku na obu końcach gradientu
    $low = [];
    $pairs = [['ink', 'bg', 7], ['ink', 'panel', 7], ['muted', 'panel', 4.5], ['muted', 'bg', 4.5], ['accent', 'panel', 4.5], ['accent-ink', 'accent', 4.5], ['accent-ink', 'accent-2', 4.5]];
    foreach (['ok', 'warn', 'err', 'info'] as $k) {
        array_push($pairs, [$k, $k.'-bg', 4.5], [$k, 'panel', 4.5], [$k, 'bg', 4.5]);
    }
    foreach ($pairs as [$fg, $bg, $min]) {
        if ($contrast($c[$fg], $c[$bg]) < $min) {
            $low[] = sprintf('%s/%s %.2f', $fg, $bg, $contrast($c[$fg], $c[$bg]));
        }
    }
    check("motyw $id: kontrast", $low, []);
}
check('css: kolory motywu', [str_contains(Theme::css('ocean'), '--accent: #2dd4bf;'), str_contains(Theme::css('ocean'), 'color-scheme: dark;'), str_contains(Theme::css('ocean'), '--s1: #3987e5;')], [true, true, true]);
check('css: nieznany motyw (np. dawny „auto”) = domyślny', Theme::css('auto'), Theme::css(Theme::DEFAULT));
$own = Theme::css('custom', (string) json_encode(['bg' => '#FFFFFF', 'panel' => '#f0f0f0', 'ink' => '#111111']));
check('Własny: kolory z ustawień, reszta z domyślnego', [str_contains($own, '--bg: #ffffff;'), str_contains($own, '--ink: #111111;'), str_contains($own, '--accent: #27272a;')], [true, true, true]);
check('Własny z jasnym tłem: jasne kontrolki, stany, tickety i cienie dla jasnego tła', [str_contains($own, 'color-scheme: light;'), str_contains($own, '--ok: #2d794d;'), str_contains($own, '--s1: #2a78d6;'), str_contains($own, '--shade: rgb(40 40 70 / .16);')], [true, true, true, true]);
check('jasny motyw wbudowany: stany dla jasnego tła', [str_contains(Theme::css('mint'), '--ok: #2d794d;'), str_contains(Theme::css('mint'), 'color-scheme: light;')], [true, true]);
check('Własny: śmieci zamiast koloru → domyślne', Theme::custom((string) json_encode(['bg' => 'red; } body { display:none', 'line' => '#12345'])), Theme::palettes()[Theme::DEFAULT]);
check('Własny: pusty zapis → domyślne', Theme::custom(''), Theme::palettes()[Theme::DEFAULT]);
check('kolory motywu (favicona): wbudowany, Własny, nieznany', [Theme::colors('settlers')['accent'], Theme::colors('custom', (string) json_encode(['accent' => '#123456']))['accent'], Theme::colors('auto')], ['#ffe41a', '#123456', Theme::palettes()['paper']]);
check('kolor: format', [Theme::isColor('#a1B2c3'), Theme::isColor('#123'), Theme::isColor('#12345g'), Theme::isColor('a1b2c3')], [true, false, false, false]);

// --- oś dnia ---
$ax = DayAxis::build('08:00', '16:30', [[720, 780]], [['ABC-1', 150], ['ABC-2', 60], ['OPS-8', 45], ['ABC-3', 165]], 420, 450);
check('oś: godziny w pracy', $ax['track']['presence'], ['left' => 10.0, 'width' => 85.0, 'label' => '7h 30m']);
check('oś: obiad', $ax['track']['breaks'], [['left' => 50.0, 'width' => 10.0, 'label' => '12:00–13:00']]);
check('oś: podziałka co 2h od 7:00', array_column($ax['track']['ticks'], 'label'), ['7:00', '9:00', '11:00', '13:00', '15:00']);
check('oś: skala ticketów do pełnej godziny', $ax['stack']['scale'], 480);
check('oś: tickety', array_column($ax['stack']['segments'], 'width'), [31.25, 12.5, 9.38, 34.38]);
check('oś: norma i obecność', $ax['stack']['markers'], [['left' => 87.5, 'label' => 'norma 7h', 'kind' => 'norm'], ['left' => 93.75, 'label' => 'obecność 7h 30m', 'kind' => 'presence']]);
check('oś: bez godzin – tylko tickety', [DayAxis::build(null, null, [], [['A-1', 60]], 420, null)['track'], count(DayAxis::build(null, null, [], [['A-1', 60]], 420, null)['stack']['markers'])], [null, 1]);
check('oś: praca po północy bez paska zegara', DayAxis::build('22:00', '06:00', [], [], 420, 480)['track'], null);
check('oś: przerwa poza godzinami pracy pominięta', DayAxis::build('13:30', '17:00', [[720, 780]], [], 420, 210)['track']['breaks'], []);
check('data długa', [Template::longDate(new DateTimeImmutable('2026-10-14'), new DateTimeImmutable('2026-10-14')), Template::longDate(new DateTimeImmutable('2025-12-30'), new DateTimeImmutable('2026-01-02'))],
    ['14 października', '30 grudnia 2025']);

echo $fail ? "\n$fail błędów\n" : "OK – wszystkie testy przeszły\n";
exit($fail ? 1 : 0);
