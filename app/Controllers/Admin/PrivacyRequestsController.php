<?php

/**
 * Maintenance › Privacy requests: look an account up by email, export its data, or delete it.
 *
 * @package LumoraFiction
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers\Admin;

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Admin\AdminView;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Models\UserStatus;
use LumoraFiction\Services\AccountDataExporter;
use LumoraFiction\Services\AccountDeletionException;
use LumoraFiction\Services\AccountDeletionService;
use LumoraFiction\Services\AccountRules;
use LumoraFiction\Services\AuditLogService;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\PrivacySettings;
use LumoraFiction\Services\UserService;

/**
 * Only people who may manage accounts reach this. The export is sent by POST, not as a link, so the address can never
 * be pasted, prefetched or kept in a history and bring a stranger's data back. Every export and deletion is audited by
 * account number: the email address that was searched for is not written to the log.
 */
final class PrivacyRequestsController extends AdminController
{
    private const PATH = 'maintenance/privacy';

    private const TOKEN = 'admin_privacy';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly UserService $users,
        private readonly AccountDataExporter $exporter,
        private readonly AccountDeletionService $deletion,
        private readonly PrivacySettings $settings,
        private readonly AuditLogService $audit,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function index(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageUsers, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $email = trim($request->queryValue('email') ?? '');
        $found = null;
        $message = '';

        if ($email !== '') {
            $found = mb_strlen($email, 'UTF-8') <= 191 ? $this->users->findByEmail(AccountRules::normalizeEmail($email)) : null;
            $message = $found === null || $found->status === UserStatus::Deleted ? 'No account has that email address.' : '';
            $found = $message === '' ? $found : null;
        }

        return $this->page($request, $user, $email, $found, $message);
    }

    /**
     * @param array<string, string> $params
     */
    public function export(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageUsers, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $subject = $this->subject($request->input('id'));

        if (!$this->validToken($request, self::TOKEN) || $subject === null) {
            $this->flash->add('error', $subject === null ? 'That account is not available.' : 'Your session expired, so nothing was exported. Please look the account up again.');

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        $this->audit->record($user->id, 'privacy.export', 'user', (string) $subject->id, [], $request->ip());
        $json = json_encode($this->exporter->export($subject), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // The file name carries the account number, never the name or address, and the response must not be kept by a cache.
        return Response::download($json, 'account-data-' . $subject->id . '.json', 'application/json')->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @param array<string, string> $params
     */
    public function showDelete(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageUsers, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $subject = $this->subject($params['id'] ?? '');

        if ($subject === null) {
            $this->flash->add('error', 'That account is not available.');

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        return $this->deletePage($request, $user, $subject, [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageUsers, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $subject = $this->subject($params['id'] ?? '');

        if ($subject === null) {
            $this->flash->add('error', 'That account is not available.');

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->deletePage($request, $user, $subject, ['form' => 'Your session expired, so nothing was deleted. Please check the page and try again.'], 400);
        }

        // Typing the display name back is the only thing that stops a mistaken click from destroying an account.
        if (!hash_equals($subject->displayName, trim($request->input('confirm') ?? ''))) {
            return $this->deletePage($request, $user, $subject, ['confirm' => 'That is not the display name of this account. Nothing was deleted.'], 422);
        }

        $keep = null;

        // The administrator decides per request; the site setting is only the preselected answer.
        if ($this->deletion->storyCount($subject) > 0) {
            $choice = $request->input('stories');

            if ($choice !== 'keep' && $choice !== 'delete') {
                return $this->deletePage($request, $user, $subject, ['stories' => 'Choose what happens to their stories.'], 422);
            }

            $keep = $choice === 'keep';
        }

        try {
            $this->deletion->delete($user, $subject, $request->ip(), $keep);
        } catch (AccountDeletionException $exception) {
            return $this->deletePage($request, $user, $subject, ['form' => $exception->getMessage()], 422);
        }

        $this->flash->add('success', 'The account was deleted.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /** The answer to remember when the page is shown again after a problem, or the site setting's on the first visit. */
    private function postedChoice(Request $request): string
    {
        $posted = $request->input('stories');

        return is_string($posted) && ($posted === 'keep' || $posted === 'delete') ? $posted : ($this->settings->keepsStories() ? 'keep' : 'delete');
    }

    private function subject(?string $id): ?User
    {
        if ($id === null || preg_match('/^[1-9][0-9]{0,9}\z/', $id) !== 1) {
            return null;
        }

        $subject = $this->users->findById((int) $id);

        return $subject === null || $subject->status === UserStatus::Deleted ? null : $subject;
    }

    private function page(Request $request, User $user, string $email, ?User $found, string $message): Response
    {
        return $this->view->render('privacy-requests', [
            'email' => $email,
            'found' => $found,
            'message' => $message,
            'csrfToken' => Csrf::token(self::TOKEN),
            'searchUrl' => AdminUrl::to(self::PATH),
            'exportUrl' => AdminUrl::to(self::PATH . '/export'),
            'deleteUrl' => $found === null ? '' : AdminUrl::to(self::PATH . '/' . $found->id . '/delete'),
            'settingsUrl' => AdminUrl::to('settings/privacy'),
        ], $user, 'Privacy requests', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $errors
     */
    private function deletePage(Request $request, User $user, User $subject, array $errors, int $status): Response
    {
        return $this->view->render('privacy-delete', [
            'person' => $subject,
            'errors' => $errors,
            'keepsStories' => $this->settings->keepsStories(),
            'storyCount' => $this->deletion->storyCount($subject),
            'chosen' => $this->postedChoice($request),
            'neutralName' => $this->settings->neutralName(),
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH . '/' . $subject->id . '/delete'),
            'backUrl' => AdminUrl::to(self::PATH),
            'settingsUrl' => AdminUrl::to('settings/privacy'),
        ], $user, 'Delete account', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
