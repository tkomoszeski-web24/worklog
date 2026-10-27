"""Generuje docs/raport-czasu-instrukcja.pdf – mini instrukcję obsługi.

Treść jest tutaj, w kodzie; po zmianie uruchom (potrzebny Docker i czcionki DejaVu na hoście):

    docker run --rm -v "$PWD":/w -v /usr/share/fonts/truetype/dejavu:/fonts:ro -w /w python:3.12-slim \\
        sh -c "pip install -q reportlab && python docs/instrukcja/build.py /fonts"

Teksty piszemy w formie neutralnej – instrukcja jest wspólna dla wszystkich użytkowników.
"""

import sys
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (Flowable, ListFlowable, ListItem, Paragraph, Preformatted, SimpleDocTemplate,
                                Spacer, Table, TableStyle)

FONTS = Path(sys.argv[1] if len(sys.argv) > 1 else '/usr/share/fonts/truetype/dejavu')
OUT = Path(__file__).resolve().parent.parent / 'raport-czasu-instrukcja.pdf'

for name, file in [('DV', 'DejaVuSans.ttf'), ('DV-B', 'DejaVuSans-Bold.ttf'), ('DV-M', 'DejaVuSansMono.ttf')]:
    pdfmetrics.registerFont(TTFont(name, str(FONTS / file)))
pdfmetrics.registerFontFamily('DV', normal='DV', bold='DV-B', italic='DV', boldItalic='DV-B')

GREEN = colors.HexColor('#2e5e4e')
INK = colors.HexColor('#222222')
MUTED = colors.HexColor('#6b7280')
BOX = colors.HexColor('#eaf2ee')
HEAD = colors.HexColor('#eef0ee')
LINE = colors.HexColor('#d9dedb')
CODE = colors.HexColor('#f1f3f2')

body = ParagraphStyle('body', fontName='DV', fontSize=9.5, leading=13.5, textColor=INK, alignment=TA_LEFT, spaceAfter=4)
small = ParagraphStyle('small', parent=body, fontSize=8.5, leading=12)
cell = ParagraphStyle('cell', parent=body, fontSize=8.5, leading=11.5, spaceAfter=0)
cell_key = ParagraphStyle('cellkey', parent=cell, fontName='DV-B', textColor=GREEN)
cell_head = ParagraphStyle('cellhead', parent=cell, fontName='DV-B')
title = ParagraphStyle('title', fontName='DV-B', fontSize=26, leading=30, textColor=GREEN)
subtitle = ParagraphStyle('subtitle', fontName='DV', fontSize=11, leading=15, textColor=MUTED, spaceAfter=10)
h1 = ParagraphStyle('h1', fontName='DV-B', fontSize=14, leading=18, textColor=GREEN, spaceBefore=12, spaceAfter=6)
h2 = ParagraphStyle('h2', fontName='DV-B', fontSize=10.5, leading=14, textColor=INK, spaceBefore=6, spaceAfter=4)
box_title = ParagraphStyle('boxtitle', parent=body, fontName='DV-B', spaceAfter=2)
code = ParagraphStyle('code', fontName='DV-M', fontSize=8.3, leading=11.5, textColor=INK)

story = []


def p(text, style=body):
    story.append(Paragraph(text, style))


def h(text):
    story.append(Paragraph(text, h1))


def sub(text):
    story.append(Paragraph(text, h2))


def steps(*items):
    story.append(ListFlowable(
        [ListItem(Paragraph(i, body), leftIndent=14) for i in items],
        bulletType='1', bulletFontName='DV-B', bulletColor=GREEN, bulletFontSize=9.5, leftIndent=14, bulletFormat='%s'))


def bullets(*items):
    story.append(ListFlowable(
        [ListItem(Paragraph(i, body), leftIndent=12) for i in items],
        bulletType='bullet', start='•', bulletColor=GREEN, leftIndent=12))


def table(header, rows, widths, key_column=True):
    data = [[Paragraph(c, cell_head) for c in header]]
    for r in rows:
        data.append([Paragraph(c, cell_key if key_column and i == 0 else cell) for i, c in enumerate(r)])
    t = Table(data, colWidths=[w * mm for w in widths], repeatRows=1)
    t.setStyle(TableStyle([
        ('BACKGROUND', (0, 0), (-1, 0), HEAD),
        ('LINEBELOW', (0, 0), (-1, -1), 0.5, LINE),
        ('VALIGN', (0, 0), (-1, -1), 'TOP'),
        ('TOPPADDING', (0, 0), (-1, -1), 4), ('BOTTOMPADDING', (0, 0), (-1, -1), 4),
        ('LEFTPADDING', (0, 0), (-1, -1), 5), ('RIGHTPADDING', (0, 0), (-1, -1), 5),
    ]))
    story.append(t)
    story.append(Spacer(1, 6))


def box(heading, *lines):
    content = ([Paragraph(heading, box_title)] if heading else []) + [Paragraph(l, small) for l in lines]
    t = Table([[content]], colWidths=[170 * mm])
    t.setStyle(TableStyle([
        ('BACKGROUND', (0, 0), (-1, -1), BOX),
        ('LEFTPADDING', (0, 0), (-1, -1), 8), ('RIGHTPADDING', (0, 0), (-1, -1), 8),
        ('TOPPADDING', (0, 0), (-1, -1), 6), ('BOTTOMPADDING', (0, 0), (-1, -1), 6),
    ]))
    story.append(Spacer(1, 4))
    story.append(t)
    story.append(Spacer(1, 6))


def cmd(text):
    t = Table([[Preformatted(text, code)]], colWidths=[170 * mm])
    t.setStyle(TableStyle([
        ('BACKGROUND', (0, 0), (-1, -1), CODE),
        ('LEFTPADDING', (0, 0), (-1, -1), 8), ('TOPPADDING', (0, 0), (-1, -1), 5), ('BOTTOMPADDING', (0, 0), (-1, -1), 5),
    ]))
    story.append(Spacer(1, 2))
    story.append(t)
    story.append(Spacer(1, 6))


