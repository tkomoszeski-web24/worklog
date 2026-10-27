<img src="docs/logo.svg" width="72" height="72" alt="Logo: tarcza zegara z pierścieniem z kolorowych odcinków – czas rozpisany na tickety">

# Raport czasu pracy

Lokalna aplikacja w Dockerze (PHP 8.5, Symfony 8.1, Doctrine ORM 3.7 + DoctrineBundle 3.3, SQLite) do codziennego raportowania:

1. **Jira** – wpisujesz tickety i czas, jeden przycisk wysyła worklogi (nowe dodaje, zmienione aktualizuje, usunięte kasuje).
2. **Slack** – gotowe podsumowanie per ticket do skopiowania jednym kliknięciem.
3. **Mail o nadgodzinach / krótszym dniu** – pojawia się sam, gdy bilans dnia wychodzi poza tolerancję; otwiera się w Twoim programie pocztowym z wypełnionymi adresatami, tematem i treścią.
4. **Planowane zmiany** – późniejsze przyjście, wcześniejsze wyjście, krótszy dzień: zapisujesz → dostajesz gotowy mail (z ostrzeżeniem, jeśli wysyłasz za późno).
5. **Stoper** – ▶ przy tickecie, nad którym pracujesz; przełączenie na inny ticket zatrzymuje poprzedni. Pełne minuty, bez zaokrąglania. „od…” pod ▶ włącza stoper od wcześniejszej godziny (zadanie zaczęło się, zanim kliknięto ▶) – nie wcześniej niż ostatnie zatrzymanie stopera, więc zmierzony czas się nie nakłada.
6. **Przypomnienie** w dni robocze o wybranej godzinie (domyślnie 15:45, zmiana w Ustawieniach) – powiadomienie na pulpicie, jeśli czegoś z dzisiejszego raportu brakuje albo czeka niewysłany mail o planowanej zmianie (dziś lub w kolejnych dniach). Gdy komputer był wtedy wyłączony, pojawi się po jego włączeniu.
7. **Urlopy i święta** – polskie święta ustawowe są wbudowane (także ruchome i Wigilia), a urlop, L4 albo inny dzień wolny dodajesz w zakładce „Wolne” albo jednym kliknięciem na stronie dnia. W dzień wolny nie ma przypomnienia ani „brak raportu” na Przeglądzie, a praca tego dnia (także w weekend) liczy się w całości jako nadgodziny.
8. **Toggl Track** (nieobowiązkowo) – mierzysz czas w Togglu, a przycisk „Pobierz z Toggla” na stronie dnia wpisuje wpisy z tego dnia do wierszy (ticket, czas, opis, godzina „od” ze startu pierwszego wpisu; „do” wylicza się z czasu wiersza). Numer ticketu bierze się z opisu wpisu albo z nazwy projektu w Togglu; wpisy z tym samym ticketem i opisem łączą się w jeden wiersz. Ponowne pobranie tego samego dnia nie dubluje wierszy, tylko aktualizuje czas – ticket i opis zmienione w aplikacji zostają. Stoper, który w Togglu nadal działa, jest doliczany do chwili pobrania (z ostrzeżeniem) – po jego zatrzymaniu pobierz dzień ponownie, a czas się domknie. Wpisy bez numeru ticketu są pomijane z ostrzeżeniem. Import ustawia też godziny obecności od–do z pierwszego i ostatniego wpisu w Togglu (także pominiętych, np. przerwy), chyba że je zmieniłeś; przerwy i obiad liczą się jak dotąd z ustawień. Wiersz, który kończyłby się po północy, nie dostaje godziny „od” (z ostrzeżeniem). Obok przycisku stoi „pobrano o 16:20 · 5 wierszy z Toggla”, a wiersze pobrane z Toggla mają przy numerze ticketu małą ikonę. Ponowne pobranie zaciąga wszystkie wpisy z Toggla, więc wiersz usunięty w aplikacji wróci, jeśli nadal jest w Togglu.
9. **Podsumowanie miesiąca** (zakładka „Miesiąc”) – bilans, suma nadgodzin i niedoborów, dni bez raportu, urlopy i czas per ticket; eksport do CSV (dzień po dniu albo wszystkie wpisy) do otwarcia w Excelu.

