<?php

namespace App\Service;

use App\Domain\DaySummary;
use App\Domain\Template;
use App\Domain\TimeFormat;
use App\Entity\WorkDay;
use App\Entity\WorklogEntry;
use App\Repository\WorkDayRepository;

/**
 * Pobiera wpisy z Toggla Track z jednego dnia do wierszy dnia.
 *
 * Wpisy o tym samym (ticket, opis) składają się w jeden wiersz. Wiersz pamięta id wpisów Toggla (togglIds),
 * więc ponowne pobranie tego samego dnia aktualizuje czas zamiast dublować wiersze. Toggl jest źródłem czasu,
 * nie tekstów – ticket, opis i tytuł zmienione w aplikacji zostają.
 * Serwis nie robi flush() – robi go kontroler.
 */
final class TogglImport
{
    public function __construct(
        private readonly TogglClient $toggl,
        private readonly Settings $settings,
        private readonly WorkDayRepository $days,
        private readonly ReportBuilder $reports,
    ) {
    }

    /** @return list<array{type: string, message: string}> */
    public function import(WorkDay $day, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $messages = [];

        try {
            $groups = $this->collect($day, $now, $messages, $seen, $allIds, $hours);
        } catch (TogglException $e) {
            return [['type' => 'error', 'message' => $e->getMessage()]];
        }

        $existing = [];
        $position = -1;
        foreach ($day->getEntries() as $entry) {
            $position = max($position, $entry->getPosition());
            // wiersze czekające na skasowanie z Jiry nie są kandydatami – ich grupa daje nowy wiersz
            if (!$entry->isDeleted() && [] !== $entry->getTogglIds()) {
                $existing[] = $entry;
            }
        }
        usort($existing, static fn (WorklogEntry $a, WorklogEntry $b): int => $a->getPosition() <=> $b->getPosition());

        $titles = null;
        $new = $updated = 0;
        $claimed = [];
        $touched = []; // wiersze, którym ten import ustawił czas: [wiersz, sekundy grupy]
        $stopperSkipped = false;
        foreach ($groups as $group) {
            // wiersze, które powstały z tych samych wpisów Toggla (np. po ujednoliceniu opisu w Togglu)
            $matches = [];
            foreach ($existing as $i => $candidate) {
                if ([] !== array_intersect($candidate->getTogglIds(), $group['ids'])) {
                    $matches[$i] = $candidate;
                }
            }
            // „od” = start najwcześniejszego wpisu grupy; „do” wylicza się z czasu wiersza (przy przeplatanych odcinkach wypadnie wcześniej niż realny koniec)
            $startClock = $this->startClock($day, $group, $messages);

            if ([] !== $matches) {
                foreach ($matches as $m) {
                    if ($m->isTimerRunning()) {
                        $messages[] = ['type' => 'warning', 'message' => sprintf('Przy tickecie %s działa stoper aplikacji – czasu z Toggla nie zmieniono. Zatrzymaj stoper i pobierz ponownie.', $m->getIssueKey())];
                        $claimed = array_merge($claimed, array_keys($matches));
                        $stopperSkipped = true;
                        continue 2;
                    }
                }
                $claimed = array_merge($claimed, array_keys($matches));
                $row = array_shift($matches);
                $row->setTrackedSeconds($group['seconds']);
                $row->setTogglIds($group['ids']);
                $row->setStartClock($startClock);
                ++$updated;
                // pozostałe wiersze usuwamy tak jak użytkownik
                foreach ($matches as $extra) {
                    $inJira = $extra->isInJira();
                    $messages[] = ['type' => 'warning', 'message' => sprintf('Wpisy z Toggla połączyły się: czas wiersza „%s” (%s, %s) przeszedł do „%s”; wiersz %s usunięto%s.',
                        self::label($extra), $extra->getIssueKey(), TimeFormat::format($extra->getMinutes()), self::label($row),
                        self::label($extra), $inJira ? ' (z Jiry zniknie przy następnej wysyłce)' : '')];
                    $inJira ? $extra->markDeleted() : $day->removeEntry($extra);
                }
            } else {
                $titles ??= $this->days->knownIssueTitles() + $this->settings->issueCache();
                $row = new WorklogEntry($day, $group['key'], 0, $group['description'], ++$position);
                $row->setTrackedSeconds($group['seconds']);
                $row->setTogglIds($group['ids']);
                $row->setStartClock($startClock);
                $row->setIssueTitle($titles[$group['key']] ?? '');
                ++$new;
            }
            $touched[] = [$row, $group['seconds']];
        }

        // wiersze z Toggla, których wpisów już tam nie ma – zostają bez zmian
        foreach ($existing as $i => $row) {
            if (!in_array($i, $claimed, true) && [] === array_intersect($row->getTogglIds(), $allIds)) {
                $messages[] = ['type' => 'warning', 'message' => sprintf('Wiersz „%s” (%s, %s) nie ma już wpisów w Togglu – czas zostawiono bez zmian.',
                    self::label($row), $row->getIssueKey(), TimeFormat::format($row->getMinutes()))];
            }
        }

        if (null !== $hours && $this->applyHours($day, $hours)) {
            $messages[] = ['type' => 'info', 'message' => sprintf('Godziny obecności z Toggla: %s–%s.', $hours['start'], $hours['end'])];
        }

        $closeMessage = $this->closeToPresence($day, $touched, $stopperSkipped);
        if (null !== $closeMessage) {
            $messages[] = ['type' => 'info', 'message' => $closeMessage];
        }
        $minutes = array_sum(array_map(static fn (array $t): int => $t[0]->getMinutes(), $touched));

        if (0 === $seen) {
            array_unshift($messages, ['type' => 'info', 'message' => 'Toggl nie ma wpisów z tego dnia.']);
        } elseif ($new + $updated > 0) {
            $parts = [];
            if ($new > 0) {
                $parts[] = Template::plural($new, 'nowy', 'nowe', 'nowych');
            }
            if ($updated > 0) {
                $parts[] = Template::plural($updated, 'zaktualizowany', 'zaktualizowane', 'zaktualizowanych');
            }
            array_unshift($messages, ['type' => 'success', 'message' => sprintf('Pobrano z Toggla: %s (%s) – razem %s.',
                Template::plural($new + $updated, 'wpis', 'wpisy', 'wpisów'), implode(', ', $parts), TimeFormat::format($minutes))]);
        }

        return $messages;
    }

