/* Local-only regression for dashboard preferences across navigation and background reads. */
const assert = require('node:assert/strict');
const base = process.env.FOXDESK_TEST_URL || 'http://127.0.0.1:8096';
assert(['127.0.0.1','localhost'].includes(new URL(base).hostname));
const jar = {};
async function request(path, options = {}) {
    const response = await fetch(new URL(path, base), {redirect:'manual', ...options, headers:{Cookie:Object.entries(jar).map(([k,v])=>`${k}=${v}`).join('; '), ...options.headers}});
    for (const cookie of response.headers.getSetCookie()) { const pair=cookie.split(';')[0], i=pair.indexOf('='); jar[pair.slice(0,i)]=pair.slice(i+1); }
    return response;
}
const decode = s => s.replace(/&amp;/g,'&').replace(/&quot;/g,'"');
function active(html, kind, param) {
    const section=html.split(`work-${kind}-switch"`)[1]?.split('</div>')[0];
    assert(section, `${kind} switch must render`);
    const tag=section.match(/<a\b[^>]*class="[^"]*is-active[^>]*>/)?.[0];
    return tag ? new URL(decode(tag.match(/href="([^"]+)"/)[1]),base).searchParams.get(param) : 'custom';
}
async function work(query='') {
    const response=await request('/index.php?page=work'+query), html=await response.text();
    assert.equal(response.status,200);
    assert(html.includes('</html>')&&!html.includes('An unexpected error occurred.'));
    return {html, period:active(html,'period','period'), scope:active(html,'scope','time_scope')};
}
async function home(query='') {
    const response=await request('/index.php?page=api&action=app-home'+query);
    assert.equal(response.status,200);
    const value=await response.json();
    return (value.data?.home || value.home)?.time;
}
(async()=>{
    let response=await request('/index.php?page=login');
    const csrf=(await response.text()).match(/name="csrf_token" value="([^"]+)"/)[1];
    response=await request('/index.php?page=login',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({email:'admin@example.test',password:'AdminPass123!',csrf_token:csrf})});
    assert.equal(response.status,302);
    assert.equal((await work()).period,'this_month','Fresh dashboard defaults to current month');
    for (const period of ['this_month','this_week','today','last_30_days','last_month']) {
        const selected=await work('&period='+period+'&time_scope=team');
        assert.equal(selected.period,period);
        const feed=await home();
        if(feed) { assert.equal(feed.period.key,period); assert.equal(feed.scope.key,'team'); }
        await home('&period=this_week&time_scope=mine');
        await request('/index.php?page=tickets');
        for(const query of ['', '&queue=unassigned']) {
            const returned=await work(query);
            assert.equal(returned.period,period,'Background API and navigation must retain '+period);
            assert.equal(returned.scope,'team','Background API must not reset team scope');
        }
    }
    await work('&period=custom&from_date=2026-08-03&to_date=2026-08-19');
    const customFeed=await home();
    if(customFeed) {
        assert.equal(customFeed.period.key,'custom');
        assert(customFeed.period.start.startsWith('2026-08-03'));
        assert(customFeed.period.end.startsWith('2026-08-19'));
    }
    await home('&period=custom&from_date=2026-07-01&to_date=2026-07-05');
    const custom=await work();
    assert.equal(custom.period,'custom');
    assert(/name="from_date"[^>]*value="2026-08-03"/.test(custom.html));
    assert(/name="to_date"[^>]*value="2026-08-19"/.test(custom.html));
    assert.equal((await work('&period=invalid')).period,'this_month');
    console.log('PASS: month default, all five presets, background API isolation, return/reload/queue navigation, team scope and custom dates — '+base);
})().catch(error=>{console.error(error);process.exitCode=1;});