class Logo(Flowable):
    """Znak aplikacji jak docs/logo.svg: tarcza zegara z pierścieniem z kolorowych odcinków (czas rozpisany na tickety)."""

    SEGMENTS = (('#2dd4bf', 0.36), ('#fbbf24', 0.24), ('#fb7185', 0.17), ('#a78bfa', 0.13))  # reszta pierścienia wolna

    def __init__(self, size):
        super().__init__()
        self.width = self.height = size

    def draw(self):
        c = self.canv
        c.saveState()
        c.scale(self.width / 32, self.height / 32)
        c.translate(0, 32)
        c.scale(1, -1)  # układ jak w SVG: y w dół (kąty łuków rosną zgodnie z ruchem wskazówek)
        frame = c.beginPath()
        frame.roundRect(0, 0, 32, 32, 8)
        c.clipPath(frame, stroke=0, fill=0)
        c.linearGradient(0, 0, 32, 32, (colors.HexColor('#1e2a4a'), colors.HexColor('#0d1326')), extend=True)
        c.setLineWidth(4.2)
        c.setStrokeColor(colors.Color(1, 1, 1, alpha=0.1))
        c.circle(16, 16, 9.5, stroke=1, fill=0)
        gap = 1.1 / (2 * 3.14159265 * 9.5) * 360  # przerwa między odcinkami w stopniach
        start = -90
        for color, share in self.SEGMENTS:
            c.setStrokeColor(colors.HexColor(color))
            arc = c.beginPath()
            arc.arc(6.5, 6.5, 25.5, 25.5, start, share * 360 - gap)
            c.drawPath(arc, stroke=1, fill=0)
            start += share * 360
        c.setStrokeColor(colors.HexColor('#f8fafc'))
        c.setFillColor(colors.HexColor('#f8fafc'))
        c.setLineCap(1)
        c.setLineWidth(2)
        c.line(16, 16, 16, 10.6)
        c.line(16, 16, 19.4, 18.2)
        c.circle(16, 16, 1.5, stroke=0, fill=1)
        c.restoreState()


B = lambda s: f'<b>{s}</b>'  # noqa: E731
G = lambda s: f'<font color="#2e5e4e"><b>{s}</b></font>'  # noqa: E731
M = lambda s: f'<font name="DV-M" size="8.5">{s}</font>'  # noqa: E731
# pasek stanu przy wierszu w kolorze z aplikacji (jasny motyw)
DOT = lambda color: f'<font color="{color}">▌</font>'  # noqa: E731

# ---------------------------------------------------------------- treść

head = Table([[Logo(17 * mm), [Paragraph('Raport czasu', title), Paragraph('Mini instrukcja: Jira, Slack i maile o czasie pracy', subtitle)]]],
             colWidths=[22 * mm, 148 * mm])
head.setStyle(TableStyle([('VALIGN', (0, 0), (-1, -1), 'MIDDLE'), ('LEFTPADDING', (0, 0), (-1, -1), 0), ('RIGHTPADDING', (0, 0), (-1, -1), 0),
                          ('TOPPADDING', (0, 0), (-1, -1), 0), ('BOTTOMPADDING', (0, 0), (-1, -1), 0)]))
story.append(head)
story.append(Spacer(1, 6))

box('Codziennie w 5 krokach',
    f'1. Otwórz {B("Raport czasu")} z menu aplikacji (albo http://127.0.0.1:8765).',
    f'2. W ciągu dnia klikaj ▶ przy tickecie, nad którym pracujesz (albo wpisz czas ręcznie). Wszystko zapisuje się samo.',
    f'3. Pod koniec dnia → {G("Zapisz i wyślij do Jiry")}.',
    f'4. {G("Kopiuj na Slacka")} → wklej w kanał.',
    f'5. Jeśli pojawi się panel maila → {G("Otwórz w programie pocztowym")} → Wyślij.')

table(['Zakładka', 'Do czego służy'], [
    ['Dzień', 'Wpisywanie czasu, wysyłka do Jiry, tekst na Slacka, mail o nadgodzinach / krótszym dniu.'],
    ['Przegląd', 'Ostatnie 4 tygodnie: co jest wysłane, a czego brakuje. Nadchodzące planowane zmiany.'],
    ['Miesiąc', 'Bilans miesiąca, nadgodziny i niedobory, czas per ticket, eksport do CSV.'],
    ['Planowane zmiany', 'Zgłoszenie późniejszego przyjścia, wcześniejszego wyjścia lub krótszego dnia.'],
    ['Wolne', 'Urlop, L4 i inne dni wolne; najbliższe święta.'],
    ['Ustawienia', 'Forma w tekstach, motyw, połączenie z Jirą, godziny pracy, przypomnienie, adresaci i treści maili, szablon Slacka.'],
], [40, 130])

h('1. Pierwsze uruchomienie')
p('Instalacja (jednorazowo, w terminalu, w katalogu z narzędziem):')
cmd('sudo usermod -aG docker $USER      # potem wyloguj się i zaloguj\n./install.sh')
p(f'{B("Na Windowsie:")} zainstaluj i uruchom Docker Desktop, potem kliknij dwukrotnie {M("install.cmd")}. '
  f'Polecenia {M("./worklog …")} z tej instrukcji wpisujesz jako {M(".\\worklog.cmd …")} (w PowerShellu lub cmd, '
  f'w katalogu z narzędziem). Przypomnienie i kopie zapasowe robi agent w tle, który startuje razem z Windowsem.')
p(f'Instalator zapyta o {B("formę w tekstach")} – jak aplikacja ma się zwracać do użytkownika i w jakiej formie '
  f'pisać domyślne maile:')
table(['Forma', 'W aplikacji', 'W mailu o nadgodzinach'], [
    ['f – żeńska', '„Pracowałam w przerwie obiadowej”', '„pracowałam dłużej o 1h”'],
    ['m – męska', '„Pracowałem w przerwie obiadowej”', '„pracowałem dłużej o 1h”'],
    ['n – neutralna', '„Praca w przerwie obiadowej”', '„mój czas pracy był dłuższy o 1h”'],
], [32, 70, 68])
p(f'Formę zmienisz później w {B("Ustawienia → Ogólne")}. Następnie otwórz {B("Ustawienia")} i uzupełnij:')
steps(
    f'{B("Adres Jiry")} – taki, jak w przeglądarce, np. https://firma.atlassian.net.',
    f'{B("E-mail i token")} – patrz tabela niżej. Pole tokenu po zapisaniu pokazuje kropki; puste pole przy kolejnym '
    f'zapisie nie usuwa tokenu.',
    f'{B("Dzień pracy")} – domyślne godziny od–do (8:00–16:00), {B("stała przerwa obiadowa")} (12:00–13:00), norma '
    f'({B("420 min = 7h")}) i tolerancja (0 – mail przy każdej różnicy względem normy). Te wartości są już wpisane – zmieniasz je tylko, '
    f'jeśli u Ciebie jest inaczej.',
    f'{B("Podpis")} oraz {B("adresaci")} trzech rodzajów maili (kilka adresów oddziel przecinkiem).',
    f'Kliknij {G("Zapisz ustawienia")}, potem {G("Sprawdź połączenie")} – powinno pokazać Twoje imię i nazwisko.',
    f'Kliknij {G("Pobierz moje tickety do podpowiedzi")} – numery będą się podpowiadać przy wpisywaniu.')

