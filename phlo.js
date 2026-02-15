const phlo = {
	get tech(){
		history.scrollRestoration = 'manual'
		history.state?.scroll && app.mod.scroll(history.state.scroll)
		phlo.state.replace()
		onpopstate = phlo.state.pop
		on('scroll', app.settings.scroll ?? window, () => (location.hash || delay('scroll', 333, () => phlo.state.scrollSave()), false))
		app.update()
	},
	state: {
		index: history.state?.index ?? 0,
		push: (url = null, trans = null) => history.pushState(phlo.state.build(++phlo.state.index, trans), '', url ?? location.href),
		replace: (url = null, index = null) => history.replaceState(phlo.state.build(index ?? phlo.state.index), '', url ?? location.href),
		scrollSave: (s = history.state) => s && (s.scroll = location.hash.length > 1 ? location.hash : app.settings.scroll ? obj(app.settings.scroll).scrollTop : window.scrollY, history.replaceState(s, '', location.href)),
		build: (index, trans = null) => ({index, lang: obj('html').lang, title: document.title, options: document.body.className, settings: Object.fromEntries(Object.entries(document.body.dataset)), body: document.body.innerHTML, trans: trans ?? history.state?.trans ?? 'forward', scroll: location.hash.length > 1 ? location.hash : app.settings.scroll ? obj(app.settings.scroll).scrollTop : window.scrollY}),
		pop: e => {
			if (!e.state) return phlo.anchor && [phlo.state.replace(null, ++phlo.state.index), phlo.anchor = '']
			const isBack = phlo.state.index > e.state.index
			let trans = e.state.trans
			trans && isBack && (trans = trans.replace('forward', 'back'))
			phlo.log(`⚓ HISTORY.${isBack ? 'BACK' : 'FORWARD'} ${Math.abs(phlo.state.index - e.state.index)}`)
			phlo.state.index = e.state.index
			apply({lang: e.state.lang, title: e.state.title, options: e.state.options, settings: e.state.settings, inner: {body: e.state.body}, scroll: e.state.scroll}, trans, false)
		}
	},
	event: (evts, els, cb) => objects(els).forEach(el => evts.split(' ').forEach(evt => {
		const listener = e => (cb.call(el, el, e, e.target === el) === false || phlo.log(...(delay(`evt-${evt}`, 2500) ? [evt.toUpperCase()] : [`💥 ${evt.toUpperCase()}\n`, el, '\n', e])))
		phlo.handlers.push({evt, el, listener})
		el.addEventListener(evt, listener)
	})),
	request: (method, path, data = null, blocking = true) => {
		const status = obj('html').classList
		if (blocking && status.contains('loading')) return
		path = `${location.origin}/${path}`
		const log = [`🌎 APP.${method} ${path}`]
		data && log.push('\n', data instanceof FormData ? Object.fromEntries(data.entries()) : data)
		phlo.log(...log)
		blocking && status.add('loading')
		const xhr = new XMLHttpRequest
		let pos = 0
		let buffer = ''
		const process = line => {
			let parsed
			try { parsed = JSON.parse(line) }
			catch (e){
				if (xhr.readyState !== 4) return
				const text = (new DOMParser().parseFromString(line.replace(/<\/?[^>]+(>|$)/g, ''), 'text/html')).documentElement.textContent
				parsed = {error: `${method} ${path} 🔴\n\n${text}`}
			}
			const {trans, state, ...cmds} = parsed
			apply(cmds, trans, state)
		}
		delay('waiting', 200, () => xhr.readyState === 4 || status.add('waiting'))
		xhr.onprogress = () => {
			buffer += xhr.responseText.slice(pos)
			pos = xhr.responseText.length
			let idx, start = 0
			while ((idx = buffer.indexOf('\n', start)) !== -1){
				const line = buffer.slice(start, idx)
				start = idx + 1
				line && process(line)
			}
			buffer = start ? buffer.slice(start) : buffer
			if (xhr.readyState === 4 && buffer){
				process(buffer)
				buffer = ''
			}
		}
		blocking && (xhr.onloadend = () => status.remove('loading', 'waiting'))
		xhr.open(method, path)
		if (data && !(data instanceof FormData)) [data = JSON.stringify(data), xhr.setRequestHeader('Content-Type', 'application/json')]
		xhr.setRequestHeader('X-Requested-With', 'phlo')
		xhr.send(data)
	},
	anchor: '',
	token: (length = 20) => Array(length).fill().map(() => String.fromCharCode(97 + Math.floor(Math.random() * 26))).join(''),
	events: [],
	handlers: [],
	delays: {},
	error: msg => [console.error(`%c${msg}`, 'font-weight:bold'), alert(msg)],
	log: (title, ...data) => app.options.contains('debug') && console.log(`%c${title}`, 'font-weight:bold', ...data)
}

