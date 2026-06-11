<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\MatchModel;
use App\Models\TeamModel;
use App\Models\TournamentModel;
use App\Support\Language;
use App\Support\Translator;

final class PublicViewController extends BaseController
{
    private const SCREEN_MAP = [
        'overview' => ['route' => 'overview', 'path' => '/overview', 'title' => 'Overview'],
        'next_matches' => ['route' => 'next', 'path' => '/next', 'title' => 'Current / Next Matches'],
        'standings' => ['route' => 'standings', 'path' => '/standings', 'title' => 'Group Standings'],
        'group_schedule' => ['route' => 'schedule', 'path' => '/schedule', 'title' => 'Group Stage Schedule'],
        'knockout' => ['route' => 'knockout', 'path' => '/knockout', 'title' => 'Knockout'],
        'recent_results' => ['route' => 'results', 'path' => '/results', 'title' => 'Recent Results'],
    ];

    public function overview(): void
    {
        $this->renderNamedScreen('overview');
    }

    public function nextMatches(): void
    {
        $this->renderNamedScreen('next_matches');
    }

    public function standings(): void
    {
        $this->renderNamedScreen('standings');
    }

    public function schedule(): void
    {
        $this->renderNamedScreen('group_schedule');
    }

    public function knockout(): void
    {
        $this->renderNamedScreen('knockout');
    }

    public function results(): void
    {
        $this->renderNamedScreen('recent_results');
    }

    public function display(): void
    {
        $context = $this->buildContext();
        if ($context === null) {
            return;
        }

        if (!(bool) ($context['tournament']['public_view_enabled'] ?? false)) {
            $this->renderPublicUnavailable((string) ($context['tournament']['name'] ?? 'Tournament'), $context['tournament']);
            return;
        }

        $enabledScreens = $this->enabledScreensOrdered($context['screens']);
        if (count($enabledScreens) === 0) {
            $this->renderPublic('public/no_screens', [
                'title' => 'Public Display',
                'tournament' => $context['tournament'],
                'screens' => $context['screens'],
            ]);
            return;
        }

        $index = $this->requestGetInt('i');
        if ($index < 0 || $index >= count($enabledScreens)) {
            $index = 0;
        }
        $screenKey = (string) ($enabledScreens[$index]['key'] ?? 'overview');
        $nextIndex = ($index + 1) % count($enabledScreens);
        $rotationSeconds = max(5, min(300, (int) ($context['tournament']['rotation_interval_seconds'] ?? 15)));
        $nextUrl = $this->url('/public/' . (string) $context['tournament']['slug'] . '/display?i=' . $nextIndex);
        $screenPayload = $this->screenPayload($context, $screenKey);
        $screenPayload['autoplay'] = (bool) ($context['tournament']['autoplay_enabled'] ?? false);
        $screenPayload['autoplay_seconds'] = $rotationSeconds;
        $screenPayload['autoplay_next_url'] = $nextUrl;

        $this->renderPublic('public/screen', $screenPayload);
    }

    private function renderNamedScreen(string $screenKey): void
    {
        $context = $this->buildContext();
        if ($context === null) {
            return;
        }

        if (!(bool) ($context['tournament']['public_view_enabled'] ?? false)) {
            $this->renderPublicUnavailable((string) ($context['tournament']['name'] ?? 'Tournament'), $context['tournament']);
            return;
        }

        $this->renderPublic('public/screen', $this->screenPayload($context, $screenKey));
    }