table(['Twoja Jira', 'Pole „E-mail”', 'Skąd wziąć token'], [
    ['Cloud (adres *.atlassian.net)', 'Twój e-mail w Atlassian',
     'id.atlassian.com → Security → Create and manage API tokens → Create API token'],
    ['Server / Data Center (adres firmowy)', 'zostaw puste', 'Avatar → Profile → Personal Access Tokens → Create token'],
], [45, 40, 85])
box(None, 'Token ma te same uprawnienia co Ty – niczego dodatkowego nie trzeba. Admina pytasz tylko wtedy, '
          'gdy w profilu nie ma opcji tokenów.')

p(f'{B("Toggl Track (nieobowiązkowo)")} – jeśli czas mierzysz w Togglu, wpisz w {B("Ustawienia → Toggl")} swój token API '
  f'(Toggl Track → avatar → Profile settings → na dole „API Token”) i kliknij {G("Sprawdź połączenie")}. Na stronie dnia '
  f'pojawi się przycisk {G("Pobierz z Toggla")}: wpisy z tego dnia trafiają do wierszy (ticket z opisu albo z nazwy projektu, '
  f'czas, godzina „od” ze startu pierwszego wpisu; „do” wylicza się z czasu wiersza). Wpisy z tym samym ticketem i opisem łączą się w jeden wiersz, a ponowne kliknięcie nie dubluje '
  f'wierszy, tylko aktualizuje czas. Czas z Toggla jest zaokrąglany do najbliższej pełnej minuty (od 30 s w górę), a stoper aplikacji dalej liczy co do sekundy. Wpisy bez numeru ticketu aplikacja pomija i o tym informuje. Stoper, który w Togglu jeszcze działa, jest doliczany do chwili pobrania – po jego zatrzymaniu pobierz dzień ponownie. '
  f'Godziny „Od–Do” dnia ustawiają się z pierwszego i ostatniego wpisu w Togglu, chyba że je zmieniłeś ręcznie. '
  f'Po pobraniu brakujące minuty (do progu z pola Ustawienia → Toggl → „Domykanie do obecności (minuty)”, domyślnie 5; 0 = wyłączone) są rozdzielane między wiersze, '
  f'żeby suma ticketów równała się obecności; większą różnicę (przerwa bez stopera, wpis bez ticketu) aplikacja zostawia widoczną. '
  f'Jeśli mierzysz wszystko w Togglu, przełącz bilans na czas w ticketach (Ustawienia → Dzień pracy, patrz rozdział 7). '
  f'Obok przycisku widać „pobrano o …”, a wiersze z Toggla mają przy tickecie małą ikonę.')

p(f'Wygląd zmienisz w {B("Ustawienia → Wygląd")}: ciemne motywy Aurora, Głębia, Zachód, Neon, Grafit, '
  f'jasne Świt, Mięta, Brzoskwinia, Lawenda, Papier (domyślny), Prosty (płasko, w stylu Bootstrapa), Osadnicy w stylu The Settlers II (okna z gry, żółte pikselowe napisy, tragarze na drodze pod menu) – albo {B("Własny")}: 8 kolorów do wyboru, z ostrzeżeniem, gdy tekst byłby słabo '
  f'czytelny. Zmianę widać od razu; zostaje po {G("Zapisz ustawienia")}.')

h('2. Wpisanie dnia')
steps(
    f'Wejdź w {B("Dzień")}. Strzałki ← → i pole daty przełączają dni; link {B("dzisiaj")} wraca do bieżącego.',
    f'Sprawdź godziny {B("Od / Do")}. Dla nowego dnia są wpisane domyślne – albo z planowanej zmiany, jeśli taka '
    f'jest. Obiadu 12:00–13:00 nie wpisujesz – odlicza się sam. Pole {B("Dodatkowa przerwa")} (np. 30m) '
    f'wypełniasz tylko, gdy była jeszcze inna przerwa.',
    'W każdym wierszu: numer ticketu (podpowiada się po kilku znakach), czas, opis. Opis trafia jako komentarz do '
    'worklogu w Jirze i do wiadomości na Slacku.',
    'Tytuł ticketu wpisz razem z numerem, np. „ABC-123 Produkcja nie działa” – numer zostanie w polu ticketu, a tytuł '
    'przejdzie do linii pod nim. Tytuł trafia na Slacka, do listy w mailu i do eksportu CSV, a przy kolejnych dniach '
    'podpowiada się sam po wpisaniu numeru.',
    f'Kolumna {B("Od–do")} nie jest obowiązkowa. Wpisz w niej, o której zaczęło się zadanie, a potem czas – koniec '
    f'wyliczy się sam – albo godzinę zakończenia – wtedy wyliczy się czas. Godziny wpisujesz jako 9:30 albo 9.30. '
    f'Obiad i przerwy wpisane godzinami się nie liczą: 11:00–14:00 przy obiedzie 12:00–13:00 to 2h, a 11:00 + 2h '
    f'kończy się o 14:00.',
    f'{B("+ Dodaj wiersz")} albo Enter w ostatnim wierszu dodaje kolejny.',
    'Nic nie musisz klikać – zmiany zapisują się same po sekundzie (status przy przyciskach: „✓ zapisano 14:32”). '
    'Do Jiry nic jeszcze nie idzie.')
sub('Jak wpisywać czas')
table(['Wpisujesz', 'Oznacza'], [
    ['1h 30m · 1h30', '1 godzina 30 minut'], ['1.5 · 1,5h', 'półtorej godziny'], ['90m · 90 min', '90 minut'],
    ['1:30', '1 godzina 30 minut'], ['2h · 2 godz', '2 godziny'],
], [70, 100])
box('Uwaga', 'Sama liczba bez jednostki to godziny (2 = 2h), ale najwyżej 12. Przy większej, np. 20, pod wierszem '
           'pojawi się pytanie „minuty czy godziny?” – dopisz „m” albo „h” (20m, 20h).')

