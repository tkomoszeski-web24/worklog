<?php

namespace App\Domain;

/**
 * Parsowanie i formatowanie czasu. Akceptuje m.in.: "1h 30m", "1h30", "1.5", "1,5h", "90m", "1:30", "2 godz".
 */
final class TimeFormat
{
    /** Sama liczba bez jednostki to godziny – ale najwyżej tyle. „20” to raczej 20 minut niż 20 godzin, więc trzeba dopisać m albo h. */
    public const MAX_BARE_HOURS = 12;

    public static function parse(string $raw): ?int
    {
        $s = mb_strtolower(trim(str_replace(',', '.', $raw)));
        if ('' === $s) {
            return null;
        }

        if (preg_match('/^(\d{1,2}):([0-5]\d)$/', $s, $m)) {
            $minutes = (int) $m[1] * 60 + (int) $m[2];
        } elseif (preg_match('/^\d+(\.\d+)?$/', $s)) {
            if (null !== self::ambiguousNumber($s)) {
                return null;
            }
            $minutes = (int) round((float) $s * 60);
        } elseif (preg_match('/^(?:(\d+(?:\.\d+)?)\s*(?:h|g|godz\.?)\s*)?(?:(\d+)\s*(?:m|min\.?)?)?$/u', $s, $m)
            && (($m[1] ?? '') !== '' || ($m[2] ?? '') !== '')
        ) {
            $hours = ($m[1] ?? '') !== '' ? (float) $m[1] : 0.0;
            $mins = ($m[2] ?? '') !== '' ? (int) $m[2] : 0;
            // samo "45" bez jednostki złapał już wariant liczbowy (godziny); tu "1h 30" = 1h 30m
            $minutes = (int) round($hours * 60) + $mins;
        } else {
            return null;
        }

        return $minutes > 0 && $minutes <= 24 * 60 ? $minutes : null;
    }

    /** Sama liczba całkowita powyżej MAX_BARE_HOURS (np. „20”) – nie wiadomo, minuty czy godziny. Zwraca ją, inaczej null. */
    public static function ambiguousNumber(string $raw): ?int
    {
        $s = trim($raw);

        return ctype_digit($s) && (int) $s > self::MAX_BARE_HOURS ? (int) $s : null;
    }

    /**
     * Przerwa, także „na raty”. Części oddzielone przecinkiem, średnikiem, plusem albo nową linią:
     *  - czas trwania: "30" (sama liczba = minuty), "1h", "20m",
     *  - przedział godzin: "13:30-13:50" (też "13.30–13.50").
     *
     * @return array{minutes: int, ranges: list<array{0: int, 1: int}>}|null minutes = suma samych czasów trwania,
     *                                                                       ranges = przedziały w minutach od północy; null gdy coś jest niepoprawne
     */
    public static function parseBreakSpec(string $raw): ?array
    {
        $out = ['minutes' => 0, 'ranges' => []];
        $raw = trim($raw);
        if ('' === $raw) {
            return $out;
        }
        foreach (preg_split('/\s*[+,;\n]\s*/u', $raw) as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }
            if (preg_match('/^(\d{1,2}[:.]\d{2})\s*[-–—]\s*(\d{1,2}[:.]\d{2})$/u', $part, $m)) {
                $from = self::clockToMinutes($m[1]);
                $to = self::clockToMinutes($m[2]);
                if (null === $from || null === $to || $to <= $from) {
                    return null;
                }
                $out['ranges'][] = [$from, $to];
                continue;
            }
            $minutes = ctype_digit($part) ? (int) $part : self::parse($part);
            if (null === $minutes) {
                return null;
            }
            $out['minutes'] += $minutes;
        }

        return self::breakSpecTotal($out) <= 24 * 60 ? $out : null;
    }

    /** Łączna długość przerwy (czasy trwania + długości przedziałów). Zwraca null dla niepoprawnego tekstu. */
    public static function parseBreak(string $raw): ?int
    {
        $spec = self::parseBreakSpec($raw);

        return null === $spec ? null : self::breakSpecTotal($spec);
    }

    /** @param array{minutes: int, ranges: list<array{0: int, 1: int}>} $spec */
    public static function breakSpecTotal(array $spec): int
    {
        return $spec['minutes'] + array_sum(array_map(static fn (array $r) => $r[1] - $r[0], $spec['ranges']));
    }

    /** Sekundy na najbliższą pełną minutę: reszta od 30 s w górę, poniżej w dół. Ujemne traktuje jak 0. */
    public static function roundToMinutes(int $seconds): int
    {
        return intdiv(max(0, $seconds) + 30, 60);
    }

    public static function format(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        if (0 === $h) {
            return $sign.$m.'m';
        }
        if (0 === $m) {
            return $sign.$h.'h';
        }

        return sprintf('%s%dh %dm', $sign, $h, $m);
    }

    /** "8:05" → "08:05"; null gdy niepoprawne. */
    public static function normalizeClock(string $raw): ?string
    {
        if (!preg_match('/^([01]?\d|2[0-3])[:.]([0-5]\d)$/', trim($raw), $m)) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    public static function clockToMinutes(string $clock): ?int
    {
        $n = self::normalizeClock($clock);
        if (null === $n) {
            return null;
        }
        [$h, $m] = explode(':', $n);

        return (int) $h * 60 + (int) $m;
    }

    public static function minutesToClock(int $minutes): string
    {
        $minutes = (($minutes % 1440) + 1440) % 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
