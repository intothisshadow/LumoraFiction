<?php

/**
 * Maintenance › Security events.
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

use LumoraFiction\Helpers\Html;

/** @var list<array{event: string, label: string, ip: string, detail: string, at: string}> $events */
/** @var array<string, string> $labels */
/** @var string $event */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var int $retentionDays */
/** @var string $listUrl */
/** @var string $settingsUrl */

$query = static function (int $number) use ($event): string {
    $parts = array_filter(['event' => $event], static fn (string $value): bool => $value !== '');

    if ($number > 1) {
        $parts['page'] = (string) $number;
    }

    return $parts === [] ? '' : '?' . http_build_query($parts);
};
?>
<p class="lf-lead">What the protections blocked: sign-ins that were locked, forms that looked automated, and registrations that were refused. Entries are kept for <?= (int) $retentionDays ?> days; change this under <a href="<?= Html::e($settingsUrl) ?>">Settings &rsaquo; Spam protection</a>. Nothing a visitor typed is recorded.</p>

<form method="get" action="<?= Html::e($listUrl) ?>" class="lf-form lf-filter">
    <div class="lf-field">
        <label for="event">Kind of event</label>
        <select id="event" name="event">
            <option value="">Every kind</option>
            <?php foreach ($labels as $name => $label): ?>
                <option value="<?= Html::e($name) ?>"<?= $event === $name ? ' selected' : '' ?>><?= Html::e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <p><button type="submit" class="lf-button lf-button--secondary">Filter</button></p>
</form>

<?php if ($events === []): ?>
    <p class="lf-hint"><?= $event !== '' ? 'Nothing of that kind has been recorded.' : 'Nothing has been blocked yet.' ?></p>
<?php else: ?>
    <p class="lf-hint"><?= number_format($total) ?> found.</p>

    <table class="lf-table lf-events-list">
        <caption class="lf-visually-hidden">Security events, newest first</caption>
        <thead>
            <tr>
                <th scope="col">When</th>
                <th scope="col">What happened</th>
                <th scope="col">Address</th>
                <th scope="col">Note</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($events as $row): ?>
                <tr>
                    <th scope="row"><time datetime="<?= Html::e(str_replace(' ', 'T', $row['at'])) ?>"><?= Html::e($row['at']) ?></time></th>
                    <td><?= Html::e($row['label']) ?></td>
                    <td><?= Html::e($row['ip']) ?></td>
                    <td><?= Html::e($row['detail']) ?></td>
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