sub('Praca w przerwie obiadowej (awaria)')
steps(
    'Zaznacz pole pracy w przerwie obiadowej pod godzinami dnia.',
    f'W polu {B("Przerwa wzięta (łącznie)")} pojawi się 1h – popraw na tyle, ile przerwy faktycznie było. Na raty '
    f'możesz wpisać sam czas (20m + 40m) albo godziny od–do (13:30-13:50, 15:00-15:40); da się też łączyć oba '
    f'sposoby. Jeśli przerwy nie było wcale – wpisz 0.',
    'Wpisz tickety normalnie, także czas z 12:00–13:00, i zapisz.')
table(['Sytuacja (8:00–16:00, awaria w porze obiadu)', 'Wpisujesz w przerwie', 'Bilans'], [
    ['przerwa odebrana później w całości', '1h', '7h – w normie'],
    ['przerwa na raty', '20m + 40m', '7h – w normie'],
    ['na raty, z godzinami', '13:30-13:50, 15:00-15:40', '7h – w normie'],
    ['przerwy nie było wcale', '0', '8h – mail o nadgodzinach'],
], [75, 55, 40], key_column=False)
p('Tego dnia worklogi w Jirze mogą wypadać w godzinach 12:00–13:00, ale omijają przerwy wpisane godzinami. '
  'Przerwa wpisana godzinami po wyjściu z pracy nie jest odliczana.', small)
p(f'{B("Skopiuj tickety z poprzedniego dnia")} – link pod pustą tabelą przenosi same numery z ostatniego '
  f'uzupełnionego dnia; czas i opis wpisujesz od nowa.')

h('3. Stoper')
steps(
    'Wpisz numer ticketu w wierszu i kliknij ▶ obok. Stoper rusza; w wierszu widać licznik, a na górze każdej '
    'strony – pasek stopera.',
    'Przełączasz się na inne zadanie? Kliknij ▶ przy innym tickecie – poprzedni stoper zatrzyma się sam (działa '
    'zawsze najwyżej jeden).',
    f'Zadanie zaczęło się wcześniej, a ▶ nie zostało kliknięte od razu? Kliknij {B("od…")} pod ▶, wpisz godzinę '
    f'startu i {B("Włącz od tej godziny")}. Stoper liczy od tej godziny, a poprzedni zatrzymuje się właśnie o niej. '
    f'Godzina nie może być w przyszłości i musi być późniejsza niż start działającego stopera (ten zatrzyma się '
    f'właśnie o niej). Nie może też wypaść przed ostatnim zatrzymaniem stopera – czas zmierzony stoperem nigdy się '
    f'nie nakłada.',
    f'Koniec pracy nad zadaniem lub przerwa: ❚❚ w wierszu albo {B("❚❚ Zatrzymaj")} na pasku. Zmierzony czas '
    f'dopisuje się do pola Czas.')
table(['Jak liczy', 'Przykład'], [
    ['Pełne minuty, bez zaokrąglania do 5 czy 15 minut', '37 minut pracy → 37m'],
    ['Kilka podejść do tego samego ticketu się sumuje, sekundy nie giną', '12 i pół minuty + 17 i pół minuty → 30m'],
    ['Stoper dolicza do tego, co już jest w polu Czas', 'było 1h, stoper 15m → 1h 15m'],
], [95, 75], key_column=False)
bullets(
    'Godzina startu stopera jest zapisana w bazie – zamknięcie przeglądarki ani restart komputera go nie gubią.',
    f'Jeśli stoper działa od innego dnia (komputer uśpiony, zostawiony na noc) albo dłużej niż 6h, na górze strony '
    f'pojawi się pytanie „O której go zatrzymać?” z podpowiedzią końca dnia pracy. Wybierz godzinę i kliknij '
    f'{B("Zatrzymaj o tej godzinie")} – nie doliczy się czas, w którym nie było pracy. Godzina zatrzymania musi być '
    f'późniejsza niż start stopera. Jeśli praca faktycznie trwa '
    f'tyle bez przerwy: {B("Pracuję dalej")}.',
    'W czasie działania stopera pola „do” i Czas są tylko do odczytu (lekko wyszarzone, podpowiedź po najechaniu). '
    'Po zatrzymaniu możesz je poprawić ręcznie (np. gdy '
    'stoper nie został zatrzymany na przerwę).',
    'Wiersz bez czasu (np. założony tylko pod stoper) nie jest wysyłany do Jiry ani na Slacka.')

h('4. Wysłanie do Jiry')
p(f'Kliknij {G("Zapisz i wyślij do Jiry")}. Wszystkie zmiany dnia idą naraz: nowe wpisy są dodawane, poprawione '
  f'aktualizowane, a usunięte kasowane. Nad formularzem pojawi się wynik dla każdego wpisu.')
table(['Pasek przy wierszu', 'Znaczenie', 'Co zrobić'], [
    [f'{DOT("#2e7d4f")} zielony', 'Wpis jest w Jirze i się zgadza', 'Nic'],
    [f'{DOT("#a86a00")} żółty', 'Poprawiony po wysłaniu', 'Zapisz i wyślij do Jiry'],
    [f'{DOT("#a8a6a0")} szary', 'Jeszcze nie wysłany', 'Zapisz i wyślij do Jiry'],
    [f'{DOT("#b3261e")} czerwony', 'Jira zgłosiła błąd – treść pod wierszem i po najechaniu', 'Patrz rozdział 12'],
], [40, 80, 50])
p(f'Błędy z Jiry są zawsze na górze, przed udanymi wysyłkami. Dopóki jakiś wpis nie trafił do Jiry, nad formularzem '
  f'zostaje czerwony baner „Jira nie przyjęła N wpisów” z listą ticketów i dokładną treścią błędu. Po poprawieniu '
  f'wiersza (albo usunięciu przyczyny, np. tokenu) kliknij na banerze {G("Wyślij ponownie do Jiry")} – baner znika '
  f'dopiero po udanej wysyłce.')