    /**
     * @return array{
     *     tournament: array<string, mixed>,
     *     groups: list<array<string, mixed>>,
     *     teams: list<array<string, mixed>>,
     *     screens: list<array{key: string, label: string, path: string, is_enabled: int, sort_order: int, url: string}>,
     *     match_model: MatchModel
     * }|null
     */
    private function buildContext(): ?array
    {
        $slug = $this->requestRouteString('slug');
        if ($slug === '') {
            http_response_code(404);
            echo '404 Not Found';
            return null;
        }

        $tournamentModel = new TournamentModel($this->db());
        $tournament = $tournamentModel->findBySlug($slug);
        if ($tournament === null) {
            http_response_code(404);
            echo '404 Not Found';
            return null;
        }

        $tournament['public_view_enabled'] = (int) ($tournament['public_view_enabled'] ?? 0) > 0;
        $tournament['autoplay_enabled'] = (int) ($tournament['autoplay_enabled'] ?? 1) > 0;
        $tournament['rotation_interval_seconds'] = (int) ($tournament['rotation_interval_seconds'] ?? 15);
        $publicViewTheme = (string) ($tournament['public_view_theme'] ?? 'dark');
        $tournament['public_view_theme'] = $publicViewTheme === 'light' ? 'light' : 'dark';
        $groups = $tournamentModel->groupsForTournament((int) $tournament['id']);
        $teamModel = new TeamModel($this->db());
        $teams = $teamModel->allByTournament((int) $tournament['id']);

        $storedScreens = [];
        foreach ($tournamentModel->publicScreensForTournament((int) $tournament['id']) as $row) {
            $screenKey = (string) ($row['screen_key'] ?? '');
            if ($screenKey !== '') {
                $storedScreens[$screenKey] = $row;
            }
        }

        $screens = [];
        foreach (self::SCREEN_MAP as $key => $meta) {
            $stored = $storedScreens[$key] ?? null;
            $sortOrder = is_array($stored) ? (int) ($stored['sort_order'] ?? 1) : count($screens) + 1;
            $isEnabled = is_array($stored) ? (int) ($stored['is_enabled'] ?? 0) : 1;
            $path = (string) ($meta['path'] ?? '/overview');
            $screens[] = [
                'key' => $key,
                'label' => (string) ($meta['title'] ?? $key),
                'path' => $path,
                'is_enabled' => $isEnabled > 0 ? 1 : 0,
                'sort_order' => max(1, min(99, $sortOrder)),
                'url' => $this->url('/public/' . $slug . $path),
            ];
        }

        return [
            'tournament' => $tournament,
            'groups' => $groups,
            'teams' => $teams,
            'screens' => $screens,
            'match_model' => new MatchModel($this->db()),
        ];
    }

