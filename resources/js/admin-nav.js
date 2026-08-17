/*
 * WordPress-style hover flyout submenus for the Magna Blog admin sidebar group.
 *
 * Each top-level item (Posts, Categories, Tags, Series, Comments) declares
 * childItems via getNavigationItems() with a blank parent url, so Filament
 * always renders the `.fi-sidebar-sub-group-items` list into the DOM.
 *
 * On hover the list is shown as a floating popover positioned beside its parent
 * item. It is teleported to <body> while open: the sidebar has transformed
 * ancestors (Alpine x-transition / x-collapse), which would make a
 * `position: fixed` child resolve against the transformed ancestor instead of
 * the viewport and drift away from the item. Re-parenting to <body> removes
 * that containing block, and the sidebar's own scroll clipping, so the
 * viewport-based coordinates from getBoundingClientRect() are exact.
 */
(function () {
    'use strict';

    var GROUP = '.fi-sidebar-group[data-group-label="Magna Blog"]';
    var GAP = -2;
    var HIDE_DELAY = 180;

    function topLevelItems() {
        return document.querySelectorAll(
            GROUP + ' > .fi-sidebar-group-items > .fi-sidebar-item'
        );
    }

    function position(item, flyout) {
        var rect = item.getBoundingClientRect();
        var vw = window.innerWidth;
        var vh = window.innerHeight;

        flyout.style.left = Math.round(rect.right + GAP) + 'px';
        flyout.style.top = Math.round(rect.top) + 'px';

        // Clamp back on-screen once its real size is known.
        requestAnimationFrame(function () {
            var fr = flyout.getBoundingClientRect();
            if (fr.right > vw - 8) {
                // Flip to the left of the sidebar item if it overflows the right.
                flyout.style.left = Math.max(8, Math.round(rect.left - fr.width - GAP)) + 'px';
            }
            if (fr.bottom > vh - 8) {
                flyout.style.top = Math.max(8, Math.round(vh - 8 - fr.height)) + 'px';
            }
        });
    }

    function bind(item) {
        if (item.dataset.mgFlyout) {
            return;
        }
        var flyout = item.querySelector(':scope > .fi-sidebar-sub-group-items');
        if (!flyout) {
            return;
        }
        item.dataset.mgFlyout = '1';
        flyout.classList.add('mg-flyout');

        var timer = null;
        var placeholder = null;

        function open() {
            clearTimeout(timer);
            if (flyout.parentNode !== document.body) {
                // Leave a marker so the list can be returned to its exact slot.
                placeholder = document.createComment('mg-flyout');
                item.insertBefore(placeholder, flyout);
                document.body.appendChild(flyout);
            }
            position(item, flyout);
            flyout.classList.add('mg-flyout-open');
        }

        function restore() {
            flyout.classList.remove('mg-flyout-open');
            if (placeholder && placeholder.parentNode) {
                placeholder.parentNode.replaceChild(flyout, placeholder);
                placeholder = null;
            }
        }

        function scheduleClose() {
            clearTimeout(timer);
            timer = setTimeout(restore, HIDE_DELAY);
        }

        item.addEventListener('mouseenter', open);
        item.addEventListener('mouseleave', scheduleClose);
        item.addEventListener('focusin', open);
        item.addEventListener('focusout', function (e) {
            if (!item.contains(e.relatedTarget) && !flyout.contains(e.relatedTarget)) {
                scheduleClose();
            }
        });
        flyout.addEventListener('mouseenter', function () {
            clearTimeout(timer);
        });
        flyout.addEventListener('mouseleave', scheduleClose);

        // The parent has a blank href (needed so Filament renders the child
        // list); make clicking its label jump to the first child, like WP.
        var link = item.querySelector(':scope > a.fi-sidebar-item-btn');
        var firstChild = flyout.querySelector('a.fi-sidebar-item-btn');
        if (link && firstChild) {
            link.addEventListener('click', function (e) {
                var href = firstChild.getAttribute('href');
                if (href && href !== '#') {
                    e.preventDefault();
                    window.location.href = href;
                }
            });
        }

        item._mgReposition = function () {
            if (flyout.classList.contains('mg-flyout-open')) {
                position(item, flyout);
            }
        };
    }

    function init() {
        topLevelItems().forEach(bind);
    }

    function repositionOpen() {
        topLevelItems().forEach(function (item) {
            if (item._mgReposition) {
                item._mgReposition();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', init);
    window.addEventListener('load', init);
    // Filament navigates with wire:navigate; rebind against the fresh sidebar.
    document.addEventListener('livewire:navigated', init);
    window.addEventListener('scroll', repositionOpen, true);
    window.addEventListener('resize', repositionOpen);
})();