p('Godziny rozpoczęcia worklogów w Jirze ustawiają się kolejno od początku dnia, według kolejności wierszy '
  '(np. 8:00–10:00, 10:00–12:00, 13:00–15:00…) i omijają przerwę 12:00–13:00. Jeśli wpis wypada w trakcie obiadu, '
  'kolejne startują po nim. Wpis z godziną w kolumnie Od–do startuje dokładnie o niej (także w porze obiadu), '
  'a pozostałe omijają jego czas. Godziny nie zależą od tego, kiedy klikniesz wysyłkę, więc możesz wysłać dzień także '
  'następnego dnia – trafi pod właściwą datę.')

h('5. Poprawianie i usuwanie wpisów')
table(['Chcesz…', 'Zrób tak', 'Co stanie się w Jirze'], [
    ['zmienić czas lub opis', 'Popraw w wierszu → Zapisz i wyślij do Jiry', 'Ten sam worklog zostanie zaktualizowany (bez duplikatu).'],
    ['zmienić numer ticketu', 'Popraw numer → Zapisz i wyślij do Jiry', 'Worklog zniknie ze starego ticketu i pojawi się w nowym.'],
    ['usunąć wpis', 'Zaznacz ✕ w wierszu → Zapisz i wyślij do Jiry → Usuń zaznaczone', 'Worklog zostanie usunięty z Jiry.'],
], [40, 65, 65])
bullets(
    f'Usunięcie zawsze wymaga potwierdzenia: po kliknięciu Zapisz przy zaznaczonym ✕ pod przyciskami pojawia się lista '
    f'wpisów do usunięcia. Usuwa je dopiero {B("Usuń zaznaczone")}; {B("Anuluj")} niczego nie zmienia. Autozapis nigdy nie usuwa.',
    'Samo Zapisz przy zaznaczonym ✕ chowa wpis z listy, ale w Jirze zostaje on do najbliższej wysyłki – pod tabelą '
    'widać ostrzeżenie „zostaną usunięte z Jiry”.',
    f'Narzędzie zmienia i usuwa tylko worklogi, które samo dodało. Wpisy dodane ręcznie w Jirze usuwasz w Jirze '
    f'(zakładka {B("Work log")} w tickecie).')

h('6. Podsumowanie na Slacka')
steps(
    f'Po zapisaniu dnia w panelu {B("Slack")} (po prawej) pojawia się gotowy tekst – jedna linia na ticket; ten sam '
    f'ticket wpisany kilka razy jest zsumowany.',
    f'Kliknij {G("Kopiuj na Slacka")} i wklej w Slacku (Ctrl+V).',
    'Status zmienia się na „✓ skopiowano” – widać to też w Przeglądzie.')
p(f'Wygląd wiadomości (nagłówek, linia na ticket, stopka) zmienisz w {B("Ustawienia → Slack")}.')

h('7. Mail o nadgodzinach lub krótszym dniu')
p('Panel maila pojawia się sam, gdy bilans dnia przekracza tolerancję. Bilans liczony jest z godzin obecności: '
  'od–do minus obiad (tylko ta część 12:00–13:00, która wypada w godzinach pracy) minus dodatkowa przerwa. Jeśli '
  'godzin nie podasz – z sumy czasu w ticketach. Norma to 7h. Jeśli pobierasz wpisy z Toggla, godziny od–do ustawia '
  'import – z pierwszego i ostatniego wpisu dnia (patrz rozdział 1), chyba że zmienisz je ręcznie. Bilans można też liczyć z sumy czasu w ticketach – patrz podrozdział niżej.')
table(['Godziny', 'Obecność', 'Bilans (norma 7h)'], [
    ['8:00–16:00', '7h (obiad odliczony)', '±0 – mail niepotrzebny'],
    ['8:00–17:00', '8h', '+1h – mail o nadgodzinach'],
    ['8:00–11:30', '3h 30m (obiadu nie było)', '−3h 30m – mail o krótszym dniu'],
    ['10:00–16:00', '5h', '−2h – mail o krótszym dniu'],
], [40, 65, 65])
steps(
    f'Sprawdź w panelu {B("Bilans")}, ile wyszło (np. +1h = nadgodziny).',
    'W panelu maila sprawdź adresatów, temat i treść.',
    f'Kliknij {G("Otwórz w programie pocztowym")} – Thunderbird (lub inny domyślny program) otworzy gotową '
    f'wiadomość. Wyślij ją jak zwykle.',
    f'Jeśli program pocztowy się nie otworzy: {B("Kopiuj treść")}, wklej do nowej wiadomości, a po wysłaniu kliknij '
    f'{B("Wysłany")} obok „do wysłania” – inaczej przypomnienie będzie o mailu wspominać.')
p('Na Windowsie długi mail (np. z długą listą ticketów) nie zmieści się w linku do programu pocztowego – otworzy się '
  'z adresatami i tematem, a treść aplikacja skopiuje do schowka: wklej ją Ctrl+V. Pod przyciskiem pojawi się '
  'podpowiedź. To samo dotyczy maila o planowanej zmianie.')
box('Wskazówka', 'Jeśli czas w ticketach różni się od czasu obecności (np. obecność 7h, a w ticketach jest 6h 30m), '
                 'panel Bilans pokaże żółte ostrzeżenie, a przypomnienie wspomni o brakującym czasie. Przy Togglu drobna różnica '
                 '(zaokrąglenia do pełnych minut, luki między zatrzymaniem a ponownym startem stopera) znika sama – import domyka czas '
                 'wierszy do obecności, o ile różnica mieści się w progu z Ustawienia → Toggl → „Domykanie do obecności (minuty)” (domyślnie 5 minut). '
                 'Większa różnica to przerwa bez stopera albo wpis bez numeru ticketu (np. projekt „Przerwa”), którego import pominął – zostaje widoczna.')

sub('Bilans z czasu w ticketach – tryb dla Toggla i stopera')
p('Używaj go, gdy cały czas mierzysz stoperem (Toggl albo ▶ w aplikacji) i suma wierszy jest Twoim prawdziwym czasem '
  'pracy – godziny od–do byłyby wtedy tylko przybliżeniem.')
steps(
    f'Otwórz {B("Ustawienia → Dzień pracy")}.',
    f'W polu {B("Bilans dnia liczony z")} wybierz {B("czasu w ticketach")}.',
    f'Kliknij {G("Zapisz ustawienia")}. Zmiana obowiązuje od razu na wszystkich stronach, także dla dni wstecz.')
