// DOM/store without a DOM: signals, derived values, persistence, scope and the mirroring that
// carries state between clients. The bindings themselves need elements and are tested apart;
// everything here is the reasoning they sit on.
const {test} = require('node:test')
const assert = require('node:assert')
const {environment} = require('./helpers/frontend')

function fresh(){
	const env = environment()
	env.load('DOM/store')
	return env
}
const tick = () => new Promise(resolve => setTimeout(resolve, 0))

test('a value written through the proxy reads back, nested and all', () => {
	const {context: c} = fresh()
	c.app.store.receipt = {total: 12.95, lines: [{title: 'Drop', amount: 1.98}]}
	assert.strictEqual(c.app.store.receipt.total, 12.95)
	assert.strictEqual(c.phlo.store.get('receipt.lines[0].title'), 'Drop')
})

test('a change reaches both the binding on the value and the one on its parent', () => {
	const {context: c} = fresh()
	c.app.store.receipt = {total: 1}
	let parent = null, child = null
	c.phlo.store.on('receipt', v => parent = v && v.total)
	c.phlo.store.on('receipt.total', v => child = v)
	c.phlo.store.set('receipt.total', 20)
	assert.strictEqual(parent, 20, 'a parent binding must see a change underneath it')
	assert.strictEqual(child, 20)
})

test('a derived value recomputes when what it depends on moves', async () => {
	const {context: c} = fresh()
	c.app.store.receipt = {total: 20}
	c.phlo.calc.withTax = () => [['receipt.total'], Math.round(c.phlo.store.get('receipt.total') * 1.06 * 100) / 100]
	await tick()
	assert.strictEqual(c.app.calc.withTax, 21.2)
	c.phlo.store.set('receipt.total', 100)
	assert.strictEqual(c.app.calc.withTax, 106)
})

test('a formatter is applied by name, and an unknown one leaves the value alone', () => {
	const {context: c} = fresh()
	c.app.format('money', v => 'XCG ' + Number(v).toFixed(2).replace('.', ','))
	assert.strictEqual(c.phlo.store.format('money', 12.5), 'XCG 12,50')
	assert.strictEqual(c.phlo.store.format('nosuchthing', 7), 7)
})

test('a persisted path survives a reset and comes back from storage', () => {
	const {context: c} = fresh()
	c.app.persist('settings', 'local')
	c.app.store.settings = {theme: 'dark'}
	c.phlo.store.reset('settings')
	c.app.persist('settings', 'local')
	assert.strictEqual(c.app.store.settings.theme, 'dark')
})

test('resetting the page scope clears it and leaves everything else standing', () => {
	const {context: c} = fresh()
	c.app.store.page = {filter: 'all'}
	c.app.store.session = {cashier: 'Maria'}
	c.phlo.store.reset(c.phlo.store.scope)
	assert.strictEqual(c.phlo.store.get('page'), undefined)
	assert.strictEqual(c.app.store.session.cashier, 'Maria')
})

test('a synced path leaves as a sync envelope, and as a post where that is asked for', () => {
	const {context: c, calls, flush} = fresh()
	c.app.sync('receipt')
	c.phlo.store.set('receipt.total', 55)
	flush()
	assert.strictEqual(calls.sent.at(-1).sync['receipt'].total, 55)
	c.app.sync('customer', {ws: false, post: 'api/customer'})
	c.phlo.store.set('customer.name', 'Jansen')
	flush()
	assert.strictEqual(calls.posts.at(-1)[0], 'api/customer')
})

test('a mirrored value is applied but never sent back', () => {
	const {context: c, calls, flush} = fresh()
	c.app.sync('mirror')
	c.app.mod.sync('mirror', {text: 'from elsewhere'})
	flush()
	assert.strictEqual(c.phlo.store.get('mirror.text'), 'from elsewhere')
	assert.strictEqual(calls.sent.length, 0, 'echoing a mirrored value back starts a loop')
	c.phlo.store.set('mirror.text', 'mine')
	flush()
	assert.strictEqual(calls.sent.length, 1, 'a change of our own does go out')
})

test('push sends the current value even though nothing changed', () => {
	const {context: c, calls, flush} = fresh()
	c.app.sync('mirror')
	c.phlo.store.set('mirror.text', 'mine')
	flush()
	calls.sent.length = 0
	c.app.push('mirror')
	flush()
	assert.strictEqual(calls.sent.at(-1).sync['mirror'].text, 'mine')
})

test('nothing is offered to a closed socket, and it resumes once there is one', () => {
	const {context: c, calls, flush} = fresh()
	c.app.sync('mirror')
	c.app.websocket.ready = false
	c.phlo.store.set('mirror.text', 'during an outage')
	flush()
	assert.strictEqual(calls.sent.length, 0)
	c.app.websocket.ready = true
	c.phlo.store.set('mirror.text', 'after')
	flush()
	assert.strictEqual(calls.sent.length, 1)
})

test('a binding that throws does not take the others down with it', () => {
	const {context: c} = fresh()
	let second = null, parent = null
	c.phlo.store.on('break', () => parent = 'parent')
	c.phlo.store.on('break.x', () => { throw new Error('broken binding') })
	c.phlo.store.on('break.x', v => second = v)
	c.phlo.store.set('break.x', 'through')
	assert.strictEqual(second, 'through', 'the next binding on the same path must still run')
	assert.strictEqual(parent, 'parent', 'and so must the path above it')
})

test('a listener goes when the element it belongs to leaves the document', () => {
	const {context: c} = fresh()
	const element = {isConnected: true}
	const cb = () => {}
	c.phlo.store.on('loose.path', cb, element)
	assert.strictEqual(c.phlo.store.listeners['loose.path'].size, 1)
	element.isConnected = false
	c.phlo.store.sweep()
	assert.strictEqual(c.phlo.store.listeners['loose.path'], undefined)
})

test('a shorter list loses its tail instead of keeping the old entries', () => {
	const {context: c} = fresh()
	c.app.mod.store('list', [1, 2, 3])
	c.app.mod.store('list', [9])
	assert.deepStrictEqual(c.phlo.store.get('list'), [9])
})

test('store patches, replace clears first', () => {
	const {context: c} = fresh()
	c.app.store.box = {a: 1, b: 2}
	c.app.mod.store('box', {a: 9})
	assert.deepStrictEqual(c.phlo.store.get('box'), {a: 9, b: 2}, 'store keeps what it was not told about')
	c.phlo.store.replace('box', {a: 9})
	assert.deepStrictEqual(c.phlo.store.get('box'), {a: 9})
	c.phlo.store.replace('box', {})
	assert.deepStrictEqual(c.phlo.store.get('box'), {})
})

test('reset tells the bindings before it clears them away', () => {
	const {context: c} = fresh()
	let told = 'not'
	c.phlo.store.set('page.x', 'something')
	c.phlo.store.on('page.x', v => told = v === undefined ? 'empty' : v)
	c.phlo.store.reset('page')
	assert.strictEqual(told, 'empty')
})

test('replace tells its bindings exactly once', () => {
	const {context: c} = fresh()
	let count = 0
	c.phlo.store.set('counter.x', 1)
	c.phlo.store.on('counter', () => count++)
	c.phlo.store.replace('counter', {x: 2})
	assert.strictEqual(count, 1)
})

test('the bindings the resource offers are all registered', () => {
	const {calls} = fresh()
	assert.deepStrictEqual(calls.exists.map(e => e.sel).sort(), ['[data-bind-attr]', '[data-bind]', '[data-each]', '[data-store-post]'])
})
