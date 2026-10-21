/* Lumora Fiction paste helper.
   Google Docs and Word mark italics, bold and underline with a style on a span, and the editors drop every
   style, so a pasted passage arrives as plain text. normalise() turns those marks into real tags before
   the editor filters the paste, and toMarkdown() writes the same marks as Markdown for the Markdown editor.
   Everything is parsed into an inert document, so nothing in the clipboard can run. */

(function () {
    'use strict';

    var BLOCKS = { P: 1, DIV: 1, H1: 1, H2: 1, H3: 1, H4: 1, H5: 1, H6: 1, BLOCKQUOTE: 1, PRE: 1, UL: 1, OL: 1, LI: 1, HR: 1, TABLE: 1, TR: 1 };
    var MEANINGFUL = 'em,strong,u,s,sup,sub,a[href],h1,h2,h3,h4,h5,h6,li,blockquote,hr';

    function parse(html) {
        /* Word's downlevel markers would otherwise show up as text. */
        var cleaned = String(html)
            .replace(/<!\[if\s[^\]]*\]>/gi, '')
            .replace(/<!\[endif\]>(?!-->)/gi, '')
            .replace(/<\/?o:p\b[^>]*>/gi, '');

        return new DOMParser().parseFromString(cleaned, 'text/html');
    }

    function styleOf(element) {
        var parsed = {};

        (element.getAttribute('style') || '').split(';').forEach(function (declaration) {
            var at = declaration.indexOf(':');

            if (at > 0) {
                parsed[declaration.slice(0, at).trim().toLowerCase()] = declaration.slice(at + 1).replace(/!important/i, '').trim().toLowerCase();
            }
        });

        return parsed;
    }

    function isBold(weight) {
        return weight === 'bold' || weight === 'bolder' || (/^\d+$/.test(weight) && parseInt(weight, 10) >= 600);
    }

    function unwrap(element) {
        var parent = element.parentNode;

        while (element.firstChild) {
            parent.insertBefore(element.firstChild, element);
        }

        parent.removeChild(element);
    }

    function insideLink(element) {
        return Boolean(element.parentNode && element.parentNode.closest && element.parentNode.closest('a'));
    }

    function marksFor(span) {
        var style = styleOf(span);
        var decoration = (style['text-decoration'] || '') + ' ' + (style['text-decoration-line'] || '');
        var tags = [];

        if (style['font-style'] === 'italic' || style['font-style'] === 'oblique') {
            tags.push('em');
        }

        if (style['font-weight'] && isBold(style['font-weight'])) {
            tags.push('strong');
        }

        /* Google Docs underlines every link's text, which is not the writer's choice. */
        if (/\bunderline\b/.test(decoration) && !insideLink(span)) {
            tags.push('u');
        }

        if (/\bline-through\b/.test(decoration)) {
            tags.push('s');
        }

        if (style['vertical-align'] === 'super') {
            tags.push('sup');
        } else if (style['vertical-align'] === 'sub') {
            tags.push('sub');
        }

        return tags;
    }

    /* Returns a document in which style marks are tags and Google Docs' wrapper is gone. */
    function normaliseDocument(html) {
        var doc = parse(html);

        /* A bold tag whose style says "normal" is Google Docs' wrapper around the whole paste. */
        Array.prototype.slice.call(doc.body.querySelectorAll('b,strong,i,em')).forEach(function (element) {
            var style = styleOf(element);
            var tag = element.tagName;
            var neutral = (tag === 'B' || tag === 'STRONG')
                ? (/^docs-internal-guid/i.test(element.id || '') || (style['font-weight'] !== undefined && !isBold(style['font-weight'])))
                : style['font-style'] === 'normal';

            if (neutral) {
                unwrap(element);
            }
        });

        Array.prototype.slice.call(doc.body.querySelectorAll('span[style]')).forEach(function (span) {
            var tags = marksFor(span);
            var outer = null;
            var inner = null;

            tags.forEach(function (tag) {
                var wrapper = doc.createElement(tag);

                if (inner) {
                    inner.appendChild(wrapper);
                } else {
                    outer = wrapper;
                }

                inner = wrapper;
            });

            if (outer) {
                while (span.firstChild) {
                    inner.appendChild(span.firstChild);
                }

                span.appendChild(outer);
            }

            /* The style has been read, so the span is only a wrapper now. */
            span.removeAttribute('style');

            if (!span.getAttribute('class')) {
                unwrap(span);
            }
        });

        return doc;
    }

    function normalise(html) {
        return normaliseDocument(html).body.innerHTML;
    }

    function escapeText(text) {
        return text.replace(/[\\*_`]/g, '\\$&');
    }

    /* Whitespace at the edges of a marked run goes outside the marks, because "*word *" is not emphasis. */
    function mark(inner, open, close) {
        var match = /^(\s*)([\s\S]*?)(\s*)$/.exec(inner);

        if (match[2] === '') {
            return inner;
        }

        return match[1] + open + match[2] + close + match[3];
    }

    function inlineChildren(node) {
        var out = '';

        Array.prototype.forEach.call(node.childNodes, function (child) {
            out += render(child);
        });

        return out;
    }

    function listItems(list, depth) {
        var ordered = list.tagName === 'OL';
        var number = 0;
        var lines = [];
        var indent = new Array(depth + 1).join('    ');

        Array.prototype.forEach.call(list.children, function (item) {
            if (item.tagName !== 'LI') {
                return;
            }

            var text = '';
            var nested = '';

            number += 1;

            Array.prototype.forEach.call(item.childNodes, function (child) {
                if (child.nodeType === 1 && (child.tagName === 'UL' || child.tagName === 'OL')) {
                    nested += '\n' + listItems(child, depth + 1);
                } else {
                    text += render(child);
                }
            });

            lines.push(indent + (ordered ? number + '. ' : '- ') + text.replace(/\s*\n+\s*/g, ' ').trim() + nested);
        });

        return lines.join('\n');
    }

    function render(node) {
        if (node.nodeType === 3) {
            return escapeText(node.nodeValue.replace(/\s+/g, ' '));
        }

        if (node.nodeType !== 1) {
            return '';
        }

        var tag = node.tagName;
        var inner;

        switch (tag) {
        case 'EM':
        case 'I':
            return mark(inlineChildren(node), '*', '*');
        case 'STRONG':
        case 'B':
            return mark(inlineChildren(node), '**', '**');
        case 'S':
        case 'DEL':
            return mark(inlineChildren(node), '~~', '~~');
        case 'U':
        case 'SUP':
        case 'SUB':
            return mark(inlineChildren(node), '<' + tag.toLowerCase() + '>', '</' + tag.toLowerCase() + '>');
        case 'BR':
            return '  \n';
        case 'A':
            inner = inlineChildren(node);

            if (/^(?:https?:|mailto:)/i.test(node.getAttribute('href') || '') && inner.trim() !== '') {
                return '[' + inner.trim() + '](' + node.getAttribute('href') + ')';
            }

            return inner;
        case 'HR':
            return '\n\n---\n\n';
        case 'H1':
        case 'H2':
        case 'H3':
        case 'H4':
        case 'H5':
        case 'H6':
            return '\n\n' + new Array(parseInt(tag.charAt(1), 10) + 1).join('#') + ' ' + inlineChildren(node).trim() + '\n\n';
        case 'BLOCKQUOTE':
            return '\n\n' + blockText(node).split('\n').map(function (line) {
                return line === '' ? '>' : '> ' + line;
            }).join('\n') + '\n\n';
        case 'UL':
        case 'OL':
            return '\n\n' + listItems(node, 0) + '\n\n';
        case 'PRE':
            return '\n\n```\n' + node.textContent.replace(/\n+$/, '') + '\n```\n\n';
        case 'SCRIPT':
        case 'STYLE':
        case 'META':
        case 'TITLE':
            return '';
        default:
            if (BLOCKS[tag]) {
                return '\n\n' + inlineChildren(node).trim() + '\n\n';
            }

            return inlineChildren(node);
        }
    }

    function blockText(node) {
        return inlineChildren(node).replace(/\n{3,}/g, '\n\n').trim();
    }

    /* Returns Markdown for the clipboard HTML, or null when it holds no formatting worth converting, so
       the editor can keep its normal plain-text paste. */
    function toMarkdown(html) {
        var doc = normaliseDocument(html);

        if (!doc.body.querySelector(MEANINGFUL)) {
            return null;
        }

        var markdown = blockText(doc.body);

        return markdown === '' ? null : markdown;
    }

    window.LumoraPaste = { normalise: normalise, toMarkdown: toMarkdown };
}());