table(['Element', 'Z czego liczony w trybie „czas w ticketach”'], [
    ['Bilans dnia i jego typ (nadgodziny, krócej, w normie)', 'suma wierszy'],
    ['Mail o nadgodzinach / krótszym dniu ({roznica})', 'suma wierszy; „w godzinach od–do” w treści nadal z godzin dnia'],
    ['Przegląd i Miesiąc (bilans, nadgodziny, „Czas pracy”)', 'suma wierszy'],
    ['Ostrzeżenie „W ticketach jest mniej niż czas obecności”', 'nie pojawia się'],
    ['Przypomnienie', 'nie wspomina o brakującym czasie'],
    ['Dzień z godzinami, ale bez wierszy', 'pusty dzień – bez maila o krótszym dniu'],
    ['Obecność w panelu Bilans', 'nadal pokazywana, tylko informacyjnie'],
    ['Godziny od–do', 'nadal służą do maila i do godzin worklogów w Jirze'],
], [85, 85])
table(['Godziny', 'W ticketach', 'Bilans – obecność', 'Bilans – tickety'], [
    ['8:00–16:00', '8h', '±0', '+1h'],
    ['7:02–16:03 (z Toggla)', '7h 30m', '+1h 1m', '+30m'],
    ['8:00–16:00', 'brak wierszy', '±0', 'dzień pusty'],
], [50, 40, 40, 40])
box('Wskazówka', 'Przy Togglu wpisy bez numeru ticketu (np. projekt „Przerwa”) nie wchodzą do sumy, więc w tym trybie '
                 'nie liczą się jako praca – tak ma być. Jeśli chcesz, żeby się liczyły, dopisz numer ticketu w opisie wpisu w Togglu.')

h('8. Planowane przyjście później / wyjście wcześniej')
steps(
    f'Wejdź w {B("Planowane zmiany")}.',
    f'Wybierz dzień i rodzaj: {B("Późniejsze przyjście")} (podaj godzinę przyjścia), {B("Wcześniejsze wyjście")} '
    f'(godzina wyjścia) lub {B("Krótszy dzień pracy")} (obie godziny).',
    f'Opcjonalnie wpisz powód i kliknij {G("Zapisz i przygotuj mail")}. Powód zostaje zapisany przy zmianie, ale do '
    f'domyślnego maila nie trafia – pojawi się w nim tylko, jeśli w szablonie w Ustawieniach wpiszesz {{powod}} '
    f'albo {{powod_linia}} (rozdział 11).',
    f'Na kolejnej stronie: {G("Otwórz w programie pocztowym")} → Wyślij.')
bullets(
    'Mail ma domyślnie tylko datę (w temacie i treści) – dopisz resztę w programie pocztowym albo ustaw własny '
    'szablon w Ustawieniach (np. z gotowym zdaniem {opis}).',
    'Jeśli zostało mniej czasu niż wymagane wyprzedzenie (domyślnie 24h), zobaczysz ostrzeżenie.',
    'Gdy otworzysz ten dzień w zakładce Dzień, godziny ze zgłoszenia podpowiedzą się same, a na górze będzie '
    'przypomnienie o zmianie.',
    'Dopóki mail nie jest wysłany, przypomnienie o nim wspomina, a dzisiejsza strona dnia pokazuje go na górze '
    '(rozdział 10).',
    f'Pomyłkę usuwasz linkiem {B("Usuń tę zmianę")} na stronie zgłoszenia (to nie wysyła żadnego maila).')

h('9. Przegląd i zaległe dni')
p(f'{B("Przegląd")} pokazuje ostatnie 4 tygodnie. Na górze są cztery liczby: czas pracy w bieżącym tygodniu wobec '
  'normy, bilans, dni robocze bez raportu i maile czekające na wysłanie. Pod nimi wykres: słupek to czas pracy danego '
  'dnia (pomarańczowy – nadgodziny, zakreskowany – brak raportu), przerywana linia to norma; najechanie pokazuje '
  'szczegóły, kliknięcie otwiera dzień. Niżej tabela (dni robocze i weekendy, w które coś wpisano):')
table(['Kolumna', 'Co znaczy'], [
    ['W ticketach / Bilans', 'Suma zalogowanego czasu i różnica względem normy.'],
    ['Jira', '✓ – wszystko wysłane; np. 2/4 – wysłano 2 z 4 wpisów (zaległość).'],
    ['Slack / Mail', '✓ – zrobione; „do wysłania” – jeszcze nie; „niepotrzebny” – bilans w normie.'],
], [45, 125])
p(f'Domyślnie najnowsze dni są na górze. Kliknięcie nagłówka {B("Dzień ↓")} odwraca kolejność (najstarsze na górze, '
  f'{B("Dzień ↑")}); aplikacja zapamiętuje wybór.')
p('Gdy w Ustawieniach bilans jest liczony z czasu w ticketach (rozdział 7), kolumna Bilans i liczby na górze biorą sumę wierszy, nie godziny od–do.')
p('Zaległy dzień: kliknij jego datę, uzupełnij i wyślij jak zwykle – trafi pod właściwą datę.')

sub('Podsumowanie miesiąca')
p(f'Zakładka {B("Miesiąc")} pokazuje wybrany miesiąc jako kalendarz: kolor dnia to bilans (cieplejszy – nadgodziny, '
  'chłodniejszy – krócej, im mocniejszy, tym większa różnica), zakreskowany – brak raportu. Kliknięcie dnia otwiera go. '
  f'Pod kalendarzem jest {B("Tabela dzień po dniu")} (rozwijana), a obok sumy i czas per ticket:')
bullets(
    f'{B("Bilans")} – nadgodziny minus „krócej”, liczone tylko z dni z raportem. Dni robocze bez raportu są wypisane '
    'osobno („Bez raportu”), bo nie wiadomo, ile wtedy pracowano.',
    f'{B("Czas pracy")} i {B("Norma za te dni")} – obecność (albo czas w ticketach, gdy nie ma godzin od–do) i norma tych samych dni.',
    'Dni robocze w miesiącu (bez świąt i urlopów), dni urlopu, L4 i świąt oraz czas per ticket.')
p(f'{G("CSV – dni")} zapisuje tabelę dzień po dniu z wierszem „Razem”, {G("CSV – wpisy")} – wszystkie wpisy (data, ticket, '
  'czas, opis, czy są w Jirze). Pliki otwierają się w Excelu bez ustawiania kodowania i separatora.')