    /**
     * Domyka sumę wierszy z tego importu do obecności dnia: brakujące (albo nadmiarowe po zaokrągleniach w górę)
     * minuty rozdziela między wiersze według największej reszty sekund. Tylko gdy różnica względem zwykłego
     * zaokrąglenia mieści się w progu z ustawień; większa to realna przerwa albo wpis bez ticketu – zostaje widoczna.
     * Zwraca komunikat, gdy coś zmieniono.
     *
     * @param list<array{0: WorklogEntry, 1: int}> $touched
     */
    private function closeToPresence(WorkDay $day, array $touched, bool $stopperSkipped): ?string
    {
        $limit = $this->settings->int('toggl_close_minutes');
        $presence = $this->reports->summary($day)->presenceMinutes;
        if (null === $presence || $limit <= 0 || [] === $touched || $stopperSkipped) {
            return null;
        }

        $touchedRows = array_map(static fn (array $t): WorklogEntry => $t[0], $touched);
        $untouched = 0;
        foreach ($day->getActiveEntries() as $entry) {
            if (!in_array($entry, $touchedRows, true)) {
                $untouched += $entry->getMinutes();
            }
        }

        $base = $remainder = [];
        foreach ($touched as $i => [, $seconds]) {
            $base[$i] = intdiv($seconds, 60);
            $remainder[$i] = $seconds % 60;
        }
        $rounded = array_sum(array_map(static fn (array $t): int => TimeFormat::roundToMinutes($t[1]), $touched));
        $diff = $presence - ($rounded + $untouched);
        $need = $presence - $untouched - array_sum($base);
        if (abs($diff) > $limit || $need < 0) {
            return null;
        }

        $order = array_keys($touched);
        usort($order, static fn (int $a, int $b): int => [$remainder[$b], $a] <=> [$remainder[$a], $b]);
        $extra = array_fill_keys($order, 0);
        for ($k = 0; $k < $need; ++$k) {
            ++$extra[$order[$k % count($order)]];
        }
        foreach ($touched as $i => [$row, $seconds]) {
            $row->setTrackedSeconds($seconds, $base[$i] + $extra[$i]);
        }

        return 0 === $diff ? null : sprintf('Domknięto czas do obecności (%s): %+d min w wierszach z Toggla.', TimeFormat::format($presence), $diff);
    }

