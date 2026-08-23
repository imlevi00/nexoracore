/**
 * PWA install prompt — dashboard banner (Kurdish).
 */
(function () {
    'use strict';

    var config = {};
    try {
        var cfgEl = document.getElementById('kasher-pwa-config');
        if (cfgEl) {
            config = JSON.parse(cfgEl.textContent || '{}');
        }
    } catch (e) {
        config = {};
    }

    var dismissKey = config.dismissKey || 'pwa_install_dismissed';
    var deferredPrompt = null;

    function isStandalone() {
        return (
            window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true
        );
    }

    function isIOS() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    }

    // قوفڵکردنی ئاراستە بۆ ئەستوونی کاتێک ئەپەکە وەک PWA کراوەتەوە.
    // ئەمە زیادکراوە بۆ سەر ئاراستەی مانیفێست ("portrait") تاکو ئەگەر
    // بەکارهێنەر مۆبایلەکەی سووڕاند، ئەپەکە هەر لەسەر ئەستوونی بمێنێتەوە —
    // وەک ئەوەی قوفڵی سووڕانەوەی سیستەم داگیرساو بێت.
    // تێبینی: screen.orientation.lock تەنیا لە دۆخی standalone/fullscreen کاردەکات
    // و لە وێبگەڕی ئاسایی هەڵەیەک دەداتەوە کە بە بێدەنگی پشتگوێ دەخرێت.
    function lockPortrait() {
        if (!isStandalone()) {
            return;
        }
        try {
            var orientation = window.screen && window.screen.orientation;
            if (orientation && typeof orientation.lock === 'function') {
                var result = orientation.lock('portrait');
                if (result && typeof result.catch === 'function') {
                    result.catch(function () {
                        /* API بەردەست نییە یان ڕێگە پێنەدراوە — بێدەنگ تێپەڕ بکە */
                    });
                }
            }
        } catch (e) {
            /* non-fatal */
        }
    }

    function isDismissed() {
        try {
            return localStorage.getItem(dismissKey) === '1';
        } catch (e) {
            return false;
        }
    }

    function setDismissed() {
        try {
            localStorage.setItem(dismissKey, '1');
        } catch (e) {
            /* ignore */
        }
    }

    function getBanner() {
        return document.getElementById('pwaInstallBanner');
    }

    function showBanner() {
        var banner = getBanner();
        if (!banner) {
            return;
        }
        banner.hidden = false;
        banner.classList.add('show');
    }

    function hideBanner() {
        var banner = getBanner();
        if (!banner) {
            return;
        }
        banner.hidden = true;
        banner.classList.remove('show');
    }

    function openIosModal() {
        var modal = document.getElementById('pwaIosModal');
        if (modal) {
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeIosModal() {
        var modal = document.getElementById('pwaIosModal');
        if (modal) {
            modal.classList.remove('show');
            document.body.style.overflow = '';
        }
    }

    window.closePwaIosModal = closeIosModal;

    function registerServiceWorker() {
        if (!('serviceWorker' in navigator) || !config.swUrl) {
            return;
        }
        // updateViaCache: 'none' → وێبگەڕ هەرگیز کاشی HTTP بۆ فایلی SW بەکارنەهێنێت،
        // بۆیە هەموو جارێک وەشانی نوێی sw.js دەپشکنێت و یەکسەر دایدەگرێت.
        navigator.serviceWorker.register(config.swUrl, {
            scope: config.scope || '/',
            updateViaCache: 'none'
        }).then(function (registration) {
            // یەکسەر پشکنینی نوێکردنەوە بکە تاکو SW-ی کۆن بە خێرایی بگۆڕدرێت.
            registration.update();
        }).catch(function () {
            /* non-fatal */
        });
    }

    function bindBannerActions() {
        var installBtn = document.getElementById('pwaInstallBtn');
        var dismissBtn = document.getElementById('pwaInstallDismiss');

        if (installBtn) {
            installBtn.addEventListener('click', function () {
                if (isIOS()) {
                    openIosModal();
                    return;
                }
                if (!deferredPrompt) {
                    return;
                }
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(function (choice) {
                    if (choice.outcome === 'accepted') {
                        hideBanner();
                    }
                    deferredPrompt = null;
                });
            });
        }

        if (dismissBtn) {
            dismissBtn.addEventListener('click', function () {
                setDismissed();
                hideBanner();
            });
        }

        var iosModal = document.getElementById('pwaIosModal');
        if (iosModal) {
            iosModal.addEventListener('click', function (e) {
                if (e.target === iosModal) {
                    closeIosModal();
                }
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeIosModal();
            }
        });
    }

    function maybeShowBanner() {
        if (isStandalone() || isDismissed()) {
            hideBanner();
            return;
        }

        if (isIOS()) {
            showBanner();
            return;
        }

        if (deferredPrompt) {
            showBanner();
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        registerServiceWorker();
        bindBannerActions();
        lockPortrait();

        // ئەگەر مۆبایلەکە سووڕا، دووبارە قوفڵی بکەرەوە بۆ ئەستوونی.
        window.addEventListener('orientationchange', lockPortrait);

        if (isStandalone() || isDismissed()) {
            hideBanner();
            return;
        }

        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            deferredPrompt = e;
            maybeShowBanner();
        });

        window.addEventListener('appinstalled', function () {
            hideBanner();
            deferredPrompt = null;
        });

        // iOS has no beforeinstallprompt — show after short delay
        if (isIOS()) {
            setTimeout(maybeShowBanner, 800);
        }
    });
})();
