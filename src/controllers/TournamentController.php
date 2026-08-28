<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\MatchModel;
use App\Models\TeamModel;
use App\Models\TournamentModel;
use App\Support\GroupStageScheduler;
use App\Support\Language;
use App\Support\Translator;
use DateInterval;
use DateTimeImmutable;
use Throwable;

final class TournamentController extends BaseController
{
    private const MATCH_MODES = ['fixed_2_sets', 'best_of_3'];
    private const MAX_TOURNAMENT_NAME_LENGTH = 150;
    private const MAX_LOCATION_LENGTH = 150;
    private const MAX_TEAM_NAME_LENGTH = 150;
    private const MAX_TEAM_DESCRIPTION_BYTES = 1000;
    private const MAX_PUBLIC_TITLE_LENGTH = 200;
    private const MAX_PUBLIC_DESCRIPTION_BYTES = 65535;
    private const MAX_URL_LENGTH = 500;
    private const MAX_ADMIN_PASSWORD_BYTES = 72;
    private const MAX_LOGO_DIMENSION = 4096;
    private const MAX_LOGO_PIXELS = 16_000_000;
    private const MAX_GROUPS = 32;
    private const ADMIN_SECTIONS = ['tournament', 'groups', 'matches', 'knockout', 'public_view', 'teams', 'exports'];
    private const PUBLIC_SCREEN_DEFINITIONS = [
        'overview' => ['label' => 'Overview', 'path' => '/overview'],
        'next_matches' => ['label' => 'Current / Next Matches', 'path' => '/next'],
        'standings' => ['label' => 'Group Standings', 'path' => '/standings'],
        'group_schedule' => ['label' => 'Group Stage Schedule', 'path' => '/schedule'],
        'knockout' => ['label' => 'Knockout', 'path' => '/knockout'],
        'recent_results' => ['label' => 'Recent Results', 'path' => '/results'],
    ];
    private const PUBLIC_SCREEN_DEFAULT_ORDER = [
        'next_matches' => 1,
        'group_schedule' => 2,
        'standings' => 3,
        'knockout' => 4,
        'overview' => 5,
        'recent_results' => 6,
    ];

    public function detail(): void
    {
        $this->requireSuperadminAuth();

        $tournamentId = $this->requestGetInt('id');
        if ($tournamentId <= 0) {
            $this->setFlash('error', 'Invalid tournament selected.');
            $this->redirect('/admin/dashboard');
        }

        $tournamentModel = new TournamentModel($this->db());
        $tournament = $tournamentModel->findById($tournamentId);
        if ($tournament === null) {
            $this->setFlash('error', 'Tournament not found.');
            $this->redirect('/admin/dashboard');
        }

        $this->renderTournamentDetail($tournament, 'superadmin', 'tournament');
    }

    public function detailSection(): void
    {
        $this->requireSuperadminAuth();

        $section = $this->sectionFromRoute();
        if ($section === null) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '404 Not Found';
            return;
        }

        $tournamentId = $this->requestGetInt('id');
        if ($tournamentId <= 0) {
            $this->setFlash('error', 'Invalid tournament selected.');
            $this->redirect('/admin/dashboard');
        }

        $tournamentModel = new TournamentModel($this->db());
        $tournament = $tournamentModel->findById($tournamentId);
        if ($tournament === null) {
            $this->setFlash('error', 'Tournament not found.');
            $this->redirect('/admin/dashboard');
        }

