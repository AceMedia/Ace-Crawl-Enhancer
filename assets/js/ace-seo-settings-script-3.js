// Fallback initializer: if main admin JS fails to bind tabs.
(function(){
    if (window.AceCrawlEnhancerAdminFallbackApplied) return;
    window.AceCrawlEnhancerAdminFallbackApplied = true;

    var tabs = document.querySelectorAll('.ace-redis-sidebar .nav-tab');
    if (!tabs.length) return;

    function updateSubnav(tabId) {
        var subnavBlocks = document.querySelectorAll('.ace-redis-sidebar .ace-tab-subnav');
        subnavBlocks.forEach(function(block){
            block.classList.remove('active');
        });
        var activeBlock = document.querySelector('.ace-redis-sidebar .ace-tab-subnav[data-tab="' + tabId + '"]');
        if (activeBlock) activeBlock.classList.add('active');
    }

    function setActiveSubtabLink(tabId, sectionId) {
        document.querySelectorAll('.ace-subtab-link').forEach(function(link){
            link.classList.remove('is-active');
        });

        if (!tabId || !sectionId) return;

        var activeLink = document.querySelector('.ace-subtab-link[data-target-tab="' + tabId + '"][data-target-group="' + sectionId + '"]');
        if (activeLink) activeLink.classList.add('is-active');
    }

    function activateTab(targetHash) {
        if (!targetHash || targetHash.charAt(0) !== '#') return;

        document.querySelectorAll('.ace-redis-sidebar .nav-tab').forEach(function(t){
            t.classList.remove('nav-tab-active');
        });

        var activeTab = document.querySelector('.ace-redis-sidebar .nav-tab[href="' + targetHash + '"]');
        if (activeTab) activeTab.classList.add('nav-tab-active');

        document.querySelectorAll('.tab-content').forEach(function(panel){
            panel.classList.remove('active');
        });

        var panel = document.querySelector(targetHash);
        if (panel) panel.classList.add('active');

        updateSubnav(targetHash.replace('#', ''));
        setActiveSubtabLink('', '');

        if (history && history.replaceState) {
            history.replaceState(null, '', targetHash);
        }
    }

    function activateSubSection(tabId, sectionId) {
        var tabHash = '#' + tabId;
        activateTab(tabHash);

        if (!sectionId) return;

        var section = document.getElementById(sectionId);
        if (section) {
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        setActiveSubtabLink(tabId, sectionId);

        if (history && history.replaceState) {
            history.replaceState(null, '', tabHash + '/' + sectionId);
        }
    }

    var anyActive = document.querySelector('.tab-content.active');
    if (!anyActive) {
        var first = tabs[0];
        var firstHref = first.getAttribute('href');
        if (firstHref) {
            activateTab(firstHref);
        }
    } else {
        var activeTab = document.querySelector('.ace-redis-sidebar .nav-tab.nav-tab-active');
        if (activeTab) {
            updateSubnav((activeTab.getAttribute('href') || '').replace('#', ''));
        }
    }

    tabs.forEach(function(tab){
        tab.addEventListener('click', function(e){
            if (e.defaultPrevented) return;
            e.preventDefault();
            activateTab(tab.getAttribute('href'));
        });
    });

    document.querySelectorAll('.ace-subtab-link').forEach(function(link){
        link.addEventListener('click', function(e){
            e.preventDefault();
            var tabId = link.getAttribute('data-target-tab');
            var sectionId = link.getAttribute('data-target-group');
            if (!tabId) return;
            activateSubSection(tabId, sectionId);
        });
    });

    var hash = window.location.hash || '';
    if (hash.indexOf('/') > -1) {
        var parts = hash.replace('#', '').split('/');
        if (parts.length >= 1 && parts[0]) {
            activateSubSection(parts[0], parts[1] || '');
        }
    } else if (hash) {
        activateTab(hash);
    }
})();
