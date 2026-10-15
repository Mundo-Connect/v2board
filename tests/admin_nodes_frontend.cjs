// Run with: node tests/admin_nodes_frontend.cjs
// Exercise the shipped bundle directly; no frontend build dependencies are needed.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/admin/umi.js'), 'utf8');
const sandbox = {};
vm.runInNewContext(source.replace('(function(e) {', '(function(e) { globalThis.modules = e; return;'), sandbox);
const modelModule = Object.values(sandbox.modules).find(fn => fn.toString().includes('name: "serverManage"'));
assert.ok(modelModule, 'server management model must exist');
const requests = [];
let responseCode = 200;
function dependency() { return Object.assign(...arguments); }
dependency.a = async () => ({ code: 200, data: [] });
dependency.b = async (url, payload) => {
    requests.push({ url, payload });
    return { code: responseCode };
};
function load() { return dependency; }
load.n = value => () => value;
load.r = value => Object.defineProperty(value, '__esModule', { value: true });
const exportsObject = {};
// Module functions live in the sandbox, including their references to window.
sandbox.window = { settings: { secure_path: 'admin-test' } };
modelModule({}, exportsObject, load);
const model = exportsObject.default;
const plain = value => JSON.parse(JSON.stringify(value));

async function runEffect(name, action, state) {
    const dispatched = [];
    const iterator = model.effects[name](action, {
        select: select => select({ serverManage: state }),
        put: action => {
            dispatched.push(action);
            if (action.type === 'setState') Object.assign(state, action.payload);
        }
    });
    let step = iterator.next();
    while (!step.done) step = iterator.next(await step.value);
    return dispatched;
}

class Component {
    constructor(props) { this.props = props; }
}
const react = {
    Component,
    Fragment: 'Fragment',
    createElement: (type, props, ...children) => ({ type, props: props || {}, children })
};
const viewContext = {};
for (const name of 'a s l c u h f d p m g v b w k C W G mAnyTLS mTuic mV2node x S T E P j M R _'.split(' ')) {
    viewContext[name] = { a: name, Item: name };
}
viewContext.i = () => Object.assign;
viewContext.y = { a: react };
viewContext.L = { e: () => 10, f: () => false };
const classStart = source.indexOf('class q extends y.a.Component {');
const classEnd = source.indexOf('t["default"]', classStart);
const viewSource = source.slice(classStart, classEnd);
const View = vm.runInNewContext(viewSource + '; q;', viewContext);
function find(tree, predicate) {
    if (!tree || typeof tree !== 'object') return undefined;
    if (predicate(tree)) return tree;
    for (const child of tree.children || []) {
        const found = Array.isArray(child)
            ? child.map(value => find(value, predicate)).find(Boolean)
            : find(child, predicate);
        if (found) return found;
    }
}
function render(state, searchKey, record, ViewClass = View) {
    const actions = [];
    const view = new ViewClass({ serverManage: state, serverGroup: { groups: [] }, dispatch: action => actions.push(action) });
    view.state.searchKey = searchKey;
    view.record = record;
    const tree = view.render();
    return {
        actions,
        tree,
        drag: find(tree, element => typeof element.props.onDragEnd === 'function'),
        table: find(tree, element => typeof element.props.rowKey === 'function')
    };
}