    /**
     * Godzina „od” wiersza = start najwcześniejszego wpisu grupy. Wiersz, który kończyłby się po północy
     * (tak jak liczy to walidacja formularza, z przerwami dnia), nie dostaje jej – inaczej formularz by go odrzucił.
     *
     * @param array{key: string, description: string, seconds: int, clock: string} $group
     * @param list<array{type: string, message: string}> $messages
     */
    private function startClock(WorkDay $day, array $group, array &$messages): ?string
    {
        $minutes = TimeFormat::roundToMinutes($group['seconds']);
        $from = TimeFormat::clockToMinutes($group['clock']);
        if (null === $from || 0 === $minutes) {
            return $group['clock'];
        }
        $windows = DaySummary::breakWindows($day->getBreakRanges(), $day->hasWorkedThroughFixedBreak(),
            $this->settings->get('lunch_from') ?: null, $this->settings->get('lunch_to') ?: null);
        if (DaySummary::advance($from, $minutes, $windows) > 24 * 60) {
            $messages[] = ['type' => 'warning', 'message' => sprintf('„%s” (%s, %s): wpis kończyłby się po północy – bez godziny „od”.',
                '' !== $group['description'] ? $group['description'] : $group['key'], $group['key'], TimeFormat::format($minutes))];

            return null;
        }

        return $group['clock'];
    }

    /**
     * Godziny obecności z Toggla dostaje dzień, w którym użytkownik ich nie wpisywał (hoursByUser) – nowy albo
     * nigdy nie poprawiany ręcznie. Zwraca true, gdy godziny się zmieniły albo ustawiono je pierwszy raz.
     *
     * @param array{start: string, end: string} $hours
     */
    private function applyHours(WorkDay $day, array $hours): bool
    {
        if ($day->isHoursByUser()) {
            return false;
        }
        $changed = [$day->getStartTime(), $day->getEndTime()] !== [$hours['start'], $hours['end']] || null === $day->getTogglHours();
        $day->setStartTime($hours['start']);
        $day->setEndTime($hours['end']);
        $day->setTogglHours($hours);

        return $changed;
    }

    private static function label(WorklogEntry $row): string
    {
        return '' !== $row->getDescription() ? $row->getDescription() : $row->getIssueKey();
    }

