/**
 * This file is part of Seo UI Plugin for ILIAS,
 * developed by OC Open Consulting to enable
 * SEO functionalities in ILIAS.
 *
 * @author Vincenzo Padula <vincenzo@oc-group.eu>
 * @copyright 2026 OC Open Consulting SB Srl
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Permalink-field typing affordance, shared by the metabar quick form and the
 * advanced modal: the field is kept in the shape ilSeoPermalink::normalize()
 * would give it while it is being typed.
 *
 * This file owns the algorithm and nothing else. The transliteration table, the
 * allowed character class and the separator arrive from PHP in
 * cfg.transliterations / cfg.allowed / cfg.separator
 * (ilSeoPermalink::clientRules()), and field ids the same way, so no rule and
 * no postvar selector is duplicated here to drift out of sync.
 *
 * Two strengths, deliberately: a keystroke gets the character-level rules only,
 * while the segment-level ones wait for blur. Applying those per keystroke
 * would cut the hyphen off "corso-" before "1" can be typed. The server stays
 * the authority either way.
 */

var il = il || {};

(function (il, document) {
    'use strict';

    var cfg = null;
    var disallowed = null;
    var pending = [];

    /**
     * Mirror of normalize()'s strtr() step. Every table key is a single UTF-16
     * code unit (Latin-1 Supplement and Latin Extended-A only), so a
     * per-character walk matches PHP's byte-sequence replacement exactly.
     */
    function transliterate(value) {
        var out = '';
        for (var i = 0; i < value.length; i++) {
            var ch = value.charAt(i);
            out += Object.prototype.hasOwnProperty.call(cfg.transliterations, ch)
                ? cfg.transliterations[ch]
                : ch;
        }
        return out;
    }

    /**
     * The character-level half of normalize(): transliterate, lower-case,
     * collapse runs.
     */
    function characterRules(value) {
        return transliterate(value).toLowerCase().replace(disallowed, cfg.separator);
    }

    function trimSeparator(segment) {
        while (segment.charAt(0) === cfg.separator) {
            segment = segment.substring(1);
        }
        while (segment.charAt(segment.length - 1) === cfg.separator) {
            segment = segment.substring(0, segment.length - 1);
        }
        return segment;
    }

    /**
     * What runs on every keystroke: the character rules, plus only the segment
     * rules that cannot get in the way of what is still being typed. A trailing
     * separator and a trailing empty segment survive (they are what the user is
     * in the middle of writing).
     */
    function sanitizeTyping(value) {
        var parts = characterRules(value).split('/');
        var kept = [];
        for (var i = 0; i < parts.length; i++) {
            var part = parts[i];
            while (part.charAt(0) === cfg.separator) {
                part = part.substring(1);
            }
            if (part !== '' || i === parts.length - 1) {
                kept.push(part);
            }
        }
        return kept.join('/');
    }

    /**
     * Mirror of ilSeoPermalink::normalize() in full, closing "/" included. Blur
     * and beyond.
     */
    function normalize(value) {
        var parts = characterRules(value).split('/');
        var segments = [];
        for (var i = 0; i < parts.length; i++) {
            var segment = trimSeparator(parts[i]);
            if (segment !== '') {
                segments.push(segment);
            }
        }
        return segments.length === 0 ? '' : segments.join('/') + '/';
    }

    function onInput(event) {
        if (event.isComposing) {
            // Mid-IME-composition the value is not final; sanitizing it now
            // would rewrite characters the input method is still assembling.
            return;
        }

        var input = event.target;
        var before = input.value;
        var after = sanitizeTyping(before);
        if (after === before) {
            return;
        }

        var caret = input.selectionStart;
        input.value = after;
        if (typeof caret === 'number' && input.setSelectionRange) {
            // Transforming just the text before the caret yields its new index
            // directly, so a collapsed run cannot drag it to the end.
            var moved = sanitizeTyping(before.substring(0, caret)).length;
            input.setSelectionRange(moved, moved);
        }
    }

    /**
     * Hold a normalized value inside the field's own maxlength. The closing "/"
     * can push it one character past, and maxlength does not stop that: it
     * constrains typing, not an assignment. Over the limit the save is a 500
     * and not a validation message, since withInput() hands the raw post to
     * withValue() before any operation runs. The cut mirrors
     * ilSeoInitialValues::fit(), in the code units maxlength counts, not bytes;
     * a normalized permalink is ASCII, so the two agree.
     */
    function fit(value, max) {
        if (max <= 0 || value.length <= max) {
            return value;
        }

        return normalize(value.substring(0, max - 1));
    }

    function onBlur(event) {
        var input = event.target;
        var full = fit(normalize(input.value), input.maxLength);
        if (full !== input.value) {
            input.value = full;
        }
    }

    /**
     * Attach to one permalink field by its id. Idempotent, and safe to call
     * before init(): the advanced modal renders asynchronously and could in
     * principle arrive first.
     */
    function bind(id) {
        if (cfg === null) {
            pending.push(id);
            return;
        }

        var input = document.getElementById(id);
        if (input === null || input.dataset.seoPermalinkBound === '1') {
            return;
        }

        input.dataset.seoPermalinkBound = '1';
        input.addEventListener('input', onInput);
        input.addEventListener('blur', onBlur);
    }

    function init(config) {
        cfg = config;
        disallowed = new RegExp('[^' + cfg.allowed + ']+', 'g');

        (cfg.bind || []).forEach(bind);
        while (pending.length > 0) {
            bind(pending.shift());
        }
    }

    il.SeoPermalink = {
        init: init,
        bind: bind
    };
})(il, document);
