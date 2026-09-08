/**
 * File: assets/js/argent-video-player.js
 */
(function () {
    'use strict';

    function restoreProgressive(video) {
        var fallback = video.getAttribute('data-argent-fallback');
        video.removeAttribute('src');
        if (fallback) {
            video.src = fallback;
        }
        video.dataset.argentHlsInitialized = 'fallback';
        video.load();
    }

    function initialize(video) {
        if (!video || video.dataset.argentHlsInitialized === '1') {
            return;
        }
        var source = video.getAttribute('data-argent-hls');
        if (!source) {
            return;
        }
        video.dataset.argentHlsInitialized = '1';

        if (window.Hls && window.Hls.isSupported()) {
            var hls = new window.Hls({
                startLevel: 0,
                capLevelToPlayerSize: true,
                maxBufferLength: 30,
                backBufferLength: 30
            });
            video.argentHls = hls;
            hls.attachMedia(video);
            hls.on(window.Hls.Events.MEDIA_ATTACHED, function () {
                hls.loadSource(source);
            });
            hls.on(window.Hls.Events.ERROR, function (_event, data) {
                if (!data || !data.fatal) {
                    return;
                }
                if (data.type === window.Hls.ErrorTypes.NETWORK_ERROR) {
                    hls.startLoad();
                } else if (data.type === window.Hls.ErrorTypes.MEDIA_ERROR) {
                    hls.recoverMediaError();
                } else {
                    hls.destroy();
                    restoreProgressive(video);
                }
            });
            return;
        }

        if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = source;
            return;
        }

        restoreProgressive(video);
    }

    function initializeAll(root) {
        var videos = (root || document).querySelectorAll('video[data-argent-hls]');
        Array.prototype.forEach.call(videos, initialize);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initializeAll(document); });
    } else {
        initializeAll(document);
    }

    if (window.MutationObserver) {
        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                Array.prototype.forEach.call(mutation.addedNodes || [], function (node) {
                    if (!node || node.nodeType !== 1) {
                        return;
                    }
                    if (node.matches && node.matches('video[data-argent-hls]')) {
                        initialize(node);
                    }
                    if (node.querySelectorAll) {
                        initializeAll(node);
                    }
                });
            });
        }).observe(document.documentElement, { childList: true, subtree: true });
    }
}());

// EOF: assets/js/argent-video-player.js