    /**
     * Wpisy Toggla z tej doby złożone w grupy (ticket, opis). Wszystkie zapytania do Toggla kończą się tutaj,
     * zanim cokolwiek zmienimy w dniu.
     *
     * @param list<array{type: string, message: string}> $messages ostrzeżenia
     * @param int $seen ile wpisów Toggla z tej doby (z działającym stoperem włącznie)
     * @param list<int> $allIds ich identyfikatory
     * @param array{start: string, end: string}|null $hours godziny obecności z pierwszego i ostatniego wpisu (null bez wpisów)
     *
     * @return list<array{key: string, description: string, seconds: int, ids: list<int>, clock: string}>
     */
    private function collect(WorkDay $day, \DateTimeImmutable $now, array &$messages, ?int &$seen, ?array &$allIds, ?array &$hours): array
    {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $from = new \DateTimeImmutable($day->getDate()->format('Y-m-d').' 00:00:00', $tz);
        $entries = [];
        foreach ($this->toggl->timeEntries($from, $from->modify('+1 day')) as $e) {
            // Toggl bywa niedokładny przy strefach – o dniu decyduje lokalna data startu
            try {
                $start = (new \DateTimeImmutable((string) ($e['start'] ?? '')))->setTimezone($tz);
            } catch (\Exception) {
                continue;
            }
            if ($start->format('Y-m-d') === $from->format('Y-m-d')) {
                $entries[] = [$start, $e];
            }
        }
        usort($entries, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $seen = count($entries);
        $allIds = array_map(static fn (array $e): int => (int) ($e[1]['id'] ?? 0), $entries);

        $projects = null;
        $groups = [];
        $skipped = [];
        $first = $last = null; // obecność: od najwcześniejszego startu do najpóźniejszego końca (także wpisów pominiętych)
        foreach ($entries as [$start, $e]) {
            $description = trim((string) ($e['description'] ?? ''));
            $duration = (int) ($e['duration'] ?? 0);
            // ujemny czas = stoper w Togglu nadal działa – doliczamy czas zmierzony do chwili pobrania
            $running = $duration < 0;
            if ($running) {
                $duration = max(0, $now->getTimestamp() - $start->getTimestamp());
            }
            $first ??= $start->getTimestamp();
            $last = max($last ?? 0, $start->getTimestamp() + $duration);
            if (0 === $duration && !$running) {
                continue;
            }

            $key = Template::extractIssueKey($description);
            if (null === $key) {
                $projectId = (int) ($e['project_id'] ?? 0);
                if ($projectId > 0) {
                    $projects ??= $this->toggl->projects(); // tylko gdy któryś wpis tego potrzebuje, raz na import
                    $key = Template::extractIssueKey($projects[$projectId] ?? '');
                }
                $text = $description; // numer z projektu – opis zostaje cały
            } else {
                // numer na początku opisu nie powtarza się w wierszu; numer w środku zostaje razem z opisem
                $text = str_starts_with(mb_strtoupper(ltrim($description)), $key) ? Template::issueTitleAfterKey($description) : $description;
            }
            if (null === $key) {
                $skipped[] = sprintf('„%s” %s', '' !== $description ? $description : 'bez opisu', TimeFormat::format(max(1, TimeFormat::roundToMinutes($duration))));
                continue;
            }

            if ($running) {
                $messages[] = ['type' => 'warning', 'message' => sprintf('Stoper w Togglu nadal działa („%s”) – doliczono %s do tej chwili; po zatrzymaniu pobierz ponownie.',
                    '' !== $description ? $description : 'bez opisu', TimeFormat::format(TimeFormat::roundToMinutes($duration)))];
            }

            $id = $key."\0".$text;
            $groups[$id] ??= ['key' => $key, 'description' => $text, 'seconds' => 0, 'ids' => [], 'clock' => $start->format('H:i')]; // wpisy są posortowane po starcie, więc pierwszy w grupie jest najwcześniejszy
            $groups[$id]['seconds'] += $duration;
            $groups[$id]['ids'][] = (int) ($e['id'] ?? 0);
        }

        $hours = null;
        if (null !== $first) {
            // pełne minuty w dół; koniec po północy przycinamy do 23:59
            $end = (new \DateTimeImmutable('@'.$last))->setTimezone($tz);
            $hours = [
                'start' => (new \DateTimeImmutable('@'.$first))->setTimezone($tz)->format('H:i'),
                'end' => $end->format('Y-m-d') > $from->format('Y-m-d') ? '23:59' : $end->format('H:i'),
            ];
        }

        if ([] !== $skipped) {
            $messages[] = ['type' => 'warning', 'message' => 'Pominięto wpisy bez numeru ticketu (w opisie ani w nazwie projektu): '.implode(', ', $skipped).' – dodaj je ręcznie.'];
        }

        return array_values($groups);
    }
}