    /**
     * @param array{
     *     tournament: array<string, mixed>,
     *     groups: list<array<string, mixed>>,
     *     teams: list<array<string, mixed>>,
     *     screens: list<array{key: string, label: string, path: string, is_enabled: int, sort_order: int, url: string}>,
     *     match_model: MatchModel
     * } $context
     * @return array<string, mixed>
     */
    private function screenPayload(array $context, string $screenKey): array
    {
        $matchModel = $context['match_model'];
        $tournament = $context['tournament'];
        $tournamentId = (int) ($tournament['id'] ?? 0);
        $screenMeta = self::SCREEN_MAP[$screenKey] ?? self::SCREEN_MAP['overview'];
        $title = $this->publicScreenTitle($screenKey, (string) ($screenMeta['title'] ?? 'Public'));
        $enabledScreens = $this->enabledScreensOrdered($context['screens']);
        $nowDateTime = new \DateTimeImmutable('now', $this->appTimezone());
        $now = $nowDateTime->format('Y-m-d H:i');
        $fullUrl = $this->absoluteCurrentUrl();
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=140x140&margin=1&data=' . rawurlencode($fullUrl);

        $payload = [
            'title' => (string) ($tournament['name'] ?? 'Tournament') . ' - ' . $title,
            'screenTitle' => $title,
            'screenKey' => $screenKey,
            'tournament' => $tournament,
            'screens' => $context['screens'],
            'enabledScreens' => $enabledScreens,
            'groups' => $context['groups'],
            'nowLabel' => $now,
            'nowIso' => $nowDateTime->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
            'qrUrl' => $qrUrl,
            'currentUrl' => $fullUrl,
            'autoplay' => false,
            'autoplay_seconds' => 0,
            'autoplay_next_url' => '',
        ];

        if ($screenKey === 'overview') {
            $payload['group_match_total'] = $matchModel->groupMatchCountForTournament($tournamentId);
            $payload['knockout_match_total'] = $matchModel->knockoutMatchCountForTournament($tournamentId);
            $payload['in_progress_matches'] = $matchModel->inProgressMatchesForTournament($tournamentId);
            $payload['finished_recent'] = $matchModel->recentFinishedMatchesForTournament($tournamentId, 5);
            $payload['overviewMapButtonUrl'] = trim((string) ($tournament['public_map_url'] ?? ''));
            $payload['overviewMapEmbedUrl'] = trim((string) ($tournament['public_map_embed_url'] ?? ''));
            return $payload;
        }

        if ($screenKey === 'next_matches') {
            $payload['in_progress_matches'] = $matchModel->inProgressMatchesForTournament($tournamentId);
            $payload['next_matches'] = $matchModel->nextScheduledMatchesForTournament($tournamentId, 20);
            return $payload;
        }

        if ($screenKey === 'standings') {
            $finishedGroupMatches = $matchModel->finishedGroupMatchesForTournament($tournamentId);
            $finishedMatchIds = array_values(array_filter(array_map(
                static fn (array $match): int => (int) ($match['id'] ?? 0),
                $finishedGroupMatches
            ), static fn (int $id): bool => $id > 0));
            $setsByMatchId = $matchModel->setsForMatches($finishedMatchIds);
            $payload['groupStandingsByGroup'] = $this->buildGroupStandings(
                $context['groups'],
                $context['teams'],
                $finishedGroupMatches,
                $setsByMatchId,
                (string) ($tournament['group_stage_mode'] ?? 'fixed_2_sets')
            );
            return $payload;
        }

        if ($screenKey === 'group_schedule') {
            $payload['groupMatches'] = $matchModel->groupMatchesForTournament($tournamentId);
            return $payload;
        }

        if ($screenKey === 'knockout') {
            $payload['knockoutMatches'] = $matchModel->knockoutMatchesForTournament($tournamentId);
            return $payload;
        }

        $payload['recentResults'] = $matchModel->recentFinishedMatchesForTournament($tournamentId, 25);
        return $payload;
    }

    private function publicScreenTitle(string $screenKey, string $fallback): string
    {
        return match ($screenKey) {
            'overview' => $this->translate('public.screen.overview'),
            'next_matches' => $this->translate('public.screen.next_matches'),
            'standings' => $this->translate('public.screen.standings'),
            'group_schedule' => $this->translate('public.screen.group_schedule'),
            'knockout' => $this->translate('public.screen.knockout'),
            'recent_results' => $this->translate('public.screen.recent_results'),
            default => $fallback,
        };
    }

    /**
     * @param list<array{key: string, label: string, path: string, is_enabled: int, sort_order: int, url: string}> $screens
     * @return list<array{key: string, label: string, path: string, is_enabled: int, sort_order: int, url: string}>
     */
    private function enabledScreensOrdered(array $screens): array
    {
        $enabled = array_values(array_filter(
            $screens,
            static fn (array $screen): bool => (int) ($screen['is_enabled'] ?? 0) > 0
        ));
        usort($enabled, static function (array $a, array $b): int {
            $orderCompare = (int) ($a['sort_order'] ?? 1) <=> (int) ($b['sort_order'] ?? 1);
            if ($orderCompare !== 0) {
                return $orderCompare;
            }
            return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        });

        return $enabled;
    }

