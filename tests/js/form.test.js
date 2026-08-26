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
		FormData: class { constructor(form){ this.form = form } },
		location: {origin: 'https://admin.test'},
	})
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

test('a form without such a field submits the same way', () => {
	const {env, puts} = mountForm('<form class="async" method="put" action="/change/product/1"><input name="name" value="x"></form>')
	submit(env)
	assert.deepStrictEqual(puts.map(p => p.path), ['change/product/1'])
})