const app = {
	get: (path, blocking = true) => phlo.request('GET', path, null, blocking),
	post: (path, data, blocking = true) => phlo.request('POST', path, data, blocking),
	put: (path, data, blocking = true) => phlo.request('PUT', path, data, blocking),
	patch: (path, data, blocking = true) => phlo.request('PATCH', path, data, blocking),
	delete: (path, blocking = true) => phlo.request('DELETE', path, null, blocking),
	get mode(){ return window.matchMedia('(display-mode:standalone)').matches },
	get state(){ return document.hidden ? 'hidden' : (document.hasFocus() ? 'active' : 'blurred') },
	get path(){ return location.pathname.substring(1) },
	options: document.body.classList,
	settings: document.body.dataset,
	mod: {
		location: path => /^https?:\/\//.test(path) ? location.assign(path) : delay('location', 100, () => app.get(path === true ? app.path : path.substring(1))),
		lang: lang => obj('html').lang = lang,
		title: title => document.title = title,
		options: options => document.body.className = options,
		settings: (key, value) => document.body.dataset[key] = value,
		remove: els => objects(els).forEach(el => el.remove()),
		css: href => obj(`link[href="${href}"]`) ? Promise.resolve() : new Promise((resolve, reject, el = document.createElement('link')) => document.head.appendChild(first(el, el.rel = 'stylesheet', el.href = href, el.onload = resolve, el.onerror = reject))),
		js: (src, nonce = obj('meta[name="nonce"]')) => obj(`script[src="${src}"]:not([defer])`) ? Promise.resolve() : new Promise((resolve, reject, el = document.createElement('script')) => document.head.appendChild(first(el, el.src = src, nonce && (el.nonce = nonce.content), el.onload = resolve, el.onerror = reject))),
		defer: (src, nonce = obj('meta[name="nonce"]')) => obj(`script[src="${src}"][defer]`) ? Promise.resolve() : new Promise((resolve, reject, el = document.createElement('script')) => document.head.appendChild(first(el, el.src = src, el.defer = true, nonce && (el.nonce = nonce.content), el.onload = resolve, el.onerror = reject))),
		main: content => obj('main') ? app.mod.outer('main', content) : app.mod.inner('body', content),
		outer: (els, content) => objects(els).forEach(el => el.outerHTML = content),
		inner: (els, content) => objects(els).forEach(el => el.innerHTML = content),
		before: (els, content) => objects(els).forEach(el => el.insertAdjacentHTML('beforebegin', content)),
		prepend: (els, content) => objects(els).forEach(el => el.insertAdjacentHTML('afterbegin', content)),
		append: (els, content) => objects(els).forEach(el => el.insertAdjacentHTML('beforeend', content)),
		after: (els, content) => objects(els).forEach(el => el.insertAdjacentHTML('afterend', content)),
		attr: (els, attr) => objects(els).forEach(el => Object.keys(attr).forEach(key => attr[key] === null ? el.removeAttribute(key) : el.setAttribute(key, attr[key]))),
		value: (els, value) => objects(els).forEach(el => el.value = value),
		data: (els, data) => objects(els).forEach(el => Object.keys(data).forEach(key => el.dataset[key] = data[key])),
		class: (els, cls) => objects(els).forEach(el => cls.split(' ').forEach(c => c[0] === '-' ? el.classList.remove(c.slice(1)) : c[0] === '!' ? el.classList.toggle(c.slice(1)) : el.classList.add(c))),
		call: cb => app[cb](),
		scroll: to => typeof to === 'string' ? document.getElementById(to.substring(1))?.scrollIntoView({behavior: 'instant'}) : obj(app.settings.scroll ?? window).scrollTo({left: 0, top: to, behavior: 'instant'}),
		log: msg => phlo.log(msg),
		error: msg => phlo.error(msg)
	},
	res: {},
	update: () => app.updates.forEach(update => update()),
	updates: [() => [phlo.handlers.forEach(handler => handler.el.removeEventListener(handler.evt, handler.listener)), phlo.handlers = [], phlo.events.forEach(item => phlo.event(item.evts, item.els, item.cb))]],
	log: true
}

