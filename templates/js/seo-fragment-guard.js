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
 * A <base href> changes the resolution base for the whole document, not just
 * the asset paths it exists for. core's own "do nothing" idiom, <a href="#">,
 * and any <a href="#section">, stop resolving against the current page and
 * navigate to the site root instead. This delegated, capture-phase listener
 * intercepts the click before that navigation happens and restores the two
 * behaviours by hand. It only ever calls preventDefault(): other handlers
 * (core's own, other plugins') still see the click.
 */
(function (document) {
    'use strict';

    function closestAnchor(node) {
        if (node && typeof node.closest === 'function') {
            try {
                return node.closest('a');
            } catch (e) {
                // Fall through to the manual walk below.
            }
        }

        while (node && node !== document) {
            if (node.tagName === 'A') {
                return node;
            }
            node = node.parentNode;
        }
        return null;
    }

    function isComponentDriven(anchor) {
        return anchor.hasAttribute('data-toggle')
            || anchor.hasAttribute('data-bs-toggle')
            || anchor.hasAttribute('aria-controls')
            || anchor.getAttribute('role') === 'tab';
    }

    function findFragmentTarget(fragment) {
        var target = document.getElementById(fragment);
        if (target !== null) {
            return target;
        }

        if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
            try {
                return document.querySelector('a[name="' + CSS.escape(fragment) + '"]');
            } catch (e) {
                return null;
            }
        }

        var candidates = document.getElementsByName(fragment);
        for (var i = 0; i < candidates.length; i++) {
            if (candidates[i].tagName === 'A') {
                return candidates[i];
            }
        }
        return null;
    }

    document.addEventListener('click', function (event) {
        var anchor = closestAnchor(event.target);
        if (anchor === null) {
            return;
        }

        var href = anchor.getAttribute('href');
        if (href === null || href.charAt(0) !== '#') {
            return;
        }

        if (isComponentDriven(anchor)) {
            // The href names this component's own target (a tab, a collapse
            // panel, a dropdown), not a place to scroll to: only stop the
            // browser's navigation and leave the component's own handler to
            // do the rest, since we never stop propagation.
            event.preventDefault();
            return;
        }

        if (href === '#') {
            event.preventDefault();
            return;
        }

        event.preventDefault();

        var fragment = href.substring(1);
        var target = findFragmentTarget(fragment);
        if (target === null) {
            // Navigating to the site root is wrong in every case here, but
            // there is nowhere in-page to send the user either.
            return;
        }

        target.scrollIntoView();
        // Rewrites only the fragment of the URL already loaded; never
        // re-resolves against <base> the way assigning a relative href would.
        // pushState, not replaceState: native fragment navigation creates a
        // history entry, so Back returns the user to where they were.
        history.pushState(null, '', window.location.pathname + window.location.search + '#' + fragment);
    }, true);
}(document));
