<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\TournamentModel;
use Throwable;

final class AdminDashboardController extends BaseController
{
    private const MATCH_MODES = ['fixed_2_sets', 'best_of_3'];
    private const MAX_TOURNAMENT_NAME_LENGTH = 150;
    private const MAX_LOCATION_LENGTH = 150;
    private const MAX_ADMIN_PASSWORD_BYTES = 72;
    private const MAX_GROUPS = 32;

    public function index(): void
    {
        $this->requireSuperadminAuth();

        $tournamentModel = new TournamentModel($this->db());
        $tournaments = $tournamentModel->all();
        $oldTournamentInput = $_SESSION['_old_tournament_create'] ?? [];
        unset($_SESSION['_old_tournament_create']);
        if (!is_array($oldTournamentInput)) {
            $oldTournamentInput = [];
        }

        $this->render('admin/dashboard', [
            'title' => 'Superadmin dashboard',
            'tournaments' => $tournaments,
            'matchModes' => self::MATCH_MODES,
            'oldTournamentInput' => $oldTournamentInput,
        ]);
    }

    public function createTournament(): void
    {
        $this->requireSuperadminAuth();

        $data = $this->collectTournamentInput(true);
        if ($data === null) {
            $_SESSION['_old_tournament_create'] = $this->safeCreateTournamentInput();
            $this->redirect('/admin/dashboard');
        }

        $tournamentModel = new TournamentModel($this->db());
        $data['slug'] = $tournamentModel->generateUniqueSlug((string) $data['name']);

        try {
            $tournamentId = $tournamentModel->create($data);
        } catch (Throwable) {
            $this->setFlash('error', 'Tournament could not be created.');
            $this->redirect('/admin/dashboard');
            return;
        }

        $this->setFlash('success', 'Tournament created.');
        $this->redirect('/admin/tournament?id=' . $tournamentId);
    }

    public function deleteTournament(): void
    {
        $this->requireSuperadminAuth();

        $tournamentId = $this->requestPostStrictInt('tournament_id', 1, PHP_INT_MAX);
        $expectedStateVersion = $this->requestPostStrictInt('state_version', 0, PHP_INT_MAX);
        if ($tournamentId === null || $expectedStateVersion === null) {
            $this->setFlash('error', 'Invalid tournament selected.');
            $this->redirect('/admin/dashboard');
        }

        $confirmation = $this->requestPostString('confirm_delete');
        if ($confirmation !== '1') {
            $this->setFlash('error', 'Deletion confirmation is required.');
            $this->redirect('/admin/dashboard');
        }

        $tournamentModel = new TournamentModel($this->db());
        try {
            $deleteResult = $tournamentModel->deleteById($tournamentId, $expectedStateVersion);
        } catch (Throwable) {
            $this->setFlash('error', 'Tournament could not be deleted.');
            $this->redirect('/admin/dashboard');
        }
        if (($deleteResult['status'] ?? '') === TournamentModel::UPDATE_STALE) {
            $this->setFlash('error', 'Tournament data changed in another session. Reload the dashboard before deleting it.');
            $this->redirect('/admin/dashboard');
        }
        if (($deleteResult['status'] ?? '') !== TournamentModel::UPDATE_UPDATED) {
            $this->setFlash('error', 'Tournament could not be deleted.');
            $this->redirect('/admin/dashboard');
        }
        $managedLogoPath = (string) ($deleteResult['managed_logo_path'] ?? '');

        $this->logSecurityEvent('tournament_deleted', [
            'tournament_id' => $tournamentId,
        ]);
        if (!$this->removeManagedPublicLogo($managedLogoPath)) {
            error_log(json_encode([
                'operational_event' => 'managed_logo_cleanup_failed',
                'occurred_at' => gmdate(\DateTimeInterface::ATOM),
                'tournament_id' => $tournamentId,
            ], JSON_UNESCAPED_SLASHES) ?: 'Managed tournament logo cleanup failed.');
        }

        $this->setFlash('success', 'Tournament deleted.');
        $this->redirect('/admin/dashboard');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function collectTournamentInput(bool $requirePassword): ?array
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

        if ($requirePassword && $adminPassword === '') {
            $this->setFlash('error', 'Tournament admin password is required.');
            return null;
        }

        if (
            $adminPassword !== ''
            && (strlen($adminPassword) < 8 || strlen($adminPassword) > self::MAX_ADMIN_PASSWORD_BYTES)
        ) {
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
     * Preserve non-sensitive values so a validation error does not erase the form.
     *
     * @return array<string, string>
     */
    private function safeCreateTournamentInput(): array
    {
        $safeFields = [
            'name',
            'event_date',
            'start_time',
            'location',
            'number_of_groups',
            'number_of_courts',
            'match_duration_minutes',
            'advancing_teams_count',
            'group_stage_mode',
            'knockout_mode',
        ];
        $result = [];
        foreach ($safeFields as $field) {
            $value = $_POST[$field] ?? '';
            if (is_string($value)) {
                $maximumLength = in_array($field, ['name', 'location'], true) ? 200 : 40;
                $result[$field] = function_exists('mb_substr')
                    ? mb_substr($value, 0, $maximumLength, 'UTF-8')
                    : substr($value, 0, $maximumLength);
            }
        }

        return $result;
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
}