const apply = (cmds, trans = false, state = true) => {
	if (trans === true) trans = 'forward'
	else if (trans && !trans.includes('forward') && !trans.includes('back')) trans += ' forward'
	let execute
	if (typeof cmds === 'function') execute = cmds
	else {
		trans && [cmds.trans = trans]
		state && 'path' in cmds && phlo.state.replace()
		phlo.anchor && [cmds.scroll = phlo.anchor]
		cmds.phlo && [phlo.log(`phlo (${cmds.phlo.length})`, `\n${cmds.phlo.join(' ')}`), delete cmds.phlo]
		cmds.debug && [phlo.log(`debug (${cmds.debug.length})`, `\n${cmds.debug.join('\n')}`), delete cmds.debug]
		cmds.dump && [cmds.dump.forEach(item => phlo.log('dump', item)), delete cmds.dump]
		execute = () => {
			'settings' in cmds && Object.keys(document.body.dataset).forEach(key => delete document.body.dataset[key])
			const promises = []
			Object.keys(app.mod).forEach(mod => {
				if (!(mod in cmds)) return
				const data = cmds[mod]
				if (data instanceof Array) data.forEach(item => promises.push(app.mod[mod](item)))
				else if (data instanceof Object) Object.keys(data).forEach(key => promises.push(app.mod[mod](key, data[key])))
				else promises.push(app.mod[mod](data))
			})
			Object.keys(app.res).forEach(responder => responder in cmds && app.res[responder](cmds))
			const replace = cmds.pathReplace
			state && (!replace && 'path' in cmds ? phlo.state.push(`/${cmds.path}${phlo.anchor}`, trans) : phlo.state.replace(replace ? `/${cmds.path}${phlo.anchor}` : null))
			phlo.anchor && [phlo.anchor = '']
			return Promise.allSettled(promises)
		}
	}
	app.log && (delay(`apply-${Object.keys(cmds).join('-')}`, 1000) ? phlo.log('✅ APPLY') : phlo.log('✅ APPLY', cmds))
	if (trans && document.startViewTransition && !window.matchMedia('(prefers-reduced-motion:reduce)').matches){
		const active = obj('html').classList
		active.add(...trans.split(' '))
		const VT = document.startViewTransition(execute)
		VT.updateCallbackDone.then(app.update)
		VT.finished.then(() => active.remove(...trans.split(' ')))
	}
	else [execute(), app.update()]
}

const delay = (id, ms, cb, ...args) => {
	const exists = !!phlo.delays[id]
	exists && clearTimeout(phlo.delays[id])
	phlo.delays[id] = setTimeout((cb, ...args) => [delete phlo.delays[id], cb && cb(...args)], ms, cb, ...args)
	return exists
}
const first = (...args) => args.shift()
const last = (...args) => args.pop()
const obj = (el, root = document) => typeof el === 'string' ? root.querySelector(el) : el
const objects = (els, root = document) => last(typeof els === 'string' && (els = root.querySelectorAll(els)), 'forEach' in els || (els = [els]), els)
const on = (evts, els, cb) => els instanceof NodeList || (els instanceof HTMLElement && (els = [els])) ? phlo.event(evts, els, cb) : phlo.events.push({evts, els, cb})
