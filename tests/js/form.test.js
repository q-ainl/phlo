// An async form finds its target in the action attribute. The property of the same name is not
// reliable: a field named action shadows it with the element itself, so a record form for a model
// with such a field (a menu button with an action, say) threw on submit and never left the page.
const {test} = require('node:test')
const assert = require('node:assert')
const {mount} = require('./helpers/dom')

const submit = env => {
	const form = env.document.querySelector('form.async')
	env.calls.events.filter(item => item.evts === 'submit' && item.els === 'form.async').forEach(item => item.cb(form, {preventDefault(){}}))
}

const mountForm = html => {
	const puts = []
	const env = mount(html, ['DOM/form'], {
		URL: URL,
		URLSearchParams: URLSearchParams,
		FormData: class {
			constructor(form){ this.form = form }
			*[Symbol.iterator](){ for (const el of this.form.querySelectorAll('input[name], select[name], textarea[name]')) yield [el.name, el.value] }
		},
		location: {origin: 'https://admin.test', pathname: '/change/here', href: 'https://admin.test/change/here'},
	}, 'https://admin.test/change/here')
	env.context.app.put = (path, data) => puts.push({path, data})
	env.context.app.get = (path, data) => puts.push({path, data})
	return {env, puts}
}

test('a form with a field named action still submits to its action attribute', () => {
	const {env, puts} = mountForm('<form class="async" method="put" action="/change/menu_button/29"><input name="action" value="search"><input name="label" value="x"></form>')
	submit(env)
	assert.strictEqual(puts.length, 1, 'the form went out')
	assert.strictEqual(puts[0].path, 'change/menu_button/29', 'to the path in the attribute, without the leading slash')
})

test('a field named method shadows the property just the same, and the attribute still wins', () => {
	const {env, puts} = mountForm('<form class="async" method="put" action="/change/x/1"><input name="method" value="delete"></form>')
	submit(env)
	assert.deepStrictEqual(puts.map(p => p.path), ['change/x/1'])
})

test('a form without an action posts to the page it is on', () => {
	const {env, puts} = mountForm('<form class="async" method="put"><input name="q" value="x"></form>')
	submit(env)
	assert.deepStrictEqual(puts.map(p => p.path), ['change/here'])
})

// A relative action means what it means everywhere else in HTML: relative to the page it sits on,
// not to the site root. Resolving against the origin sent /change/here's save to /save.
test('a relative action resolves against the page, not the site root', () => {
	const {env, puts} = mountForm('<form class="async" method="put" action="save"><input name="q" value="x"></form>')
	submit(env)
	assert.deepStrictEqual(puts.map(p => p.path), ['change/save'])
})

// A base element is what the browser resolves a relative action against, so the form has to do the
// same. An empty or absent action is the current address and a base does not move it.
test('a base element moves a relative action, but not an empty one', () => {
	const {env, puts} = mountForm('<base href="/forms/"><form class="async" method="put" action="save"></form>')
	submit(env)
	assert.deepStrictEqual(puts.map(p => p.path), ['forms/save'])

	const plain = mountForm('<base href="/forms/"><form class="async" method="put"></form>')
	submit(plain.env)
	assert.deepStrictEqual(plain.puts.map(p => p.path), ['change/here'])
})

// A GET form used to send nothing at all: the second argument of app.get is the blocking flag, not
// the data, so the fields were dropped in silence.
test('a get form sends its fields as a query string', () => {
	const {env, puts} = mountForm('<form class="async" method="get" action="/zoek"><input name="q" value="brood"><input name="tag" value="een"><input name="tag" value="twee"><input name="naam" value="café"></form>')
	submit(env)
	assert.strictEqual(puts.length, 1)
	assert.strictEqual(puts[0].path, 'zoek?q=brood&tag=een&tag=twee&naam=caf%C3%A9')
	assert.strictEqual(puts[0].data, undefined, 'a get carries nothing in its body')
})

test('a get form replaces the query string on its action, a post keeps it', () => {
	const get = mountForm('<form class="async" method="get" action="/zoek?scope=alles"><input name="q" value="x"></form>')
	submit(get.env)
	assert.deepStrictEqual(get.puts.map(p => p.path), ['zoek?q=x'], 'the fields are the query string now')

	const put = mountForm('<form class="async" method="put" action="/opslaan?scope=alles"><input name="q" value="x"></form>')
	submit(put.env)
	assert.deepStrictEqual(put.puts.map(p => p.path), ['opslaan?scope=alles'], 'a body method keeps what the action said')
})

test('a form without such a field submits the same way', () => {
	const {env, puts} = mountForm('<form class="async" method="put" action="/change/product/1"><input name="name" value="x"></form>')
	submit(env)
	assert.deepStrictEqual(puts.map(p => p.path), ['change/product/1'])
})
