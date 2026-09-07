const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('assets/js/ticket-workflow.js', 'utf8');
function capture(hrefs) {
    const stored = new Map(); let click;
    const queue = {querySelectorAll: () => hrefs.map(href => ({href, getClientRects: () => [1]}))};
    const link = {href: hrefs[0], closest: selector => selector.includes('data-work-ticket-list') ? queue : null};
    const document = {
        readyState: 'complete',
        getElementById: () => ({dataset: {scope: 'tenant-user'}}),
        querySelector: () => null,
        querySelectorAll: () => {throw new Error('Queue must not include links from unrelated page sections');},
        addEventListener: (type, fn) => {if(type === 'click') click = fn;}
    };
    vm.runInNewContext(source, {document, URL, location: {href:'https://app.test/index.php?page=work',origin:'https://app.test',pathname:'/index.php'}, window:{scrollY:42}, sessionStorage:{getItem:key=>stored.get(key),setItem:(key,value)=>stored.set(key,value)}});
    click({target:{closest:()=>link}});
    return JSON.parse([...stored.values()][0]);
}
const base = 'https://app.test/index.php?page=ticket&';
const single = capture([base+'t=only-ticket']);
assert.equal(single.links.length, 1, 'A one-ticket work queue must have no next ticket from time reports');
assert.equal(single.scroll, 42);
const duplicates = capture([base+'id=12',base+'id=12#comment-form',base+'id=13']);
assert.equal(duplicates.links.length, 2, 'Repeated activity links must not repeat the same ticket');
assert.equal(duplicates.links[1], base+'id=13');
console.log('Workflow queue section and duplicate-ticket regressions passed.');
