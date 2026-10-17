const vm = require('node:vm');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const source = fs.readFileSync(__dirname + '/../assets/js/aggregate-views.js', 'utf8');
let checks = 0;
function check(label, fn) { fn(); checks++; console.log('PASS ' + label); }
function setup(options = {}) {
    const requests = [], events = {}, timers = new Map(); let serial = 0;
    const location = new URL('https://example.test/');
    const document = {cookie: options.cookie || '', visibilityState: options.hidden ? 'hidden' : 'visible', prerendering: false,
        addEventListener(name, fn) { events[name] = fn; }, getElementById() { return null; }, ...options.document};
    const window = {aceAggregateViewsConfig: {endpoint: 'https://example.test/wp-json/ace-seo/v1/aggregate-view'}, ...options.window};
    const history = {};
    for (const name of ['pushState', 'replaceState']) history[name] = function(state, title, url) { if (url) location.href = new URL(url, location).href; return 'original result'; };
    const sandbox = {window, location, document, history, navigator: { ...options.navigator }, URL, Set,
        addEventListener(name, fn) { events[name] = fn; }, setTimeout(fn) { timers.set(++serial, fn); return serial; }, clearTimeout(id) { timers.delete(id); },
        fetch(url, init) { requests.push({url, init}); return Promise.resolve(); }};
    vm.runInNewContext(source, sandbox);
    const flush = () => { const callbacks = [...timers.values()]; timers.clear(); callbacks.forEach(f => f()); };
    return {requests, events, location, document, window, history, flush, sandbox};
}
check('Initial visible page sends exactly one minimal request', () => { const x=setup();x.flush();assert.equal(x.requests.length,1);assert.equal(x.requests[0].init.body,'{"v":1}');assert.equal(x.requests[0].init.credentials,'omit');assert.equal(x.requests[0].init.referrerPolicy,'no-referrer');assert.equal(x.document.cookie,''); });
check('Hydration replaceState and visibility do not duplicate initial view', () => {const x=setup();x.flush();x.history.replaceState({},'', '/');x.events.visibilitychange();x.flush();assert.equal(x.requests.length,1);});
check('React navigation and back each count once', () => {const x=setup();x.flush();assert.equal(x.history.pushState({},'', '/jobs'),'original result');x.flush();x.history.pushState({},'', '/weather');x.flush();x.location.href='https://example.test/jobs';x.events.popstate();x.flush();assert.equal(x.requests.length,4);});
check('Ordinary anchors do not count but app hash routes do', () => {const x=setup();x.flush();x.location.hash='section';x.events.popstate();x.flush();assert.equal(x.requests.length,1);x.history.pushState({},'', '/#explore');x.flush();assert.equal(x.requests.length,2);});
check('Hidden/prerendered views wait until visible', () => {const x=setup({hidden:true});x.flush();assert.equal(x.requests.length,0);x.document.visibilityState='visible';x.events.visibilitychange();x.flush();assert.equal(x.requests.length,1);});
check('GPC suppresses baseline',()=>{const x=setup({navigator:{globalPrivacyControl:true}});x.flush();assert.equal(x.requests.length,0);});
check('DNT suppresses baseline',()=>{const x=setup({navigator:{doNotTrack:'1'}});x.flush();assert.equal(x.requests.length,0);});
for(const cookie of ['ace_basic_views_off=1','viewed_cookie_policy=yes; cookielawinfo-checkbox-analytics=no','cookieyes-consent=consent:yes,analytics:no','cookieyes-consent=consent:no,action:yes,analytics:no','cmplz_banner-status=dismissed; cmplz_statistics=deny']) check('Respects stored refusal '+cookie.split('=')[0],()=>{const x=setup({cookie});x.flush();assert.equal(x.requests.length,0);});
check('Unanswered native consent allows only baseline; accepted consent adds no duplicate baseline',()=>{const x=setup({window:{aceConsent:{answered:false,has:()=>false}}});x.flush();x.events['ace:consent']({detail:{analytics:true}});x.flush();assert.equal(x.requests.length,1);});
check('Live refusal stops subsequent views',()=>{const x=setup();x.flush();x.events['ace:consent']({detail:{analytics:false}});x.history.pushState({},'', '/jobs');x.flush();assert.equal(x.requests.length,1);});
check('Explicit baseline opt-out stores only preference and stops subsequent views',()=>{const x=setup();x.flush();x.window.aceAggregateViews.setOptOut(true);assert.match(x.document.cookie,/^ace_basic_views_off=1;/);x.history.pushState({},'', '/jobs');x.flush();assert.equal(x.requests.length,1);});
check('Back-forward cache restoration is a new view',()=>{const x=setup();x.flush();x.events.pageshow({persisted:true});assert.equal(x.requests.length,2);});
check('Double-loaded script does not install a second tracker',()=>{const x=setup();vm.runInNewContext(source,x.sandbox);x.flush();assert.equal(x.requests.length,1);});
check('CookieYes undecided defaults do not block the minimal counter',()=>{const x=setup({cookie:'cookieyes-consent=consent:no,action:,analytics:no'});x.flush();assert.equal(x.requests.length,1);});

for (const shown of [false,true]) check('Consent notice follows explicit UI flag '+shown,()=>{let appended=0; const element=()=>({dataset:{},style:{},appendChild(){},addEventListener(){}}); const x=setup({window:{aceAggregateViewsConfig:{endpoint:'/counter',showPrivacy:shown}},document:{querySelector:()=>({querySelector:()=>null,appendChild(){appended++;}}),createElement:element}}); x.flush();assert.equal(appended,shown?1:0);assert.equal(x.requests.length,1);});
console.log(checks+' checks passed');