        $this->renderTournamentDetail($tournament, 'superadmin', $section);
    }

    public function detailBySlug(): void
    {
        $tournament = $this->resolveTournamentBySlugWithAdminAccess();
        if ($tournament === null) {
            return;
        }

        $context = $this->currentSuperadmin() !== null ? 'superadmin' : 'tournament_admin';
        $this->renderTournamentDetail($tournament, $context, 'tournament');
    }

    public function detailBySlugSection(): void
    {
        $section = $this->sectionFromRoute();
        if ($section === null) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '404 Not Found';
            return;
        }

        $tournament = $this->resolveTournamentBySlugWithAdminAccess();
        if ($tournament === null) {
            return;
        }

        $context = $this->currentSuperadmin() !== null ? 'superadmin' : 'tournament_admin';
        $this->renderTournamentDetail($tournament, $context, $section);
    }

    public function update(): void
    {
        $this->requireSuperadminAuth();
        $tournament = $this->resolveTournamentByPostForSuperadmin();
        if ($tournament === null) {
            return;
        }

        $this->handleUpdate($tournament, $this->superadminSectionRedirectPath((int) $tournament['id']));
    }

    public function updateBySlug(): void
    {
        $tournament = $this->resolveTournamentBySlugWithAdminAccess();
        if ($tournament === null) {
            return;
        }

        $redirectSection = $this->sectionFromPost();
        $this->handleUpdate(
            $tournament,
            $this->tournamentAdminSectionRedirectPath((string) $tournament['slug'], $redirectSection),
            true,
            $redirectSection
        );
    }

    public function updatePublicView(): void
    {
        $this->requireSuperadminAuth();
        $tournament = $this->resolveTournamentByPostForSuperadmin();
        if ($tournament === null) {
            return;
        }

        $this->handleUpdatePublicView($tournament, $this->superadminSectionRedirectPath((int) $tournament['id'], 'public_view'));
    }

    public function updatePublicViewBySlug(): void
    {
        $tournament = $this->resolveTournamentBySlugWithAdminAccess();
        if ($tournament === null) {
            return;
        }

        $this->handleUpdatePublicView($tournament, $this->tournamentAdminSectionRedirectPath((string) $tournament['slug'], 'public_view'));
    }

    public function createTeam(): void
    {
        $this->requireSuperadminAuth();
        $tournament = $this->resolveTournamentByPostForSuperadmin();
        if ($tournament === null) {
            return;
        }

        $this->handleCreateTeam($tournament, $this->superadminSectionRedirectPath((int) $tournament['id']));
    }

    public function createTeamBySlug(): void
    {
        $tournament = $this->resolveTournamentBySlugWithAdminAccess();
        if ($tournament === null) {
            return;
        }

        $this->handleCreateTeam($tournament, $this->tournamentAdminSectionRedirectPath((string) $tournament['slug']));
    }

    public function updateTeam(): void
    {
        $this->requireSuperadminAuth();
        $tournament = $this->resolveTournamentByPostForSuperadmin();
        if ($tournament === null) {
            return;
        }

        $this->handleUpdateTeam($tournament, $this->superadminSectionRedirectPath((int) $tournament['id']));
    }

    public function updateTeamBySlug(): void
    {
        $tournament = $this->resolveTournamentBySlugWithAdminAccess();
        if ($tournament === null) {
            return;
        }

        $this->handleUpdateTeam($tournament, $this->tournamentAdminSectionRedirectPath((string) $tournament['slug']));
    }

    public function deleteTeam(): void
    {
        $this->requireSuperadminAuth();
        $tournament = $this->resolveTournamentByPostForSuperadmin();
        if ($tournament === null) {
            return;
        }

        $this->handleDeleteTeam($tournament, $this->superadminSectionRedirectPath((int) $tournament['id']));
    }

    public function deleteTeamBySlug(): void
    {
        $tournament = $this->resolveTournamentBySlugWithAdminAccess();
        if ($tournament === null) {
            return;
        }

        $this->handleDeleteTeam($tournament, $this->tournamentAdminSectionRedirectPath((string) $tournament['slug']));
    }

    public function assignTeamGroup(): void
    {
        $this->requireSuperadminAuth();
        $tournament = $this->resolveTournamentByPostForSuperadmin();
        if ($tournament === null) {
            return;
        }

        $this->handleAssignTeamGroup($tournament, $this->superadminSectionRedirectPath((int) $tournament['id']));
    }

    public function assignTeamGroupBySlug(): void
    {
        $tournament = $this->resolveTournamentBySlugWithAdminAccess();
        if ($tournament === null) {
            return;
        }

        $this->handleAssignTeamGroup($tournament, $this->tournamentAdminSectionRedirectPath((string) $tournament['slug']));
    }

    public function autoAssignTeams(): void
    {
        $this->requireSuperadminAuth();
        $tournament = $this->resolveTournamentByPostForSuperadmin();
        if ($tournament === null) {
            return;
        }

        $this->handleAutoAssignTeams($tournament, $this->superadminSectionRedirectPath((int) $tournament['id']));
    }

    public function autoAssignTeamsBySlug(): void
    {
        $tournament = $this->resolveTournamentBySlugWithAdminAccess();
        if ($tournament === null) {
            return;
        }

        $this->handleAutoAssignTeams($tournament, $this->tournamentAdminSectionRedirectPath((string) $tournament['slug']));
    }

    public function generateGroupMatches(): void
    {
        $this->requireSuperadminAuth();
        $tournament = $this->resolveTournamentByPostForSuperadmin();
        if ($tournament === null) {
            return;
        }

        $this->handleGenerateGroupMatches($tournament, $this->superadminSectionRedirectPath((int) $tournament['id']));
    }

    public function generateGroupMatchesBySlug(): void
    {
        $tournament = $this->resolveTournamentBySlugWithAdminAccess();
        if ($tournament === null) {
            return;
        }

        $this->handleGenerateGroupMatches($tournament, $this->tournamentAdminSectionRedirectPath((string) $tournament['slug']));
    }

    public function generateKnockoutMatches(): void
    {
        $this->requireSuperadminAuth();
        $tournament = $this->resolveTournamentByPostForSuperadmin();
        if ($tournament === null) {
            return;
        }

        $this->handleGenerateKnockoutMatches($tournament, $this->superadminSectionRedirectPath((int) $tournament['id'], 'knockout'));
    }

    public function generateKnockoutMatchesBySlug(): void
    {
        $tournament = $this->resolveTournamentBySlugWithAdminAccess();
        if ($tournament === null) {
            return;
        }

        $this->handleGenerateKnockoutMatches($tournament, $this->tournamentAdminSectionRedirectPath((string) $tournament['slug'], 'knockout'));
    }

    public function groupMatchDetail(): void
    {
        $this->requireSuperadminAuth();

        $tournament = $this->resolveTournamentByQueryForSuperadmin();
        if ($tournament === null) {
            return;
        }

        $matchId = $this->matchIdFromRoute();
        if ($matchId <= 0) {
            $this->setFlash('error', 'Invalid match selected.');
            $this->redirect($this->superadminSectionRedirectPath((int) $tournament['id'], 'matches'));
        }

        $filters = $this->filtersFromGet();
        $this->renderGroupMatchDetail($tournament, $matchId, false, $filters);
    }

    public function groupMatchDetailBySlug(): void
    {
        $tournament = $this->resolveTournamentBySlugWithAdminAccess();
        if ($tournament === null) {
            return;
        }

        $matchId = $this->matchIdFromRoute();
        if ($matchId <= 0) {
            $this->setFlash('error', 'Invalid match selected.');
            $this->redirect($this->tournamentAdminSectionRedirectPath((string) $tournament['slug'], 'matches'));
        }

        $filters = $this->filtersFromGet();
        $this->renderGroupMatchDetail($tournament, $matchId, true, $filters);
    }

    public function knockoutMatchDetail(): void
    {
        $this->requireSuperadminAuth();

        $tournament = $this->resolveTournamentByQueryForSuperadmin();
        if ($tournament === null) {
            return;
        }

        $matchId = $this->matchIdFromRoute();
        if ($matchId <= 0) {
            $this->setFlash('error', 'Invalid match selected.');
            $this->redirect($this->superadminSectionRedirectPath((int) $tournament['id'], 'knockout'));
        }

        $this->renderKnockoutMatchDetail($tournament, $matchId, false);
    }

    public function knockoutMatchDetailBySlug(): void
    {
        $tournament = $this->resolveTournamentBySlugWithAdminAccess();
        if ($tournament === null) {
            return;
        }

        $matchId = $this->matchIdFromRoute();
        if ($matchId <= 0) {
            $this->setFlash('error', 'Invalid match selected.');
            $this->redirect($this->tournamentAdminSectionRedirectPath((string) $tournament['slug'], 'knockout'));
        }

        $this->renderKnockoutMatchDetail($tournament, $matchId, true);
    }

    public function startGroupMatch(): void
    {
        $this->requireSuperadminAuth();

        $tournament = $this->resolveTournamentByPostForSuperadmin();
        if ($tournament === null) {
            return;
        }

        $matchId = $this->matchIdFromRoute();
        $filters = $this->filtersFromPost();
        $redirectPath = $this->shouldReturnToMatches()
            ? '/admin/tournament/matches' . $this->querySuffix(array_merge(['id' => (int) $tournament['id']], $filters))
            : $this->superadminMatchDetailPath((int) $tournament['id'], $matchId, $filters);
        if ($matchId <= 0) {
            $this->setFlash('error', 'Invalid match selected.');
            $this->redirect('/admin/tournament/matches' . $this->querySuffix(array_merge(['id' => (int) $tournament['id']], $filters)));
        }

        $this->handleStartGroupMatch($tournament, $matchId, $redirectPath);
    }

    public function startGroupMatchBySlug(): void
    {
        $tournament = $this->resolveTournamentBySlugWithAdminAccess();
        if ($tournament === null) {
            return;
        }

        $matchId = $this->matchIdFromRoute();
        $filters = $this->filtersFromPost();
        $redirectPath = $this->shouldReturnToMatches()
            ? $this->tournamentAdminSectionRedirectPath((string) $tournament['slug'], 'matches') . $this->querySuffix($filters)
            : $this->tournamentAdminMatchDetailPath((string) $tournament['slug'], $matchId, $filters);
        if ($matchId <= 0) {
            $this->setFlash('error', 'Invalid match selected.');
            $this->redirect($this->tournamentAdminSectionRedirectPath((string) $tournament['slug'], 'matches') . $this->querySuffix($filters));
        }

        $this->handleStartGroupMatch($tournament, $matchId, $redirectPath);
    }

    public function saveGroupMatchScore(): void
    {
        $this->requireSuperadminAuth();

        $tournament = $this->resolveTournamentByPostForSuperadmin();
        if ($tournament === null) {
            return;
        }

        $matchId = $this->matchIdFromRoute();
        $filters = $this->filtersFromPost();
        $redirectPath = $this->superadminMatchDetailPath((int) $tournament['id'], $matchId, $filters);
        $successRedirectPath = '/admin/tournament/matches' . $this->querySuffix(array_merge(['id' => (int) $tournament['id']], $filters));
        if ($matchId <= 0) {
            $this->setFlash('error', 'Invalid match selected.');
            $this->redirect($this->superadminSectionRedirectPath((int) $tournament['id'], 'matches'));
        }

        $this->handleSaveGroupMatchScore($tournament, $matchId, $redirectPath, $successRedirectPath);
    }

    public function saveGroupMatchScoreBySlug(): void
    {
        $tournament = $this->resolveTournamentBySlugWithAdminAccess();
        if ($tournament === null) {
            return;
        }

        $matchId = $this->matchIdFromRoute();
        $filters = $this->filtersFromPost();
        $redirectPath = $this->tournamentAdminMatchDetailPath((string) $tournament['slug'], $matchId, $filters);
        $successRedirectPath = $this->tournamentAdminSectionRedirectPath((string) $tournament['slug'], 'matches') . $this->querySuffix($filters);
        if ($matchId <= 0) {
            $this->setFlash('error', 'Invalid match selected.');
            $this->redirect($this->tournamentAdminSectionRedirectPath((string) $tournament['slug'], 'matches'));
        }

        $this->handleSaveGroupMatchScore($tournament, $matchId, $redirectPath, $successRedirectPath);
    }

    public function resetGroupMatchResult(): void
    {
        $this->requireSuperadminAuth();

        $tournament = $this->resolveTournamentByPostForSuperadmin();
        if ($tournament === null) {
            return;
        }

        $matchId = $this->matchIdFromRoute();
        $filters = $this->filtersFromPost();
        $redirectPath = $this->superadminMatchDetailPath((int) $tournament['id'], $matchId, $filters);
        if ($matchId <= 0) {
            $this->setFlash('error', 'Invalid match selected.');
            $this->redirect('/admin/tournament/matches' . $this->querySuffix(array_merge(['id' => (int) $tournament['id']], $filters)));
        }

        $this->handleResetGroupMatchResult($tournament, $matchId, $redirectPath);
    }

    public function resetGroupMatchResultBySlug(): void
    {
        $tournament = $this->resolveTournamentBySlugWithAdminAccess();
        if ($tournament === null) {
            return;
        }

        $matchId = $this->matchIdFromRoute();
        $filters = $this->filtersFromPost();
        $redirectPath = $this->tournamentAdminMatchDetailPath((string) $tournament['slug'], $matchId, $filters);
        if ($matchId <= 0) {
            $this->setFlash('error', 'Invalid match selected.');
            $this->redirect($this->tournamentAdminSectionRedirectPath((string) $tournament['slug'], 'matches') . $this->querySuffix($filters));
        }

        $this->handleResetGroupMatchResult($tournament, $matchId, $redirectPath);
    }

    public function saveKnockoutMatchScore(): void
    {
        $this->requireSuperadminAuth();

        $tournament = $this->resolveTournamentByPostForSuperadmin();
        if ($tournament === null) {
            return;
        }

        $matchId = $this->matchIdFromRoute();
        if ($matchId <= 0) {
            $this->setFlash('error', 'Invalid match selected.');
            $this->redirect($this->superadminSectionRedirectPath((int) $tournament['id'], 'knockout'));
        }

        $redirectPath = $this->superadminKnockoutMatchDetailPath((int) $tournament['id'], $matchId);
        $successRedirectPath = $this->superadminSectionRedirectPath((int) $tournament['id'], 'knockout');
        $this->handleSaveKnockoutMatchScore($tournament, $matchId, $redirectPath, $successRedirectPath);
    }

    public function saveKnockoutMatchScoreBySlug(): void
    {
        $tournament = $this->resolveTournamentBySlugWithAdminAccess();
        if ($tournament === null) {
            return;
        }

        $matchId = $this->matchIdFromRoute();
        if ($matchId <= 0) {
            $this->setFlash('error', 'Invalid match selected.');
            $this->redirect($this->tournamentAdminSectionRedirectPath((string) $tournament['slug'], 'knockout'));
        }

        $redirectPath = $this->tournamentAdminKnockoutMatchDetailPath((string) $tournament['slug'], $matchId);
        $successRedirectPath = $this->tournamentAdminSectionRedirectPath((string) $tournament['slug'], 'knockout');
        $this->handleSaveKnockoutMatchScore($tournament, $matchId, $redirectPath, $successRedirectPath);
    }

    public function printSchedule(): void
    {
        $this->renderPrintForSuperadmin('schedule');
    }

    public function printScheduleByCourt(): void
    {
        $this->renderPrintForSuperadmin('schedule_by_court');
    }

    public function printScheduleByGroup(): void
    {
        $this->renderPrintForSuperadmin('schedule_by_group');
    }

    public function printGroupMatrix(): void
    {
        $this->renderPrintForSuperadmin('group_matrix');
    }

    public function printKnockout(): void
    {
        $this->renderPrintForSuperadmin('knockout');
    }

    public function printScheduleBySlug(): void
    {
        $this->renderPrintForSlug('schedule');
    }

    public function printScheduleByCourtBySlug(): void
    {
        $this->renderPrintForSlug('schedule_by_court');
    }

    public function printScheduleByGroupBySlug(): void
    {
        $this->renderPrintForSlug('schedule_by_group');
    }

    public function printGroupMatrixBySlug(): void
    {
        $this->renderPrintForSlug('group_matrix');
    }

    public function printKnockoutBySlug(): void
    {
        $this->renderPrintForSlug('knockout');
    }

    private function renderPrintForSuperadmin(string $printType): void
    {
        $this->requireSuperadminAuth();
        $tournament = $this->resolveTournamentByQueryForSuperadmin();
        if ($tournament === null) {
            return;
        }

        $this->renderTournamentPrint($tournament, false, $printType);
    }

    private function renderPrintForSlug(string $printType): void
    {
        $tournament = $this->resolveTournamentBySlugWithAdminAccess();
        if ($tournament === null) {
            return;
        }

        $this->renderTournamentPrint($tournament, true, $printType);
    }

    /**
     * @param array<string, mixed> $tournament
     */
    private function renderTournamentPrint(array $tournament, bool $isSlugContext, string $printType): void
    {
        $allowedPrintTypes = ['schedule', 'schedule_by_court', 'schedule_by_group', 'group_matrix', 'knockout'];
        if (!in_array($printType, $allowedPrintTypes, true)) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '404 Not Found';
            return;
        }

        $tournamentId = (int) ($tournament['id'] ?? 0);
        $tournamentSlug = (string) ($tournament['slug'] ?? '');
        $prefill = (string) ($_GET['prefill'] ?? '0') === '1';

        $tournamentModel = new TournamentModel($this->db());
        $teamModel = new TeamModel($this->db());
        $matchModel = new MatchModel($this->db());

        $groups = $tournamentModel->groupsForTournament($tournamentId);
        $teams = $teamModel->allByTournament($tournamentId);
        $groupAssignment = $this->buildGroupAssignmentViewData($groups, $teams);
        $groupMatches = $this->sortGroupMatchesForPrint($matchModel->groupMatchesForTournament($tournamentId));
        $knockoutMatches = $matchModel->knockoutMatchesForTournament($tournamentId);
        $allMatches = $this->buildPrintMatchList($groupMatches, $knockoutMatches);
        $finishedMatchIds = array_values(array_filter(
            array_map(
                static fn (array $match): int => (string) ($match['status'] ?? '') === 'finished' ? (int) ($match['id'] ?? 0) : 0,
                $allMatches
            ),
            static fn (int $id): bool => $id > 0
        ));
        $setsByMatchId = $matchModel->setsForMatches($finishedMatchIds);
        $finishedGroupMatches = $matchModel->finishedGroupMatchesForTournament($tournamentId);
        $groupStandingsByGroup = $prefill
            ? $this->buildGroupStandings(
                $groups,
                $teams,
                $finishedGroupMatches,
                $setsByMatchId,
                (string) ($tournament['group_stage_mode'] ?? ($tournament['match_mode'] ?? ''))
            )
            : [];
        $knockoutPrint = $this->buildKnockoutPrintData($knockoutMatches);

        $baseAdminPath = $isSlugContext ? '/tournament/' . $tournamentSlug . '/admin' : '/admin/tournament';
        $idSuffix = $isSlugContext ? '' : '?id=' . $tournamentId;
        $exportsUrl = $this->url($baseAdminPath . '/exports' . $idSuffix);
        $printPaths = [
            'schedule' => $baseAdminPath . '/print/schedule',
            'schedule_by_court' => $baseAdminPath . '/print/schedule-by-court',
            'schedule_by_group' => $baseAdminPath . '/print/schedule-by-group',
            'group_matrix' => $baseAdminPath . '/print/group-matrix',
            'knockout' => $baseAdminPath . '/print/knockout',
        ];
        $printUrls = [];
        foreach ($printPaths as $key => $path) {
            $params = ['prefill' => $prefill ? 1 : 0];
            if (!$isSlugContext) {
                $params = ['id' => $tournamentId] + $params;
            }
            $printUrls[$key] = $this->url($path . '?' . http_build_query($params));
        }

        $toggleParams = ['prefill' => $prefill ? 0 : 1];
        if (!$isSlugContext) {
            $toggleParams = ['id' => $tournamentId] + $toggleParams;
        }
        $prefillToggleUrl = $this->url($printPaths[$printType] . '?' . http_build_query($toggleParams));

        $outputMeta = $this->printOutputMeta($printType);
        $publicUrl = $this->absoluteUrl('/public/' . $tournamentSlug . '/overview');
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=96x96&margin=1&data=' . rawurlencode($publicUrl);
        $printedAt = (new DateTimeImmutable('now', $this->appTimezone()))->format('Y-m-d H:i');
        $pageOrientation = $printType === 'group_matrix' || $printType === 'knockout' ? 'landscape' : 'portrait';

        $this->renderPrintView('admin/print/' . $printType, [
            'title' => (string) ($tournament['name'] ?? 'Tournament') . ' - ' . $outputMeta['title'],
            'printOutputTitle' => $outputMeta['title'],
            'pageOrientation' => $pageOrientation,
            'tournament' => $tournament,
            'groups' => $groups,
            'teams' => $teams,
            'groupAssignment' => $groupAssignment,
            'groupMatches' => $groupMatches,
            'knockoutMatches' => $knockoutMatches,
            'allMatches' => $allMatches,
            'setsByMatchId' => $setsByMatchId,
            'groupStandingsByGroup' => $groupStandingsByGroup,
            'knockoutPrint' => $knockoutPrint,
            'prefill' => $prefill,
            'exportsUrl' => $exportsUrl,
            'printUrls' => $printUrls,
            'prefillToggleUrl' => $prefillToggleUrl,
            'publicUrl' => $publicUrl,
            'qrUrl' => $qrUrl,
            'printedAt' => $printedAt,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $groupMatches
     * @param list<array<string, mixed>> $knockoutMatches
     * @return list<array<string, mixed>>
     */
    private function buildPrintMatchList(array $groupMatches, array $knockoutMatches): array
    {
        $matches = [];
        foreach ($groupMatches as $match) {
            $match['stage'] = 'group';
            $match['stage_label'] = 'Group Stage';
            $match['context_label'] = 'Group ' . (string) ($match['group_name'] ?? '-');
            $matches[] = $match;
        }
        foreach ($knockoutMatches as $match) {
            $roundName = trim((string) ($match['round_name'] ?? ''));
            $match['stage'] = 'knockout';
            $match['stage_label'] = 'Knockout';
            $match['context_label'] = $roundName !== '' ? $roundName : 'Round';
            $match['schedule_order'] = (int) ($match['bracket_position'] ?? 0);
            $matches[] = $match;
        }

        usort($matches, static function (array $a, array $b): int {
            $timeA = trim((string) ($a['planned_start'] ?? ''));
            $timeB = trim((string) ($b['planned_start'] ?? ''));
            $timeCompare = ($timeA === '' ? '9999-12-31 23:59:59' : $timeA) <=> ($timeB === '' ? '9999-12-31 23:59:59' : $timeB);
            if ($timeCompare !== 0) {
                return $timeCompare;
            }

            $courtCompare = (int) ($a['court_number'] ?? 0) <=> (int) ($b['court_number'] ?? 0);
            if ($courtCompare !== 0) {
                return $courtCompare;
            }

            $orderCompare = (int) ($a['schedule_order'] ?? 0) <=> (int) ($b['schedule_order'] ?? 0);
            if ($orderCompare !== 0) {
                return $orderCompare;
            }

            return strcmp((string) ($a['context_label'] ?? ''), (string) ($b['context_label'] ?? ''));
        });

        return $matches;
    }

    /**
     * @param list<array<string, mixed>> $groupMatches
     * @return list<array<string, mixed>>
     */
    private function sortGroupMatchesForPrint(array $groupMatches): array
    {
        usort($groupMatches, static function (array $a, array $b): int {
            $timeA = trim((string) ($a['planned_start'] ?? ''));
            $timeB = trim((string) ($b['planned_start'] ?? ''));
            $timeCompare = ($timeA === '' ? '9999-12-31 23:59:59' : $timeA) <=> ($timeB === '' ? '9999-12-31 23:59:59' : $timeB);
            if ($timeCompare !== 0) {
                return $timeCompare;
            }

            $courtCompare = (int) ($a['court_number'] ?? 0) <=> (int) ($b['court_number'] ?? 0);
            if ($courtCompare !== 0) {
                return $courtCompare;
            }

            return (int) ($a['schedule_order'] ?? 0) <=> (int) ($b['schedule_order'] ?? 0);
        });

        return $groupMatches;
    }

    /**
     * @param list<array<string, mixed>> $knockoutMatches
     * @return array{
     *     rounds: array<string, list<array<string, mixed>>>,
     *     source_labels: array<string, string>,
     *     final: array<string, mixed>|null,
     *     left_rounds: list<array{name: string, matches: list<array<string, mixed>>}>,
     *     right_rounds: list<array{name: string, matches: list<array<string, mixed>>}>,
     *     semifinal_labels: list<string>,
     *     note: string
     * }
     */
    private function buildKnockoutPrintData(array $knockoutMatches): array
    {
        $rounds = [];
        $sourceLabels = [];
        $roundIndex = 0;
        $currentRoundName = '';
        $matchNumberInRound = 0;

        foreach ($knockoutMatches as $match) {
            $roundName = trim((string) ($match['round_name'] ?? ''));
            if ($roundName === '') {
                $roundName = 'Round';
            }
            if ($roundName !== $currentRoundName) {
                $currentRoundName = $roundName;
                $roundIndex++;
                $matchNumberInRound = 0;
            }

            $matchNumberInRound++;
            $humanRoundName = $this->humanKnockoutRoundLabel($roundName);
            $match['print_round_name'] = $humanRoundName;
            $match['print_match_label'] = strcasecmp($humanRoundName, 'Final') === 0
                ? 'Final'
                : $humanRoundName . ' ' . $matchNumberInRound;
            $sourceLabels['winner:r' . $roundIndex . ':m' . $matchNumberInRound] = (string) $match['print_match_label'];

            if (!isset($rounds[$humanRoundName])) {
                $rounds[$humanRoundName] = [];
            }
            $rounds[$humanRoundName][] = $match;
        }

        foreach ($rounds as &$roundMatches) {
            usort(
                $roundMatches,
                static function (array $a, array $b): int {
                    $positionCompare = (int) ($a['bracket_position'] ?? 0) <=> (int) ($b['bracket_position'] ?? 0);
                    if ($positionCompare !== 0) {
                        return $positionCompare;
                    }

                    return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
                }
            );
        }
        unset($roundMatches);

        $final = null;
        foreach ($rounds['Final'] ?? [] as $match) {
            $final = $match;
            break;
        }

        $leftRounds = [];
        $rightRounds = [];
        $semifinalLabels = [];
        foreach ($rounds as $roundName => $roundMatches) {
            if (strcasecmp($roundName, 'Final') === 0) {
                continue;
            }

            if (strcasecmp($roundName, 'Semifinal') === 0) {
                foreach ($roundMatches as $index => $match) {
                    $semifinalLabels[] = (string) ($match['print_match_label'] ?? ('Semifinal ' . ($index + 1)));
                }
            }

            $half = (int) ceil(count($roundMatches) / 2);
            $leftRounds[] = [
                'name' => $roundName,
                'matches' => array_slice($roundMatches, 0, $half),
            ];
            $rightRounds[] = [
                'name' => $roundName,
                'matches' => array_slice($roundMatches, $half),
            ];
        }

        $matchCount = count($knockoutMatches);
        $note = $matchCount > 15 ? 'This bracket has more than 16 knockout teams. A4 may be tight; larger paper is recommended.' : '';

        return [
            'rounds' => $rounds,
            'source_labels' => $sourceLabels,
            'final' => $final,
            'left_rounds' => $leftRounds,
            'right_rounds' => array_reverse($rightRounds),
            'semifinal_labels' => $semifinalLabels,
            'note' => $note,
        ];
    }

    private function humanKnockoutRoundLabel(string $roundName): string
    {
        $normalized = strtolower(trim($roundName));
        return match ($normalized) {
            'play-in', 'play in', 'playin' => 'Play in',
            'round of 16' => 'Round of 16',
            'quarterfinal', 'quarterfinals' => 'Quarterfinal',
            'semifinal', 'semifinals' => 'Semifinal',
            'final' => 'Final',
            default => $roundName !== '' ? $roundName : 'Round',
        };
    }

    /**
     * @return array{title: string}
     */
    private function printOutputMeta(string $printType): array
    {
        return match ($printType) {
            'schedule_by_court' => ['title' => $this->translate('print.schedule_by_court')],
            'schedule_by_group' => ['title' => $this->translate('print.schedule_by_group')],
            'group_matrix' => ['title' => $this->translate('print.group_round_robin_matrix')],
            'knockout' => ['title' => $this->translate('exports_print.knockout_bracket')],
            default => ['title' => $this->translate('exports_print.full_match_schedule')],
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderPrintView(string $view, array $data): void
    {
        $printViewFile = __DIR__ . '/../views/' . $view . '.php';
        if (!is_file($printViewFile)) {
            throw new \RuntimeException(sprintf('View "%s" not found.', $view));
        }

        $config = $this->services['config'] ?? [];
        $currentLanguage = Language::resolve();
        $translator = new Translator($currentLanguage);
        $t = fn (string $key, array $params = []): string => $translator->translate($key, $params);
        $e = fn (string $key, array $params = []): string => htmlspecialchars($translator->translate($key, $params), ENT_QUOTES, 'UTF-8');
        $url = fn (string $path = '/'): string => $this->url($path);
        extract($data, EXTR_SKIP);
        require __DIR__ . '/../views/admin/print/layout.php';
    }

    private function absoluteUrl(string $path): string
    {
        return $this->canonicalOrigin() . $this->url($path);
    }

    private function appTimezone(): \DateTimeZone
    {
        $config = $this->services['config'] ?? [];
        $configured = is_array($config) ? ($config['app']['timezone'] ?? null) : null;
        $timezone = is_string($configured) && trim($configured) !== '' ? trim($configured) : date_default_timezone_get();
        try {
            return new \DateTimeZone($timezone);
        } catch (Throwable) {
            return new \DateTimeZone('UTC');
        }
    }

    /**
     * @param array<string, mixed> $tournament
     */
    private function renderTournamentDetail(array $tournament, string $context, string $section): void
    {
        $tournamentId = (int) ($tournament['id'] ?? 0);
        $tournamentSlug = (string) ($tournament['slug'] ?? '');
        $tournamentModel = new TournamentModel($this->db());
        $teamModel = new TeamModel($this->db());
        $matchModel = new MatchModel($this->db());

        $groups = $tournamentModel->groupsForTournament($tournamentId);
        $teams = $teamModel->allByTournament($tournamentId);
        $groupAssignment = $this->buildGroupAssignmentViewData($groups, $teams);
        $groupMatches = $matchModel->groupMatchesForTournament($tournamentId);
        $knockoutMatches = $matchModel->knockoutMatchesForTournament($tournamentId);
        $finishedGroupMatches = $matchModel->finishedGroupMatchesForTournament($tournamentId);
        $finishedMatchIds = array_values(array_filter(
            array_map(
                static fn (array $match): int => (int) ($match['id'] ?? 0),
                $finishedGroupMatches
            ),
            static fn (int $id): bool => $id > 0
        ));
        $setsByMatchId = $matchModel->setsForMatches($finishedMatchIds);
        $groupStandingsByGroup = $this->buildGroupStandings(
            $groups,
            $teams,
            $finishedGroupMatches,
            $setsByMatchId,
            (string) ($tournament['group_stage_mode'] ?? ($tournament['match_mode'] ?? ''))
        );
        $hasGroupMatches = count($groupMatches) > 0;
        $hasKnockoutMatches = count($knockoutMatches) > 0;
        $groupMatchesInProgressCount = count(array_filter(
            $groupMatches,
            static fn (array $match): bool => (string) ($match['status'] ?? '') === 'in_progress'
        ));
        $knockoutMatchesFinishedCount = count(array_filter(
            $knockoutMatches,
            static fn (array $match): bool => (string) ($match['status'] ?? '') === 'finished'
        ));
        $knockoutMatchesInProgressCount = count(array_filter(
            $knockoutMatches,
            static fn (array $match): bool => (string) ($match['status'] ?? '') === 'in_progress'
        ));

        $isSlugContext = $context === 'tournament_admin';
        $section = $this->normalizeSection($section);
        $baseAdminPath = $isSlugContext
            ? '/tournament/' . $tournamentSlug . '/admin'
            : '/admin/tournament';

        $sectionNav = [
            'tournament' => $this->url($baseAdminPath . ($isSlugContext ? '' : '?id=' . $tournamentId)),
            'groups' => $this->url($baseAdminPath . '/groups' . ($isSlugContext ? '' : '?id=' . $tournamentId)),
            'matches' => $this->url($baseAdminPath . '/matches' . ($isSlugContext ? '' : '?id=' . $tournamentId)),
            'knockout' => $this->url($baseAdminPath . '/knockout' . ($isSlugContext ? '' : '?id=' . $tournamentId)),
            'public_view' => $this->url($baseAdminPath . '/public_view' . ($isSlugContext ? '' : '?id=' . $tournamentId)),
            'teams' => $this->url($baseAdminPath . '/teams' . ($isSlugContext ? '' : '?id=' . $tournamentId)),
            'exports' => $this->url($baseAdminPath . '/exports' . ($isSlugContext ? '' : '?id=' . $tournamentId)),
        ];
        $printRouteParams = $isSlugContext ? ['prefill' => 0] : ['id' => $tournamentId, 'prefill' => 0];
        $printUrls = [
            'schedule' => $this->url($baseAdminPath . '/print/schedule?' . http_build_query($printRouteParams)),
            'schedule_by_court' => $this->url($baseAdminPath . '/print/schedule-by-court?' . http_build_query($printRouteParams)),
            'schedule_by_group' => $this->url($baseAdminPath . '/print/schedule-by-group?' . http_build_query($printRouteParams)),
            'group_matrix' => $this->url($baseAdminPath . '/print/group-matrix?' . http_build_query($printRouteParams)),
            'knockout' => $this->url($baseAdminPath . '/print/knockout?' . http_build_query($printRouteParams)),
        ];
        $publicScreens = $this->buildPublicScreenSettingsViewData($tournamentModel, $tournamentId, $tournamentSlug);

        $groupFilterOptions = [];
        foreach ($groups as $group) {
            $groupId = (int) ($group['id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }

            $groupFilterOptions[$groupId] = (string) ($group['name'] ?? '');
        }

        $courtFilterSet = [];
        $configuredCourtCount = (int) ($tournament['number_of_courts'] ?? 0);
        for ($court = 1; $court <= $configuredCourtCount; $court++) {
            $courtFilterSet[$court] = true;
        }
        foreach ($groupMatches as $match) {
            $courtNumber = (int) ($match['court_number'] ?? 0);
            if ($courtNumber > 0) {
                $courtFilterSet[$courtNumber] = true;
            }
        }
        ksort($courtFilterSet);
        $courtFilterOptions = array_keys($courtFilterSet);

        $selectedGroupFilter = $this->requestGetInt('group_id');
        if ($selectedGroupFilter > 0 && !isset($groupFilterOptions[$selectedGroupFilter])) {
            $selectedGroupFilter = 0;
        }

        $selectedCourtFilter = $this->requestGetInt('court');
        if ($selectedCourtFilter > 0 && !isset($courtFilterSet[$selectedCourtFilter])) {
            $selectedCourtFilter = 0;
        }

        $filteredGroupMatches = array_values(array_filter(
            $groupMatches,
            static function (array $match) use ($selectedGroupFilter, $selectedCourtFilter): bool {
                $matchGroupId = (int) ($match['group_id'] ?? 0);
                $matchCourt = (int) ($match['court_number'] ?? 0);
                if ($selectedGroupFilter > 0 && $matchGroupId !== $selectedGroupFilter) {
                    return false;
                }
                if ($selectedCourtFilter > 0 && $matchCourt !== $selectedCourtFilter) {
                    return false;
                }

                return true;
            }
        ));

        $activeFilters = [];
        if ($selectedGroupFilter > 0) {
            $activeFilters['group_id'] = $selectedGroupFilter;
        }
        if ($selectedCourtFilter > 0) {
            $activeFilters['court'] = $selectedCourtFilter;
        }
        foreach ($filteredGroupMatches as &$match) {
            $matchId = (int) ($match['id'] ?? 0);
            if ($matchId <= 0) {
                continue;
            }

            $path = $isSlugContext
                ? $this->tournamentAdminMatchDetailPath($tournamentSlug, $matchId, $activeFilters)
                : $this->superadminMatchDetailPath($tournamentId, $matchId, $activeFilters);
            $match['detail_url'] = $this->url($path);
            $startPath = $isSlugContext
                ? '/tournament/' . $tournamentSlug . '/admin/matches/' . $matchId . '/start'
                : '/admin/tournament/matches/' . $matchId . '/start';
            $match['start_action_url'] = $this->url($startPath);
        }
        unset($match);

        foreach ($knockoutMatches as &$knockoutMatch) {
            $matchId = (int) ($knockoutMatch['id'] ?? 0);
            if ($matchId <= 0) {
                continue;
            }

            $path = $isSlugContext
                ? $this->tournamentAdminKnockoutMatchDetailPath($tournamentSlug, $matchId)
                : $this->superadminKnockoutMatchDetailPath($tournamentId, $matchId);
            $knockoutMatch['detail_url'] = $this->url($path);
        }
        unset($knockoutMatch);

        $this->render('admin/tournament_detail', [
            'title' => (string) ($tournament['name'] ?? 'Tournament'),
            'tournament' => $tournament,
            'groups' => $groups,
            'teams' => $teams,
            'groupAssignment' => $groupAssignment,
            'groupStandingsByGroup' => $groupStandingsByGroup,
            'groupMatches' => $filteredGroupMatches,
            'groupMatchesTotalCount' => count($groupMatches),
            'groupMatchesFinishedCount' => count($finishedGroupMatches),
            'groupMatchesInProgressCount' => $groupMatchesInProgressCount,
            'hasGroupMatches' => $hasGroupMatches,
            'hasAnyMatches' => $hasGroupMatches || $hasKnockoutMatches,
            'knockoutMatches' => $knockoutMatches,
            'knockoutMatchesFinishedCount' => $knockoutMatchesFinishedCount,
            'knockoutMatchesInProgressCount' => $knockoutMatchesInProgressCount,
            'hasKnockoutMatches' => $hasKnockoutMatches,
            'matchModes' => self::MATCH_MODES,
            'activeSection' => $section,
            'sectionNav' => $sectionNav,
            'printUrls' => $printUrls,
            'matchesFilterActionUrl' => $sectionNav['matches'],
            'groupFilterOptions' => $groupFilterOptions,
            'courtFilterOptions' => $courtFilterOptions,
            'selectedGroupFilter' => $selectedGroupFilter,
            'selectedCourtFilter' => $selectedCourtFilter,
            'publicScreenSettings' => $publicScreens,
            'backUrl' => $isSlugContext ? null : $this->url('/admin/dashboard'),
            'backLabel' => 'Back to dashboard',
            'settingsActionUrl' => $isSlugContext
                ? $this->url('/tournament/' . $tournamentSlug . '/admin/update')
                : $this->url('/admin/tournament/update'),
            'createTeamActionUrl' => $isSlugContext
                ? $this->url('/tournament/' . $tournamentSlug . '/admin/teams/create')
                : $this->url('/admin/tournament/teams/create'),
            'updateTeamActionUrl' => $isSlugContext
                ? $this->url('/tournament/' . $tournamentSlug . '/admin/teams/update')
                : $this->url('/admin/tournament/teams/update'),
            'deleteTeamActionUrl' => $isSlugContext
                ? $this->url('/tournament/' . $tournamentSlug . '/admin/teams/delete')
                : $this->url('/admin/tournament/teams/delete'),
            'assignTeamActionUrl' => $isSlugContext
                ? $this->url('/tournament/' . $tournamentSlug . '/admin/teams/assign')
                : $this->url('/admin/tournament/teams/assign'),
            'autoAssignTeamsActionUrl' => $isSlugContext
                ? $this->url('/tournament/' . $tournamentSlug . '/admin/teams/assign-auto')
                : $this->url('/admin/tournament/teams/assign-auto'),
            'generateGroupMatchesActionUrl' => $isSlugContext
                ? $this->url('/tournament/' . $tournamentSlug . '/admin/matches/generate')
                : $this->url('/admin/tournament/matches/generate'),
            'generateKnockoutMatchesActionUrl' => $isSlugContext
                ? $this->url('/tournament/' . $tournamentSlug . '/admin/knockout/generate')
                : $this->url('/admin/tournament/knockout/generate'),
            'publicViewSettingsActionUrl' => $isSlugContext
                ? $this->url('/tournament/' . $tournamentSlug . '/admin/public-view/update')
                : $this->url('/admin/tournament/public-view/update'),
            'publicDisplayUrl' => $this->url('/public/' . $tournamentSlug . '/display'),
        ]);
    }

    /**
     * @param array<string, mixed> $tournament
     * @param array<string, int> $filters
     */
    private function renderGroupMatchDetail(array $tournament, int $matchId, bool $isSlugContext, array $filters): void
    {
        $tournamentId = (int) ($tournament['id'] ?? 0);
        $tournamentSlug = (string) ($tournament['slug'] ?? '');

        $matchModel = new MatchModel($this->db());
        $match = $matchModel->findGroupMatchDetailForTournament($tournamentId, $matchId);
        if ($match === null) {
            $this->setFlash('error', 'Group-stage match not found.');
            if ($isSlugContext) {
                $this->redirect($this->tournamentAdminSectionRedirectPath($tournamentSlug, 'matches') . $this->querySuffix($filters));
            }

            $this->redirect('/admin/tournament/matches' . $this->querySuffix(array_merge(['id' => $tournamentId], $filters)));
        }

        $matchSets = $matchModel->setsForMatch($matchId);
        $requiresKnockoutResetConfirmation = $matchModel->knockoutMatchCountForTournament($tournamentId) > 0;
        $backToMatchesPath = $isSlugContext
            ? $this->tournamentAdminSectionRedirectPath($tournamentSlug, 'matches')
            : $this->superadminSectionRedirectPath($tournamentId, 'matches');
        $startActionPath = $isSlugContext
            ? '/tournament/' . $tournamentSlug . '/admin/matches/' . $matchId . '/start'
            : '/admin/tournament/matches/' . $matchId . '/start';
        $scoreActionPath = $isSlugContext
            ? '/tournament/' . $tournamentSlug . '/admin/matches/' . $matchId . '/score'
            : '/admin/tournament/matches/' . $matchId . '/score';
        $resetActionPath = $isSlugContext
            ? '/tournament/' . $tournamentSlug . '/admin/matches/' . $matchId . '/reset'
            : '/admin/tournament/matches/' . $matchId . '/reset';

        $this->render('admin/match_detail', [
            'title' => 'Match detail',
            'tournament' => $tournament,
            'match' => $match,
            'matchSets' => $matchSets,
            'filters' => $filters,
            'backToMatchesUrl' => $isSlugContext
                ? $this->url($backToMatchesPath . $this->querySuffix($filters))
                : $this->url('/admin/tournament/matches' . $this->querySuffix(array_merge(['id' => $tournamentId], $filters))),
            'startActionUrl' => $this->url($startActionPath),
            'scoreActionUrl' => $this->url($scoreActionPath),
            'resetActionUrl' => $this->url($resetActionPath),
            'isSlugContext' => $isSlugContext,
            'matchStage' => 'group',
            'requiresDependentResetConfirmation' => false,
            'requiresKnockoutResetConfirmation' => $requiresKnockoutResetConfirmation,
        ]);
    }

    /**
     * @param array<string, mixed> $tournament
     */
    private function renderKnockoutMatchDetail(array $tournament, int $matchId, bool $isSlugContext): void
    {
        $tournamentId = (int) ($tournament['id'] ?? 0);
        $tournamentSlug = (string) ($tournament['slug'] ?? '');

        $matchModel = new MatchModel($this->db());
        $match = $matchModel->findKnockoutMatchDetailForTournament($tournamentId, $matchId);
        if ($match === null) {
            $this->setFlash('error', 'Knockout match not found.');
            if ($isSlugContext) {
                $this->redirect($this->tournamentAdminSectionRedirectPath($tournamentSlug, 'knockout'));
            }

            $this->redirect($this->superadminSectionRedirectPath($tournamentId, 'knockout'));
        }

        $knockoutMatches = $matchModel->knockoutMatchesForTournament($tournamentId);
        $graph = $this->buildKnockoutSourceGraph($knockoutMatches);
        $descendantIds = $this->descendantMatchIdsForSource((int) $match['id'], $graph['source_by_match_id'], $graph['children_by_source']);
        $requiresDependentResetConfirmation = false;
        foreach ($knockoutMatches as $knockoutMatch) {
            $candidateId = (int) ($knockoutMatch['id'] ?? 0);
            if (!isset($descendantIds[$candidateId])) {
                continue;
            }

            $status = (string) ($knockoutMatch['status'] ?? '');
            $winnerTeamId = (int) ($knockoutMatch['winner_team_id'] ?? 0);
            $setsSummaryA = (int) ($knockoutMatch['sets_summary_a'] ?? 0);
            $setsSummaryB = (int) ($knockoutMatch['sets_summary_b'] ?? 0);
            if (in_array($status, ['in_progress', 'finished'], true) || $winnerTeamId > 0 || $setsSummaryA > 0 || $setsSummaryB > 0) {
                $requiresDependentResetConfirmation = true;
                break;
            }
        }

        $matchSets = $matchModel->setsForMatch($matchId);
        $backToKnockoutPath = $isSlugContext
            ? $this->tournamentAdminSectionRedirectPath($tournamentSlug, 'knockout')
            : $this->superadminSectionRedirectPath($tournamentId, 'knockout');
        $scoreActionPath = $isSlugContext
            ? '/tournament/' . $tournamentSlug . '/admin/knockout/' . $matchId . '/score'
            : '/admin/tournament/knockout/' . $matchId . '/score';

        $this->render('admin/match_detail', [
            'title' => 'Knockout match detail',
            'tournament' => $tournament,
            'match' => $match,
            'matchSets' => $matchSets,
            'filters' => [],
            'backToMatchesUrl' => $this->url($backToKnockoutPath),
            'scoreActionUrl' => $this->url($scoreActionPath),
            'startActionUrl' => null,
            'resetActionUrl' => null,
            'isSlugContext' => $isSlugContext,
            'matchStage' => 'knockout',
            'requiresDependentResetConfirmation' => $requiresDependentResetConfirmation,
            'requiresKnockoutResetConfirmation' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $tournament
     */
    private function handleStartGroupMatch(array $tournament, int $matchId, string $redirectPath): void
    {
        $tournamentId = (int) ($tournament['id'] ?? 0);
        $matchModel = new MatchModel($this->db());
        $match = $matchModel->findGroupMatchDetailForTournament($tournamentId, $matchId);
        if ($match === null) {
            $this->setFlash('error', 'Group-stage match not found.');
            $this->redirect($redirectPath);
        }

        if ((string) ($match['status'] ?? '') !== 'scheduled') {
            $this->setFlash('error', 'Only scheduled matches can be started.');
            $this->redirect($redirectPath);
        }

        $expectedLockVersion = $this->requestPostStrictInt('lock_version', 0, PHP_INT_MAX);
        if ($expectedLockVersion === null) {
            $this->setFlash('error', 'Invalid or stale match version.');
            $this->redirect($redirectPath);
        }

        try {
            $started = $matchModel->markGroupMatchInProgress($tournamentId, $matchId, $expectedLockVersion);
        } catch (Throwable) {
            $this->setFlash('error', 'Match could not be started.');
            $this->redirect($redirectPath);
        }
        if (!$started) {
            $this->setFlash('error', 'Match could not be started.');
            $this->redirect($redirectPath);
        }

        $this->setFlash('success', 'Match status changed to in progress.');
        $this->redirect($redirectPath);
    }

    /**
     * @param array<string, mixed> $tournament
     */
    private function handleSaveGroupMatchScore(
        array $tournament,
        int $matchId,
        string $redirectPath,
        string $successRedirectPath
    ): void
    {
        $tournamentId = (int) ($tournament['id'] ?? 0);
        $matchMode = (string) ($tournament['group_stage_mode'] ?? ($tournament['match_mode'] ?? ''));
        $matchModel = new MatchModel($this->db());
        $match = $matchModel->findGroupMatchDetailForTournament($tournamentId, $matchId);
        if ($match === null) {
            $this->setFlash('error', 'Group-stage match not found.');
            $this->redirect($redirectPath);
        }

        $status = (string) ($match['status'] ?? '');
        if (!in_array($status, ['scheduled', 'in_progress', 'finished'], true)) {
            $this->setFlash('error', 'Only scheduled, in-progress, or finished matches can save score.');
            $this->redirect($redirectPath);
        }

        $validated = $this->validateScoreInput(
            $matchMode,
            (int) ($match['team_a_id'] ?? 0),
            (int) ($match['team_b_id'] ?? 0),
            false
        );
        if ($validated === null) {
            $this->redirect($redirectPath);
        }

        $expectedLockVersion = $this->requestPostStrictInt('lock_version', 0, PHP_INT_MAX);
        if ($expectedLockVersion === null) {
            $this->setFlash('error', 'Invalid or stale match version.');
            $this->redirect($redirectPath);
        }

        try {
            $writeResult = $matchModel->saveGroupMatchResult(
                $tournamentId,
                $matchId,
                $expectedLockVersion,
                (int) ($match['team_a_id'] ?? 0),
                (int) ($match['team_b_id'] ?? 0),
                $validated['sets'],
                $validated['sets_summary_a'],
                $validated['sets_summary_b'],
                $validated['winner_team_id'],
                $this->requestPostString('confirm_reset_knockout') === '1'
            );
        } catch (Throwable) {
            $this->setFlash('error', 'Match result could not be saved.');
            $this->redirect($redirectPath);
        }
        if ($writeResult === MatchModel::WRITE_REQUIRES_KNOCKOUT_RESET) {
            $this->setFlash('error', 'Changing a group result requires confirmation because the knockout bracket will be removed.');
            $this->redirect($redirectPath);
        }
        if ($writeResult === MatchModel::WRITE_STALE) {
            $this->setFlash('error', 'This match changed in another session. Reload it before saving.');
            $this->redirect($redirectPath);
        }
        if (!in_array($writeResult, [MatchModel::WRITE_SAVED, MatchModel::WRITE_IDEMPOTENT], true)) {
            $this->setFlash('error', 'Match result could not be saved. Check match status and try again.');
            $this->redirect($redirectPath);
        }

        $this->setFlash(
            'success',
            $writeResult === MatchModel::WRITE_IDEMPOTENT
                ? 'Match result was already up to date.'
                : 'Match result saved. Match marked as finished.'
        );
        $this->redirect($successRedirectPath);
    }

    /**
     * @param array<string, mixed> $tournament
     */
    private function handleSaveKnockoutMatchScore(
        array $tournament,
        int $matchId,
        string $redirectPath,
        string $successRedirectPath
    ): void {
        $tournamentId = (int) ($tournament['id'] ?? 0);
        $matchMode = (string) ($tournament['knockout_mode'] ?? 'best_of_3');
        $matchModel = new MatchModel($this->db());
        $match = $matchModel->findKnockoutMatchDetailForTournament($tournamentId, $matchId);
        if ($match === null) {
            $this->setFlash('error', 'Knockout match not found.');
            $this->redirect($redirectPath);
        }

        $status = (string) ($match['status'] ?? '');
        if (!in_array($status, ['scheduled', 'in_progress', 'finished'], true)) {
            $this->setFlash('error', 'Only scheduled, in-progress, or finished knockout matches can save score.');
            $this->redirect($redirectPath);
        }

        $validated = $this->validateScoreInput(
            $matchMode,
            (int) ($match['team_a_id'] ?? 0),
            (int) ($match['team_b_id'] ?? 0),
            true
        );
        if ($validated === null) {
            $this->redirect($redirectPath);
        }

        $knockoutMatches = $matchModel->knockoutMatchesForTournament($tournamentId);
        $graph = $this->buildKnockoutSourceGraph($knockoutMatches);
        $sourceByMatchId = $graph['source_by_match_id'];
        $childrenBySource = $graph['children_by_source'];
        $winnerSourceCode = $sourceByMatchId[$matchId] ?? '';
        if (!is_string($winnerSourceCode) || $winnerSourceCode === '') {
            $this->setFlash('error', 'Match source mapping is missing.');
            $this->redirect($redirectPath);
        }

        $currentWinnerTeamId = (int) ($match['winner_team_id'] ?? 0);
        $winnerChanged = $currentWinnerTeamId > 0
            && $currentWinnerTeamId !== (int) $validated['winner_team_id'];
        $descendantIds = $winnerChanged
            ? $this->descendantMatchIdsForSource($matchId, $sourceByMatchId, $childrenBySource)
            : [];
        $hasScoredDownstream = false;
        foreach ($knockoutMatches as $knockoutMatch) {
            $descendantId = (int) ($knockoutMatch['id'] ?? 0);
            if (!isset($descendantIds[$descendantId])) {
                continue;
            }

            $descendantStatus = (string) ($knockoutMatch['status'] ?? '');
            $descendantWinner = (int) ($knockoutMatch['winner_team_id'] ?? 0);
            $descendantSetsA = (int) ($knockoutMatch['sets_summary_a'] ?? 0);
            $descendantSetsB = (int) ($knockoutMatch['sets_summary_b'] ?? 0);
            if (
                in_array($descendantStatus, ['in_progress', 'finished'], true)
                || $descendantWinner > 0
                || $descendantSetsA > 0
                || $descendantSetsB > 0
            ) {
                $hasScoredDownstream = true;
                break;
            }
        }

        $confirmResetDependents = $this->requestPostString('confirm_reset_dependents') === '1';
        if ($hasScoredDownstream && !$confirmResetDependents) {
            $this->setFlash('error', 'Changing this result will reset dependent knockout matches.');
            $this->redirect($redirectPath);
        }

        $resetMatchIds = array_keys($descendantIds);
        $resetSourceCodes = [$winnerSourceCode];
        foreach ($descendantIds as $descendantId => $_unused) {
            $descendantSource = $sourceByMatchId[$descendantId] ?? '';
            if (!is_string($descendantSource) || $descendantSource === '') {
                continue;
            }

            $resetSourceCodes[] = $descendantSource;
        }
        $resetSourceCodes = array_values(array_unique($resetSourceCodes));

        $expectedLockVersion = $this->requestPostStrictInt('lock_version', 0, PHP_INT_MAX);
        if ($expectedLockVersion === null) {
            $this->setFlash('error', 'Invalid or stale match version.');
            $this->redirect($redirectPath);
        }

        try {
            $writeResult = $matchModel->applyKnockoutResultAndProgress(
                $tournamentId,
                $matchId,
                $expectedLockVersion,
                (int) ($match['team_a_id'] ?? 0),
                (int) ($match['team_b_id'] ?? 0),
                $validated['sets'],
                $validated['sets_summary_a'],
                $validated['sets_summary_b'],
                (int) $validated['winner_team_id'],
                $resetMatchIds,
                $resetSourceCodes,
                $winnerSourceCode,
                $confirmResetDependents
            );
        } catch (Throwable) {
            $this->setFlash('error', 'Knockout result could not be saved.');
            $this->redirect($redirectPath);
        }
        if ($writeResult === MatchModel::WRITE_REQUIRES_DEPENDENT_RESET) {
            $this->setFlash('error', 'Changing the winner requires confirmation because dependent knockout results will be reset.');
            $this->redirect($redirectPath);
        }
        if ($writeResult === MatchModel::WRITE_STALE) {
            $this->setFlash('error', 'This match changed in another session. Reload it before saving.');
            $this->redirect($redirectPath);
        }
        if (!in_array($writeResult, [MatchModel::WRITE_SAVED, MatchModel::WRITE_IDEMPOTENT], true)) {
            $this->setFlash('error', 'Knockout result could not be saved.');
            $this->redirect($redirectPath);
        }

        $this->setFlash(
            'success',
            $writeResult === MatchModel::WRITE_IDEMPOTENT
                ? 'Knockout result was already up to date.'
                : 'Knockout result saved. Progression updated.'
        );
        $this->redirect($successRedirectPath);
    }

    /**
     * @param array<string, mixed> $tournament
     */
    private function handleResetGroupMatchResult(array $tournament, int $matchId, string $redirectPath): void
    {
        $tournamentId = (int) ($tournament['id'] ?? 0);
        $matchModel = new MatchModel($this->db());
        $match = $matchModel->findGroupMatchDetailForTournament($tournamentId, $matchId);
        if ($match === null) {
            $this->setFlash('error', 'Group-stage match not found.');
            $this->redirect($redirectPath);
        }

        if (!in_array((string) ($match['status'] ?? ''), ['finished', 'scheduled'], true)) {
            $this->setFlash('error', 'Only finished matches can be reset.');
            $this->redirect($redirectPath);
        }

        $expectedLockVersion = $this->requestPostStrictInt('lock_version', 0, PHP_INT_MAX);
        if ($expectedLockVersion === null) {
            $this->setFlash('error', 'Invalid or stale match version.');
            $this->redirect($redirectPath);
        }

        try {
            $writeResult = $matchModel->resetGroupMatchResult(
                $tournamentId,
                $matchId,
                $expectedLockVersion,
                $this->requestPostString('confirm_reset_knockout') === '1'
            );
        } catch (Throwable) {
            $this->setFlash('error', 'Match result could not be reset.');
            $this->redirect($redirectPath);
        }
        if ($writeResult === MatchModel::WRITE_REQUIRES_KNOCKOUT_RESET) {
            $this->setFlash('error', 'Resetting a group result requires confirmation because the knockout bracket will be removed.');
            $this->redirect($redirectPath);
        }
        if ($writeResult === MatchModel::WRITE_STALE) {
            $this->setFlash('error', 'This match changed in another session. Reload it before resetting.');
            $this->redirect($redirectPath);
        }
        if (!in_array($writeResult, [MatchModel::WRITE_SAVED, MatchModel::WRITE_IDEMPOTENT], true)) {
            $this->setFlash('error', 'Match result could not be reset.');
            $this->redirect($redirectPath);
        }

        $this->setFlash(
            'success',
            $writeResult === MatchModel::WRITE_IDEMPOTENT
                ? 'Match result was already reset.'
                : 'Match result reset. Status changed to scheduled.'
        );
        $this->redirect($redirectPath);
    }

    /**
     * @param array<string, mixed> $tournament
     */
    private function handleUpdate(
        array $tournament,
        string $redirectPath,
        bool $redirectByUpdatedSlug = false,
        string $redirectSection = 'tournament'
    ): void
    {
        $expectedStateVersion = $this->requestPostStrictInt('state_version', 0, PHP_INT_MAX);
        if ($expectedStateVersion === null) {
            $this->setFlash('error', 'Tournament settings are out of date. Reload the page and try again.');
            $this->redirect($redirectPath);
        }

        $data = $this->collectTournamentInput();
        if ($data === null) {
            $this->redirect($redirectPath);
        }

        $tournamentModel = new TournamentModel($this->db());
        $currentSlug = (string) ($tournament['slug'] ?? '');
        $slugUpdateAction = $this->requestPostString('slug_update_action');
        $effectiveSlug = $currentSlug;
        if ($slugUpdateAction === 'update') {
            $effectiveSlug = $tournamentModel->generateUniqueSlug((string) $data['name'], (int) ($tournament['id'] ?? 0));
        }
        $data['slug'] = $effectiveSlug;
        $currentStartTime = $this->normalizeTimeHHMMOrEmpty((string) ($tournament['start_time'] ?? ''));
        $structuralChange = (string) ($tournament['event_date'] ?? '') !== (string) $data['event_date']
            || (string) ($currentStartTime ?? '') !== (string) $data['start_time']
            || (int) ($tournament['number_of_groups'] ?? 0) !== (int) $data['number_of_groups']
            || (int) ($tournament['number_of_courts'] ?? 0) !== (int) $data['number_of_courts']
            || (int) ($tournament['match_duration_minutes'] ?? 0) !== (int) $data['match_duration_minutes']
            || (int) ($tournament['advancing_teams_count'] ?? 0) !== (int) $data['advancing_teams_count']
            || (string) ($tournament['group_stage_mode'] ?? ($tournament['match_mode'] ?? '')) !== (string) $data['group_stage_mode']
            || (string) ($tournament['knockout_mode'] ?? '') !== (string) $data['knockout_mode'];
        $confirmResetMatches = $this->requestPostString('confirm_reset_matches') === '1';

        try {
            $updateResult = $tournamentModel->update(
                (int) $tournament['id'],
                $data,
                $expectedStateVersion,
                $structuralChange,
                $confirmResetMatches
            );
        } catch (Throwable) {
            $this->setFlash('error', 'Tournament could not be updated.');
            $this->redirect($redirectPath);
        }
        if ($updateResult === TournamentModel::UPDATE_REQUIRES_MATCH_RESET) {
            $this->setFlash('error', 'Structural tournament changes require confirmation because existing matches will be removed.');
            $this->redirect($redirectPath);
        }
        if ($updateResult === TournamentModel::UPDATE_STALE) {
            $this->setFlash('error', 'Tournament settings changed in another session. Reload the page before saving.');
            $this->redirect($redirectPath);
        }
        if ($updateResult !== TournamentModel::UPDATE_UPDATED) {
            $this->setFlash('error', 'Tournament could not be updated.');
            $this->redirect($redirectPath);
        }

        if ((string) ($data['admin_password'] ?? '') !== '') {
            $this->logSecurityEvent('tournament_admin_password_changed', [
                'tournament_id' => (int) $tournament['id'],
            ]);
        }

        $currentTournamentAdmin = $this->currentTournamentAdmin();
        if (is_array($currentTournamentAdmin) && (int) $currentTournamentAdmin['id'] === (int) $tournament['id']) {
            if ((string) ($data['admin_password'] ?? '') !== '') {
                $this->endAuthentication();
                $this->setFlash('success', 'Tournament password changed. Sign in again with the new password.');
                $this->redirect('/tournament/' . $effectiveSlug . '/login');
            }

            $sessionFingerprint = $_SESSION['tournament_admin']['credential_fingerprint'] ?? '';
            $_SESSION['tournament_admin'] = [
                'id' => (int) $tournament['id'],
                'slug' => $effectiveSlug,
                'name' => (string) $data['name'],
                'credential_fingerprint' => is_string($sessionFingerprint) ? $sessionFingerprint : '',
            ];
        }

        $successRedirectPath = $redirectPath;
        if ($redirectByUpdatedSlug) {
            $successRedirectPath = $this->tournamentAdminSectionRedirectPath($effectiveSlug, $redirectSection);
        }

        $this->setFlash('success', 'Tournament settings updated.');
        $this->redirect($successRedirectPath);
    }

    /**
     * @param array<string, mixed> $tournament
     */
    private function handleCreateTeam(array $tournament, string $redirectPath): void
    {
        $teamName = $this->requestPostString('team_name');
        $description = $this->requestPostString('description');

        if ($teamName === '') {
            $this->setFlash('error', 'Team name is required.');
            $this->redirect($redirectPath);
        }
        if ($this->stringLength($teamName) > self::MAX_TEAM_NAME_LENGTH) {
            $this->setFlash('error', 'Team name must be at most 150 characters.');
            $this->redirect($redirectPath);
        }
        if (strlen($description) > self::MAX_TEAM_DESCRIPTION_BYTES) {
            $this->setFlash('error', 'Team description must be at most 1000 bytes.');
            $this->redirect($redirectPath);
        }

        $teamModel = new TeamModel($this->db());
        try {
            $teamModel->create((int) $tournament['id'], $teamName, $description);
        } catch (\DomainException $exception) {
            $message = $exception->getMessage() === 'TEAM_LIMIT_REACHED'
                ? 'A tournament can have at most 64 teams.'
                : 'Team could not be added.';
            $this->setFlash('error', $message);
            $this->redirect($redirectPath);
        } catch (Throwable) {
            $this->setFlash('error', 'Team could not be added.');
            $this->redirect($redirectPath);
        }

        $this->setFlash('success', 'Team added.');
        $this->redirect($redirectPath);
    }

    /**
     * @param array<string, mixed> $tournament
     */
    private function handleUpdateTeam(array $tournament, string $redirectPath): void
    {
        $teamId = $this->requestPostStrictInt('team_id', 1, PHP_INT_MAX) ?? 0;
        $expectedStateVersion = $this->requestPostStrictInt('state_version', 0, PHP_INT_MAX);
        $teamName = $this->requestPostString('team_name');
        $description = $this->requestPostString('description');

        if ($teamId <= 0 || $expectedStateVersion === null || $teamName === '') {
            $this->setFlash('error', 'Team name is required.');
            $this->redirect($redirectPath);
        }
        if ($this->stringLength($teamName) > self::MAX_TEAM_NAME_LENGTH) {
            $this->setFlash('error', 'Team name must be at most 150 characters.');
            $this->redirect($redirectPath);
        }
        if (strlen($description) > self::MAX_TEAM_DESCRIPTION_BYTES) {
            $this->setFlash('error', 'Team description must be at most 1000 bytes.');
            $this->redirect($redirectPath);
        }

        $teamModel = new TeamModel($this->db());
        try {
            $writeResult = $teamModel->update(
                $teamId,
                (int) $tournament['id'],
                $expectedStateVersion,
                $teamName,
                $description
            );
        } catch (Throwable) {
            $this->setFlash('error', 'Team could not be updated.');
            $this->redirect($redirectPath);
        }
        if ($writeResult === TeamModel::WRITE_STALE) {
            $this->setFlash('error', 'Tournament data changed in another session. Reload the page before updating this team.');
            $this->redirect($redirectPath);
        }
        if (!in_array($writeResult, [TeamModel::WRITE_UPDATED, TeamModel::WRITE_IDEMPOTENT], true)) {
            $this->setFlash('error', 'Team could not be updated.');
            $this->redirect($redirectPath);
        }

        $this->setFlash('success', 'Team updated.');
        $this->redirect($redirectPath);
    }

    /**
     * @param array<string, mixed> $tournament
     */
    private function handleDeleteTeam(array $tournament, string $redirectPath): void
    {
        $teamId = $this->requestPostStrictInt('team_id', 1, PHP_INT_MAX) ?? 0;
        $expectedStateVersion = $this->requestPostStrictInt('state_version', 0, PHP_INT_MAX);
        $confirmation = $this->requestPostString('confirm_delete');

        if ($teamId <= 0 || $expectedStateVersion === null) {
            $this->setFlash('error', 'Invalid team selected.');
            $this->redirect($redirectPath);
        }

        if ($confirmation !== '1') {
            $this->setFlash('error', 'Deletion confirmation is required.');
            $this->redirect($redirectPath);
        }

        $teamModel = new TeamModel($this->db());
        try {
            $writeResult = $teamModel->delete(
                $teamId,
                (int) $tournament['id'],
                $expectedStateVersion,
                $this->requestPostString('confirm_reset_matches') === '1'
            );
        } catch (Throwable) {
            $this->setFlash('error', 'Team could not be deleted.');
            $this->redirect($redirectPath);
        }
        if ($writeResult === TeamModel::WRITE_REQUIRES_MATCH_RESET) {
            $this->setFlash('error', 'Deleting this team requires confirmation because all generated matches will be removed.');
            $this->redirect($redirectPath);
        }
        if ($writeResult === TeamModel::WRITE_STALE) {
            $this->setFlash('error', 'Tournament data changed in another session. Reload the page before deleting this team.');
            $this->redirect($redirectPath);
        }
        if ($writeResult !== TeamModel::WRITE_UPDATED) {
            $this->setFlash('error', 'Team was not found or could not be deleted.');
            $this->redirect($redirectPath);
        }

        $this->setFlash('success', 'Team deleted.');
        $this->redirect($redirectPath);
    }

    /**
     * @param array<string, mixed> $tournament
     */
    private function handleAssignTeamGroup(array $tournament, string $redirectPath): void
    {
        $teamId = $this->requestPostStrictInt('team_id', 1, PHP_INT_MAX) ?? 0;
        $expectedStateVersion = $this->requestPostStrictInt('state_version', 0, PHP_INT_MAX);
        $groupIdRaw = $this->requestPostString('group_id');
        $groupId = $groupIdRaw === ''
            ? null
            : $this->requestPostStrictInt('group_id', 1, PHP_INT_MAX);

        if ($teamId <= 0 || $expectedStateVersion === null) {
            $this->setFlash('error', 'Invalid team selected.');
            $this->redirect($redirectPath);
        }

        $tournamentModel = new TournamentModel($this->db());
        $groups = $tournamentModel->groupsForTournament((int) $tournament['id']);
        $validGroupIds = $this->groupIdSet($groups);

        if ($groupIdRaw !== '' && $groupId === null) {
            $this->setFlash('error', 'Invalid group selected.');
            $this->redirect($redirectPath);
        }
        if ($groupId !== null && !isset($validGroupIds[$groupId])) {
            $this->setFlash('error', 'Invalid group selected.');
            $this->redirect($redirectPath);
        }

        $teamModel = new TeamModel($this->db());
        try {
            $writeResult = $teamModel->updateGroupAssignment(
                $teamId,
                (int) $tournament['id'],
                $groupId,
                $expectedStateVersion,
                $this->requestPostString('confirm_reset_matches') === '1'
            );
        } catch (Throwable) {
            $this->setFlash('error', 'Team assignment could not be updated.');
            $this->redirect($redirectPath);
        }
        if ($writeResult === TeamModel::WRITE_REQUIRES_MATCH_RESET) {
            $this->setFlash('error', 'Changing a group assignment requires confirmation because all generated matches will be removed.');
            $this->redirect($redirectPath);
        }
        if ($writeResult === TeamModel::WRITE_STALE) {
            $this->setFlash('error', 'Tournament data changed in another session. Reload the page before changing assignments.');
            $this->redirect($redirectPath);
        }
        if (!in_array($writeResult, [TeamModel::WRITE_UPDATED, TeamModel::WRITE_IDEMPOTENT], true)) {
            $this->setFlash('error', 'Team assignment could not be updated.');
            $this->redirect($redirectPath);
        }

        $this->setFlash('success', 'Team assignment updated.');
        $this->redirect($redirectPath);
    }

    /**
     * @param array<string, mixed> $tournament
     */
    private function handleAutoAssignTeams(array $tournament, string $redirectPath): void
    {
        $overwriteConfirmed = $this->requestPostString('confirm_overwrite') === '1';
        $expectedStateVersion = $this->requestPostStrictInt('state_version', 0, PHP_INT_MAX);
        $tournamentId = (int) $tournament['id'];
        if ($expectedStateVersion === null) {
            $this->setFlash('error', 'Tournament data is out of date. Reload the page before assigning teams.');
            $this->redirect($redirectPath);
        }

        $tournamentModel = new TournamentModel($this->db());
        $groups = $tournamentModel->groupsForTournament($tournamentId);
        if (count($groups) === 0) {
            $this->setFlash('error', 'No groups available for this tournament.');
            $this->redirect($redirectPath);
        }

        $teamModel = new TeamModel($this->db());
        $teams = $teamModel->allByTournament($tournamentId);
        if (count($teams) === 0) {
            $this->setFlash('error', 'No teams available to assign.');
            $this->redirect($redirectPath);
        }

        if ($teamModel->hasAnyAssignedTeam($tournamentId) && !$overwriteConfirmed) {
            $this->setFlash('error', 'Some teams already have a group. Confirm overwrite to continue.');
            $this->redirect($redirectPath);
        }

        $teamIds = [];
        foreach ($teams as $team) {
            $teamIds[] = (int) ($team['id'] ?? 0);
        }

        shuffle($teamIds);

        $groupIds = [];
        foreach ($groups as $group) {
            $groupIds[] = (int) ($group['id'] ?? 0);
        }

        $groupCount = count($groupIds);
        $teamCount = count($teamIds);
        $baseSize = intdiv($teamCount, $groupCount);
        $extra = $teamCount % $groupCount;

        $assignments = [];
        $teamIndex = 0;
        for ($i = 0; $i < $groupCount; $i++) {
            $capacity = $baseSize + ($i < $extra ? 1 : 0);
            for ($slot = 0; $slot < $capacity; $slot++) {
                if (!isset($teamIds[$teamIndex])) {
                    break;
                }

                $assignments[$teamIds[$teamIndex]] = $groupIds[$i];
                $teamIndex++;
            }
        }

        try {
            $writeResult = $teamModel->bulkUpdateGroupAssignments(
                $tournamentId,
                $assignments,
                $expectedStateVersion,
                $this->requestPostString('confirm_reset_matches') === '1'
            );
        } catch (Throwable) {
            $this->setFlash('error', 'Teams could not be assigned.');
            $this->redirect($redirectPath);
        }
        if ($writeResult === TeamModel::WRITE_REQUIRES_MATCH_RESET) {
            $this->setFlash('error', 'Automatic assignment requires confirmation because all generated matches will be removed.');
            $this->redirect($redirectPath);
        }
        if ($writeResult === TeamModel::WRITE_STALE) {
            $this->setFlash('error', 'Tournament teams changed during automatic assignment. Review the latest groups and try again.');
            $this->redirect($redirectPath);
        }
        if (!in_array($writeResult, [TeamModel::WRITE_UPDATED, TeamModel::WRITE_IDEMPOTENT], true)) {
            $this->setFlash('error', 'Teams could not be assigned.');
            $this->redirect($redirectPath);
        }

        $this->setFlash('success', 'Teams were automatically assigned to groups.');
        $this->redirect($redirectPath);
    }

    /**
     * @param array<string, mixed> $tournament
     */
    private function handleGenerateGroupMatches(array $tournament, string $redirectPath): void
    {
        $tournamentId = (int) ($tournament['id'] ?? 0);
        if ($tournamentId <= 0) {
            $this->setFlash('error', 'Invalid tournament selected.');
            $this->redirect($redirectPath);
        }

        $eventDate = (string) ($tournament['event_date'] ?? '');
        $startTimeRaw = (string) ($tournament['start_time'] ?? '');
        $startTime = $this->normalizeTimeHHMMOrEmpty($startTimeRaw);
        if ($eventDate === '' || $startTime === '') {
            $this->setFlash('error', 'Set both tournament date and start time before generating matches.');
            $this->redirect($redirectPath);
        }
        if ($startTime === null) {
            $this->setFlash('error', 'Invalid tournament date or start time.');
            $this->redirect($redirectPath);
        }

        $startDateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i', $eventDate . ' ' . $startTime);
        if (!$startDateTime instanceof DateTimeImmutable) {
            $this->setFlash('error', 'Invalid tournament date or start time.');
            $this->redirect($redirectPath);
        }

        $matchDurationMinutes = (int) ($tournament['match_duration_minutes'] ?? 0);
        $courtCount = (int) ($tournament['number_of_courts'] ?? 0);
        if ($matchDurationMinutes <= 0 || $courtCount <= 0) {
            $this->setFlash('error', 'Tournament courts and match duration must be greater than zero.');
            $this->redirect($redirectPath);
        }

        $tournamentModel = new TournamentModel($this->db());
        $groups = $tournamentModel->groupsForTournament($tournamentId);
        $teamModel = new TeamModel($this->db());
        $teams = $teamModel->allByTournament($tournamentId);
        $groupAssignment = $this->buildGroupAssignmentViewData($groups, $teams);

        $matchModel = new MatchModel($this->db());
        $hasGroupMatches = $matchModel->groupMatchCountForTournament($tournamentId) > 0;
        $hasKnockoutMatches = $matchModel->knockoutMatchCountForTournament($tournamentId) > 0;

        $confirmUnassigned = $this->requestPostString('confirm_unassigned') === '1';
        if ((int) $groupAssignment['unassigned_count'] > 0 && !$confirmUnassigned) {
            $this->setFlash('error', 'Some teams are unassigned. Confirm generation to proceed with assigned teams only.');
            $this->redirect($redirectPath);
        }

        $confirmRegenerate = $this->requestPostString('confirm_regenerate') === '1';
        if (($hasGroupMatches || $hasKnockoutMatches) && !$confirmRegenerate) {
            $this->setFlash('error', 'Confirm regeneration to replace group matches and remove any knockout bracket.');
            $this->redirect($redirectPath);
        }

        $teamsByGroupId = [];
        $groupNamesById = [];
        foreach ($groups as $group) {
            $groupId = (int) ($group['id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }

            $teamsByGroupId[$groupId] = [];
            $groupNamesById[$groupId] = (string) ($group['name'] ?? '');
        }

        foreach ($teams as $team) {
            $groupIdRaw = $team['group_id'] ?? null;
            $groupId = is_numeric($groupIdRaw) ? (int) $groupIdRaw : null;
            if ($groupId === null || !isset($teamsByGroupId[$groupId])) {
                continue;
            }

            $teamsByGroupId[$groupId][] = [
                'id' => (int) ($team['id'] ?? 0),
                'name' => (string) ($team['team_name'] ?? ''),
            ];
        }

        $invalidGroups = [];
        foreach ($teamsByGroupId as $groupId => $groupTeams) {
            if (count($groupTeams) < 2) {
                $invalidGroups[] = $groupNamesById[$groupId] ?? ('#' . (string) $groupId);
            }
        }

        if (count($invalidGroups) > 0) {
            $this->setFlash('error', 'Each group must have at least 2 teams. Invalid groups: ' . implode(', ', $invalidGroups) . '.');
            $this->redirect($redirectPath);
        }

        try {
            $scheduledMatches = (new GroupStageScheduler())->schedule(
                $teamsByGroupId,
                $courtCount,
                $matchDurationMinutes,
                $startDateTime
            );
            $writeResult = $matchModel->replaceGroupMatches(
                $tournamentId,
                (int) ($tournament['state_version'] ?? -1),
                $scheduledMatches
            );
        } catch (Throwable) {
            $this->setFlash('error', 'Group-stage matches could not be generated.');
            $this->redirect($redirectPath);
        }
        if ($writeResult === MatchModel::WRITE_STALE) {
            $this->setFlash('error', 'Tournament data changed during generation. Review the latest settings and try again.');
            $this->redirect($redirectPath);
        }
        if ($writeResult !== MatchModel::WRITE_SAVED) {
            $this->setFlash('error', 'Group-stage matches could not be generated.');
            $this->redirect($redirectPath);
        }

        $this->setFlash('success', sprintf('Generated %d group-stage matches.', count($scheduledMatches)));
        $this->redirect($redirectPath);
    }

    /**
     * @param array<string, mixed> $tournament
     */
    private function handleGenerateKnockoutMatches(array $tournament, string $redirectPath): void
    {
        $tournamentId = (int) ($tournament['id'] ?? 0);
        if ($tournamentId <= 0) {
            $this->setFlash('error', 'Invalid tournament selected.');
            $this->redirect($redirectPath);
        }

        $advancingTeamsCount = (int) ($tournament['advancing_teams_count'] ?? 0);
        if ($advancingTeamsCount < 2) {
            $this->setFlash('error', 'Advancing teams count must be at least 2.');
            $this->redirect($redirectPath);
        }

        $matchModel = new MatchModel($this->db());
        $groupMatchCount = $matchModel->groupMatchCountForTournament($tournamentId);
        if ($groupMatchCount <= 0) {
            $this->setFlash('error', 'Generate group-stage matches first.');
            $this->redirect($redirectPath);
        }

        $finishedGroupMatches = $matchModel->finishedGroupMatchesForTournament($tournamentId);
        if (count($finishedGroupMatches) !== $groupMatchCount) {
            $this->setFlash('error', 'Knockout can be generated only after all group-stage matches are finished.');
            $this->redirect($redirectPath);
        }

        $hasKnockoutMatches = $matchModel->knockoutMatchCountForTournament($tournamentId) > 0;
        $confirmRegenerate = $this->requestPostString('confirm_regenerate') === '1';
        if ($hasKnockoutMatches && !$confirmRegenerate) {
            $this->setFlash('error', 'Knockout matches already exist. Confirm regeneration to replace them.');
            $this->redirect($redirectPath);
        }

        $finishedMatchIds = array_values(array_filter(
            array_map(
                static fn (array $match): int => (int) ($match['id'] ?? 0),
                $finishedGroupMatches
            ),
            static fn (int $id): bool => $id > 0
        ));
        $setsByMatchId = $matchModel->setsForMatches($finishedMatchIds);

        $tournamentModel = new TournamentModel($this->db());
        $groups = $tournamentModel->groupsForTournament($tournamentId);
        if (count($groups) < 1) {
            $this->setFlash('error', 'No groups found for this tournament.');
            $this->redirect($redirectPath);
        }
        $teamModel = new TeamModel($this->db());
        $teams = $teamModel->allByTournament($tournamentId);
        $groupStandingsByGroup = $this->buildGroupStandings(
            $groups,
            $teams,
            $finishedGroupMatches,
            $setsByMatchId,
            (string) ($tournament['group_stage_mode'] ?? ($tournament['match_mode'] ?? ''))
        );
        $seededTeams = $this->buildKnockoutSeededTeamsByGroupAdvancement(
            $groups,
            $groupStandingsByGroup,
            $advancingTeamsCount
        );
        if ($seededTeams === null) {
            $this->redirect($redirectPath);
        }

        $matches = $this->buildKnockoutBracketMatches($seededTeams, $advancingTeamsCount);
        if (count($matches) < 1) {
            $this->setFlash('error', 'Knockout matches could not be prepared.');
            $this->redirect($redirectPath);
        }

        $matches = $this->applyEstimatedKnockoutSchedule(
            $matches,
            (int) ($tournament['number_of_courts'] ?? 1),
            (int) ($tournament['match_duration_minutes'] ?? 20)
        );

        try {
            $writeResult = $matchModel->replaceKnockoutMatches(
                $tournamentId,
                (int) ($tournament['state_version'] ?? -1),
                $matches
            );
        } catch (Throwable) {
            $this->setFlash('error', 'Knockout matches could not be generated.');
            $this->redirect($redirectPath);
        }
        if ($writeResult === MatchModel::WRITE_STALE) {
            $this->setFlash('error', 'Tournament results changed during knockout generation. Review the latest standings and try again.');
            $this->redirect($redirectPath);
        }
        if ($writeResult !== MatchModel::WRITE_SAVED) {
            $this->setFlash('error', 'Knockout matches could not be generated.');
            $this->redirect($redirectPath);
        }

        $bracketSize = $this->nextPowerOfTwo($advancingTeamsCount);
        $byeCount = $bracketSize - $advancingTeamsCount;
        if ($byeCount > 0) {
            $this->setFlash('success', sprintf('Generated %d knockout matches (bracket %d, byes %d).', count($matches), $bracketSize, $byeCount));
            $this->redirect($redirectPath);
        }

        $this->setFlash('success', sprintf('Generated %d knockout matches (bracket %d).', count($matches), $bracketSize));
        $this->redirect($redirectPath);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveTournamentByQueryForSuperadmin(): ?array
    {
        $this->requireSuperadminAuth();

        $tournamentId = $this->requestGetInt('id');
        if ($tournamentId <= 0) {
            $this->setFlash('error', 'Invalid tournament selected.');
            $this->redirect('/admin/dashboard');
        }

        $tournamentModel = new TournamentModel($this->db());
        $tournament = $tournamentModel->findById($tournamentId);
        if ($tournament === null) {
            $this->setFlash('error', 'Tournament not found.');
            $this->redirect('/admin/dashboard');
        }

        return $tournament;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveTournamentByPostForSuperadmin(): ?array
    {
        $tournamentId = $this->requestPostStrictInt('tournament_id', 1, PHP_INT_MAX) ?? 0;
        if ($tournamentId <= 0) {
            $this->setFlash('error', 'Invalid tournament selected.');
            $this->redirect('/admin/dashboard');
        }

        $tournamentModel = new TournamentModel($this->db());
        $tournament = $tournamentModel->findById($tournamentId);
        if ($tournament === null) {
            $this->setFlash('error', 'Tournament not found.');
            $this->redirect('/admin/dashboard');
        }

        return $tournament;
    }

    private function matchIdFromRoute(): int
    {
        $matchId = $this->requestRouteString('matchId');
        if ($matchId === '' || !ctype_digit($matchId)) {
            return 0;
        }

        return (int) $matchId;
    }

    /**
     * @return array<string, int>
     */
    private function filtersFromGet(): array
    {
        $filters = [];
        $groupId = $this->requestGetInt('group_id');
        $court = $this->requestGetInt('court');
        if ($groupId > 0) {
            $filters['group_id'] = $groupId;
        }
        if ($court > 0) {
            $filters['court'] = $court;
        }

        return $filters;
    }

    /**
     * @return array<string, int>
     */
    private function filtersFromPost(): array
    {
        $filters = [];
        $groupId = $this->requestPostInt('group_id');
        $court = $this->requestPostInt('court');
        if ($groupId > 0) {
            $filters['group_id'] = $groupId;
        }
        if ($court > 0) {
            $filters['court'] = $court;
        }

        return $filters;
    }

    private function requestPostInt(string $key): int
    {
        $value = $this->requestPostString($key);
        if ($value === '' || !ctype_digit($value)) {
            return 0;
        }

        return (int) $value;
    }

    private function shouldReturnToMatches(): bool
    {
        return $this->requestPostString('return_to') === 'matches';
    }

    /**
     * @param array<string, int> $params
     */
    private function querySuffix(array $params): string
    {
        if (count($params) === 0) {
            return '';
        }

        return '?' . http_build_query($params);
    }

    /**
     * @param array<string, int> $filters
     */
    private function superadminMatchDetailPath(int $tournamentId, int $matchId, array $filters = []): string
    {
        $params = array_merge(['id' => $tournamentId], $filters);
        return '/admin/tournament/matches/' . $matchId . $this->querySuffix($params);
    }

    /**
     * @param array<string, int> $filters
     */
    private function tournamentAdminMatchDetailPath(string $slug, int $matchId, array $filters = []): string
    {
        return '/tournament/' . $slug . '/admin/matches/' . $matchId . $this->querySuffix($filters);
    }

    private function superadminKnockoutMatchDetailPath(int $tournamentId, int $matchId): string
    {
        return '/admin/tournament/knockout/' . $matchId . $this->querySuffix(['id' => $tournamentId]);
    }

    private function tournamentAdminKnockoutMatchDetailPath(string $slug, int $matchId): string
    {
        return '/tournament/' . $slug . '/admin/knockout/' . $matchId;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveTournamentBySlugWithAdminAccess(): ?array
    {
        $slug = $this->requestRouteString('slug');
        if ($slug === '') {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '404 Not Found';
            return null;
        }

        $tournamentModel = new TournamentModel($this->db());
        $tournament = $tournamentModel->findBySlug($slug);
        if ($tournament === null) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '404 Not Found';
            return null;
        }

        if ($this->currentSuperadmin() !== null) {
            return $tournament;
        }

        $tournamentAdmin = $this->currentTournamentAdmin();
        if (is_array($tournamentAdmin) && (int) $tournamentAdmin['id'] === (int) $tournament['id']) {
            $authRecord = $tournamentModel->findAuthBySlug($slug);
            $sessionFingerprint = $_SESSION['tournament_admin']['credential_fingerprint'] ?? '';
            $expectedFingerprint = is_array($authRecord)
                ? hash('sha256', (string) ($authRecord['admin_password_hash'] ?? ''))
                : '';
            if (
                !is_string($sessionFingerprint)
                || $sessionFingerprint === ''
                || $expectedFingerprint === ''
                || !hash_equals($expectedFingerprint, $sessionFingerprint)
            ) {
                $this->endAuthentication();
                $this->setFlash('error', 'Tournament credentials changed. Please sign in again.');
                $this->redirect('/tournament/' . $slug . '/login');
            }

            return $tournament;
        }

        $this->setFlash('error', 'Please sign in as tournament admin.');
        $this->redirect('/tournament/' . $slug . '/login');
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function collectTournamentInput(): ?array
    {
        $name = $this->requestPostString('name');
        $eventDate = $this->requestPostString('event_date');
        $startTimeRaw = $this->requestPostString('start_time');
        $startTime = $this->normalizeTimeHHMMOrEmpty($startTimeRaw);
        $location = $this->requestPostString('location');
        $adminPassword = $this->requestPostRawString('admin_password');
        $numberOfGroups = $this->requestPostStrictInt('number_of_groups', 1, self::MAX_GROUPS);
        $numberOfCourts = $this->requestPostStrictInt('number_of_courts', 1, 99);
        $matchDurationMinutes = $this->requestPostStrictInt('match_duration_minutes', 1, 240);
        $advancingTeamsCount = $this->requestPostStrictInt('advancing_teams_count', 2, 64);
        $groupStageMode = $this->requestPostString('group_stage_mode');
        $knockoutMode = $this->requestPostString('knockout_mode');

        if ($name === '') {
            $this->setFlash('error', 'Tournament name is required.');
            return null;
        }
        if ($this->stringLength($name) > self::MAX_TOURNAMENT_NAME_LENGTH) {
            $this->setFlash('error', 'Tournament name must be at most 150 characters.');
            return null;
        }
        if ($this->stringLength($location) > self::MAX_LOCATION_LENGTH) {
            $this->setFlash('error', 'Location must be at most 150 characters.');
            return null;
        }

        if ($adminPassword !== '' && (strlen($adminPassword) < 8 || strlen($adminPassword) > self::MAX_ADMIN_PASSWORD_BYTES)) {
            $this->setFlash('error', 'Tournament admin password must be between 8 and 72 bytes.');
            return null;
        }

        if ($eventDate !== '' && !$this->isValidCalendarDate($eventDate)) {
            $this->setFlash('error', 'Event date must be a valid calendar date in YYYY-MM-DD format.');
            return null;
        }

        if ($startTime === null) {
            $this->setFlash('error', 'Start time must use HH:MM format.');
            return null;
        }

        if ($numberOfGroups === null) {
            $this->setFlash('error', 'Number of groups must be between 1 and 32.');
            return null;
        }

        if ($numberOfCourts === null) {
            $this->setFlash('error', 'Number of courts must be between 1 and 99.');
            return null;
        }

        if ($matchDurationMinutes === null) {
            $this->setFlash('error', 'Match duration must be between 1 and 240 minutes.');
            return null;
        }

        if ($advancingTeamsCount === null) {
            $this->setFlash('error', 'Advancing teams count must be between 2 and 64.');
            return null;
        }

        if (!in_array($groupStageMode, self::MATCH_MODES, true)) {
            $this->setFlash('error', 'Invalid group stage mode selected.');
            return null;
        }

        if (!in_array($knockoutMode, self::MATCH_MODES, true)) {
            $this->setFlash('error', 'Invalid knockout mode selected.');
            return null;
        }

        return [
            'name' => $name,
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'location' => $location,
            'admin_password' => $adminPassword,
            'number_of_groups' => $numberOfGroups,
            'number_of_courts' => $numberOfCourts,
            'match_duration_minutes' => $matchDurationMinutes,
            'advancing_teams_count' => $advancingTeamsCount,
            'group_stage_mode' => $groupStageMode,
            'knockout_mode' => $knockoutMode,
        ];
    }

    /**
     * @param array<string, mixed> $tournament
     */
    private function handleUpdatePublicView(array $tournament, string $redirectPath): void
    {
        $expectedStateVersion = $this->requestPostStrictInt('state_version', 0, PHP_INT_MAX);
        if ($expectedStateVersion === null) {
            $this->setFlash('error', 'Public View settings are out of date. Reload the page and try again.');
            $this->redirect($redirectPath);
        }

        $formScope = $this->requestPostString('public_view_form');
        if ($formScope !== 'general' && $formScope !== 'screen_list') {
            $formScope = 'general';
        }

        if ($formScope === 'screen_list') {
            $rawEnabled = $_POST['screen_enabled'] ?? [];
            $rawOrder = $_POST['screen_order'] ?? [];
            $enabledMap = is_array($rawEnabled) ? $rawEnabled : [];
            $orderMap = is_array($rawOrder) ? $rawOrder : [];

            $screensByKey = [];
            foreach (self::PUBLIC_SCREEN_DEFINITIONS as $screenKey => $definition) {
                $isEnabled = isset($enabledMap[$screenKey]) && (string) $enabledMap[$screenKey] === '1';
                $sortOrder = 1;
                if (isset($orderMap[$screenKey])) {
                    $rawSortOrder = $orderMap[$screenKey];
                    if (!is_string($rawSortOrder) && !is_int($rawSortOrder)) {
                        $this->setFlash('error', 'Screen order must be a whole number between 1 and 99.');
                        $this->redirect($redirectPath);
                    }
                    $rawSortOrder = trim((string) $rawSortOrder);
                    if (!ctype_digit($rawSortOrder)) {
                        $this->setFlash('error', 'Screen order must be a whole number between 1 and 99.');
                        $this->redirect($redirectPath);
                    }
                    $normalizedSortOrder = ltrim($rawSortOrder, '0');
                    $normalizedSortOrder = $normalizedSortOrder !== '' ? $normalizedSortOrder : '0';
                    if (
                        strlen($normalizedSortOrder) > 2
                        || (int) $normalizedSortOrder < 1
                        || (int) $normalizedSortOrder > 99
                    ) {
                        $this->setFlash('error', 'Screen order must be a whole number between 1 and 99.');
                        $this->redirect($redirectPath);
                    }
                    $sortOrder = (int) $normalizedSortOrder;
                }
                $screensByKey[$screenKey] = [
                    'is_enabled' => $isEnabled ? 1 : 0,
                    'sort_order' => $sortOrder,
                ];
            }

            try {
                $updateResult = (new TournamentModel($this->db()))->savePublicScreens(
                    (int) $tournament['id'],
                    $expectedStateVersion,
                    $screensByKey
                );
            } catch (Throwable) {
                $this->setFlash('error', 'Public screen settings could not be updated.');
                $this->redirect($redirectPath);
            }
            if ($updateResult === TournamentModel::UPDATE_STALE) {
                $this->setFlash('error', 'Public View settings changed in another session. Reload the page before saving.');
                $this->redirect($redirectPath);
            }
            if ($updateResult !== TournamentModel::UPDATE_UPDATED) {
                $this->setFlash('error', 'Public screen settings could not be updated.');
                $this->redirect($redirectPath);
            }

            $this->setFlash('success', 'Public screen settings updated.');
            $this->redirect($redirectPath);
        }

        $publicViewEnabled = $this->requestPostString('public_view_enabled') === '1';
        $autoplayEnabled = $this->requestPostString('autoplay_enabled') === '1';
        $rotationIntervalSeconds = $this->requestPostStrictInt('rotation_interval_seconds', 5, 300);
        $publicViewTheme = $this->requestPostString('public_view_theme');
        if (!in_array($publicViewTheme, ['dark', 'light'], true)) {
            $publicViewTheme = 'dark';
        }
        if ($rotationIntervalSeconds === null) {
            $this->setFlash('error', 'Rotation interval must be between 5 and 300 seconds.');
            $this->redirect($redirectPath);
        }

        $publicTitleOverride = trim($this->requestPostString('public_title_override'));
        $publicDescription = trim($this->requestPostString('public_description'));
        $publicMapUrl = trim($this->requestPostString('public_map_url'));
        if ($this->stringLength($publicTitleOverride) > self::MAX_PUBLIC_TITLE_LENGTH) {
            $this->setFlash('error', 'Public title must be at most 200 characters.');
            $this->redirect($redirectPath);
        }
        if (strlen($publicDescription) > self::MAX_PUBLIC_DESCRIPTION_BYTES) {
            $this->setFlash('error', 'Public description is too long.');
            $this->redirect($redirectPath);
        }
        if ($this->stringLength($publicMapUrl) > self::MAX_URL_LENGTH) {
            $this->setFlash('error', 'Map URL must be at most 500 characters.');
            $this->redirect($redirectPath);
        }
        if ($publicMapUrl !== '' && filter_var($publicMapUrl, FILTER_VALIDATE_URL) === false) {
            $this->setFlash('error', 'Map URL must be a valid absolute URL.');
            $this->redirect($redirectPath);
        }
        if ($publicMapUrl !== '' && !preg_match('#^https?://#i', $publicMapUrl)) {
            $this->setFlash('error', 'Map URL must start with http:// or https://.');
            $this->redirect($redirectPath);
        }
        $publicMapEmbedInput = trim($this->requestPostString('public_map_embed_url'));
        if ($this->stringLength($publicMapEmbedInput) > 4000) {
            $this->setFlash('error', 'Map embed input is too long.');
            $this->redirect($redirectPath);
        }
        $publicMapEmbedUrl = $this->extractPublicMapEmbedUrl($publicMapEmbedInput);
        if ($publicMapEmbedInput !== '' && $publicMapEmbedUrl === null) {
            $this->setFlash('error', 'Map embed must be a valid Google embed URL or iframe snippet.');
            $this->redirect($redirectPath);
        }
        if (is_string($publicMapEmbedUrl) && $this->stringLength($publicMapEmbedUrl) > self::MAX_URL_LENGTH) {
            $this->setFlash('error', 'Map embed URL must be at most 500 characters.');
            $this->redirect($redirectPath);
        }

        $logoPath = '';
        $uploadedNewLogo = false;
        $logoUpload = $_FILES['public_logo'] ?? null;
        if (
            is_array($logoUpload)
            && (int) ($logoUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        ) {
            $uploadResult = $this->handlePublicLogoUpload($logoUpload);
            if (!is_array($uploadResult) || (string) ($uploadResult['error'] ?? '') !== '') {
                $this->setFlash('error', (string) ($uploadResult['error'] ?? 'Logo upload failed.'));
                $this->redirect($redirectPath);
            }
            $logoPath = (string) ($uploadResult['path'] ?? '');
            $uploadedNewLogo = $logoPath !== '';
        }

        $tournamentModel = new TournamentModel($this->db());
        try {
            $saveResult = $tournamentModel->savePublicViewSettings(
                (int) $tournament['id'],
                $expectedStateVersion,
                $publicViewEnabled,
                $autoplayEnabled,
                $rotationIntervalSeconds,
                $publicViewTheme,
                $publicTitleOverride,
                $publicDescription,
                $uploadedNewLogo ? $logoPath : null,
                $publicMapUrl,
                $publicMapEmbedUrl ?? ''
            );
        } catch (Throwable) {
            if ($uploadedNewLogo) {
                $this->removeManagedPublicLogo($logoPath);
            }
            $this->setFlash('error', 'Public View settings could not be updated.');
            $this->redirect($redirectPath);
            return;
        }

        if (($saveResult['status'] ?? '') === TournamentModel::UPDATE_STALE) {
            if ($uploadedNewLogo) {
                $this->removeManagedPublicLogo($logoPath);
            }
            $this->setFlash('error', 'Public View settings changed in another session. Reload the page before saving.');
            $this->redirect($redirectPath);
        }
        if (($saveResult['status'] ?? '') !== TournamentModel::UPDATE_UPDATED) {
            if ($uploadedNewLogo) {
                $this->removeManagedPublicLogo($logoPath);
            }
            $this->setFlash('error', 'Public View settings could not be updated.');
            $this->redirect($redirectPath);
        }

        if ($uploadedNewLogo) {
            $previousLogoPath = (string) ($saveResult['previous_logo_path'] ?? '');
            if (
                $previousLogoPath !== ''
                && $previousLogoPath !== $logoPath
                && !$this->removeManagedPublicLogo($previousLogoPath)
            ) {
                error_log(json_encode([
                    'operational_event' => 'managed_logo_cleanup_failed',
                    'occurred_at' => gmdate(\DateTimeInterface::ATOM),
                    'tournament_id' => (int) $tournament['id'],
                ], JSON_UNESCAPED_SLASHES) ?: 'Managed tournament logo cleanup failed.');
            }
        }

        $this->setFlash('success', 'Public View settings updated.');
        $this->redirect($redirectPath);
    }

    private function extractPublicMapEmbedUrl(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }

        $candidate = $input;
        if (stripos($input, '<iframe') !== false) {
            if (preg_match('/src\s*=\s*"([^"]+)"/i', $input, $matches) === 1) {
                $candidate = trim((string) ($matches[1] ?? ''));
            } elseif (preg_match("/src\s*=\s*'([^']+)'/i", $input, $matches) === 1) {
                $candidate = trim((string) ($matches[1] ?? ''));
            } else {
                return null;
            }
        }

        if (filter_var($candidate, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        if (strpos($candidate, 'https://www.google.com/maps/embed') !== 0) {
            return null;
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed> $logoUpload
     * @return array{path?: string, error?: string}
     */
    private function handlePublicLogoUpload(array $logoUpload): array
    {
        $uploadError = (int) ($logoUpload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            return ['error' => 'Logo upload failed.'];
        }

        $tmpName = (string) ($logoUpload['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return ['error' => 'Uploaded logo file is invalid.'];
        }

        $fileSize = (int) ($logoUpload['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > (2 * 1024 * 1024)) {
            return ['error' => 'Logo file size must be up to 2 MB.'];
        }

        $originalName = (string) ($logoUpload['name'] ?? '');
        $extensionFromName = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extensionFromName, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            return ['error' => 'Logo file extension must be PNG, JPG, or WEBP.'];
        }

        $mimeType = '';
        if (function_exists('finfo_open') && function_exists('finfo_file')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = @finfo_file($finfo, $tmpName);
                @finfo_close($finfo);
                if (is_string($detected)) {
                    $mimeType = strtolower(trim($detected));
                }
            }
        }

        $fileInfo = @getimagesize($tmpName);
        $imageWidth = is_array($fileInfo) ? (int) ($fileInfo[0] ?? 0) : 0;
        $imageHeight = is_array($fileInfo) ? (int) ($fileInfo[1] ?? 0) : 0;
        if ($imageWidth <= 0 || $imageHeight <= 0) {
            return ['error' => 'Logo must be a valid image file.'];
        }
        if (
            $imageWidth > self::MAX_LOGO_DIMENSION
            || $imageHeight > self::MAX_LOGO_DIMENSION
            || $imageWidth > intdiv(self::MAX_LOGO_PIXELS, $imageHeight)
        ) {
            return ['error' => 'Logo dimensions must be at most 4096 x 4096 pixels and 16 megapixels.'];
        }
        $imageMimeType = strtolower((string) ($fileInfo['mime'] ?? ''));
        if ($mimeType === '') {
            $mimeType = $imageMimeType;
        } elseif ($imageMimeType === '' || $mimeType !== $imageMimeType) {
            return ['error' => 'Logo image type is inconsistent.'];
        }
        $extensionByMime = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];
        $extension = $extensionByMime[$mimeType] ?? '';
        if ($extension === '') {
            return ['error' => 'Logo must be PNG, JPG, or WEBP.'];
        }

        $publicRoot = dirname(__DIR__, 2) . '/public';
        $uploadDirectory = $publicRoot . '/uploads/tournament_logos';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
            return ['error' => 'Could not create logo upload directory.'];
        }
        $uploadHtaccess = $uploadDirectory . '/.htaccess';
        if (!is_file($uploadHtaccess)) {
            @file_put_contents(
                $uploadHtaccess,
                "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|asp|aspx|jsp|sh)$\">\n    Require all denied\n</FilesMatch>\n"
            );
        }

        $random = bin2hex(random_bytes(8));
        $fileName = 'logo_' . time() . '_' . $random . '.' . $extension;
        $targetPath = $uploadDirectory . '/' . $fileName;
        if (!move_uploaded_file($tmpName, $targetPath)) {
            return ['error' => 'Could not save uploaded logo file.'];
        }

        $relativePath = 'uploads/tournament_logos/' . $fileName;

        return ['path' => $relativePath];
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     is_enabled: int,
     *     sort_order: int,
     *     path: string,
     *     direct_url: string
     * }>
     */
    private function buildPublicScreenSettingsViewData(TournamentModel $tournamentModel, int $tournamentId, string $tournamentSlug): array
    {
        $stored = [];
        $storedOrderValues = [];
        foreach ($tournamentModel->publicScreensForTournament($tournamentId) as $row) {
            $screenKey = (string) ($row['screen_key'] ?? '');
            if ($screenKey === '') {
                continue;
            }

            $storedOrder = (int) ($row['sort_order'] ?? 1);
            $stored[$screenKey] = [
                'is_enabled' => (int) ($row['is_enabled'] ?? 0),
                'sort_order' => $storedOrder,
            ];
            $storedOrderValues[] = $storedOrder;
        }

        $uniqueStoredOrders = array_values(array_unique($storedOrderValues));
        $useDefaultDisplayOrder = count($stored) > 0
            && count($uniqueStoredOrders) === 1
            && (int) ($uniqueStoredOrders[0] ?? 0) === 1;

        $screens = [];
        foreach (self::PUBLIC_SCREEN_DEFINITIONS as $screenKey => $definition) {
            $path = (string) ($definition['path'] ?? '/overview');
            $settings = $stored[$screenKey] ?? null;
            $defaultSortOrder = self::PUBLIC_SCREEN_DEFAULT_ORDER[$screenKey] ?? (count($screens) + 1);
            $isEnabled = is_array($settings) ? (int) ($settings['is_enabled'] ?? 0) : 1;
            $sortOrder = is_array($settings)
                ? ($useDefaultDisplayOrder ? $defaultSortOrder : (int) ($settings['sort_order'] ?? $defaultSortOrder))
                : $defaultSortOrder;

            $screens[] = [
                'key' => $screenKey,
                'label' => (string) ($definition['label'] ?? $screenKey),
                'is_enabled' => $isEnabled > 0 ? 1 : 0,
                'sort_order' => max(1, min(99, $sortOrder)),
                'path' => $path,
                'direct_url' => $this->url('/public/' . $tournamentSlug . $path),
            ];
        }

        usort(
            $screens,
            static function (array $a, array $b): int {
                $orderCompare = (int) $a['sort_order'] <=> (int) $b['sort_order'];
                if ($orderCompare !== 0) {
                    return $orderCompare;
                }

                $defaultCompare = (self::PUBLIC_SCREEN_DEFAULT_ORDER[(string) ($a['key'] ?? '')] ?? 999)
                    <=> (self::PUBLIC_SCREEN_DEFAULT_ORDER[(string) ($b['key'] ?? '')] ?? 999);
                if ($defaultCompare !== 0) {
                    return $defaultCompare;
                }

                return strcmp((string) $a['label'], (string) $b['label']);
            }
        );

        return $screens;
    }

    /**
     * @param list<array<string, mixed>> $groups
     * @param array<int, list<array<string, int|string>>> $groupStandingsByGroup
     * @return list<array<string, int|string>>|null
     */
    private function buildKnockoutSeededTeamsByGroupAdvancement(
        array $groups,
        array $groupStandingsByGroup,
        int $advancingTeamsCount
    ): ?array {
        $groupCount = count($groups);
        if ($groupCount < 1) {
            $this->setFlash('error', 'No groups found for advancement.');
            return null;
        }

        $base = intdiv($advancingTeamsCount, $groupCount);
        $remainder = $advancingTeamsCount % $groupCount;

        $selectedByTeamId = [];
        $selectedRows = [];
        $wildcardCandidates = [];

        foreach ($groups as $group) {
            $groupId = (int) ($group['id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }

            $rows = $groupStandingsByGroup[$groupId] ?? [];
            if (count($rows) < $base) {
                $groupName = (string) ($group['name'] ?? ('#' . $groupId));
                $this->setFlash('error', sprintf('Group %s does not have enough ranked teams for advancement.', $groupName));
                return null;
            }

            for ($i = 0; $i < count($rows); $i++) {
                $row = $rows[$i];
                $teamId = (int) ($row['team_id'] ?? 0);
                if ($teamId <= 0) {
                    continue;
                }

                if ($i < $base) {
                    if (!isset($selectedByTeamId[$teamId])) {
                        $selectedByTeamId[$teamId] = true;
                        $selectedRows[] = $row;
                    }
                    continue;
                }

                $wildcardCandidates[] = $row;
            }
        }

        if ($remainder > 0) {
            usort(
                $wildcardCandidates,
                static function (array $a, array $b): int {
                    $pointsCompare = (int) $b['tournament_points'] <=> (int) $a['tournament_points'];
                    if ($pointsCompare !== 0) {
                        return $pointsCompare;
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
                }
            );

            foreach ($wildcardCandidates as $candidate) {
                if ($remainder <= 0) {
                    break;
                }

                $teamId = (int) ($candidate['team_id'] ?? 0);
                if ($teamId <= 0 || isset($selectedByTeamId[$teamId])) {
                    continue;
                }

                $selectedByTeamId[$teamId] = true;
                $selectedRows[] = $candidate;
                $remainder--;
            }
        }

        if (count($selectedRows) !== $advancingTeamsCount) {
            $this->setFlash('error', sprintf('Not enough ranked teams for knockout. Need %d, selected %d.', $advancingTeamsCount, count($selectedRows)));
            return null;
        }

        usort(
            $selectedRows,
            static function (array $a, array $b): int {
                $positionCompare = (int) $a['position'] <=> (int) $b['position'];
                if ($positionCompare !== 0) {
                    return $positionCompare;
                }

                $pointsCompare = (int) $b['tournament_points'] <=> (int) $a['tournament_points'];
                if ($pointsCompare !== 0) {
                    return $pointsCompare;
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
            }
        );

        return array_values($selectedRows);
    }

    private function nextPowerOfTwo(int $value): int
    {
        $power = 1;
        while ($power < $value) {
            $power *= 2;
        }

        return $power;
    }

    private function knockoutRoundNameByParticipantCount(int $participants): string
    {
        return match ($participants) {
            2 => 'Final',
            4 => 'Semifinal',
            8 => 'Quarterfinal',
            16 => 'Round of 16',
            default => 'Round of ' . $participants,
        };
    }

    /**
     * @param list<array<string, int|string>> $seededTeams
     * @return list<array{
     *     round_name: string,
     *     bracket_position: int,
     *     team_a_id: int|null,
     *     team_b_id: int|null,
     *     team_a_source: string|null,
     *     team_b_source: string|null,
     *     status: string,
     *     court_number?: int|null,
     *     planned_start?: string|null
     * }>
     */
    private function buildKnockoutBracketMatches(array $seededTeams, int $advancingTeamsCount): array
    {
        $bracketSize = $this->nextPowerOfTwo($advancingTeamsCount);
        $byeCount = $bracketSize - $advancingTeamsCount;
        $seededParticipants = [];
        foreach ($seededTeams as $index => $team) {
            $teamId = (int) ($team['team_id'] ?? 0);
            if ($teamId <= 0) {
                continue;
            }

            $seededParticipants[] = [
                'seed' => $index + 1,
                'rank' => $index + 1,
                'team_id' => $teamId,
                'source' => null,
            ];
        }

        if (count($seededParticipants) !== $advancingTeamsCount) {
            return [];
        }

        $matches = [];
        $bracketPosition = 1;
        $roundIndex = 1;
        $participantsForMainRound = [];

        if ($byeCount > 0) {
            for ($i = 0; $i < $byeCount; $i++) {
                $byeParticipant = $seededParticipants[$i] ?? null;
                if (!is_array($byeParticipant)) {
                    return [];
                }

                $participantsForMainRound[] = [
                    'rank' => (int) ($byeParticipant['rank'] ?? 0),
                    'team_id' => (int) ($byeParticipant['team_id'] ?? 0),
                    'source' => null,
                ];
            }

            $playInParticipants = array_slice($seededParticipants, $byeCount);
            $playInPairings = $this->pairHighLow($playInParticipants);
            if (count($playInPairings) < 1) {
                return [];
            }

            foreach ($playInPairings as $index => $pairing) {
                $teamAId = (int) ($pairing['a']['team_id'] ?? 0);
                $teamBId = (int) ($pairing['b']['team_id'] ?? 0);
                if ($teamAId <= 0 || $teamBId <= 0) {
                    return [];
                }

                $sourceCode = 'winner:r' . $roundIndex . ':m' . ($index + 1);
                $matches[] = [
                    'round_name' => 'Play-in',
                    'bracket_position' => $bracketPosition,
                    'team_a_id' => $teamAId,
                    'team_b_id' => $teamBId,
                    'team_a_source' => null,
                    'team_b_source' => null,
                    'status' => 'scheduled',
                ];
                $bracketPosition++;

                $participantsForMainRound[] = [
                    'rank' => min((int) ($pairing['a']['seed'] ?? 0), (int) ($pairing['b']['seed'] ?? 0)),
                    'team_id' => null,
                    'source' => $sourceCode,
                ];
            }

            usort(
                $participantsForMainRound,
                static fn (array $a, array $b): int => (int) ($a['rank'] ?? 0) <=> (int) ($b['rank'] ?? 0)
            );

            $roundIndex++;
        } else {
            foreach ($seededParticipants as $seededParticipant) {
                $participantsForMainRound[] = [
                    'rank' => (int) ($seededParticipant['rank'] ?? 0),
                    'team_id' => (int) ($seededParticipant['team_id'] ?? 0),
                    'source' => null,
                ];
            }
        }

        $mainPairings = $this->pairStandardBracket($participantsForMainRound);
        if (count($mainPairings) < 1) {
            return [];
        }

        $nextRoundParticipants = [];
        foreach ($mainPairings as $index => $pairing) {
            $teamAId = (int) ($pairing['a']['team_id'] ?? 0);
            $teamBId = (int) ($pairing['b']['team_id'] ?? 0);
            $sourceA = is_string($pairing['a']['source'] ?? null) ? (string) $pairing['a']['source'] : null;
            $sourceB = is_string($pairing['b']['source'] ?? null) ? (string) $pairing['b']['source'] : null;
            $sourceCode = 'winner:r' . $roundIndex . ':m' . ($index + 1);

            $matches[] = [
                'round_name' => $this->knockoutRoundNameByParticipantCount(count($participantsForMainRound)),
                'bracket_position' => $bracketPosition,
                'team_a_id' => $teamAId > 0 ? $teamAId : null,
                'team_b_id' => $teamBId > 0 ? $teamBId : null,
                'team_a_source' => $teamAId > 0 ? null : $sourceA,
                'team_b_source' => $teamBId > 0 ? null : $sourceB,
                'status' => $teamAId > 0 && $teamBId > 0 ? 'scheduled' : 'pending',
            ];
            $bracketPosition++;

            $nextRoundParticipants[] = [
                'team_id' => null,
                'source' => $sourceCode,
            ];
        }

        $roundIndex++;
        while (count($nextRoundParticipants) > 1) {
            if (count($nextRoundParticipants) % 2 !== 0) {
                return [];
            }

            $currentRoundParticipants = $nextRoundParticipants;
            $nextRoundParticipants = [];
            $matchNumber = 1;
            for ($i = 0; $i < count($currentRoundParticipants); $i += 2) {
                $participantA = $currentRoundParticipants[$i];
                $participantB = $currentRoundParticipants[$i + 1];
                $teamAId = (int) ($participantA['team_id'] ?? 0);
                $teamBId = (int) ($participantB['team_id'] ?? 0);
                $sourceA = is_string($participantA['source'] ?? null) ? (string) $participantA['source'] : null;
                $sourceB = is_string($participantB['source'] ?? null) ? (string) $participantB['source'] : null;

                $matches[] = [
                    'round_name' => $this->knockoutRoundNameByParticipantCount(count($currentRoundParticipants)),
                    'bracket_position' => $bracketPosition,
                    'team_a_id' => $teamAId > 0 ? $teamAId : null,
                    'team_b_id' => $teamBId > 0 ? $teamBId : null,
                    'team_a_source' => $teamAId > 0 ? null : $sourceA,
                    'team_b_source' => $teamBId > 0 ? null : $sourceB,
                    'status' => $teamAId > 0 && $teamBId > 0 ? 'scheduled' : 'pending',
                ];
                $bracketPosition++;

                $nextRoundParticipants[] = [
                    'team_id' => null,
                    'source' => 'winner:r' . $roundIndex . ':m' . $matchNumber,
                ];
                $matchNumber++;
            }

            $roundIndex++;
        }

        return $matches;
    }

    /**
     * @param list<array{
     *     round_name: string,
     *     bracket_position: int,
     *     team_a_id: int|null,
     *     team_b_id: int|null,
     *     team_a_source: string|null,
     *     team_b_source: string|null,
     *     status: string,
     *     court_number?: int|null,
     *     planned_start?: string|null
     * }> $matches
     * @return list<array{
     *     round_name: string,
     *     bracket_position: int,
     *     team_a_id: int|null,
     *     team_b_id: int|null,
     *     team_a_source: string|null,
     *     team_b_source: string|null,
     *     status: string,
     *     court_number?: int|null,
     *     planned_start?: string|null
     * }>
     */
    private function applyEstimatedKnockoutSchedule(array $matches, int $courtCount, int $matchDurationMinutes): array
    {
        $courtCount = max(1, $courtCount);
        $matchDurationMinutes = max(1, $matchDurationMinutes);
        $firstStart = (new DateTimeImmutable('now'))->add(new DateInterval('PT10M'));

        $roundIndexes = [];
        $roundOrder = [];
        foreach ($matches as $index => $match) {
            $roundName = (string) ($match['round_name'] ?? ('round-' . $index));
            if (!isset($roundIndexes[$roundName])) {
                $roundIndexes[$roundName] = [];
                $roundOrder[] = $roundName;
            }
            $roundIndexes[$roundName][] = $index;
        }

        $waveOffset = 0;
        foreach ($roundOrder as $roundName) {
            $indexes = $roundIndexes[$roundName] ?? [];
            foreach ($indexes as $slot => $matchIndex) {
                $courtNumber = ($slot % $courtCount) + 1;
                $wave = $waveOffset + intdiv($slot, $courtCount);
                $minutes = $wave * $matchDurationMinutes;
                $plannedStart = $firstStart->add(new DateInterval('PT' . $minutes . 'M'));
                $matches[$matchIndex]['court_number'] = $courtNumber;
                $matches[$matchIndex]['planned_start'] = $plannedStart->format('Y-m-d H:i:s');
            }

            $waveOffset += max(1, (int) ceil(count($indexes) / $courtCount));
        }

        return $matches;
    }

    /**
     * @param list<array<string, mixed>> $participants
     * @return list<array{a: array<string, mixed>, b: array<string, mixed>}>
     */
    private function pairHighLow(array $participants): array
    {
        $pairs = [];
        $left = 0;
        $right = count($participants) - 1;
        while ($left < $right) {
            $pairs[] = [
                'a' => $participants[$left],
                'b' => $participants[$right],
            ];
            $left++;
            $right--;
        }

        return $pairs;
    }

    /**
     * Orders a power-of-two field so the best two ranks occupy opposite
     * halves and cannot meet before the final.
     *
     * @param list<array<string, mixed>> $participants
     * @return list<array{a: array<string, mixed>, b: array<string, mixed>}>
     */
    private function pairStandardBracket(array $participants): array
    {
        $participantCount = count($participants);
        if (
            $participantCount < 2
            || ($participantCount & ($participantCount - 1)) !== 0
        ) {
            return [];
        }

        $participantsByRank = [];
        foreach ($participants as $participant) {
            $rank = (int) ($participant['rank'] ?? 0);
            if (
                $rank < 1
                || $rank > $participantCount
                || isset($participantsByRank[$rank])
            ) {
                return [];
            }

            $participantsByRank[$rank] = $participant;
        }
        if (count($participantsByRank) !== $participantCount) {
            return [];
        }

        $seedOrder = [1, 2];
        $currentSize = 2;
        while ($currentSize < $participantCount) {
            $nextSize = $currentSize * 2;
            $mirrorSum = $nextSize + 1;
            $nextOrder = [];
            foreach ($seedOrder as $index => $seed) {
                $mirror = $mirrorSum - $seed;
                if ($index % 2 === 0) {
                    $nextOrder[] = $seed;
                    $nextOrder[] = $mirror;
                } else {
                    $nextOrder[] = $mirror;
                    $nextOrder[] = $seed;
                }
            }

            $seedOrder = $nextOrder;
            $currentSize = $nextSize;
        }

        $pairs = [];
        for ($index = 0; $index < count($seedOrder); $index += 2) {
            $seedA = $seedOrder[$index] ?? 0;
            $seedB = $seedOrder[$index + 1] ?? 0;
            if (!isset($participantsByRank[$seedA], $participantsByRank[$seedB])) {
                return [];
            }

            $pairs[] = [
                'a' => $participantsByRank[$seedA],
                'b' => $participantsByRank[$seedB],
            ];
        }

        return $pairs;
    }

    /**
     * @param list<array<string, mixed>> $knockoutMatches
     * @return array{
     *     source_by_match_id: array<int, string>,
     *     children_by_source: array<string, list<array{match_id: int, slot: string}>>
     * }
     */
    private function buildKnockoutSourceGraph(array $knockoutMatches): array
    {
        $sourceByMatchId = [];
        $childrenBySource = [];

        $roundIndex = 0;
        $currentRoundName = '';
        $matchNumberInRound = 0;
        foreach ($knockoutMatches as $match) {
            $roundName = trim((string) ($match['round_name'] ?? ''));
            if ($roundName !== $currentRoundName) {
                $currentRoundName = $roundName;
                $roundIndex++;
                $matchNumberInRound = 0;
            }

            $matchNumberInRound++;
            $matchId = (int) ($match['id'] ?? 0);
            if ($matchId <= 0) {
                continue;
            }

            $sourceByMatchId[$matchId] = 'winner:r' . $roundIndex . ':m' . $matchNumberInRound;
        }

        foreach ($knockoutMatches as $match) {
            $matchId = (int) ($match['id'] ?? 0);
            if ($matchId <= 0) {
                continue;
            }

            $teamASource = trim((string) ($match['team_a_source'] ?? ''));
            if ($teamASource !== '') {
                if (!isset($childrenBySource[$teamASource])) {
                    $childrenBySource[$teamASource] = [];
                }
                $childrenBySource[$teamASource][] = [
                    'match_id' => $matchId,
                    'slot' => 'a',
                ];
            }

            $teamBSource = trim((string) ($match['team_b_source'] ?? ''));
            if ($teamBSource !== '') {
                if (!isset($childrenBySource[$teamBSource])) {
                    $childrenBySource[$teamBSource] = [];
                }
                $childrenBySource[$teamBSource][] = [
                    'match_id' => $matchId,
                    'slot' => 'b',
                ];
            }
        }

        return [
            'source_by_match_id' => $sourceByMatchId,
            'children_by_source' => $childrenBySource,
        ];
    }

    /**
     * @param array<int, string> $sourceByMatchId
     * @param array<string, list<array{match_id: int, slot: string}>> $childrenBySource
     * @return array<int, bool>
     */
    private function descendantMatchIdsForSource(int $matchId, array $sourceByMatchId, array $childrenBySource): array
    {
        $rootSource = $sourceByMatchId[$matchId] ?? '';
        if (!is_string($rootSource) || $rootSource === '') {
            return [];
        }

        $queue = [$rootSource];
        $visitedSources = [];
        $descendantIds = [];
        while (count($queue) > 0) {
            $source = array_shift($queue);
            if (!is_string($source) || $source === '' || isset($visitedSources[$source])) {
                continue;
            }

            $visitedSources[$source] = true;
            $children = $childrenBySource[$source] ?? [];
            foreach ($children as $child) {
                $childMatchId = (int) ($child['match_id'] ?? 0);
                if ($childMatchId <= 0) {
                    continue;
                }

                $descendantIds[$childMatchId] = true;
                $nextSource = $sourceByMatchId[$childMatchId] ?? '';
                if (is_string($nextSource) && $nextSource !== '' && !isset($visitedSources[$nextSource])) {
                    $queue[] = $nextSource;
                }
            }
        }

        return $descendantIds;
    }

    /**
     * @param array<string, mixed> $match
     */
    private function isDrawMatchForStandings(array $match, string $matchMode): bool
    {
        $setsA = (int) ($match['sets_summary_a'] ?? 0);
        $setsB = (int) ($match['sets_summary_b'] ?? 0);

        return $matchMode === 'fixed_2_sets' && $setsA === $setsB;
    }

    /**
     * @param array<string, mixed> $match
     */
    private function winnerTeamIdForStandings(array $match, string $matchMode): int
    {
        if ($this->isDrawMatchForStandings($match, $matchMode)) {
            return 0;
        }

        $setsA = (int) ($match['sets_summary_a'] ?? 0);
        $setsB = (int) ($match['sets_summary_b'] ?? 0);
        if ($setsA > $setsB) {
            return (int) ($match['team_a_id'] ?? 0);
        }
        if ($setsB > $setsA) {
            return (int) ($match['team_b_id'] ?? 0);
        }

        return (int) ($match['winner_team_id'] ?? 0);
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
        $groupIds = $this->groupIdSet($groups);
        $groupIdsList = array_keys($groupIds);
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
            if ($teamId <= 0 || $groupId <= 0 || !isset($groupIds[$groupId])) {
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
            if ($this->isDrawMatchForStandings($match, $matchMode)) {
                $groupStandings[$groupId][$teamAId]['draws']++;
                $groupStandings[$groupId][$teamBId]['draws']++;
                $groupStandings[$groupId][$teamAId]['tournament_points']++;
                $groupStandings[$groupId][$teamBId]['tournament_points']++;
                $headToHeadByGroup[$groupId][$pairKey] = 0;
                continue;
            }

            $winnerTeamId = $this->winnerTeamIdForStandings($match, $matchMode);
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
            usort(
                $rows,
                static function (array $a, array $b): int {
                    return ((int) $b['tournament_points'] <=> (int) $a['tournament_points'])
                        ?: ((int) $a['team_id'] <=> (int) $b['team_id']);
                }
            );
            $rows = $this->resolveStandingsTieClusters($rows, $headToHead);

            foreach ($rows as $index => &$row) {
                $row['position'] = $index + 1;
            }
            unset($row);

            $sortedByGroup[$groupId] = $rows;
        }

        return $sortedByGroup;
    }

    /**
     * @param list<array<string, int|string>> $rows
     * @param array<string, int> $headToHead
     * @return list<array<string, int|string>>
     */
    private function resolveStandingsTieClusters(array $rows, array $headToHead): array
    {
        $metricComparator = static function (array $a, array $b): int {
            return ((int) $b['point_diff'] <=> (int) $a['point_diff'])
                ?: ((int) $b['points_for'] <=> (int) $a['points_for'])
                ?: ((int) $a['team_id'] <=> (int) $b['team_id']);
        };

        $resolved = [];
        $offset = 0;
        while ($offset < count($rows)) {
            $points = (int) ($rows[$offset]['tournament_points'] ?? 0);
            $cluster = [];
            while (
                $offset < count($rows)
                && (int) ($rows[$offset]['tournament_points'] ?? 0) === $points
            ) {
                $cluster[] = $rows[$offset];
                $offset++;
            }

            if (count($cluster) === 2) {
                usort($cluster, static function (array $a, array $b) use ($headToHead, $metricComparator): int {
                    $idA = (int) ($a['team_id'] ?? 0);
                    $idB = (int) ($b['team_id'] ?? 0);
                    $pairKey = $idA < $idB ? ($idA . ':' . $idB) : ($idB . ':' . $idA);
                    $headToHeadValue = (int) ($headToHead[$pairKey] ?? 0);
                    if ($headToHeadValue !== 0) {
                        if ($idA < $idB) {
                            return $headToHeadValue === 1 ? -1 : 1;
                        }

                        return $headToHeadValue === -1 ? -1 : 1;
                    }

                    return $metricComparator($a, $b);
                });
            } elseif (count($cluster) > 1) {
                usort($cluster, $metricComparator);
            }

            foreach ($cluster as $row) {
                $resolved[] = $row;
            }
        }

        return $resolved;
    }

    /**
     * @return array{
     *     sets: list<array{set_number: int, score_a: int, score_b: int}>,
     *     sets_summary_a: int,
     *     sets_summary_b: int,
     *     winner_team_id: int|null
     * }|null
     */
    private function validateScoreInput(
        string $matchMode,
        int $teamAId,
        int $teamBId,
        bool $isKnockout
    ): ?array
    {
        if ($teamAId <= 0 || $teamBId <= 0) {
            $this->setFlash('error', 'Match teams are missing.');
            return null;
        }

        if (!in_array($matchMode, self::MATCH_MODES, true)) {
            $this->setFlash('error', 'Unsupported match mode.');
            return null;
        }

        $rawSets = [];
        for ($setNumber = 1; $setNumber <= 3; $setNumber++) {
            $scoreA = $this->readSetScore($setNumber, 'a');
            $scoreB = $this->readSetScore($setNumber, 'b');
            if ($scoreA === false || $scoreB === false) {
                $this->setFlash('error', sprintf('Set %d scores must be whole numbers between 0 and 99.', $setNumber));
                return null;
            }
            if ($scoreA === null && $scoreB === null) {
                continue;
            }
            if ($scoreA === null || $scoreB === null) {
                $this->setFlash('error', sprintf('Set %d must have both scores filled.', $setNumber));
                return null;
            }
            if ($scoreA === $scoreB) {
                $this->setFlash('error', sprintf('Set %d cannot end in a tie.', $setNumber));
                return null;
            }

            $rawSets[] = [
                'set_number' => $setNumber,
                'score_a' => $scoreA,
                'score_b' => $scoreB,
            ];
        }

        if ($matchMode === 'fixed_2_sets') {
            if (count($rawSets) !== 2 || (int) ($rawSets[0]['set_number'] ?? 0) !== 1 || (int) ($rawSets[1]['set_number'] ?? 0) !== 2) {
                $this->setFlash('error', 'Fixed 2 sets mode requires exactly set 1 and set 2.');
                return null;
            }

            $setWinsA = 0;
            $setWinsB = 0;
            $totalPointsA = 0;
            $totalPointsB = 0;
            foreach ($rawSets as $set) {
                $scoreA = (int) $set['score_a'];
                $scoreB = (int) $set['score_b'];
                $totalPointsA += $scoreA;
                $totalPointsB += $scoreB;
                if ($scoreA > $scoreB) {
                    $setWinsA++;
                } else {
                    $setWinsB++;
                }
            }

            $winnerTeamId = null;
            if ($setWinsA > $setWinsB) {
                $winnerTeamId = $teamAId;
            } elseif ($setWinsB > $setWinsA) {
                $winnerTeamId = $teamBId;
            } elseif ($isKnockout) {
                if ($totalPointsA === $totalPointsB) {
                    $this->setFlash('error', 'Fixed 2 sets knockout needs a total-points winner. Totals are tied.');
                    return null;
                }

                $winnerTeamId = $totalPointsA > $totalPointsB ? $teamAId : $teamBId;
            }

            return [
                'sets' => $rawSets,
                'sets_summary_a' => $setWinsA,
                'sets_summary_b' => $setWinsB,
                'winner_team_id' => $winnerTeamId,
            ];
        }

        if (count($rawSets) < 2 || count($rawSets) > 3) {
            $this->setFlash('error', 'Best of 3 mode requires 2 or 3 sets.');
            return null;
        }
        if ((int) ($rawSets[0]['set_number'] ?? 0) !== 1 || (int) ($rawSets[1]['set_number'] ?? 0) !== 2) {
            $this->setFlash('error', 'Best of 3 mode requires set 1 and set 2.');
            return null;
        }
        if (count($rawSets) === 3 && (int) ($rawSets[2]['set_number'] ?? 0) !== 3) {
            $this->setFlash('error', 'Only set 3 can be the optional decider.');
            return null;
        }

        $setWinsA = 0;
        $setWinsB = 0;
        foreach ($rawSets as $index => $set) {
            $scoreA = (int) $set['score_a'];
            $scoreB = (int) $set['score_b'];
            if ($scoreA > $scoreB) {
                $setWinsA++;
            } else {
                $setWinsB++;
            }

            $isLastEnteredSet = $index === count($rawSets) - 1;
            if (($setWinsA >= 2 || $setWinsB >= 2) && !$isLastEnteredSet) {
                $this->setFlash('error', 'Best of 3 match cannot continue after a team reaches 2 set wins.');
                return null;
            }
        }

        if (!($setWinsA === 2 || $setWinsB === 2)) {
            $this->setFlash('error', 'Best of 3 match must end 2:0 or 2:1.');
            return null;
        }

        return [
            'sets' => $rawSets,
            'sets_summary_a' => $setWinsA,
            'sets_summary_b' => $setWinsB,
            'winner_team_id' => $setWinsA > $setWinsB ? $teamAId : $teamBId,
        ];
    }

    private function readSetScore(int $setNumber, string $side): int|false|null
    {
        $value = $this->requestPostString('set_' . $setNumber . '_' . $side);
        if ($value === '') {
            return null;
        }
        if (!ctype_digit($value)) {
            return false;
        }

        $score = (int) $value;
        return $score >= 0 && $score <= 99 ? $score : false;
    }

    /**
     * @param list<array<string, mixed>> $groups
     * @param list<array<string, mixed>> $teams
     * @return array{
     *     total_teams: int,
     *     group_count: int,
     *     unassigned_count: int,
     *     teams_per_group: array<int, int>,
     *     grouped_teams: array<int, list<array<string, mixed>>>,
     *     unassigned_teams: list<array<string, mixed>>
     * }
     */
    private function buildGroupAssignmentViewData(array $groups, array $teams): array
    {
        $groupedTeams = [];
        $teamsPerGroup = [];

        foreach ($groups as $group) {
            $groupId = (int) ($group['id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }

            $groupedTeams[$groupId] = [];
            $teamsPerGroup[$groupId] = 0;
        }

        $unassignedTeams = [];
        foreach ($teams as $team) {
            $groupId = $team['group_id'] ?? null;
            $groupId = is_numeric($groupId) ? (int) $groupId : null;

            if ($groupId !== null && isset($groupedTeams[$groupId])) {
                $groupedTeams[$groupId][] = $team;
                $teamsPerGroup[$groupId]++;
                continue;
            }

            $unassignedTeams[] = $team;
        }

        return [
            'total_teams' => count($teams),
            'group_count' => count($groups),
            'unassigned_count' => count($unassignedTeams),
            'teams_per_group' => $teamsPerGroup,
            'grouped_teams' => $groupedTeams,
            'unassigned_teams' => $unassignedTeams,
        ];
    }

    /**
     * @param list<array<string, mixed>> $groups
     * @return array<int, bool>
     */
    private function groupIdSet(array $groups): array
    {
        $set = [];
        foreach ($groups as $group) {
            $groupId = (int) ($group['id'] ?? 0);
            if ($groupId > 0) {
                $set[$groupId] = true;
            }
        }

        return $set;
    }

    private function requestPostStrictInt(string $key, int $minimum, int $maximum): ?int
    {
        $value = $this->requestPostString($key);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        $normalized = ltrim($value, '0');
        if ($normalized === '') {
            $normalized = '0';
        }
        $maximumString = (string) $maximum;
        if (
            strlen($normalized) > strlen($maximumString)
            || (strlen($normalized) === strlen($maximumString) && strcmp($normalized, $maximumString) > 0)
        ) {
            return null;
        }

        $integer = (int) $normalized;
        return $integer >= $minimum && $integer <= $maximum ? $integer : null;
    }

    private function isValidCalendarDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
            return false;
        }

        $year = (int) ($matches[1] ?? 0);
        $month = (int) ($matches[2] ?? 0);
        $day = (int) ($matches[3] ?? 0);
        return $year >= 1000 && $year <= 9999 && checkdate($month, $day, $year);
    }

    private function stringLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function normalizeSection(string $section): string
    {
        return in_array($section, self::ADMIN_SECTIONS, true) ? $section : 'tournament';
    }

    private function sectionFromRoute(): ?string
    {
        $section = $this->requestRouteString('section');
        if ($section === '' || !in_array($section, self::ADMIN_SECTIONS, true)) {
            return null;
        }

        return $section;
    }

    private function sectionFromPost(): string
    {
        return $this->normalizeSection($this->requestPostString('return_section'));
    }

    private function sectionPathSuffix(string $section): string
    {
        return $section === 'tournament' ? '' : '/' . $section;
    }

    private function superadminSectionRedirectPath(int $tournamentId, ?string $section = null): string
    {
        $targetSection = $section ?? $this->sectionFromPost();
        return '/admin/tournament' . $this->sectionPathSuffix($targetSection) . '?id=' . $tournamentId;
    }

    private function tournamentAdminSectionRedirectPath(string $slug, ?string $section = null): string
    {
        $targetSection = $section ?? $this->sectionFromPost();
        return '/tournament/' . $slug . '/admin' . $this->sectionPathSuffix($targetSection);
    }
}