## Prywatność

- Port jest wystawiony tylko na `127.0.0.1` – aplikacja nie jest dostępna z sieci. Dodatkowo odrzuca żądania z obcym nagłówkiem `Host` i POST-y wysłane z innych stron.
- Połączenia wychodzące z działającego kontenera: adres Twojej Jiry oraz – tylko jeśli wpiszesz token Toggla – `api.track.toggl.com` (tylko odczyt Twoich wpisów). Slack i maile nie są wysyłane przez aplikację – kopiujesz tekst / zatwierdzasz mail w swoim kliencie.
- Strona nie ładuje nic z internetu (żadnych CDN, fontów, analityki). Czcionki Inter i Pixelify Sans (do motywu Osadnicy) są dołączone do aplikacji (`public/fonts`, licencja SIL OFL).
- Dane i tokeny leżą w wolumenie Dockera `worklog-data` (pliki z uprawnieniami 600). Tokeny (Jiry i Toggla) są w osobnych plikach (`.jira_token`, `.toggl_token`), nie w bazie, więc kopie zapasowe ich nie zawierają – po przywróceniu kopii na nowym komputerze wpisz je ponownie w Ustawieniach. Kontener działa jako zwykły użytkownik, bez uprawnień (`cap_drop: ALL`, `no-new-privileges`).
- Internet (Docker Hub, packagist.org) jest potrzebny tylko przy budowaniu obrazu.

## Bezpieczeństwo wpisów

- **Autozapis** – każda zmiana trafia do bazy ok. 1 s po wpisaniu i przy zamykaniu karty; status „✓ zapisano 14:32” przy przyciskach.
- **Kopia w przeglądarce** – gdy zapis do bazy się nie uda, zmiany zostają w przeglądarce i po ponownym otwarciu dnia można je przywrócić.
- **Stoper w bazie** – czas startu jest na dysku, więc przeżywa zamknięcie przeglądarki i restart; stoper działający od innego dnia lub > 6h pyta, o której go zatrzymać.
- **Kopie co godzinę** – w dni robocze do `~/worklog-backups` (30 dni, sprawdzana integralność, bez tokenów; powiadomienie przy błędzie). Inny katalog: `./install.sh --backup-dir /ścieżka`.
- **Kopia przed zmianą struktury bazy** – gdy nowa wersja zmienia tabele (start po `./worklog up`), kontener najpierw robi kopię do `/data/kopie` w wolumenie (10 ostatnich); bez udanej kopii nie startuje i niczego nie zmienia. Komunikat jest w `./worklog logs`. Przywrócenie: `docker cp worklog:/data/kopie/PLIK.db ~/` i `./worklog restore ~/PLIK.db`.
- **Odporny zapis** – SQLite w trybie WAL z `synchronous=FULL`.
- **Usuwanie z potwierdzeniem** – zaznaczenie ✕ niczego nie kasuje. Po „Zapisz” aplikacja pokazuje listę zaznaczonych wpisów i usuwa je dopiero po „Usuń zaznaczone” (także gdy formularz wyśle się Enterem); autozapis nigdy nie usuwa.

## Instalacja (Ubuntu)