    /**
     * @param array<string, mixed> $tournament
     */
    private function renderPublicUnavailable(string $tournamentName, array $tournament = []): void
    {
        $this->renderPublic('public/unavailable', [
            'title' => $tournamentName . ' - Public View Unavailable',
            'tournamentName' => $tournamentName,
            'tournament' => $tournament,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderPublic(string $view, array $data): void
    {
        $viewFile = __DIR__ . '/../Views/' . $view . '.php';
        if (!is_file($viewFile)) {
            throw new \RuntimeException(sprintf('View "%s" not found.', $view));
        }

        $config = $this->services['config'] ?? [];
        $currentLanguage = Language::resolve();
        $translator = new Translator($currentLanguage);
        $t = fn (string $key, array $params = []): string => $translator->translate($key, $params);
        $e = fn (string $key, array $params = []): string => htmlspecialchars($translator->translate($key, $params), ENT_QUOTES, 'UTF-8');
        $url = fn (string $path = '/'): string => $this->url($path);
        extract($data, EXTR_SKIP);
        require __DIR__ . '/../Views/public/layout.php';
    }

    private function absoluteCurrentUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        return $scheme . '://' . $host . $requestUri;
    }

    private function appTimezone(): \DateTimeZone
    {
        $config = $this->services['config'] ?? [];
        $configured = is_array($config) ? ($config['app']['timezone'] ?? null) : null;
        $timezone = is_string($configured) && trim($configured) !== '' ? trim($configured) : date_default_timezone_get();
        try {
            return new \DateTimeZone($timezone);
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }

    /**
     * @param list<array<string, mixed>> $groups
     * @param list<array<string, mixed>> $teams
     * @param list<array<string, mixed>> $finishedMatches
     * @param array<int, list<array{set_number: int, score_a: int, score_b: int}>> $setsByMatchId
     * @return array<int, list<array<string, int|string>>>
     */
    private function buildGroupStandings(
        array $groups,
        array $teams,
        array $finishedMatches,
        array $setsByMatchId,
        string $matchMode
    ): array {
        $groupIdsList = [];
        foreach ($groups as $group) {
            $groupId = (int) ($group['id'] ?? 0);
            if ($groupId > 0) {
                $groupIdsList[] = $groupId;
            }
        }

        $groupStandings = [];
        $headToHeadByGroup = [];
        foreach ($groupIdsList as $groupId) {
            $groupStandings[$groupId] = [];
            $headToHeadByGroup[$groupId] = [];
        }

        foreach ($teams as $team) {
            $teamId = (int) ($team['id'] ?? 0);
            $groupIdRaw = $team['group_id'] ?? null;
            $groupId = is_numeric($groupIdRaw) ? (int) $groupIdRaw : 0;
            if ($teamId <= 0 || $groupId <= 0 || !isset($groupStandings[$groupId])) {
                continue;
            }

            $groupStandings[$groupId][$teamId] = [
                'team_id' => $teamId,
                'team_name' => (string) ($team['team_name'] ?? ''),
                'played' => 0,
                'wins' => 0,
                'draws' => 0,
                'losses' => 0,
                'sets_for' => 0,
                'sets_against' => 0,
                'points_for' => 0,
                'points_against' => 0,
                'point_diff' => 0,
                'tournament_points' => 0,
            ];
        }

        foreach ($finishedMatches as $match) {
            $matchId = (int) ($match['id'] ?? 0);
            $groupId = (int) ($match['group_id'] ?? 0);
            $teamAId = (int) ($match['team_a_id'] ?? 0);
            $teamBId = (int) ($match['team_b_id'] ?? 0);
            if (
                $matchId <= 0
                || $groupId <= 0
                || $teamAId <= 0
                || $teamBId <= 0
                || !isset($groupStandings[$groupId][$teamAId])
                || !isset($groupStandings[$groupId][$teamBId])
            ) {
                continue;
            }

            $setsA = (int) ($match['sets_summary_a'] ?? 0);
            $setsB = (int) ($match['sets_summary_b'] ?? 0);
            $sets = $setsByMatchId[$matchId] ?? [];
            $pointsA = 0;
            $pointsB = 0;
            foreach ($sets as $set) {
                $pointsA += (int) ($set['score_a'] ?? 0);
                $pointsB += (int) ($set['score_b'] ?? 0);
            }

            $groupStandings[$groupId][$teamAId]['played']++;
            $groupStandings[$groupId][$teamBId]['played']++;
            $groupStandings[$groupId][$teamAId]['sets_for'] += $setsA;
            $groupStandings[$groupId][$teamAId]['sets_against'] += $setsB;
            $groupStandings[$groupId][$teamBId]['sets_for'] += $setsB;
            $groupStandings[$groupId][$teamBId]['sets_against'] += $setsA;
            $groupStandings[$groupId][$teamAId]['points_for'] += $pointsA;
            $groupStandings[$groupId][$teamAId]['points_against'] += $pointsB;
            $groupStandings[$groupId][$teamBId]['points_for'] += $pointsB;
            $groupStandings[$groupId][$teamBId]['points_against'] += $pointsA;

            $pairKey = $teamAId < $teamBId ? ($teamAId . ':' . $teamBId) : ($teamBId . ':' . $teamAId);
            $winnerTeamId = (int) ($match['winner_team_id'] ?? 0);
            if ($matchMode === 'fixed_2_sets' && $setsA === $setsB) {
                $groupStandings[$groupId][$teamAId]['draws']++;
                $groupStandings[$groupId][$teamBId]['draws']++;
                $groupStandings[$groupId][$teamAId]['tournament_points']++;
                $groupStandings[$groupId][$teamBId]['tournament_points']++;
                $headToHeadByGroup[$groupId][$pairKey] = 0;
                continue;
            }

            if ($winnerTeamId === $teamAId) {
                $groupStandings[$groupId][$teamAId]['wins']++;
                $groupStandings[$groupId][$teamBId]['losses']++;
                $groupStandings[$groupId][$teamAId]['tournament_points'] += 2;
                $headToHeadByGroup[$groupId][$pairKey] = $teamAId < $teamBId ? 1 : -1;
                continue;
            }
            if ($winnerTeamId === $teamBId) {
                $groupStandings[$groupId][$teamBId]['wins']++;
                $groupStandings[$groupId][$teamAId]['losses']++;
                $groupStandings[$groupId][$teamBId]['tournament_points'] += 2;
                $headToHeadByGroup[$groupId][$pairKey] = $teamAId < $teamBId ? -1 : 1;
            }
        }

        $sortedByGroup = [];
        foreach ($groupIdsList as $groupId) {
            $rows = array_values($groupStandings[$groupId] ?? []);
            foreach ($rows as &$row) {
                $row['point_diff'] = (int) $row['points_for'] - (int) $row['points_against'];
            }
            unset($row);

            $headToHead = $headToHeadByGroup[$groupId] ?? [];
            usort($rows, static function (array $a, array $b) use ($headToHead): int {
                $pointsCompare = (int) $b['tournament_points'] <=> (int) $a['tournament_points'];
                if ($pointsCompare !== 0) {
                    return $pointsCompare;
                }

                $idA = (int) $a['team_id'];
                $idB = (int) $b['team_id'];
                $pairKey = $idA < $idB ? ($idA . ':' . $idB) : ($idB . ':' . $idA);
                $headToHeadValue = $headToHead[$pairKey] ?? 0;
                if ($headToHeadValue !== 0) {
                    if ($idA < $idB) {
                        return $headToHeadValue === 1 ? -1 : 1;
                    }
                    return $headToHeadValue === -1 ? -1 : 1;
                }

                $pointDiffCompare = (int) $b['point_diff'] <=> (int) $a['point_diff'];
                if ($pointDiffCompare !== 0) {
                    return $pointDiffCompare;
                }
                $pointsForCompare = (int) $b['points_for'] <=> (int) $a['points_for'];
                if ($pointsForCompare !== 0) {
                    return $pointsForCompare;
                }

                return (int) $a['team_id'] <=> (int) $b['team_id'];
            });

            foreach ($rows as $index => &$row) {
                $row['position'] = $index + 1;
            }
            unset($row);
            $sortedByGroup[$groupId] = $rows;
        }

        return $sortedByGroup;
    }
}
