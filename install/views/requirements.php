<?php

/**
 * Installer screen shown when the server does not meet a requirement.
 *
 * @package LumoraFiction
 * @subpackage Installer
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

use LumoraFiction\Installer\Html;

/** @var list<string> $requirementProblems */
$pageTitle = 'System requirements';

require __DIR__ . '/_head.php';
?>
        <h1>System requirements</h1>
        <p>Lumora Fiction cannot be installed until the following are resolved:</p>
        <ul class="problems">
            <?php foreach ($requirementProblems as $problem): ?>
                <li class="notice notice--error"><?= Html::e($problem) ?></li>
            <?php endforeach; ?>
        </ul>
        <p>Once they are fixed, reload this page.</p>
<?php require __DIR__ . '/_foot.php'; ?>
