/* Lumora Fiction chapter editor.
   Upgrades the textarea to the writer's chosen mode (visual, Markdown, HTML or plain text), keeps a live
   word count, previews through the server, warns before leaving with unsaved changes, and converts the
   text when the mode changes. Nothing here loads from another site, and nothing needs an inline script
   or style. If this file cannot run, the textarea still saves as it is. */

(function () {
    'use strict';

    var WORD = /[\p{L}\p{N}\p{M}]+(?:['’-][\p{L}\p{N}\p{M}]+)*/gu;
    var BLOCK_END = /<\/(?:p|div|li|h[1-6]|blockquote|pre)>|<br\s*\/?>|<hr\s*\/?>/gi;

    function Editor(root) {
        this.root = root;
        this.textarea = root.querySelector('[data-lf-textarea]');
        this.formatField = root.querySelector('[data-lf-format-field]');
        this.status = root.querySelector('[data-lf-status]');
        this.words = root.querySelector('[data-lf-words]');
        this.characters = root.querySelector('[data-lf-characters]');
        this.modeBar = root.querySelector('[data-lf-modes]');
        this.modeButtons = Array.prototype.slice.call(root.querySelectorAll('[data-lf-mode-button]'));
        this.previewButton = root.querySelector('[data-lf-preview-button]');
        this.preview = root.querySelector('[data-lf-preview]');
        this.previewBody = root.querySelector('[data-lf-preview-body]');
        this.form = this.textarea.form;
        this.token = root.getAttribute('data-lf-token') || '';
        this.mode = root.getAttribute('data-lf-mode') || 'visual';
        this.tiny = null;
        this.markdown = null;
        this.dirty = false;
        this.busy = false;
        this.formats = {};

        this.modeButtons.forEach(function (button) {
            this.formats[button.getAttribute('data-lf-mode-button')] = button.getAttribute('data-lf-format');
        }, this);
    }

    Editor.prototype.formatOf = function (mode) {
        if (this.formats[mode]) {
            return this.formats[mode];
        }

        return mode === 'markdown' ? 'markdown' : (mode === 'plain' ? 'plain' : 'html');
    };

    Editor.prototype.say = function (message, isError) {
        this.status.textContent = message || '';
        this.status.classList.toggle('is-error', Boolean(isError));
    };

    Editor.prototype.value = function () {
        if (this.tiny) {
            return this.tiny.getContent();
        }

        if (this.markdown) {
            return this.markdown.value();
        }

        return this.textarea.value;
    };

    /* The form posts the textarea, so whatever the editor holds is copied into it before every save. */
    Editor.prototype.sync = function () {
        this.textarea.value = this.value();
    };

    Editor.prototype.plainText = function (text) {
        var format = this.formatOf(this.mode);

        if (format === 'plain') {
            return text;
        }

        if (format === 'markdown') {
            text = text
                .replace(/^(?:`{3}|~{3}).*$/gm, ' ')
                .replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1')
                .replace(/^\s{0,3}(?:#{1,6}\s+|>\s?|[-*+]\s+|\d+[.)]\s+|[-*_]{3,}\s*$)/gm, ' ')
                .replace(/[*_~`]+/g, '');
        }

        /* Parsed into an inert document, so nothing in the text can run. */
        var spaced = text.replace(BLOCK_END, ' ');
        var doc = new DOMParser().parseFromString(spaced, 'text/html');

        return doc.body.textContent || '';
    };

    Editor.prototype.count = function () {
        var text = this.plainText(this.value());
        var found = text.match(WORD);

        this.words.textContent = String(found ? found.length : 0);
        this.characters.textContent = String(text.replace(/\s+/g, ' ').trim().length);
    };

    Editor.prototype.changed = function () {
        this.dirty = true;
        this.count();
    };

    Editor.prototype.request = function (url, fields) {
        var self = this;
        var body = new URLSearchParams();

        Object.keys(fields).forEach(function (key) {
            body.append(key, fields[key]);
        });
        body.append('csrf_token', this.token);

        return window.fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
            body: body.toString()
        }).then(function (response) {
            return response.json().catch(function () {
                throw new Error('The server did not answer as expected. Reload the page, copying your text first so nothing is lost.');
            });
        }).then(function (data) {
            /* Each answer carries the next token, because a token works once. */
            if (data.token) {
                self.token = data.token;
            }

            if (!data.ok) {
                throw new Error(data.message || 'That did not work.');
            }

            return data;
        });
    };

    Editor.prototype.teardown = function () {
        var value = this.value();

        if (this.tiny) {
            this.tiny.remove();
            this.tiny = null;
        }

        if (this.markdown) {
            this.markdown.toTextArea();
            this.markdown = null;
        }

        this.textarea.value = value;

        return value;
    };

    Editor.prototype.setup = function (mode, value) {
        this.mode = mode;
        this.textarea.value = value;
        this.formatField.value = this.formatOf(mode);
        this.root.className = this.root.className.replace(/\s*lf-editor--\S+/g, '') + ' lf-editor--' + mode;

        this.modeButtons.forEach(function (button) {
            button.setAttribute('aria-pressed', button.getAttribute('data-lf-mode-button') === mode ? 'true' : 'false');
        });

        if (mode === 'visual' && window.tinymce) {
            return this.startVisual();
        }

        if (mode === 'markdown' && window.EasyMDE) {
            this.startMarkdown();
        }

        this.count();

        return Promise.resolve();
    };

    Editor.prototype.startVisual = function () {
        var self = this;
        var base = this.root.getAttribute('data-lf-tinymce-base');
        var alignable = 'p,h1,h2,h3,h4,h5,h6';

        return window.tinymce.init({
            target: this.textarea,
            base_url: base,
            suffix: '.min',
            license_key: 'gpl',
            promotion: false,
            branding: false,
            menubar: false,
            statusbar: false,
            min_height: 420,
            skin: 'oxide',
            content_css: this.root.getAttribute('data-lf-content-css'),
            plugins: 'lists link autolink code',
            toolbar: 'undo redo | blocks | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | blockquote hr | bullist numlist | link unlink | removeformat | code',
            block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4',
            /* Alignment is a class, never an inline style, because the sanitiser (and the page's security policy) refuse styles. */
            formats: {
                alignleft: { selector: alignable, classes: 'lf-align-left' },
                aligncenter: { selector: alignable, classes: 'lf-align-center' },
                alignright: { selector: alignable, classes: 'lf-align-right' },
                alignjustify: { selector: alignable, classes: 'lf-align-justify' }
            },
            valid_elements: 'p[class],br,hr,h1[class],h2[class],h3[class],h4[class],h5[class],h6[class],strong/b,em/i,u,s/del,sup,sub,code[class],pre[class],blockquote,ul,ol,li,a[href|title|target|rel],span[class]',
            convert_urls: false,
            relative_urls: false,
            remove_script_host: false,
            entity_encoding: 'raw',
            paste_data_images: false,
            link_title: false,
            target_list: false,
            browser_spellcheck: true,
            contextmenu: false,
            setup: function (editor) {
                editor.on('input change keyup SetContent', function () {
                    self.changed();
                });
            },
            init_instance_callback: function (editor) {
                self.tiny = editor;
                self.count();
            }
        });
    };

    Editor.prototype.startMarkdown = function () {
        var self = this;
        var EasyMDE = window.EasyMDE;

        function tool(name, action, text, title) {
            return { name: name, action: action, text: text, title: title, className: 'lf-editor__tool' };
        }

        this.markdown = new EasyMDE({
            element: this.textarea,
            /* Both default to fetching files from another site; the editor must make no outside requests. */
            spellChecker: false,
            autoDownloadFontAwesome: false,
            status: false,
            forceSync: true,
            autofocus: false,
            minHeight: '420px',
            toolbar: [
                tool('bold', EasyMDE.toggleBold, 'Bold', 'Bold'),
                tool('italic', EasyMDE.toggleItalic, 'Italic', 'Italic'),
                tool('strikethrough', EasyMDE.toggleStrikethrough, 'Strike', 'Strikethrough'),
                '|',
                tool('heading', EasyMDE.toggleHeadingSmaller, 'Heading', 'Heading'),
                tool('quote', EasyMDE.toggleBlockquote, 'Quote', 'Quotation'),
                tool('unordered-list', EasyMDE.toggleUnorderedList, 'List', 'Bulleted list'),
                tool('ordered-list', EasyMDE.toggleOrderedList, '1. List', 'Numbered list'),
                tool('horizontal-rule', EasyMDE.drawHorizontalRule, 'Break', 'Scene break'),
                '|',
                tool('link', EasyMDE.drawLink, 'Link', 'Link'),
                '|',
                tool('undo', EasyMDE.undo, 'Undo', 'Undo'),
                tool('redo', EasyMDE.redo, 'Redo', 'Redo')
            ]
        });

        this.markdown.codemirror.on('change', function () {
            self.changed();
        });
    };

    Editor.prototype.switchTo = function (target) {
        var self = this;

        if (this.busy || target === this.mode) {
            return;
        }

        var previousMode = this.mode;
        var from = this.formatOf(previousMode);
        var to = this.formatOf(target);
        var value = this.value();

        this.busy = true;
        this.say('Switching…');

        /* Visual and HTML both store HTML, so only a change of stored format needs the server. */
        var converted = from === to || value.trim() === ''
            ? Promise.resolve(value)
            : this.request(this.root.getAttribute('data-lf-convert-url'), { content: value, from: from, to: to }).then(function (data) {
                return data.content;
            });

        converted.then(function (next) {
            self.teardown();
            self.dirty = self.dirty || next !== value;

            return self.setup(target, next);
        }).then(function () {
            self.say('');
        }).catch(function (error) {
            /* The text is untouched, so the writer stays where they were. */
            self.say(error.message, true);
        }).then(function () {
            self.busy = false;
        });
    };

    Editor.prototype.showPreview = function () {
        var self = this;

        if (this.busy) {
            return;
        }

        this.busy = true;
        this.say('Preparing the preview…');

        this.request(this.root.getAttribute('data-lf-preview-url'), { content: this.value(), format: this.formatOf(this.mode) }).then(function (data) {
            /* The server has already sanitised this, and the page's security policy blocks scripts and inline handlers regardless. */
            self.previewBody.innerHTML = data.html;
            self.preview.hidden = false;
            self.preview.querySelector('h2').focus();
            self.say('');
        }).catch(function (error) {
            self.say(error.message, true);
        }).then(function () {
            self.busy = false;
        });
    };

    Editor.prototype.start = function () {
        var self = this;

        if (this.modeBar) {
            this.modeBar.hidden = false;
        }

        if (this.previewButton) {
            this.previewButton.hidden = false;
            this.previewButton.addEventListener('click', function () {
                self.showPreview();
            });
        }

        this.modeButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                self.switchTo(button.getAttribute('data-lf-mode-button'));
            });
        });

        if (this.form) {
            /* Capture phase, so the text is in the textarea before anything else reads the form. */
            this.form.addEventListener('submit', function () {
                self.sync();
                self.dirty = false;
            }, true);
        }

        window.addEventListener('beforeunload', function (event) {
            if (self.dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        });

        /* The plain textarea modes (HTML, plain text) report typing through the textarea itself. */
        this.textarea.addEventListener('input', function () {
            self.changed();
        });

        /* Opening existing text is not editing it, so anything the editors report while starting is discarded. */
        this.setup(this.mode, this.textarea.value).then(function () {
            self.dirty = false;
        }).catch(function () {
            self.say('The editor could not start, so the text is shown as it is stored.', true);
        });
    };

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-lf-editor]'), function (root) {
            new Editor(root).start();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
