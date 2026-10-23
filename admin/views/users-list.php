<?php

/**
 * Admin view: the list of accounts, with search and filters. Administrators only, so it also shows login names.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

use LumoraFiction\Core\Admin\ColumnSort;
use LumoraFiction\Core\Admin\SortHeading;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\User;
use LumoraFiction\Models\UserRole;
use LumoraFiction\Models\UserStatus;

/** @var list<User> $people */
/** @var ColumnSort $sort */
/** @var array{search: string, role: string, status: string} $filters */
/** @var list<UserRole> $roles */
/** @var list<UserStatus> $statuses */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var string $listUrl */
/** @var string $personUrl */
/** @var int $selfId */

$kept = array_filter(['q' => $filters['search'], 'role' => $filters['role'], 'status' => $filters['status']], static fn (string $value): bool => $value !== '');
$query = static function (int $number) use ($kept, $sort): string {
    $parts = $kept + $sort->query();

    if ($number > 1) {
        $parts['page'] = (string) $number;
    }

    return $parts === [] ? '' : '?' . http_build_query($parts);
};

$filtered = $filters['search'] !== '' || $filters['role'] !== '' || $filters['status'] !== '';
$statusClass = static fn (UserStatus $status): string => match ($status) {
    UserStatus::Active => ' lf-status--ok',
    UserStatus::Pending => ' lf-status--warning',
    UserStatus::Suspended => ' lf-status--error',
};
?>
<form method="get" action="<?= Html::e($listUrl) ?>" class="lf-form lf-filter" role="search">
    <div class="lf-field">
        <label for="q">Search names, login names and email addresses</label>
        <input type="text" id="q" name="q" value="<?= Html::e($filters['search']) ?>" maxlength="100">
    </div>
    <div class="lf-field">
        <label for="role">Role</label>
        <select id="role" name="role">
            <option value="">Any role</option>
            <?php foreach ($roles as $role): ?>
                <option value="<?= Html::e($role->value) ?>"<?= $filters['role'] === $role->value ? ' selected' : '' ?>><?= Html::e($role->label()) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="lf-field">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">Any status</option>
            <?php foreach ($statuses as $status): ?>
                <option value="<?= Html::e($status->value) ?>"<?= $filters['status'] === $status->value ? ' selected' : '' ?>><?= Html::e(ucfirst($status->value)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php foreach ($sort->query() as $name => $value): ?>
        <input type="hidden" name="<?= Html::e($name) ?>" value="<?= Html::e($value) ?>">
    <?php endforeach; ?>
    <p><button type="submit" class="lf-button lf-button--secondary">Filter</button></p>
</form>

<?php if ($people === []): ?>
    <p class="lf-hint"><?= $filtered ? 'No account matches.' : 'There are no accounts yet.' ?></p>
<?php else: ?>
    <p class="lf-hint"><?= number_format($total) ?> found.</p>

    <table class="lf-table lf-users-list">
        <caption class="lf-visually-hidden">Accounts</caption>
        <thead>
            <tr>
                <?= SortHeading::render($sort, 'name', 'Name', $listUrl, $kept) ?>
                <th scope="col">Login name</th>
                <?= SortHeading::render($sort, 'email', 'Email address', $listUrl, $kept) ?>
                <?= SortHeading::render($sort, 'role', 'Role', $listUrl, $kept) ?>
                <?= SortHeading::render($sort, 'status', 'Status', $listUrl, $kept) ?>
                <?= SortHeading::render($sort, 'joined', 'Joined', $listUrl, $kept) ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($people as $person): ?>
                <tr>
                    <th scope="row"><a href="<?= Html::e($personUrl . '/' . $person->id) ?>"><?= Html::e($person->displayName) ?></a><?= $person->id === $selfId ? ' <span class="lf-hint">(you)</span>' : '' ?></th>
                    <td><?= Html::e($person->loginName) ?></td>
                    <td><?= Html::e($person->email) ?></td>
                    <td><?= Html::e($person->role->label()) ?></td>
                    <td><span class="lf-status<?= Html::e($statusClass($person->status)) ?>"><?= Html::e(ucfirst($person->status->value)) ?></span></td>
                    <td><time datetime="<?= Html::e($person->createdAt->format('Y-m-d')) ?>"><?= Html::e($person->createdAt->format('Y-m-d')) ?></time></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <nav class="lf-pagination" aria-label="Pages">
            <?php if ($page > 1): ?><a href="<?= Html::e($listUrl . $query($page - 1)) ?>" rel="prev">Previous</a><?php endif; ?>
            <span>Page <?= (int) $page ?> of <?= (int) $pages ?></span>
            <?php if ($page < $pages): ?><a href="<?= Html::e($listUrl . $query($page + 1)) ?>" rel="next">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