async function main() {
    const servers = [
        { type: 'vmess', id: 1, name: 'hidden-a' },
        { type: 'mx', id: 1, name: 'target-a' },
        { type: 'trojan', id: 1, name: 'hidden-b' },
        { type: 'mx', id: 2, name: 'target-b' }
    ];
    const original = plain(servers);
    const state = { servers, sortMode: true };
    let view = render(state, 'target');
    assert.equal(view.table.props.dataSource.length, 2);
    view.drag.props.onDragEnd(0, 1);
    assert.deepEqual(plain(view.actions), [{ type: 'serverManage/sort', fromIndex: 1, toIndex: 3 }]);
    await runEffect('sort', view.actions[0], state);
    assert.deepEqual(state.servers.map(node => node.name), ['hidden-a', 'hidden-b', 'target-b', 'target-a']);
    assert.deepEqual(servers, original, 'sorting must not mutate existing state');
    view = render(state, 'target');
    view.drag.props.onDragEnd(1, 0);
    await runEffect('sort', view.actions[0], state);
    assert.deepEqual(state.servers.map(node => node.name), ['hidden-a', 'hidden-b', 'target-a', 'target-b']);
    for (const [fromIndex, toIndex] of [[0, -1], [-1, 0], [0, 9], [0, 0], [0.5, 1]]) {
        const before = state.servers;
        assert.equal((await runEffect('sort', { fromIndex, toIndex }, state)).length, 0);
        assert.equal(state.servers, before);
    }
    view.drag.props.onDragEnd(0, -1);
    assert.equal(view.actions.length, 1, 'dropping outside rows must be ignored');
    assert.notEqual(view.table.props.rowKey(servers[0]), view.table.props.rowKey(servers[1]));
    assert.equal(view.drag.props.nodeSelector, 'tbody > tr');
    const handle = view.table.props.columns[0].render();
    assert.equal(handle.children[0].props.className, view.drag.props.handleSelector.slice(1));
    state.sortMode = false;
    const normalView = render(state);
    normalView.drag.props.onDragEnd(0, 1);
    assert.equal(normalView.actions.length, 0);
    assert.notEqual(normalView.table.props.key, view.table.props.key, 'entering sort mode must clear table filters and column sorting');
    assert.equal((await runEffect('sort', { fromIndex: 0, toIndex: 1 }, state)).length, 0);

    // 4b1604d8 fixed the local menu function I shadowing the imported assign helper I.
    // Exercise both MX edit entry points, including deferred row rendering by the table.
    const mxRecord = servers[1];
    const mxBefore = plain(mxRecord);
    const actionColumn = normalView.table.props.columns.find(column => column.key === 'action');
    const rowAction = actionColumn.render(undefined, mxRecord, 1);
    const dropdown = find(rowAction, element => Boolean(element.props.overlay));
    const inlineEditor = find(dropdown.props.overlay, element => element.props.mxOnly === true);
    const contextView = render(state, undefined, mxRecord);
    const contextEditor = find(contextView.tree, element => element.props.mxOnly === true && Boolean(element.props.record.id));
    for (const editor of [inlineEditor, contextEditor]) {
        assert.ok(editor, 'MX edit entry point must render');
        assert.deepEqual(plain(editor.props.record), { protocol: 'mx', ...mxBefore });
        assert.notEqual(editor.props.record, mxRecord, 'editor defaults must not mutate the node');
    }
    assert.deepEqual(mxRecord, mxBefore);
    // Replay the two expressions reverted by that fix: both assertions must catch the old white screen.
    const historicalBug = viewSource.replaceAll('record: Object.assign({', 'record: I()({');
    assert.notEqual(historicalBug, viewSource);
    const BrokenView = vm.runInNewContext(historicalBug + '; q;', { ...viewContext });
    assert.throws(() => {
        const broken = render(state, undefined, undefined, BrokenView);
        broken.table.props.columns.find(column => column.key === 'action').render(undefined, mxRecord, 1);
    }, /type/);
    assert.throws(() => render(state, undefined, mxRecord, BrokenView), /type/);

    const types = ['shadowsocks', 'vmess', 'vless', 'trojan', 'tuic', 'hysteria', 'anytls', 'mx', 'v2node'];
    state.servers = types.map(type => ({ type, id: 1 }));
    state.sortMode = true;
    const saved = await runEffect('saveSort', {}, state);
    assert.equal(requests[0].url, '/admin-test/server/manage/sort');
    assert.deepEqual(plain(requests[0].payload), Object.fromEntries(types.map((type, index) => [type, { 1: index }])));
    assert.ok(saved.some(action => action.type === 'getNodes'));
    responseCode = 500;
    const failed = await runEffect('saveSort', {}, state);
    assert.equal(state.fetchLoading, false);
    assert.equal(state.sortMode, true);
    assert.ok(!failed.some(action => action.type === 'getNodes'), 'failed saves must preserve the editable order');
    console.log('PASS: filtered dragging, both directions, invalid drops, immutable state, unique row keys, MX edit menus and historical shadowing regression, all 9 protocols and save failures');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
