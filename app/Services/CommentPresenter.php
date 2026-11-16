<?php

/**
 * Turns a chapter's comments into the one bundled array a theme prints: the list, the count, the form and the notices.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Core\Theme\SafeHtml;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\Comment;
use LumoraFiction\Models\User;

/**
 * One bundled array, so a field added later reaches every theme without each template being edited. Every text value is a
 * plain string that the theme escapes; the only markup is the comment body, which is escaped here and turned into
 * paragraphs and line breaks, so a comment can never carry a tag or a clickable link.
 */
final class CommentPresenter
{
    public const POST_ACTION = 'comment_post';

    public const DELETE_ACTION = 'comment_delete';

    public const EDIT_ACTION = 'comment_edit';

    public const REPORT_ACTION = 'comment_report';

    public const REPLY_ACTION = 'comment_reply';

    public function __construct(private readonly CommentService $comments)
    {
    }

    /**
     * @param array{story_id: int, chapter_id: int, owner_id: int, number: int, title: string, story_title: string, story_slug: string} $target
     * @return array<string, mixed> empty when comments are turned off for the whole site, so a theme prints nothing
     */
    public function forChapter(?User $viewer, array $target, int $page, string $pageUrl): array
    {
        $settings = $this->comments->settings();

        if (!$settings->enabled()) {
            return [];
        }

        // With replies on, a page is counted in conversations and each carries its replies; with them off, every visible
        // comment, replies already made included, is one flat list.
        $nested = $settings->repliesAllowed();
        $total = $nested ? $this->comments->countThreads($target['chapter_id'], $target['owner_id'], $viewer) : $this->comments->countVisible($target['chapter_id'], $target['owner_id'], $viewer);
        $pages = max(1, (int) ceil($total / CommentService::PER_PAGE));
        $page = min($pages, max(1, $page));
        $closed = $this->comments->isClosed($target['story_id'], $target['chapter_id']);
        $published = $this->comments->countForChapter($target['chapter_id']);
        $offset = ($page - 1) * CommentService::PER_PAGE;
        $entries = $nested
            ? $this->comments->threadsForChapter($target['chapter_id'], $target['owner_id'], $viewer, CommentService::PER_PAGE, $offset)
            : $this->comments->forChapter($target['chapter_id'], $target['owner_id'], $viewer, CommentService::PER_PAGE, $offset);
        $replyCounts = $this->comments->replyCounts(array_map(static fn (array $entry): int => $entry['comment']->id, $entries));
        $items = [];

        foreach ($entries as $entry) {
            $items[] = $this->item($entry, $viewer, $target, $closed, $nested, $replyCounts);
        }

        return [
            'anchor' => 'comments',
            'count' => $published,
            'count_label' => $published === 1 ? '1 comment' : number_format($published) . ' comments',
            'closed' => $closed,
            'closed_text' => 'Comments are turned off here.',
            'items' => $items,
            'forms' => $closed ? [] : array_values(array_filter([$this->postForm($viewer, $target)])),
            'signin' => $viewer === null && !$closed ? ['text' => 'Sign in to leave a comment.', 'url' => PublicUrl::account('sign-in'), 'label' => 'Sign in'] : null,
            'pagination' => ['page' => $page, 'pages' => $pages, 'url' => $pageUrl . (str_contains($pageUrl, '?') ? '&' : '?') . 'comments_page={page}#comments'],
        ];
    }

    /**
     * The same bundle for a chapter named by id, which the controllers that already hold a chapter use.
     *
     * @return array<string, mixed>
     */
    public function forChapterId(?User $viewer, int $chapterId, int $page, string $pageUrl): array
    {
        $target = $this->comments->target($chapterId);

        return $target === null ? [] : $this->forChapter($viewer, $target, $page, $pageUrl);
    }

    /**
     * The comment count for a story page, or an empty array when comments are off or there are none.
     *
     * @return array{count: int, label: string}|array{}
     */
    public function storySummary(int $storyId): array
    {
        if (!$this->comments->settings()->enabled()) {
            return [];
        }

        $count = $this->comments->countsForStories([$storyId])[$storyId] ?? 0;

        return $count === 0 ? [] : ['count' => $count, 'label' => $count === 1 ? '1 comment' : number_format($count) . ' comments'];
    }

    /**
     * The comment count line for each of several chapters, so a page that prints many chapters asks once. Chapters
     * without a published comment are left out, and an empty list means comments are off.
     *
     * @param list<int> $chapterIds
     * @return array<int, string> a label such as "3 comments" by chapter id
     */
    public function chapterLabels(array $chapterIds): array
    {
        if (!$this->comments->settings()->enabled()) {
            return [];
        }

        $labels = [];

        foreach ($this->comments->countsForChapters($chapterIds) as $id => $count) {
            $labels[$id] = $count === 1 ? '1 comment' : number_format($count) . ' comments';
        }

        return $labels;
    }

