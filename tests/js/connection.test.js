// The verdict on the connection is drawn from the traffic, not from a clock: a request without an
// answer or a failed reconnect means offline, any answer or an open socket means online, and a
// socket that merely closes says nothing.
const {test} = require('node:test')
const assert = require('node:assert')
const {mount} = require('./helpers/dom')

class FakeXhr {
	constructor(){ this.listeners = {} }
	addEventListener(evt, cb){ (this.listeners[evt] ??= []).push(cb) }
	fire(evt){ (this.listeners[evt] || []).forEach(cb => cb()) }
}

const mountConnection = () => {
	const made = []
	const socket = {subs: {}, ready: true, send(){}, on(evt, cb){ (this.subs[evt] ??= []).push(cb) }, emit(evt){ (this.subs[evt] || []).forEach(cb => cb()) }}
	const env = mount('<main></main>', ['DOM/connection'], {
		phlo: {existing: new WeakMap, log(){}, error(){}, request: () => { const xhr = new FakeXhr; made.push(xhr); return xhr }},
		Date,
	})
	return {env, made, socket, app: env.context.app}
}

test('a request without an answer makes it offline, any answer makes it online', () => {
	const {env, made, app} = mountConnection()
	env.context.app.websocket = undefined
	assert.strictEqual(app.online, true)
	env.context.phlo.request('GET', 'x')
	made[0].fire('error')
	assert.strictEqual(app.online, false)
	assert.ok(env.document.body.classList.contains('offline'), 'body says so')
	env.context.phlo.request('GET', 'x')
	made[1].fire('load')
	assert.strictEqual(app.online, true)
	assert.ok(!env.document.body.classList.contains('offline'))
})

test('a closing socket is no verdict, a failed reconnect is, an open socket is online again', () => {
	const socket = {subs: {}, on(evt, cb){ (this.subs[evt] ??= []).push(cb) }, emit(evt){ (this.subs[evt] || []).forEach(cb => cb()) }}
	const env = mount('<main></main>', ['DOM/connection'], {
		phlo: {existing: new WeakMap, log(){}, error(){}, request: () => null},
		app: {mod: {}, updates: [], options: {contains: () => true}, post(){}, websocket: socket},
		Date,
	})
	const app = env.context.app
	socket.emit('error')
	assert.strictEqual(app.online, true, 'an error on a live socket says nothing')
	socket.emit('close')
	assert.strictEqual(app.online, true, 'a close says nothing either')
	socket.emit('error')
	assert.strictEqual(app.online, false, 'a reconnect that fails does')
	socket.emit('connect')
	assert.strictEqual(app.online, true)
})

test('subscribers hear every change once, and app.online cannot be assigned', () => {
	const {env, made, app} = mountConnection()
	env.context.app.websocket = undefined
	const heard = []
	app.connection.on((online, reason) => heard.push([online, reason]))
	env.context.phlo.request('GET', 'x'); made[0].fire('timeout')
	env.context.phlo.request('GET', 'x'); made[1].fire('error')
	env.context.phlo.request('GET', 'x'); made[2].fire('load')
	assert.deepStrictEqual(heard, [[false, 'timeout'], [true, 'response']])
	app.online = false
	assert.strictEqual(app.online, true, 'the verdict is read, never written')
})
