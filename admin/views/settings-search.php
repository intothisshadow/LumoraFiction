<?php

/**
 * Search engines: asking them to stay away, and where the sitemap and robots.txt are.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;

/** @var array{discourage: bool} $values */
/** @var array<string, string> $errors */
/** @var bool $sitemapOffered */
/** @var bool $signInRequired */
/** @var string $sitemapUrl */
/** @var string $robotsUrl */
/** @var string $redirectsUrl */
/** @var string $csrfToken */
/** @var string $action */
?>
<?php if ($errors !== []): ?>
    <div class="lf-notice lf-notice--error" role="alert" id="lf-error-summary" tabindex="-1">
        <p>Please fix the following:</p>
        <ul>
            <?php foreach ($errors as $field => $message): ?>
                <li><?= $field === 'form' ? Html::e($message) : '<a href="#' . Html::e($field) . '">' . Html::e($message) . '</a>' ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<p class="lf-lead">Search engines find the site through a sitemap and a robots.txt file, both made for you. Stories, authors, classification pages, news and pages are listed; drafts, stories waiting for approval and unpublished stories never are.</p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <section class="lf-card" aria-labelledby="lf-discourage-heading">
        <h2 id="lf-discourage-heading">Visibility</h2>
        <div class="lf-choice">
            <input type="checkbox" id="discourage" name="discourage" value="1"<?= $values['discourage'] ? ' checked' : '' ?> aria-describedby="discourage_hint">
            <label for="discourage">Ask search engines not to index this site</label>
        </div>
        <p class="lf-hint" id="discourage_hint">Adds a noindex tag to every page, tells crawlers in robots.txt to stay out, and stops publishing the sitemap. Well-behaved search engines respect this, but it does not make the stories private: anyone with a link can still read them. Use the &ldquo;sign in to read&rdquo; setting under Archive for that.</p>
    </section>

    <p><button type="submit" class="lf-button">Save settings</button></p>
</form>

<section class="lf-card" aria-labelledby="lf-addresses-heading">
    <h2 id="lf-addresses-heading">Addresses</h2>
    <?php if ($robotsUrl === ''): ?>
        <p class="lf-hint">The site address is not set, so the full addresses cannot be shown.</p>
    <?php else: ?>
        <dl class="lf-details-list">
            <dt>Sitemap</dt>
            <dd>
                <?php if ($sitemapOffered): ?>
                    <a href="<?= Html::e($sitemapUrl) ?>"><?= Html::e($sitemapUrl) ?></a>
                <?php elseif ($signInRequired): ?>
                    Not published, because the archive requires sign-in to read, and a crawler cannot sign in.
                <?php else: ?>
                    Not published, because search engines are asked to stay away.
                <?php endif; ?>
            </dd>
            <dt>robots.txt</dt>
            <dd><a href="<?= Html::e($robotsUrl) ?>"><?= Html::e($robotsUrl) ?></a></dd>
        </dl>
    <?php endif; ?>
    <p class="lf-hint">Crawlers look for robots.txt at the top of the site's address. If the site lives in a folder, or the web server already has a real robots.txt file, that file is the one crawlers read. Moved something? <a href="<?= Html::e($redirectsUrl) ?>">Redirects</a> sends visitors from an old address to the new one.</p>
</section>
