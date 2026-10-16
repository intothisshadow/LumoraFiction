<?php

/**
 * Maintenance › System Information.
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

/** @var array<string, list<array{label: string, value: string, status: string, private: bool}>> $sections */
/** @var string $downloadUrl */

// Colour alone is not enough to carry meaning, so every status also has a word.
$statusWords = ['ok' => 'OK', 'warning' => 'Check', 'error' => 'Problem', 'info' => ''];
?>
<p class="lf-lead">What this server offers and how the site is set up. If you ask for help, download the snapshot and send it along: it leaves out passwords, the secret key, the database name and table prefix, and folder locations on the server.</p>

<p><a class="lf-button" href="<?= Html::e($downloadUrl) ?>" download>Download a snapshot</a></p>

<?php foreach ($sections as $title => $rows): ?>
    <section class="lf-card" aria-labelledby="lf-section-<?= Html::e(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $title))) ?>">
        <h2 id="lf-section-<?= Html::e(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $title))) ?>"><?= Html::e($title) ?></h2>
        <table class="lf-table">
            <caption class="lf-visually-hidden"><?= Html::e($title) ?></caption>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <th scope="row"><?= Html::e($row['label']) ?></th>
                        <td>
                            <?= Html::e($row['value']) ?>
                            <?php if (($statusWords[$row['status']] ?? '') !== ''): ?>
                                <span class="lf-status lf-status--<?= Html::e($row['status']) ?>"><?= Html::e($statusWords[$row['status']]) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endforeach; ?>
