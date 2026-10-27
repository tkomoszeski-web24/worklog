<?php

namespace App\Entity;

use App\Domain\TimeFormat;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\WorklogEntryRepository::class)]
#[ORM\Table(name: 'worklog_entry')]
class WorklogEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkDay::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WorkDay $day;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 50)]
    private string $issueKey;

    #[ORM\Column]
    private int $minutes;

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    /** Tytuł ticketu wpisany obok numeru („ABC-123 Produkcja nie działa”) – na Slacka, do maila i CSV. */
    #[ORM\Column(length: 255, options: ['default' => ''])]
    private string $issueTitle = '';

    /** Godzina rozpoczęcia podana ręcznie („GG:MM”); null = worklog dostaje kolejną wolną godzinę dnia. */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $startClock = null;

    /** Wpis usunięty lokalnie, który trzeba jeszcze skasować w Jirze. */
    #[ORM\Column]
    private bool $deleted = false;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $jiraWorklogId = null;

    /** Ticket, w którym worklog faktycznie istnieje w Jirze (może się różnić po edycji). */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $jiraIssueKey = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $jiraSyncHash = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $jiraSyncedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $jiraError = null;

    /** Kiedy uruchomiono stoper przy tym wpisie (null = nie działa). Zapisywane od razu w bazie. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $timerStartedAt = null;

    /** Kiedy ostatnio zatrzymano stoper przy tym wpisie – nowy stoper nie może zacząć się wcześniej (czas by się nakładał). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $timerStoppedAt = null;

    /**
     * Sekundy, które nie złożyły się w pełną minutę – kolejne sesje stopera je dopełniają. Ze stopera 0–59,
     * po imporcie z Toggla (zaokrąglenie do najbliższej minuty) −30…29, po domknięciu do obecności także dalej
     * (np. −59…+59): minutes*60 + carrySeconds to zawsze dokładny czas.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $carrySeconds = 0;

    /**
     * Identyfikatory wpisów Toggla, z których powstał ten wiersz (JSON) – klucz do ponownego pobrania tego samego dnia.
     * Wiersz wpisany ręcznie nie ma ich wcale.
     *
     * @var list<int>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $togglIds = null;

    public function __construct(WorkDay $day, string $issueKey, int $minutes, string $description, int $position)
    {
        $this->day = $day;
        $this->issueKey = $issueKey;
        $this->minutes = max(0, $minutes);
        $this->description = $description;
        $this->position = $position;
        $day->addEntry($this);
    }

    public function getTimerStartedAt(): ?\DateTimeImmutable
    {
        return $this->timerStartedAt;
    }

    public function getTimerStoppedAt(): ?\DateTimeImmutable
    {
        return $this->timerStoppedAt;
    }

    public function isTimerRunning(): bool
    {
        return null !== $this->timerStartedAt;
    }

    public function startTimer(\DateTimeImmutable $at): void
    {
        $this->timerStartedAt ??= $at;
    }

    /**
     * Zatrzymuje stoper i dolicza zmierzony czas w pełnych minutach (bez zaokrąglania).
     * Niepełna minuta zostaje w carrySeconds, więc np. 12m 30s + 17m 30s = 30m.
     *
     * @return int doliczone sekundy
     */
    public function stopTimer(\DateTimeImmutable $at): int
    {
        if (null === $this->timerStartedAt) {
            return 0;
        }
        $elapsed = max(0, $at->getTimestamp() - $this->timerStartedAt->getTimestamp());
        $this->timerStoppedAt = max($at, $this->timerStartedAt);
        $this->timerStartedAt = null;
        $this->addTrackedSeconds($elapsed);

        return $elapsed;
    }

    public function addTrackedSeconds(int $seconds): void
    {
        $total = $this->minutes * 60 + $this->carrySeconds + max(0, $seconds);
        $this->minutes = intdiv($total, 60);
        $this->carrySeconds = $total % 60;
    }

    /**
     * Ustawia czas z sekund (np. z Toggla): minuty zaokrąglone do najbliższej pełnej, a różnica do dokładnego
     * czasu w carrySeconds (−30…29, może być ujemna). Inaczej niż update() nie zeruje reszty,
     * więc minutes*60 + carrySeconds dalej równa się sekundom z Toggla, a stoper aplikacji dolicza do nich dokładnie.
     * Podane $minutes (domknięcie dnia do obecności) zastępuje zaokrąglenie; carry to wtedy $seconds − $minutes*60
     * i może wyjść poza −30…29.
     */
    public function setTrackedSeconds(int $seconds, ?int $minutes = null): void
    {
        $seconds = max(0, $seconds);
        $this->minutes = null !== $minutes ? max(0, $minutes) : TimeFormat::roundToMinutes($seconds);
        $this->carrySeconds = $seconds - $this->minutes * 60;
    }

    /** @return list<int> */
    public function getTogglIds(): array
    {
        return $this->togglIds ?? [];
    }

    /** @param list<int> $ids */
    public function setTogglIds(array $ids): void
    {
        $this->togglIds = [] === $ids ? null : array_values(array_map('intval', $ids));
    }

    public function getCarrySeconds(): int
    {
        return $this->carrySeconds;
    }

    /** Ile sekund ma wpis w tej chwili – razem z działającym stoperem. */
    public function liveSeconds(\DateTimeImmutable $now): int
    {
        $running = null !== $this->timerStartedAt ? max(0, $now->getTimestamp() - $this->timerStartedAt->getTimestamp()) : 0;

        return $this->minutes * 60 + $this->carrySeconds + $running;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDay(): WorkDay
    {
        return $this->day;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function update(string $issueKey, int $minutes, string $description, int $position): void
    {
        if ($minutes !== $this->minutes) {
            $this->carrySeconds = 0; // czas poprawiony ręcznie – to on jest teraz obowiązujący
        }
        $this->issueKey = $issueKey;
        $this->minutes = max(0, $minutes);
        $this->description = $description;
        $this->position = $position;
    }

    public function getIssueTitle(): string
    {
        return $this->issueTitle;
    }

    public function setIssueTitle(string $issueTitle): void
    {
        $this->issueTitle = mb_substr(trim($issueTitle), 0, 255);
    }

    public function getStartClock(): ?string
    {
        return $this->startClock;
    }

    public function setStartClock(?string $startClock): void
    {
        $this->startClock = $startClock;
    }

    public function getIssueKey(): string
    {
        return $this->issueKey;
    }

    public function getMinutes(): int
    {
        return $this->minutes;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function markDeleted(): void
    {
        $this->deleted = true;
    }

    public function getJiraWorklogId(): ?string
    {
        return $this->jiraWorklogId;
    }

    public function getJiraIssueKey(): ?string
    {
        return $this->jiraIssueKey;
    }

    public function getJiraSyncHash(): ?string
    {
        return $this->jiraSyncHash;
    }

    public function getJiraSyncedAt(): ?\DateTimeImmutable
    {
        return $this->jiraSyncedAt;
    }

    public function isInJira(): bool
    {
        return null !== $this->jiraWorklogId;
    }

    public function markSynced(string $worklogId, string $hash): void
    {
        $this->jiraWorklogId = $worklogId;
        $this->jiraIssueKey = $this->issueKey;
        $this->jiraSyncHash = $hash;
        $this->jiraSyncedAt = new \DateTimeImmutable();
        $this->jiraError = null;
    }

    public function markRemovedFromJira(): void
    {
        $this->jiraWorklogId = null;
        $this->jiraIssueKey = null;
        $this->jiraSyncHash = null;
        $this->jiraSyncedAt = null;
    }

    public function getJiraError(): ?string
    {
        return $this->jiraError;
    }

    public function setJiraError(?string $jiraError): void
    {
        $this->jiraError = $jiraError;
    }
}
