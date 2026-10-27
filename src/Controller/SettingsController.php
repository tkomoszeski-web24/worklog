<?php

namespace App\Controller;

use App\Domain\ReminderSchedule;
use App\Domain\Template;
use App\Domain\Theme;
use App\Domain\TimeFormat;
use App\Service\JiraClient;
use App\Service\JiraException;
use App\Service\Settings;
use App\Service\TogglClient;
use App\Service\TogglException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SettingsController extends AbstractController
{
    private const PLACEHOLDERS_DAY = '{data} {data_iso} {dzien} {od} {do} {przerwa} {obecnosc} {zalogowane} {norma} {roznica} {lista} {podpis}';
    private const PLACEHOLDERS_PLAN = '{data} {data_iso} {dzien} {typ} {od} {do} {opis} {powod} {powod_linia} {podpis}';

    /** Sekcja → [klucz => [etykieta, typ pola, podpowiedź]] */
    private const FORM = [
        'Ogólne' => [
            'forma' => ['Forma w tekstach', 'select', 'Jak aplikacja zwraca się do Ciebie i w jakiej formie pisze domyślne maile, np. „pracowałam / pracowałem dłużej” albo neutralnie „mój czas pracy był dłuższy”. Zmiana podmienia też treści maili, których nie edytowano.'],
        ],
        'Wygląd' => [
            'theme' => ['Motyw', 'themes', 'Kliknij motyw, żeby zobaczyć go od razu na tej stronie – zostanie po kliknięciu Zapisz ustawienia.'],
            'theme_custom' => ['Kolory motywu Własnego', 'colors', 'Kliknij kolor, żeby go zmienić – zmiana jest widoczna od razu. Przy jasnym tle kolory stanów (nadgodziny, błędy) przechodzą na wersję dla jasnego tła.'],
        ],
        'Jira' => [
            'jira_url' => ['Adres Jiry', 'url', 'np. https://firma.atlassian.net albo https://jira.firma.pl'],
            'jira_email' => ['E-mail (tylko Jira Cloud)', 'text', 'Dla Jira Server / Data Center zostaw puste – wtedy token to Personal Access Token.'],
            'jira_token' => ['Token API', 'password', 'Cloud: id.atlassian.com → Security → API tokens. Server/DC: Profil → Personal Access Tokens. Zostaw puste, żeby nie zmieniać.'],
            'jira_jql' => ['Zapytanie JQL – „moje tickety” do podpowiedzi', 'text', ''],
        ],
        'Toggl' => [
            'toggl_token' => ['Token API Toggl Track', 'password', 'Toggl Track → avatar → Profile settings → na dole „API Token”. Zostaw puste, żeby nie zmieniać.'],
            'toggl_close_minutes' => ['Domykanie czasu z Toggla do obecności (minuty)', 'number', 'Po pobraniu z Toggla brakujące minuty (do tylu) są rozdzielane między wiersze, żeby suma ticketów równała się obecności – znikają różnice z zaokrągleń i sekundowych luk między wpisami. 0 = wyłączone.'],
        ],
        'Dzień pracy' => [
            'default_start' => ['Domyślna godzina rozpoczęcia', 'time', 'Od tej godziny liczone są godziny „started” worklogów, jeśli nie podasz godziny dnia.'],
            'default_end' => ['Domyślna godzina zakończenia', 'time', ''],
            'balance_source' => ['Bilans dnia liczony z', 'select', 'Obecność: od–do minus obiad i przerwy – gdy godziny wpisujesz ręcznie. Czas w ticketach: suma wierszy – gdy mierzysz wszystko stoperem, np. w Togglu; godziny od–do służą wtedy tylko do maila i do godzin worklogów w Jirze.'],
            'lunch_from' => ['Stała przerwa (obiad) – od', 'time', 'Odliczana automatycznie w części przypadającej na godziny pracy; worklogi w Jirze ją omijają. Oba pola puste = bez stałej przerwy.'],
            'lunch_to' => ['Stała przerwa (obiad) – do', 'time', ''],
            'default_break' => ['Dodatkowa przerwa (minuty)', 'number', 'Domyślna wartość pola „Dodatkowa przerwa” w nowym dniu – zwykle 0.'],
            'norm_minutes' => ['Norma dzienna (minuty)', 'number', '420 = 7h, 480 = 8h'],
            'tolerance_minutes' => ['Tolerancja (minuty)', 'number', 'Różnice do tylu minut nie wymagają maila. 0 = mail przy każdej różnicy względem normy.'],
            'signature' => ['Podpis w mailach', 'textarea', 'np. imię i nazwisko'],
        ],
        'Przypomnienie' => [
            'remind_time' => ['Godzina przypomnienia (pon–pt)', 'time', 'Powiadomienie na pulpicie, jeśli raport z dnia nie jest domknięty. Gdy komputer był wtedy wyłączony – pojawi się po jego włączeniu. Między 06:00 a 22:00. Puste = bez przypomnienia.'],
        ],
        'Slack' => [
            'slack_header' => ['Nagłówek', 'text', 'Zmienne: '.self::PLACEHOLDERS_DAY],
            'slack_line' => ['Linia na ticket', 'text', 'Dodatkowo: {ticket} {czas} {opis} {tytul}'],
            'slack_footer' => ['Stopka', 'text', 'Zostaw puste, żeby pominąć.'],
        ],
        'Mail – nadgodziny' => [
            'mail_over_to' => ['Do', 'text', 'Kilka adresów oddziel przecinkiem.'],
            'mail_over_cc' => ['DW', 'text', ''],
            'mail_over_subject' => ['Temat', 'text', ''],
            'mail_over_body' => ['Treść', 'textarea', 'Zmienne: '.self::PLACEHOLDERS_DAY],
        ],
        'Mail – krótszy dzień (po fakcie)' => [
            'mail_under_to' => ['Do', 'text', ''],
            'mail_under_cc' => ['DW', 'text', ''],
            'mail_under_subject' => ['Temat', 'text', ''],
            'mail_under_body' => ['Treść', 'textarea', 'Zmienne: '.self::PLACEHOLDERS_DAY],
        ],
        'Mail – zaplanowana zmiana (z wyprzedzeniem)' => [
            'mail_plan_to' => ['Do', 'text', ''],
            'mail_plan_cc' => ['DW', 'text', ''],
            'mail_plan_subject' => ['Temat', 'text', 'Domyślnie sama data – resztę dopisujesz w programie pocztowym.'],
            'mail_plan_body' => ['Treść', 'textarea', 'Domyślnie sama data. Np. „Dzień dobry, w dniu {data} ({dzien}) {opis}. {powod_linia}”. Zmienne: '.self::PLACEHOLDERS_PLAN],
            'plan_phrase_late' => ['{opis} dla późniejszego przyjścia', 'text', 'Zdania {opis} są używane tylko, jeśli wpiszesz {opis} w temat lub treść.'],
            'plan_phrase_early' => ['{opis} dla wcześniejszego wyjścia', 'text', ''],
            'plan_phrase_short' => ['{opis} dla krótszego dnia', 'text', ''],
            'plan_notice_hours' => ['Wymagane wyprzedzenie (godziny)', 'number', 'Ostrzeżenie, jeśli mail wysyłasz później.'],
        ],
    ];

    #[Route('/settings', name: 'settings', methods: ['GET'])]
    public function index(Settings $settings): Response
    {
        return $this->renderForm($settings->all(), $settings);
    }

    #[Route('/settings', name: 'settings_save', methods: ['POST'])]
    public function save(Request $request, Settings $settings): Response
    {
        $values = [];
        foreach (self::FORM as $fields) {
            foreach ($fields as $key => [, $type]) {
                if ('colors' !== $type) {
                    $values[$key] = str_replace("\r\n", "\n", trim((string) $request->request->get($key, '')));
                }
            }
        }
        foreach (array_keys(Settings::TOKEN_FILES) as $key) {
            if ('' === $values[$key]) {
                unset($values[$key]); // puste = bez zmian
            }
        }

        $errors = [];
        if (!isset(Settings::FORMS[$values['forma']])) {
            $errors[] = 'Wybierz formę w tekstach.';
        }
        if (!isset(Settings::BALANCE_SOURCES[$values['balance_source']])) {
            $errors[] = 'Wybierz, z czego liczyć bilans dnia.';
        }
        if (!isset(Theme::choices()[$values['theme']])) {
            $errors[] = 'Wybierz motyw.';
        }
        $colors = $request->request->all('theme_custom');
        if ([] !== $colors) {
            $custom = [];
            foreach (array_keys(Theme::VARS) as $name) {
                if (!Theme::isColor((string) ($colors[$name] ?? ''))) {
                    $errors[] = 'Kolory motywu Własnego wybierz z palety (format #rrggbb).';
                    break;
                }
                $custom[$name] = strtolower((string) $colors[$name]);
            }
            $values['theme_custom'] = (string) json_encode($custom);
        }
        if ('' !== $values['jira_url'] && !preg_match('#^https?://[^\s/]+#', $values['jira_url'])) {
            $errors[] = 'Adres Jiry musi zaczynać się od https://';
        }
        foreach (['default_start', 'default_end'] as $k) {
            if (null === TimeFormat::normalizeClock($values[$k])) {
                $errors[] = 'Godziny wpisz w formacie GG:MM.';
            } else {
                $values[$k] = (string) TimeFormat::normalizeClock($values[$k]);
            }
        }
        if ('' !== $values['remind_time']) {
            if (null === TimeFormat::normalizeClock($values['remind_time'])) {
                $errors[] = 'Godziny wpisz w formacie GG:MM.';
            } elseif (!ReminderSchedule::inTimerWindow($values['remind_time'])) {
                $errors[] = 'Przypomnienie może być między 06:00 a 22:00 (wtedy sprawdza je timer).';
            } else {
                $values['remind_time'] = (string) TimeFormat::normalizeClock($values['remind_time']);
            }
        }
        // oba pola puste = brak stałej przerwy
        if ('' !== $values['lunch_from'] || '' !== $values['lunch_to']) {
            $from = TimeFormat::clockToMinutes($values['lunch_from']);
            $to = TimeFormat::clockToMinutes($values['lunch_to']);
            if (null === $from || null === $to || $to <= $from) {
                $errors[] = 'Stała przerwa: podaj obie godziny (GG:MM), „do” później niż „od” – albo zostaw oba pola puste.';
            } else {
                $values['lunch_from'] = TimeFormat::minutesToClock($from);
                $values['lunch_to'] = TimeFormat::minutesToClock($to);
            }
        }
        foreach (['default_break', 'norm_minutes', 'tolerance_minutes', 'toggl_close_minutes', 'plan_notice_hours'] as $k) {
            if (!ctype_digit($values[$k])) {
                $errors[] = 'Pola liczbowe muszą zawierać liczbę całkowitą.';
            }
        }
        foreach (['mail_over_to', 'mail_over_cc', 'mail_under_to', 'mail_under_cc', 'mail_plan_to', 'mail_plan_cc'] as $k) {
            if ($bad = Template::invalidAddresses($values[$k])) {
                $errors[] = 'Niepoprawne adresy e-mail: '.implode(', ', $bad);
            }
        }

        if ([] !== $errors) {
            foreach (array_unique($errors) as $e) {
                $this->addFlash('error', $e);
            }

            return $this->renderForm($values + $settings->all(), $settings, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $settings->save($values);
        $this->addFlash('success', 'Zapisano ustawienia.');

        return $this->redirectToRoute('settings');
    }

    #[Route('/settings/jira-test', name: 'settings_jira_test', methods: ['POST'])]
    public function jiraTest(JiraClient $jira): Response
    {
        try {
            $me = $jira->myself();
            $this->addFlash('success', sprintf('Połączono z Jirą jako %s (%s).', $me['name'], $me['account']));
        } catch (JiraException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('settings');
    }

    #[Route('/settings/toggl-test', name: 'settings_toggl_test', methods: ['POST'])]
    public function togglTest(TogglClient $toggl): Response
    {
        try {
            $me = $toggl->me();
            $this->addFlash('success', sprintf('Połączono z Togglem jako %s (%s).', $me['name'], $me['email']));
        } catch (TogglException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('settings');
    }

    #[Route('/settings/jira-issues', name: 'settings_jira_issues', methods: ['POST'])]
    public function refreshIssues(JiraClient $jira, Settings $settings, Request $request): Response
    {
        try {
            $issues = $jira->searchIssues($settings->get('jira_jql'));
            $settings->save(['jira_issue_cache' => (string) json_encode($issues, JSON_UNESCAPED_UNICODE)]);
            $this->addFlash('success', sprintf('Pobrano %d ticketów do podpowiedzi.', count($issues)));
        } catch (JiraException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        $back = (string) $request->request->get('back', '');

        return str_starts_with($back, '/') && !str_starts_with($back, '//')
            ? $this->redirect($back)
            : $this->redirectToRoute('settings');
    }

    /** @param array<string, string> $values */
    private function renderForm(array $values, Settings $settings, int $status = 200): Response
    {
        return $this->render('settings.html.twig', [
            'form' => self::FORM,
            'options' => ['forma' => Settings::FORMS, 'balance_source' => Settings::BALANCE_SOURCES],
            'themes' => Theme::choices(),
            'themeGroups' => Theme::groups(),
            // dopisek pod nazwą motywu z własnym wyglądem
            'themeNotes' => array_map(static fn (string $game): string => 'w stylu '.$game, Theme::GAMES) + Theme::STYLED,
            'defaultTheme' => Theme::DEFAULT,
            'palettes' => Theme::palettes(),
            // Własny jeszcze nieustawiony → zaczyna od kolorów obecnego motywu
            'customColors' => '' !== $values['theme_custom'] ? Theme::custom($values['theme_custom']) : (Theme::palettes()[$values['theme']] ?? Theme::palettes()[Theme::DEFAULT]),
            'themeVars' => Theme::VARS,
            'states' => ['dark' => Theme::STATES_DARK, 'light' => Theme::STATES_LIGHT],
            'series' => ['dark' => Theme::SERIES_DARK, 'light' => Theme::SERIES_LIGHT],
            'shades' => Theme::SHADES,
            'values' => $values,
            'hasTokens' => array_map(static fn (string $key): bool => '' !== $settings->get($key), array_combine(array_keys(Settings::TOKEN_FILES), array_keys(Settings::TOKEN_FILES))),
            'issueCount' => count($settings->issueCache()),
        ], new Response(status: $status));
    }
}