sub('Urlopy, L4 i święta')
p(f'Święta ustawowe aplikacja zna sama (także ruchome, np. Boże Ciało, i Wigilię). Urlop, L4 albo inny dzień wolny '
  f'dodajesz w zakładce {B("Wolne")} (zakres od–do) albo jednym kliknięciem na stronie dnia: „Nie pracujesz tego dnia? '
  f'Oznacz go jako urlop · L4 · dzień wolny”.')
bullets(
    'W dzień wolny nie ma przypomnienia, a Przegląd pokazuje powód zamiast „brak raportu”.',
    'Praca w dzień wolny (weekend, święto, urlop) liczy się w całości jako nadgodziny – norma tego dnia wynosi 0.',
    'Za święto w sobotę pracodawca wyznacza inny dzień wolny – dodaj go jako „Dzień wolny”. Strona Wolne podpowiada, '
    'których świąt to dotyczy.',
    'Strona Wolne liczy dni robocze każdego wpisu i sumę w bieżącym roku – osobno urlop, L4 i dni wolne.')

h('10. Przypomnienie')
p(f'W dni robocze (bez świąt, urlopów i innych dni wolnych) o wybranej godzinie (domyślnie 15:45) pojawia się powiadomienie na pulpicie, jeśli w '
  f'dzisiejszym raporcie czegoś brakuje (wpisy, Jira, Slack, mail) albo czeka niewysłany mail o planowanej zmianie '
  f'(dziś lub później). Gdy wszystko jest zrobione – nic się nie '
  f'pokazuje. Jeśli komputer był wtedy wyłączony lub uśpiony, przypomnienie pojawi się po jego włączeniu '
  f'(tego samego dnia, do 22:00).')
p(f'Mail liczy się jako wysłany po kliknięciu {G("Otwórz w programie pocztowym")}. Jeśli poszedł inaczej '
  f'(np. przez {G("Kopiuj treść")}), kliknij {B("Wysłany")} obok „do wysłania” – inaczej przypomnienie będzie o nim wspominać. '
  'Niewysłane maile o kolejnych dniach widać też na górze dzisiejszej strony dnia.')
p('Przy bilansie liczonym z czasu w ticketach (rozdział 7) przypomnienie nie wspomina o brakującym czasie względem godzin od–do.')
p(f'Godzinę zmieniasz w {B("Ustawienia → Przypomnienie")} (między 06:00 a 22:00); puste pole wyłącza przypomnienie.')
cmd('./worklog remind                 # sprawdź teraz w terminalu\n'
    './install.sh --no-remind         # wyłączenie razem z timerem systemd')
p(f'Na Windowsie powiadomienie przychodzi od „Windows PowerShell” – kliknięcie otwiera raport. Wyłączenie: '
  f'{M(".\\install.cmd -NoRemind")}.', small)

h('11. Szablony maili i Slacka')
p(f'W {B("Ustawieniach")} każdy tekst możesz zmienić. W nawiasy klamrowe wpisujesz zmienne, które narzędzie podmieni:')
table(['Zmienna', 'Wstawia', 'Gdzie działa'], [
    ['{data} {dzien}', '13.10.2026, wtorek', 'wszędzie'],
    ['{od} {do} {przerwa}', 'godziny dnia i odliczona przerwa (obiad + dodatkowa)', 'wszędzie'],
    ['{obecnosc} {zalogowane} {norma}', 'czas obecności, czas w ticketach, norma', 'Slack, maile o dniu'],
    ['{roznica}', 'o ile dłużej / krócej, np. 1h 15m', 'maile o dniu'],
    ['{lista}', 'wszystkie tickety z czasem i opisem', 'maile o dniu'],
    ['{ticket} {czas} {opis} {tytul}', 'dane jednego ticketu', 'linia Slacka'],
    ['{typ} {opis} {powod} {powod_linia}', 'rodzaj zmiany, gotowe zdanie, powód', 'mail o planowanej zmianie'],
    ['{podpis}', 'podpis z ustawień', 'wszędzie'],
], [58, 72, 40], key_column=False)
p('Jeśli w gotowym tekście widzisz zmienną w nawiasach (np. {dzein}), to literówka w szablonie. Domyślne treści '
  'maili o dniu są w formie wybranej przy instalacji; po zmianie formy w Ustawieniach zmieniają się te treści, '
  'których nikt nie edytował.')

h('12. Gdy coś nie działa')
table(['Objaw / komunikat', 'Co zrobić'], [
    ['Strona http://127.0.0.1:8765 się nie otwiera', f'{M("./worklog status")} – jeśli kontener nie działa: '
     f'{M("./worklog up")}. Szczegóły: {M("./worklog logs")}.'],
    ['„Jira odrzuciła logowanie (401)”', 'Sprawdź token. Cloud: e-mail musi być wpisany. Server/DC: pole e-mail ma być puste.'],
    ['Bilans pokazuje ±0, choć w ticketach jest 8h', 'Bilans liczy się z godzin od–do (domyślnie). Popraw godziny albo przełącz bilans na czas w ticketach (rozdział 7).'],
    ['„Nie znaleziono (404)”', 'Literówka w numerze ticketu albo brak dostępu do projektu.'],
    ['„Brak uprawnień (403)”', 'Nie możesz logować czasu w tym projekcie – zapytaj jego administratora.'],
    ['„Toggl odrzucił token (401 / 403)”', 'Sprawdź token API Toggla w Ustawieniach (Toggl Track → Profile settings → API Token).'],
    ['„Brak połączenia z Jirą”', f'Sprawdź adres Jiry w ustawieniach i czy otwiera się w przeglądarce. Jeśli działa '
     f'tylko przez VPN: {M("./install.sh --vpn")}.'],
    ['„Popraw zaznaczone wiersze”', 'Wiersz z czerwoną ramką ma zły numer ticketu (np. ABC-123) albo czas.'],
    ['Program pocztowy się nie otwiera', 'Ustaw domyślny program pocztowy (Ubuntu: Ustawienia → Aplikacje domyślne; '
     'Windows: Ustawienia → Aplikacje → Aplikacje domyślne) albo użyj Kopiuj treść.'],
    ['Windows: mail otwiera się bez treści', 'Mail jest za długi na link – treść jest w schowku, wklej ją Ctrl+V '
     '(rozdział 7).'],
    ['Nie wiadomo, czy działa nowa wersja', f'Wersję (skrót commita i data) widać na dole każdej strony i w '
     f'{M("./worklog status")}. „+ zmiany lokalne” – w katalogu są niezacommitowane zmiany.'],
    ['Brak powiadomień', f'Sprawdź godzinę w Ustawieniach → Przypomnienie. Ubuntu: zainstaluj libnotify-bin; sprawdź '
     f'{M("systemctl --user status worklog-remind.timer")}. Windows: włącz powiadomienia dla „Windows PowerShell” '
     f'(Ustawienia → System → Powiadomienia); błędy agenta są w pliku .agent.log w katalogu kopii.'],
], [58, 112], key_column=False)

