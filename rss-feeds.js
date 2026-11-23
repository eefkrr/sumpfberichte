// rss-feeds.js
document.addEventListener('DOMContentLoaded', () => {
    const configFiles = { mitte: 'feeds_mitte.json', berlin: 'feeds_berlin.json' };
    const viewHashMap = { dates: 'datum', categories: 'quelle' };
    const hashViewMap = { datum: 'dates', quelle: 'categories', dates: 'dates', categories: 'categories' };

    const container = document.getElementById('feeds');
    const categoryTab = document.getElementById('categoryTab');
    const dateTab = document.getElementById('dateTab');
    const berlinButton = document.querySelector('.config-button[data-config="berlin"]');
    const mitteButton = document.querySelector('.config-button[data-config="mitte"]');
    const loadingIndicator = document.getElementById('loadingIndicator');
    const lastUpdatedEl = document.getElementById('lastUpdated');
    const radarOnlyButton = document.getElementById('radarOnlyButton');

    let loadingCount = 0;
    let activeRequestId = 0;
    let activeConfig = 'berlin';
    let activeView = 'dates';
    let radarOnly = false;
    let currentData = null;
    const previousVisitByConfig = {};

    function getPreviousVisit(configKey) {
        if (Object.prototype.hasOwnProperty.call(previousVisitByConfig, configKey)) {
            return previousVisitByConfig[configKey];
        }
        let prev = null;
        try {
            const raw = localStorage.getItem(`lastVisit_${configKey}`);
            if (raw) {
                const parsed = parseInt(raw, 10);
                if (!isNaN(parsed)) prev = parsed;
            }
            localStorage.setItem(`lastVisit_${configKey}`, String(Date.now()));
        } catch (e) {}
        previousVisitByConfig[configKey] = prev;
        return prev;
    }

    function appendArticleBadges(containerEl, art) {
        if (art.isNew) {
            const newBadge = document.createElement('span');
            newBadge.className = 'new-badge';
            newBadge.textContent = 'Neu';
            containerEl.appendChild(newBadge);
        }
        if (art.paywall) {
            const plusBadge = document.createElement('span');
            plusBadge.className = 'plus-badge';
            plusBadge.title = 'Bezahlartikel';
            plusBadge.textContent = 'Plus';
            containerEl.appendChild(plusBadge);
        }
    }

    function showLoading(on) {
        if (!loadingIndicator) return;
        if (on) {
            loadingCount++;
            loadingIndicator.hidden = false;
            loadingIndicator.setAttribute('aria-busy', 'true');
        } else {
            loadingCount = Math.max(0, loadingCount - 1);
            if (loadingCount === 0) {
                loadingIndicator.hidden = true;
                loadingIndicator.setAttribute('aria-busy', 'false');
            }
        }
    }

    window.addEventListener('unhandledrejection', (e) => {
        console.error('Unhandled rejection:', e.reason);
        loadingCount = 0;
        showLoading(false);
    });

    function readInitialState() {
        let cfg = null;
        let view = null;

        const hash = location.hash.replace(/^#/, '').trim().toLowerCase();
        if (hash) {
            const parts = hash.split('/').filter(Boolean);
            if (parts[0] && configFiles[parts[0]]) cfg = parts[0];
            if (parts[1] && hashViewMap[parts[1]]) view = hashViewMap[parts[1]];
        }

        if (!cfg) {
            const storedCfg = localStorage.getItem('activeConfig');
            if (storedCfg && configFiles[storedCfg]) cfg = storedCfg;
        }
        if (!view) {
            const storedView = localStorage.getItem('lastView');
            if (storedView === 'categories' || storedView === 'dates') view = storedView;
        }

        activeConfig = cfg || 'berlin';
        activeView = view || 'dates';
    }

    function persistState(updateHash = true) {
        try {
            localStorage.setItem('activeConfig', activeConfig);
            localStorage.setItem('lastView', activeView);
        } catch (e) { }

        if (updateHash) {
            const targetHash = `#${activeConfig}/${viewHashMap[activeView] || 'datum'}`;
            if (location.hash !== targetHash) {
                history.replaceState(null, '', targetHash);
            }
        }
    }

    function updateNavigationUI() {
        document.querySelectorAll('.config-button').forEach(btn => {
            const isActive = btn.dataset.config === activeConfig;
            btn.classList.toggle('active', isActive);
            btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });

        const isCategories = activeView === 'categories';
        categoryTab.classList.toggle('active', isCategories);
        categoryTab.setAttribute('aria-selected', isCategories ? 'true' : 'false');

        dateTab.classList.toggle('active', !isCategories);
        dateTab.setAttribute('aria-selected', !isCategories ? 'true' : 'false');

        if (radarOnlyButton) {
            radarOnlyButton.classList.toggle('active', radarOnly);
            radarOnlyButton.setAttribute('aria-pressed', radarOnly ? 'true' : 'false');
        }
    }

    function updateLastUpdatedDisplay(data) {
        if (!lastUpdatedEl) return;
        const ts = data?.config?.last_updated;
        if (!ts) {
            lastUpdatedEl.textContent = '';
            return;
        }
        const d = new Date(ts);
        if (isNaN(d.getTime())) {
            lastUpdatedEl.textContent = '';
            return;
        }
        const formatted = d.toLocaleString('de-DE', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        lastUpdatedEl.textContent = `Stand: ${formatted} Uhr`;
    }

    function safeLinkElement(href, title) {
        const a = document.createElement('a');
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.textContent = title || '(kein Titel)';
        try {
            const url = new URL(href, location.origin);
            if (url.protocol === 'http:' || url.protocol === 'https:') a.href = url.href;
            else a.href = '#';
        } catch (e) {
            a.href = '#';
        }
        return a;
    }

    function cacheSet(key, value, ttlSec = 30) {
        try {
            sessionStorage.setItem(key, JSON.stringify({ t: Date.now(), ttl: ttlSec * 1000, v: value }));
        } catch (e) { }
    }

    function cacheGet(key) {
        try {
            const p = JSON.parse(sessionStorage.getItem(key));
            if (p && (Date.now() - p.t < p.ttl)) return p.v;
            sessionStorage.removeItem(key);
        } catch (e) { }
        return null;
    }

    function parseArticleDate(article) {
        const candidates = [article.pubDate, article['dc:date'], article.isoDate, article.updated, article.published];
        for (const c of candidates) {
            if (c) {
                const d = new Date(c);
                if (!isNaN(d.getTime())) return d;
            }
        }
        return null;
    }

    function isWithinLastDays(date, daysToShow) {
        if (!date || isNaN(date.getTime())) return false;
        const cutoff = new Date();
        cutoff.setDate(cutoff.getDate() - daysToShow);
        cutoff.setHours(0, 0, 0, 0);
        return date >= cutoff;
    }

    function formatDate(pubDate) {
        return new Date(pubDate).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
    }

    function escapeRegex(str) {
        return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function compileKeywordRegexes(keywords) {
        return (keywords || [])
            .filter(kw => typeof kw === 'string' && kw.trim() !== '')
            .map(kw => {
                const escaped = escapeRegex(kw.trim());
                // Unicode-Wortgrenzen + häufige deutsche Flexionsendungen (case-insensitive)
                return new RegExp(`(?<![\\p{L}\\p{N}_])${escaped}(?:e|en|er|es|n|s)?(?![\\p{L}\\p{N}_])`, 'iu');
            });
    }

    function highlightKeywords(title, keywordRegexes) {
        if (!title || !keywordRegexes.length) return '';
        if (/\bGrüne Woche\b/i.test(title)) return '';
        for (const re of keywordRegexes) {
            if (re.test(title)) return 'highlight';
        }
        return '';
    }

    function createCollapsibleHeader(tagName, source, count, ul, extraClass = '') {
        const heading = document.createElement(tagName);
        heading.className = extraClass ? `${extraClass} collapsible-header` : 'collapsible-header';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'collapsible-toggle';
        btn.setAttribute('aria-expanded', 'true');

        const caret = document.createElement('span');
        caret.className = 'caret';
        caret.setAttribute('aria-hidden', 'true');
        caret.textContent = '▼';

        const label = document.createElement('span');
        label.textContent = source;

        const countSpan = document.createElement('span');
        countSpan.className = 'article-count';
        countSpan.textContent = String(count);

        btn.append(caret, label, countSpan);
        heading.appendChild(btn);

        btn.addEventListener('click', () => {
            const isCollapsed = ul.classList.toggle('collapsed');
            btn.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
            caret.textContent = isCollapsed ? '▶' : '▼';
        });

        return heading;
    }

    function showError(message) {
        if (!container) return;
        container.innerHTML = '';
        const wrapper = document.createElement('div');
        wrapper.className = 'hinweistext';
        wrapper.style.color = 'crimson';
        wrapper.textContent = message;
        container.appendChild(wrapper);
    }

    async function fetchFeedsData(configKey) {
        const filePath = configFiles[configKey] || configFiles.berlin;
        const cacheKey = `feeds:${filePath}`;
        const cached = cacheGet(cacheKey);
        if (cached) return cached;

        showLoading(true);
        try {
            const resp = await fetch(filePath, { cache: 'no-cache' });
            if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
            const data = await resp.json();
            cacheSet(cacheKey, data, 30);
            return data;
        } finally {
            showLoading(false);
        }
    }

    function getNormalizedArticles(data) {
        const articles = [];
        const daysToShow = data.config?.days_to_show || 10;
        const keywordRegexes = compileKeywordRegexes(data.config?.keywords || []);
        const now = new Date();
        const prevVisit = getPreviousVisit(activeConfig);

        Object.keys(data.feeds || {}).forEach(originalSource => {
            const feed = data.feeds[originalSource];
            const displaySource = originalSource.replace(/\s*\(.*?\)\s*/g, '');

            if (typeof feed === 'string' && feed.startsWith('❌')) {
                articles.push({
                    source: displaySource,
                    title: feed,
                    link: '#',
                    pubDate: now,
                    dateStr: formatDate(now),
                    isError: true,
                    paywall: false,
                    isNew: false,
                    highlightClass: ''
                });
                return;
            }

            const items = Array.isArray(feed?.channel?.item)
                ? feed.channel.item
                : feed?.channel?.item
                    ? [feed.channel.item]
                    : [];

            items.forEach(item => {
                const pubDate = parseArticleDate(item);
                if (!pubDate || pubDate > now || !isWithinLastDays(pubDate, daysToShow)) return;

                const title = item.title || '(kein Titel)';
                const highlightClass = highlightKeywords(title, keywordRegexes);
                if (radarOnly && !highlightClass) return;

                articles.push({
                    source: displaySource,
                    title,
                    link: item.link || '#',
                    pubDate,
                    dateStr: formatDate(pubDate),
                    isError: false,
                    paywall: Boolean(item.paywall),
                    isNew: prevVisit !== null && pubDate.getTime() > prevVisit,
                    highlightClass
                });
            });
        });
        return articles;
    }

    const articleTemplate = document.createElement('template');
    articleTemplate.innerHTML = `<li class="article-item"><span class="article-link"></span></li>`;

    function displayFeedsByDate(data) {
        try {
            container.className = 'date-container';
            container.innerHTML = '';
            const articles = getNormalizedArticles(data);
            const daysToShow = data.config?.days_to_show || 10;

            if (articles.length === 0) {
                const msg = radarOnly
                    ? 'Keine Schlagwort-Treffer in diesem Zeitraum gefunden.'
                    : `Keine Artikel in den letzten ${daysToShow} Tagen erfasst`;
                showError(msg);
                return;
            }

            const grouped = {};
            articles.forEach(art => {
                grouped[art.dateStr] = grouped[art.dateStr] || {};
                grouped[art.dateStr][art.source] = grouped[art.dateStr][art.source] || [];
                grouped[art.dateStr][art.source].push(art);
            });

            const sortedDates = Object.keys(grouped).sort((a, b) => {
                const dA = a.split('.');
                const dB = b.split('.');
                return new Date(dB[2], dB[1] - 1, dB[0]) - new Date(dA[2], dA[1] - 1, dA[0]);
            });

            const todayStr = formatDate(new Date());
            const frag = document.createDocumentFragment();
            sortedDates.forEach(dateStr => {
                const dateBlock = document.createElement('div');
                dateBlock.classList.add('date-block');
                const isToday = dateStr === todayStr;
                if (isToday) dateBlock.classList.add('is-today');

                const dParts = dateStr.split('.');
                const dObj = new Date(dParts[2], dParts[1] - 1, dParts[0]);

                const dateHeader = document.createElement('h2');
                dateHeader.textContent = dObj.toLocaleDateString('de-DE', {
                    weekday: 'short',
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric'
                });
                if (isToday) {
                    const badge = document.createElement('span');
                    badge.className = 'today-badge';
                    badge.textContent = 'Heute';
                    dateHeader.appendChild(badge);
                }
                dateBlock.appendChild(dateHeader);

                const highlightedItems = [];
                Object.keys(grouped[dateStr]).forEach(source => {
                    const items = grouped[dateStr][source];
                    const ul = document.createElement('ul');
                    ul.className = 'collapsible-content';

                    const sourceHeader = createCollapsibleHeader('h3', source, items.length, ul);
                    dateBlock.appendChild(sourceHeader);

                    items.forEach(art => {
                        const node = articleTemplate.content.cloneNode(true);
                        const li = node.querySelector('li');
                        const linkWrap = li.querySelector('.article-link');
                        linkWrap.appendChild(safeLinkElement(art.link, art.title));
                        appendArticleBadges(linkWrap, art);
                        if (art.highlightClass) {
                            li.classList.add(art.highlightClass);
                            highlightedItems.push(art);
                        }
                        ul.appendChild(li);
                    });
                    dateBlock.appendChild(ul);
                });

                if (highlightedItems.length > 0) {
                    const radarBox = document.createElement('div');
                    radarBox.className = 'radar-box';
                    const alertHeader = document.createElement('h3');
                    alertHeader.textContent = '🎯 Schlagwort-Radar';
                    const alertList = document.createElement('ul');
                    alertList.className = 'highlight-section';
                    highlightedItems.forEach(item => {
                        const li = document.createElement('li');
                        const strong = document.createElement('strong');
                        strong.appendChild(safeLinkElement(item.link, item.title));
                        li.appendChild(strong);
                        appendArticleBadges(li, item);
                        const span = document.createElement('span');
                        span.className = 'source-name';
                        span.textContent = `· ${item.source}`;
                        li.appendChild(span);
                        alertList.appendChild(li);
                    });
                    radarBox.append(alertHeader, alertList);
                    dateBlock.insertBefore(radarBox, dateBlock.childNodes[1] || null);
                }
                frag.appendChild(dateBlock);
            });
            container.appendChild(frag);
        } catch (err) {
            console.error(err);
            showError('Fehler beim Anzeigen der Datumsansicht.');
        }
    }

    function displayFeedsByCategory(data) {
        try {
            container.className = 'category-container';
            container.innerHTML = '';
            const articles = getNormalizedArticles(data);
            const perFeedMax = data.config?.max_items ? parseInt(data.config.max_items, 10) : 15;
            const daysToShow = data.config?.days_to_show || 10;

            const grouped = {};
            Object.keys(data.feeds || {}).forEach(src => {
                const displaySrc = src.replace(/\s*\(.*?\)\s*/g, '');
                grouped[displaySrc] = [];
            });

            articles.forEach(art => {
                if (grouped[art.source] && grouped[art.source].length < perFeedMax) {
                    grouped[art.source].push(art);
                } else if (!grouped[art.source]) {
                    grouped[art.source] = [art];
                }
            });

            const frag = document.createDocumentFragment();
            Object.keys(grouped).forEach(source => {
                const items = grouped[source];
                const col = document.createElement('div');
                col.className = 'feed-column';

                if (items.length > 0) {
                    const ul = document.createElement('ul');
                    ul.className = 'collapsible-content';
                    const h2 = createCollapsibleHeader('h2', source, items.length, ul, 'category-header');
                    col.appendChild(h2);

                    items.forEach(art => {
                        const li = document.createElement('li');
                        li.appendChild(safeLinkElement(art.link, art.title));
                        appendArticleBadges(li, art);
                        if (art.highlightClass) li.classList.add(art.highlightClass);
                        ul.appendChild(li);
                    });
                    col.appendChild(ul);
                } else {
                    const h2 = document.createElement('h2');
                    h2.className = 'category-header';
                    h2.textContent = source;
                    col.appendChild(h2);

                    const note = document.createElement('p');
                    note.className = 'no-articles';
                    note.textContent = `Keine Artikel in den letzten ${daysToShow} Tagen erfasst`;
                    col.appendChild(note);
                }
                frag.appendChild(col);
            });
            container.appendChild(frag);
        } catch (err) {
            console.error(err);
            showError('Fehler beim Anzeigen der Kategorieansicht.');
        }
    }

    function renderData(data) {
        updateLastUpdatedDisplay(data);
        if (activeView === 'categories') displayFeedsByCategory(data);
        else displayFeedsByDate(data);
    }

    async function renderCurrentView() {
        const requestId = ++activeRequestId;
        updateNavigationUI();
        try {
            const data = await fetchFeedsData(activeConfig);
            if (requestId !== activeRequestId) return; // Veraltete Antwort verwerfen
            currentData = data;
            renderData(data);
        } catch (err) {
            if (requestId !== activeRequestId) return;
            console.error(err);
            showError('Fehler beim Laden der Feeds.');
        }
    }

    function switchFeedConfig(config) {
        if (!configFiles[config]) return;
        activeConfig = config;
        persistState(true);
        renderCurrentView();
    }

    function switchView(view) {
        if (view !== 'categories' && view !== 'dates') return;
        activeView = view;
        persistState(true);
        renderCurrentView();
    }

    categoryTab.addEventListener('click', () => switchView('categories'));
    dateTab.addEventListener('click', () => switchView('dates'));
    mitteButton.addEventListener('click', () => switchFeedConfig('mitte'));
    berlinButton.addEventListener('click', () => switchFeedConfig('berlin'));

    if (radarOnlyButton) {
        radarOnlyButton.addEventListener('click', () => {
            radarOnly = !radarOnly;
            updateNavigationUI();
            if (currentData) renderData(currentData);
        });
    }

    window.addEventListener('hashchange', () => {
        readInitialState();
        persistState(false);
        renderCurrentView();
    });

    readInitialState();
    persistState(true);
    renderCurrentView();
});
