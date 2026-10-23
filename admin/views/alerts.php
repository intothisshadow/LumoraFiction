<?php

/**
 * Admin view: alerts about the site itself, grouped by severity, with a switch for emailing critical ones.
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

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\AlertSeverity;
use LumoraFiction\Models\SiteAlert;

/** @var array<string, list<SiteAlert>> $groups alerts by severity name, every severity present */
/** @var bool $emailsCritical */
/** @var string $csrfToken */
/** @var string $listUrl */
/** @var string $dismissUrl */
/** @var string $emailUrl */
/** @var int $retentionDays */

$total = array_sum(array_map('count', $groups));
$ago = static function (DateTimeImmutable $when): string {
    $seconds = max(0, time() - $when->getTimestamp());

    return match (true) {
        $seconds < 90 => 'just now',
        $seconds < 5400 => (int) round($seconds / 60) . ' minutes ago',
        $seconds < 129600 => (int) round($seconds / 3600) . ' hours ago',
        default => (int) round($seconds / 86400) . ' days ago',
    };
};
?>
<p class="lf-lead">Things about the site itself that need an Administrator&rsquo;s attention. Editors and Authors do not see this page.</p>

<?php if ($total === 0): ?>
    <p class="lf-alerts__none">Nothing needs attention right now.</p>
<?php endif; ?>

<?php foreach (AlertSeverity::cases() as $severity): ?>
    <?php if ($groups[$severity->value] !== []): ?>
        <section class="lf-alerts lf-alerts--<?= Html::e($severity->value) ?>" aria-labelledby="lf-alerts-<?= Html::e($severity->value) ?>">
            <h2 id="lf-alerts-<?= Html::e($severity->value) ?>"><?= Html::e($severity->label()) ?> (<?= count($groups[$severity->value]) ?>)</h2>
            <ul class="lf-alert-list">
                <?php foreach ($groups[$severity->value] as $alert): ?>
                    <li class="lf-alert lf-notice lf-notice--<?= Html::e($severity->variant()) ?>">
                        <p class="lf-alert__message"><?= Html::e($alert->message) ?></p>
                        <?php if ($alert->detail !== ''): ?><p class="lf-alert__detail"><?= Html::e($alert->detail) ?></p><?php endif; ?>
                        <p class="lf-alert__meta">
                            <?php if ($alert->lastSeen !== null && $alert->firstSeen !== null): ?>
                                <?= $alert->occurrences > 1 ? (int) $alert->occurrences . ' times, ' : '' ?>first seen <time datetime="<?= Html::e($alert->firstSeen->format('Y-m-d\TH:i:s')) ?>"><?= Html::e($ago($alert->firstSeen)) ?></time>, last seen <time datetime="<?= Html::e($alert->lastSeen->format('Y-m-d\TH:i:s')) ?>"><?= Html::e($ago($alert->lastSeen)) ?></time>.
                            <?php else: ?>
                                Present now; it goes away by itself when the cause is fixed.
                            <?php endif; ?>
                        </p>
                        <p class="lf-alert__actions">
                            <?php if ($alert->path !== ''): ?>
                                <a class="lf-button lf-button--secondary" href="<?= Html::e(AdminUrl::to($alert->path)) ?>">Fix this<span class="lf-visually-hidden">: <?= Html::e($alert->message) ?></span></a>
                            <?php endif; ?>
                            <?php if ($alert->id !== null): ?>
                                <form method="post" action="<?= Html::e($dismissUrl . '/' . $alert->id . '/dismiss') ?>" class="lf-inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                                    <button type="submit" class="lf-button lf-button--quiet">Dismiss<span class="lf-visually-hidden">: <?= Html::e($alert->message) ?></span></button>
                                </form>
                            <?php endif; ?>
                        </p>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
<?php endforeach; ?>

<form method="post" action="<?= Html::e($emailUrl) ?>" class="lf-form lf-card">
    <h2>Email</h2>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
    <div class="lf-choice">
        <input type="checkbox" id="email_critical" name="email_critical" value="1"<?= $emailsCritical ? ' checked' : '' ?>>
        <label for="email_critical">Email me about critical alerts</label>
        <p class="lf-hint">Sent to the oldest active Administrator account, at most one message per alert per day, and never with anything a visitor typed. Only alerts kept from an event can be emailed; problems checked live, such as debug mode, are shown here and on the dashboard. Kept alerts are removed after <?= (int) $retentionDays ?> quiet days.</p>
    </div>
    <p><button type="submit" class="lf-button">Save</button></p>
</form>