Potrzebny Docker Engine z wtyczką compose ([instrukcja](https://docs.docker.com/engine/install/ubuntu/)) i dostęp do niego bez sudo:

```bash
sudo usermod -aG docker $USER      # potem wyloguj się i zaloguj
sudo apt install libnotify-bin     # powiadomienia (zwykle już jest)

tar xzf worklog-tool.tar.gz && cd worklog-tool
./install.sh                       # opcje: --port 8765  --remind 16:00  --no-remind  --vpn  --forma f|m|n
```

Instalator zapyta o **formę w tekstach**: żeńską („Pracowałam w przerwie obiadowej”, w mailu „pracowałam dłużej o 1h”), męską („Pracowałem…”) albo neutralną („Praca w przerwie obiadowej”, „mój czas pracy był dłuższy o 1h”). Później zmienisz ją w Ustawieniach → Ogólne.

Potem otwórz **http://127.0.0.1:8765** (albo „Raport czasu” z menu aplikacji) i zacznij od **Ustawień**.
Kontener startuje sam po restarcie komputera.

### VPN / Jira w sieci firmowej

Jeśli „Sprawdź połączenie” zgłasza brak połączenia, a Jira działa w przeglądarce (np. tylko przez VPN lub firmowy DNS), zainstaluj z `--vpn`:

```bash
./install.sh --vpn
```

Kontener użyje wtedy sieci hosta, a serwer będzie słuchał wyłącznie na `127.0.0.1`.

## Instalacja (Windows)

Potrzebny [Docker Desktop](https://docs.docker.com/desktop/setup/install/windows-install/) z domyślnymi ustawieniami (WSL 2, kontenery Linux). W firmach od 250 pracowników albo od 10 mln USD rocznego przychodu Docker Desktop wymaga płatnej licencji.

1. Rozpakuj archiwum albo zrób `git clone` (plik `.gitattributes` pilnuje końców linii, więc Git na Windowsie niczego nie zepsuje).
2. Uruchom Docker Desktop i poczekaj, aż wystartuje.
3. Kliknij dwukrotnie `install.cmd`. Z opcjami – w PowerShellu albo cmd, w katalogu z narzędziem:

```powershell
.\install.cmd                      # opcje: -Port 8765  -Remind 16:00  -NoRemind  -BackupDir D:\kopie  -Forma f|m|n
```

Instalator robi to samo co na Ubuntu (obraz, kontener, forma w tekstach, pierwsza kopia), a zamiast timerów systemd uruchamia **agenta w tle**: ukryty proces PowerShella, który startuje przy logowaniu (skrót „Raport czasu (w tle)” w Autostarcie – widać go w Menedżerze zadań → Aplikacje autostartu). Agent:

- co minutę w dni robocze pyta aplikację o przypomnienie i pokazuje je jako powiadomienie Windows (nadawca: „Windows PowerShell”; kliknięcie otwiera raport),
- w dni robocze co godzinę robi kopię bazy do `%USERPROFILE%\worklog-backups` (30 dni wstecz, bez duplikatów); kopię przegapioną, bo komputer był wyłączony, robi po jego włączeniu.

W menu Start pojawia się skrót „Raport czasu”. Polecenia `./worklog …` wpisujesz jako `.\worklog.cmd …`:

```powershell
.\worklog.cmd status
.\worklog.cmd backup              # albo: backup D:\kopie\raport.db
.\worklog.cmd restore ~\worklog-backups\worklog-RRRRMMDD-GGMM.db
.\worklog.cmd update
.\worklog.cmd uninstall           # usuwa też agenta i skróty; kopie zapasowe zostają
```

- **VPN:** opcja `--vpn` nie jest potrzebna – Docker Desktop wysyła ruch kontenera przez Windows, więc także przez VPN i firmowy DNS.
- **Skrypty zablokowane:** `install.cmd` i `worklog.cmd` uruchamiają PowerShella z `-ExecutionPolicy Bypass`. Jeśli zasady firmy (GPO) mimo to blokują skrypty, potrzebna jest zgoda działu IT.
- **Brak powiadomień:** Ustawienia → System → Powiadomienia → włącz dla „Windows PowerShell” (tryb „Nie przeszkadzać” je wycisza). Błędy agenta, np. nieudane kopie, trafiają też do pliku `.agent.log` w katalogu kopii.
- **Długi mail:** Windows przekazuje programowi pocztowemu najwyżej ok. 2000 znaków linku. Dłuższy mail (np. długa lista ticketów) otwiera się z adresatami i tematem, a treść aplikacja kopiuje do schowka – wklej ją Ctrl+V.

## Jira – token

Token generujesz sam, z uprawnieniami swojego konta:

| Twoja Jira | E-mail | Token |
|---|---|---|
| Cloud (`*.atlassian.net`) | Twój e-mail w Atlassian | id.atlassian.com → Security → Create and manage API tokens |
| Server / Data Center | **puste** | Avatar → Profile → Personal Access Tokens → Create token |

Jeśli nie widzisz tej opcji, admin wyłączył tokeny – poproś go o Personal Access Token / API token z Twoimi uprawnieniami.

## Toggl – token

Token API generujesz sam: Toggl Track → avatar (Profile) → Profile settings → na dole „API Token”. Wpisz go w Ustawienia → Toggl i kliknij „Sprawdź połączenie” (panel „Połączenie z Togglem”). Dopiero z tokenem na stronie dnia pojawia się przycisk „Pobierz z Toggla”. Czas wiersza z Toggla jest zaokrąglany do najbliższej pełnej minuty (od 30 s w górę); stoper aplikacji dalej liczy co do sekundy. Po pobraniu import domyka sumę wierszy do obecności dnia: brakujące minuty (do progu z Ustawienia → Toggl → „Domykanie czasu z Toggla do obecności”, domyślnie 5; 0 = wyłączone) trafiają do wierszy z największą resztą sekund, a nadmiar po zaokrągleniach w górę jest odejmowany. Większa różnica (przerwa bez stopera, wpis bez ticketu) zostaje widoczna; nic się nie domyka, gdy przy którymś wierszu działa stoper aplikacji. Aplikacja tylko czyta Twoje wpisy; limit Toggla to ok. 1 zapytanie na sekundę.

## Codzienny przebieg

1. **Dzień** → godziny od–do (domyślne są już wpisane) + wiersze: ticket, czas (`1h 30m`, `1.5`, `90m`, `1:30`; sama liczba to godziny, najwyżej 12 – przy `20` aplikacja zapyta, czy chodzi o minuty), opis.
   „Skopiuj tickety z poprzedniego dnia” przenosi same numery.
   Tytuł ticketu wpisujesz razem z numerem („ABC-123 Produkcja nie działa”) – numer zostaje w polu ticketu, tytuł przechodzi do linii pod nim i trafia na Slacka, do `{lista}` w mailu i do CSV. Przy kolejnych dniach podpowiada się sam.
   Kolumna **Od–do** (nieobowiązkowa) ustala, o której zaczął się worklog: wpisz start i czas – koniec wyliczy się sam, albo start i koniec – wyliczy się czas. Obiad i przerwy wpisane godzinami się nie liczą (11:00–14:00 z obiadem 12–13 = 2h).
   Jeśli czas mierzysz w Togglu: **Pobierz z Toggla** (obok „Dodaj wpis”) zaciąga wpisy tego dnia. Przed wysłaniem do Jiry sprawdź wiersze – wpisy bez numeru ticketu trzeba dodać ręcznie.
2. **Zapisz i wyślij do Jiry.** Pasek z lewej strony wiersza: zielony = w Jirze, żółty = zmieniony po wysłaniu, czerwony = błąd (najedź, żeby zobaczyć treść).
3. **Kopiuj na Slacka** → wklej w kanał.
4. Jeśli pojawi się panel maila → **Otwórz w programie pocztowym** → Wyślij.

Worklogi dostają kolejne godziny startu od początku dnia i omijają przerwę obiadową (8:00–10:00, 10:00–12:00, 13:00–15:00…), więc nie nakładają się w kalendarzu/Tempo. Wpis z godziną w kolumnie Od–do startuje dokładnie o niej, a pozostałe omijają jego czas tak jak przerwę.

## Szablony

Teksty Slacka i maili edytujesz w Ustawieniach. Zmienne:

- dzień: `{data} {data_iso} {dzien} {od} {do} {przerwa} {obecnosc} {zalogowane} {norma} {roznica} {lista} {podpis}`
- linia Slacka dodatkowo: `{ticket} {czas} {opis} {tytul}`
- planowana zmiana: `{data} {dzien} {typ} {od} {do} {opis} {powod} {powod_linia} {podpis}`

Mail o **planowanej zmianie** (późniejsze przyjście, wcześniejsze wyjście, krótszy dzień) ma domyślnie w temacie i treści samą datę – resztę dopisujesz przed wysłaniem albo ustawiasz własny szablon w Ustawieniach (np. z gotowym zdaniem `{opis}`).

Nieznana zmienna zostaje w tekście dosłownie – łatwo wyłapać literówkę.
Domyślnie: norma **7h**, praca 8:00–16:00, stała przerwa obiadowa **12:00–13:00** (zmienisz w Ustawieniach → Dzień pracy).
Bilans liczony jest z godzin obecności: od–do minus ta część obiadu, która wypada w godzinach pracy, minus ewentualna dodatkowa przerwa; gdy godzin nie podasz – z sumy czasu w ticketach. W Ustawienia → Dzień pracy możesz przełączyć „Bilans dnia liczony z” na czas w ticketach (dobre, gdy cały czas mierzysz stoperem, np. w Togglu): wtedy bilans dnia i miesiąca, nadgodziny/krótszy dzień, tolerancja i maile liczą się z sumy wierszy (obowiązuje od razu, także wstecz). Obecność zostaje w panelu jako informacja, godziny od–do służą dalej do maila i godzin worklogów w Jirze, a ostrzeżenie o różnicy z obecnością i wzmianka w przypomnieniu znikają. Dzień z godzinami, ale bez wierszy, jest wtedy pusty (bez maila o krótszym dniu). Przy imporcie z Toggla godziny od–do ustawia import (z pierwszego i ostatniego wpisu dnia, chyba że je zmienisz); drobna różnica względem czasu w ticketach (zaokrąglenia, luki między zatrzymaniem a ponownym startem stopera) znika dzięki domykaniu czasu z Toggla (próg w Ustawieniach → Toggl, domyślnie 5 minut), a większa oznacza przerwę bez stopera albo wpis bez numeru ticketu (np. projekt „Przerwa”), który import pominął.

**Awaria w porze obiadu:** zaznacz w dniu pole pracy w przerwie obiadowej i wpisz przerwę, która faktycznie była – czasem (`1h`, `20m + 40m`) albo godzinami (`13:30-13:50, 15:00-15:40`). Obiad nie odliczy się wtedy automatycznie, a worklogi w Jirze ominą wpisane godziny przerw.

## Wygląd

W **Ustawieniach → Wygląd** wybierasz motyw z poświatą – ciemny: Aurora (fiolet i błękit), Głębia (turkus), Zachód (pomarańcz i róż), Neon (róż i fiolet), Grafit (szarości), jasny: Świt (fiolet i błękit), Mięta, Brzoskwinia, Lawenda, Papier (domyślny, czerń i biel), albo z gier: **Osadnicy** w stylu The Settlers II: Veni Vidi Vici – trawa z mapy w tle, panele jak okna z gry (kamienna rama, pasek tytułu z kwadratami w rogach, ciemnozielony środek w smugi), żółte pikselowe napisy (Pixelify Sans, licencja OFL; cyfry zostają w Inter), wypukłe przyciski, a pod nagłówkiem droga z chorągiewkami, po której tragarze noszą kłody, kamienie i zboże, obok drwal i budowniczy. Postaci to własny pixel-art (`public/img/osadnicy-*.svg`, rysuje je `docs/grafika/osadnicy.py`). Wygląd motywu z gier (poza kolorami) jest w `base.html.twig` pod `[data-theme=settlers]`. **Własny** pozwala ustawić 8 kolorów (tła, tekst, linie, oba akcenty); „Wczytaj kolory” bierze punkt wyjścia z gotowego motywu, a przy słabo czytelnym tekście pojawia się ostrzeżenie. Przy jasnym tle kolory stanów (nadgodziny, błędy) przechodzą na wersję dla jasnego tła. Kliknięty motyw albo zmieniony kolor widać od razu, po zapisie – na wszystkich stronach. Kolory są w `src/Domain/Theme.php`, a `tests/domain_test.php` sprawdza ich kontrast. Każdy ticket na osi dnia ma własny kolor – ten sam przy jego wierszu.

## Obsługa

Na Windowsie te same polecenia: `.\worklog.cmd status`, `.\worklog.cmd backup` itd.

```bash
./worklog up         # zbudowanie i uruchomienie nowej wersji (np. po git pull) – dane zostają
./worklog status     # stan kontenera i wersja (ta sama jest w stopce strony, np. „fd55a4a z 14.10.2026”)
./worklog logs       # logi na żywo
./worklog restart
./worklog backup     # dodatkowa kopia teraz (automatyczne są co godzinę w ~/worklog-backups)
./worklog restore ~/worklog-backups/worklog-RRRRMMDD-GGMM.db   # przed nadpisaniem robi kopię obecnego stanu
./worklog update     # przebudowa obrazu na najnowszych łatkach PHP 8.5 / Symfony 8.1
./worklog remind     # co zostało do zrobienia dzisiaj
./worklog down       # zatrzymanie (dane zostają w wolumenie)
./worklog uninstall  # usunięcie wszystkiego (kontener, dane, timery, skrót) – najpierw ostatnia kopia bazy
```

Godzinę przypomnienia zmieniasz w aplikacji: **Ustawienia → Przypomnienie** (puste pole = bez przypomnienia).
Zmiana portu: uruchom ponownie `./install.sh --port 8766` – dane zostają.

Całkowite usunięcie: `./worklog uninstall`. Usuwa kontener, obraz, wolumen z danymi, timery systemd i skrót w menu; przedtem robi ostatnią kopię bazy. Kopie zapasowe i katalog z kodem zostają. Potem unieważnij token w Jirze.

## Wersje

| Składnik | Wersja |
|---|---|
| PHP | 8.5 (`php:8.5-cli-alpine`) |
| Symfony | 8.1.* |
| Doctrine ORM / DBAL | ^3.7 / ^4.3 |
| DoctrineBundle | ^3.3 |
| Twig | ^3.21 |

Dokładne wersje pakietów są przypięte w `composer.lock` – `./worklog update` podnosi je do najnowszych łatek w ramach `composer.json` i przebudowuje obraz.

Symfony 8.1 ma wsparcie bezpieczeństwa do stycznia 2027 – później zmień w `composer.json` `8.1.*` na kolejną wersję (8.2 / 8.4 LTS) i zrób `./worklog update`.
Jeśli wolisz rzadsze aktualizacje, możesz zamiast tego użyć LTS: `7.4.*` (wsparcie do listopada 2029).

## Testy logiki

`tests/services_test.php` obejmuje m.in. import z Toggla (grupowanie, strefy czasowe, ponowny import) na atrapie HTTP.

```bash
docker exec worklog php tests/domain_test.php
docker exec worklog php tests/services_test.php
cd tests/browser && npm install && npm test     # autozapis, szkic, stoper, usuwanie, od–do, tytuły – w symulowanej przeglądarce
```

## Instrukcja PDF

`docs/raport-czasu-instrukcja.pdf` jest generowana z `docs/instrukcja/build.py` (treść jest w tym pliku). Po zmianie:

```bash
docker run --rm -v "$PWD":/w -v /usr/share/fonts/truetype/dejavu:/fonts:ro -w /w python:3.12-slim \
    sh -c "pip install -q reportlab && python docs/instrukcja/build.py /fonts"
```