h('13. Bezpieczeństwo danych')
p('Żeby wpisy z dnia nie przepadły przed wysłaniem do Jiry, działa sześć zabezpieczeń:')
table(['Zabezpieczenie', 'Co daje'], [
    ['Autozapis', 'Każda zmiana trafia do bazy ok. 1 s po ostatnim wpisanym znaku oraz przy zamykaniu karty. Status '
     'widać przy przyciskach.'],
    ['Kopia w przeglądarce', 'Gdy zapis do bazy się nie uda, zmiany zostają w przeglądarce, a po ponownym otwarciu '
     'dnia pojawi się propozycja Przywróć.'],
    ['Stoper w bazie', 'Czas startu jest zapisany na dysku – przeżywa zamknięcie przeglądarki i restart komputera.'],
    ['Kopie co godzinę', 'W dni robocze 7:55–20:55 do katalogu ~/worklog-backups, z ostatnich 30 dni. Gdy kopia się '
     'nie uda – powiadomienie na pulpicie.'],
    ['Kopia przed zmianą bazy', 'Gdy nowa wersja zmienia tabele w bazie, kontener przy starcie najpierw robi kopię '
     '(10 ostatnich, w wolumenie w /data/kopie). Bez udanej kopii nie startuje i niczego nie zmienia.'],
    ['Odporny zapis bazy', 'Nagłe wyłączenie prądu nie psuje wcześniej zapisanych danych; „zapisano” oznacza, że dane '
     'są fizycznie na dysku.'],
], [45, 125])
sub('Status zapisu przy przyciskach')
table(['Widzisz', 'Znaczenie'], [
    ['✓ zapisano 14:32:05', 'Wszystko jest w bazie.'],
    ['zapisywanie…', 'Zmiana czeka na zapis (najwyżej sekunda).'],
    ['⚠ zapisano, oprócz zaznaczonych wierszy', 'Wiersz w czerwonej ramce ma błędny numer ticketu lub czas – reszta '
     'zapisana; popraw ten wiersz.'],
    ['⚠ nie udało się zapisać…', f'Baza chwilowo niedostępna (np. kontener zatrzymany). Zmiany są w przeglądarce, '
     f'ponowienie co 10 s. Sprawdź {M("./worklog status")}.'],
], [58, 112], key_column=False)
box('Warto wiedzieć',
    'Usunięcie wiersza (✕) nie dzieje się automatycznie – zatwierdzasz je przyciskiem Zapisz. Przypadkowe '
    'kliknięcie ✕ niczego nie skasuje.',
    f'Przywrócenie kopii: {M("./worklog restore ~/worklog-backups/worklog-RRRRMMDD-GGMM.db")} – przed nadpisaniem '
    f'narzędzie samo robi kopię obecnego stanu (jeśli ta się nie uda, nic nie zmienia). Plik, który nie jest kopią '
    f'raportu, zostanie odrzucony; starsza kopia działa od razu, bez restartu.',
    'Najlepiej, żeby katalog ~/worklog-backups był objęty Twoją zwykłą kopią zapasową (np. firmową) – wtedy '
    'przetrwa nawet awarię dysku. Kopie nie zawierają tokenów (Jiry i Toggla): po przywróceniu kopii na nowym komputerze '
    'wpisz je ponownie w Ustawieniach.')

h('14. Obsługa i kopia zapasowa')
table(['Polecenie (w katalogu narzędzia)', 'Działanie'], [
    [M('./worklog up'), 'zbudowanie i uruchomienie nowej wersji (np. po git pull); dane zostają'],
    [M('./worklog status'), 'czy aplikacja działa i jaka wersja (ta sama jest na dole każdej strony)'],
    [M('./worklog logs'), 'logi na żywo (Ctrl+C kończy)'],
    [M('./worklog restart'), 'ponowne uruchomienie'],
    [M('./worklog backup'), 'kopia bazy do ~/worklog-backup-DATA.db'],
    [M('./worklog restore PLIK.db'), 'przywrócenie kopii (pyta o potwierdzenie)'],
    [M('./worklog update'), 'aktualizacja do najnowszych poprawek PHP i Symfony'],
    [M('./worklog down'), 'zatrzymanie (dane zostają)'],
    [M('./worklog uninstall'), 'usunięcie wszystkiego – najpierw robi ostatnią kopię bazy'],
], [70, 100], key_column=False)
box('Prywatność',
    'Dane i token są tylko na Twoim komputerze. Aplikacja łączy się wyłącznie z Jirą (i z Toggl Track, jeśli wpiszesz jego token); Slacka i maili nie wysyła sama.',
    f'Usunięcie wszystkiego razem z danymi: {M("./worklog uninstall")} (przedtem robi ostatnią kopię bazy; kopie '
    f'zapasowe zostają) – potem unieważnij token w Jirze.')


# ---------------------------------------------------------------- strona

def decorate(canvas, doc):
    canvas.saveState()
    canvas.setFont('DV', 7.5)
    canvas.setFillColor(MUTED)
    if doc.page > 1:
        canvas.drawString(20 * mm, A4[1] - 12 * mm, 'Raport czasu – mini instrukcja')
    canvas.drawRightString(A4[0] - 20 * mm, 12 * mm, str(doc.page))
    canvas.restoreState()


doc = SimpleDocTemplate(str(OUT), pagesize=A4, leftMargin=20 * mm, rightMargin=20 * mm, topMargin=18 * mm,
                        bottomMargin=18 * mm, title='Raport czasu – mini instrukcja', author='Raport czasu',
                        subject='Instrukcja obsługi')
doc.build(story, onFirstPage=decorate, onLaterPages=decorate)
print(f'Zapisano {OUT}')