    /**
     * The form that posts a comment, or null when this person cannot.
     *
     * @param array{story_id: int, chapter_id: int, owner_id: int, number: int, title: string, story_title: string, story_slug: string} $target
     * @return array<string, mixed>|null
     */
    public function postForm(?User $viewer, array $target, string $body = '', string $error = ''): ?array
    {
        if ($viewer === null || !$this->comments->mayComment($viewer)) {
            return null;
        }

        return $this->form(
            self::POST_ACTION,
            PublicUrl::commentPost($target['story_slug'], $target['number']),
            'Post comment',
            [$this->bodyField($body, $error)],
            'Leave a comment',
            $error,
        );
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    public function form(string $action, string $url, string $submit, array $fields, string $heading = '', string $error = ''): array
    {
        return [
            'id' => $action,
            'action' => $url,
            'csrf_token' => Csrf::token($action),
            'submit' => $submit,
            'heading' => $heading,
            'fields' => $fields,
            'errors' => $error === '' ? [] : [['message' => $error]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function bodyField(string $body, string $error = ''): array
    {
        return [
            'name' => 'body',
            'id' => 'lf-field-comment-body',
            'label' => 'Your comment',
            'type' => 'textarea',
            'value' => $body,
            'autocomplete' => 'off',
            'hint' => 'Plain text only. Up to ' . number_format(CommentService::BODY_MAX) . ' characters. Links are shown as text.',
            'error' => '',
            'options' => [],
            'rows' => 5,
            'maxlength' => CommentService::BODY_MAX,
            'required' => true,
        ];
    }

    /**
     * Escaped text in paragraphs; a blank line starts a paragraph and a single line break stays a line break.
     */
    public static function bodyHtml(string $body): SafeHtml
    {
        $paragraphs = preg_split('/\n{2,}/', $body) ?: [$body];
        $html = '';

        foreach ($paragraphs as $paragraph) {
            $html .= '<p>' . nl2br(Html::e(trim($paragraph)), false) . '</p>';
        }

        return new SafeHtml($html);
    }

    /**
     * @param array{comment: Comment, name: string, is_author: bool, pending: bool, replies?: list<array{comment: Comment, name: string, is_author: bool, pending: bool}>} $entry
     * @param array{story_id: int, chapter_id: int, owner_id: int, number: int, title: string, story_title: string, story_slug: string} $target
     * @param array<int, int> $replyCounts replies each comment has, whatever their state
     * @return array<string, mixed>
     */
    private function item(array $entry, ?User $viewer, array $target, bool $closed, bool $nested, array $replyCounts, string $answers = ''): array
    {
        $comment = $entry['comment'];
        $own = $viewer !== null && $viewer->id === $comment->userId;
        $mayDelete = $viewer !== null && ($own || $this->comments->mayManage($viewer, $target['owner_id']));
        $replyTotal = $replyCounts[$comment->id] ?? 0;
        $replies = [];

        foreach ($entry['replies'] ?? [] as $replyEntry) {
            $replies[] = $this->item($replyEntry, $viewer, $target, $closed, $nested, $replyCounts, $entry['name']);
        }

        $delete = null;

        if ($mayDelete) {
            $delete = [
                'action' => PublicUrl::commentAction($comment->id, 'delete'),
                'csrf_token' => Csrf::token(self::DELETE_ACTION),
                'label' => 'Delete',
                // A comment with replies asks first, on a page that says how many go with it.
                'confirm_url' => $replyTotal > 0 ? PublicUrl::commentAction($comment->id, 'delete') : '',
            ];
        }

        return [
            'id' => $comment->id,
            'anchor' => 'comment-' . $comment->id,
            'name' => $entry['name'],
            // Worked out from the story's owner when the list is built; the theme prints the label as real text.
            'is_author' => $entry['is_author'],
            'author_label' => 'Author',
            'body_html' => self::bodyHtml($comment->body),
            'posted' => $comment->createdAt->format('j M Y, H:i'),
            'datetime' => $comment->createdAt->format('Y-m-d\TH:i:s'),
            'edited' => $comment->wasEdited(),
            'edited_label' => 'edited',
            'pending' => $entry['pending'],
            'pending_label' => $own ? 'Awaiting approval. Only you and the moderators can see this yet.' : 'Awaiting approval',
            'edit_url' => $viewer !== null && $this->comments->mayEdit($viewer, $comment) ? PublicUrl::commentAction($comment->id, 'edit') : '',
            'report_url' => $viewer !== null && !$own && $viewer->can(\LumoraFiction\Models\Capability::Comment) && !$entry['pending'] ? PublicUrl::commentAction($comment->id, 'report') : '',
            // Only a published top-level comment can be answered, and only while the chapter takes comments.
            'reply_url' => $nested && !$closed && $viewer !== null && $this->comments->mayComment($viewer) && $this->comments->isReplyable($comment) ? PublicUrl::commentAction($comment->id, 'reply') : '',
            'reply_label' => 'Reply',
            'is_reply' => $comment->isReply(),
            // Printed by assistive technology in front of a reply, so it is clear what the indented comment answers.
            'reply_to_label' => $answers === '' ? '' : 'Reply to ' . $answers,
            'reply_count' => count($replies),
            'replies' => $replies,
            'delete' => $delete,
        ];
    }

    /**
     * The form that answers one comment, or null when this person cannot.
     *
     * @return array<string, mixed>|null
     */
    public function replyForm(?User $viewer, Comment $parent, string $parentName, string $body = '', string $error = ''): ?array
    {
        if ($viewer === null || !$this->comments->mayComment($viewer) || !$this->comments->isReplyable($parent)) {
            return null;
        }

        $name = $parentName === '' ? 'this comment' : $parentName;
        $form = $this->form(
            self::REPLY_ACTION,
            PublicUrl::commentAction($parent->id, 'reply'),
            'Post reply',
            [$this->bodyField($body, $error)],
            'Reply to ' . $name,
            $error,
        );
        $form['fields'][0]['label'] = 'Your reply';
        $form['intro'] = 'You are answering this comment: “' . self::excerpt($parent->body) . '”';

        return $form;
    }

    /** The start of a comment on one line, for saying which comment a form answers. */
    public static function excerpt(string $body, int $length = 160): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $body));

        return mb_strlen($line, 'UTF-8') > $length ? rtrim(mb_substr($line, 0, $length, 'UTF-8')) . '…' : $line;
    }
}
