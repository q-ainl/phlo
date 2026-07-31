// The same loader as frontend.js, but with a document under it, so the bindings that read and
// write elements can be exercised. jsdom is a dev dependency and ships with nothing: the engine
// itself carries no JavaScript toolchain.
//
// This layer exists because the logic tests cannot see the one mistake that keeps happening:
// a binding pointing at something that no longer exists. Nothing throws, the element simply
// stops updating.
const {JSDOM} = require('jsdom')
const {environment} = require('./frontend')

// A stand-in for what app.js does after a response: run every registered onExist over the
// document, then bind the events. Enough for the bindings to attach and redraw.
function mount(html, resources = ['DOM/store']){
	const dom = new JSDOM(`<!doctype html><body>${html}</body>`)
	const {window} = dom
	const env = environment({
		window,
		document: window.document,
		Node: window.Node,
		HTMLElement: window.HTMLElement,
		NodeList: window.NodeList,
		Event: window.Event,
		obj: (el, root = window.document) => typeof el === 'string' ? root.querySelector(el) : el,
		objects: (els, root = window.document) => {
			if (typeof els === 'string') els = root.querySelectorAll(els)
			return 'forEach' in els ? els : [els]
		},
		addEventListener: (...a) => window.addEventListener(...a),
	})

	// app.mod is what an apply() would reach for; the bindings lean on inner, attr and value.
	const context = env.context
	context.app.mod.inner = (els, content) => context.objects(els).forEach(el => el.innerHTML = content)
	context.app.mod.append = (els, content) => context.objects(els).forEach(el => el.insertAdjacentHTML('beforeend', content))
	context.app.mod.value = (els, value) => context.objects(els).forEach(el => el.value = value)
	context.app.mod.attr = (els, attrs) => context.objects(els).forEach(el => Object.keys(attrs).forEach(key => {
		attrs[key] === null ? el.removeAttribute(key) : el.setAttribute(key, attrs[key])
	}))
	context.app.mod.class = (els, cls) => context.objects(els).forEach(el => cls.split(' ').forEach(c => {
		c[0] === '-' ? el.classList.remove(c.slice(1)) : el.classList.add(c)
	}))
	resources.forEach(r => env.load(r))

	// An update runs the registered onExist handlers over the document again, so elements that
	// were just created get their behaviour too. Each element is handled once per selector,
	// which is what the engine's WeakMap does; without that a redraw would bind twice.
	const handled = new WeakMap
	env.apply = () => {
		env.calls.exists.forEach(({sel, cb}) => window.document.querySelectorAll(sel).forEach(el => {
			const seen = handled.get(el) || new Set
			if (seen.has(sel)) return
			seen.add(sel)
			handled.set(el, seen)
			cb(el)
		}))
	}
	context.app.update = () => {
		context.app.updates.forEach(update => update())
		env.apply()
	}
	env.apply()
	env.window = window
	env.document = window.document
	return env
}

module.exports = {mount}
