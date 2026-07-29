// Loads the script block of a frontend resource into a bare stand-in for what app.js gives it
// at runtime, so the logic can be exercised in node without a browser. No dependencies: this
// has to keep working in a repo that ships an engine, not a toolchain.
//
// What it does NOT do is a DOM. Anything that reads or writes elements belongs in a test that
// brings one; this is for the reasoning underneath.
const fs = require('fs')
const path = require('path')
const vm = require('vm')

const ENGINE = path.resolve(__dirname, '../../..')

// A resource name resolves against the engine; an absolute path lets anything built on Phlo
// load its own resources with the same helper.
function scriptOf(resource){
	const file = path.isAbsolute(resource) ? resource : path.join(ENGINE, 'resources', resource + '.phlo')
	const source = fs.readFileSync(file, 'utf8')
	const blocks = [...source.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(m => m[1])
	if (!blocks.length) throw new Error(`no script block in ${resource}`)
	return blocks.join('\n')
}

// The globals app.js defines that a resource may lean on. Each one records what it was asked
// to do, so a test can assert on the calls instead of on a rendered page.
function environment(over = {}){
	const calls = {posts: [], sent: [], logs: [], errors: [], exists: [], events: []}
	const store = {}
	const timers = []

	const env = {
		console,
		JSON,
		Math,
		Date,
		Promise,
		Set,
		Map,
		WeakMap,
		Array,
		Object,
		String,
		Number,
		Boolean,
		RegExp,
		Error,
		setTimeout,
		clearTimeout,
		phlo: {
			existing: new WeakMap,
			log: (...a) => calls.logs.push(a),
			error: (...a) => calls.errors.push(a),
		},
		app: {
			mod: {},
			updates: [],
			options: {contains: () => true},
			post: (...a) => calls.posts.push(a),
			websocket: {ready: true, send: data => calls.sent.push(data)},
		},
		// A resource registers behaviour through these; the tests care that it registered, and
		// then call the callback themselves.
		on: (evts, els, cb) => calls.events.push({evts, els, cb}),
		onExist: (sel, cb) => calls.exists.push({sel, cb}),
		obj: () => null,
		objects: () => [],
		// Real timers would make every test wait; a test drives them by hand instead.
		delay: (id, ms, cb) => {
			const at = timers.findIndex(t => t.id === id)
			if (at >= 0) timers.splice(at, 1)
			timers.push({id, cb})
		},
		localStorage: {
			getItem: key => store[key] ?? null,
			setItem: (key, value) => store[key] = String(value),
			removeItem: key => delete store[key],
		},
		addEventListener: () => {},
	}
	env.sessionStorage = env.localStorage
	env.globalThis = env
	Object.assign(env, over)

	const context = vm.createContext(env)
	return {
		context,
		calls,
		// Fire the timer that delay() is holding, oldest first: the coalescing that a resource
		// does with delay() is behaviour worth asserting, not something to wait out.
		flush(){
			while (timers.length) timers.shift().cb()
		},
		load(resource){
			vm.runInContext(scriptOf(resource), context, {filename: resource + '.phlo'})
			return context
		},
	}
}

module.exports = {environment, scriptOf}
