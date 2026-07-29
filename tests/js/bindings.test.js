// The bindings of DOM/store against a real document. This is the layer that sees what the
// logic tests cannot: an element that quietly stops updating.
const {test} = require('node:test')
const assert = require('node:assert')
const {mount} = require('./helpers/dom')

test('a bound element takes the value, and follows it afterwards', () => {
	const env = mount('<span id="t" data-bind="receipt.total"></span>')
	env.context.app.mod.store('receipt', {total: 12.5})
	assert.strictEqual(env.document.querySelector('#t').textContent, '12.5')
	env.context.phlo.store.set('receipt.total', 20)
	assert.strictEqual(env.document.querySelector('#t').textContent, '20')
})

test('a formatter is applied to what the element shows', () => {
	const env = mount('<span id="t" data-bind="receipt.total" data-bind-format="money"></span>')
	env.context.app.format('money', v => 'XCG ' + Number(v).toFixed(2))
	env.context.app.mod.store('receipt', {total: 3})
	assert.strictEqual(env.document.querySelector('#t').textContent, 'XCG 3.00')
})

test('an attribute binding sets and removes, so hidden really hides again', () => {
	const env = mount('<div id="t" data-bind-attr="hidden: !receipt.total"></div>')
	env.context.app.mod.store('receipt', {total: 0})
	assert.ok(env.document.querySelector('#t').hasAttribute('hidden'), 'nothing to show, so hidden')
	env.context.phlo.store.set('receipt.total', 5)
	assert.ok(!env.document.querySelector('#t').hasAttribute('hidden'), 'and back again once there is')
})

// The class it applied last time is remembered and removed on its own. What data-bind-class is
// for is the class the markup already carried before the binding ever ran: without declaring it,
// that one is never anyone's to remove and stays for good.
test('a declared class from the markup is cleared, an undeclared one is left alone', () => {
	const env = mount('<div id="t" class="keep is-busy" data-bind-attr="class: receipt.state" data-bind-class="is-empty is-busy"></div>')
	const el = () => env.document.querySelector('#t')
	env.context.app.mod.store('receipt', {state: 'is-empty'})
	assert.ok(el().classList.contains('is-empty'))
	assert.ok(!el().classList.contains('is-busy'), 'a declared class already in the markup has to go')
	assert.ok(el().classList.contains('keep'), 'an undeclared class is not ours to touch')
})

test('and the class it applied itself is replaced on the next change', () => {
	const env = mount('<div id="t" data-bind-attr="class: receipt.state" data-bind-class="is-empty is-busy"></div>')
	const el = () => env.document.querySelector('#t')
	env.context.app.mod.store('receipt', {state: 'is-busy'})
	assert.ok(el().classList.contains('is-busy'))
	env.context.phlo.store.set('receipt.state', 'is-empty')
	assert.ok(!el().classList.contains('is-busy'))
	assert.ok(el().classList.contains('is-empty'))
})

test('a list renders a row per item and gives them back when it shrinks', () => {
	const env = mount(`<ul id="l" data-each="receipt.lines" data-key="i">
		<template><li data-bind=".title"></li></template>
	</ul>`)
	const rows = () => [...env.document.querySelectorAll('#l li')].map(li => li.textContent)
	env.context.app.mod.store('receipt', {lines: [{i: 0, title: 'Drop'}, {i: 1, title: 'Cola'}]})
	assert.deepStrictEqual(rows(), ['Drop', 'Cola'])
	env.context.app.mod.store('receipt', {lines: [{i: 0, title: 'Drop'}]})
	assert.deepStrictEqual(rows(), ['Drop'], 'a list that gets shorter must lose its tail')
	env.context.app.mod.store('receipt', {lines: []})
	assert.deepStrictEqual(rows(), [], 'and an empty list must empty the element')
})

test('a template is chosen per item', () => {
	const env = mount(`<ul id="l" data-each="receipt.lines" data-each-template="kind" data-key="i">
		<template><li class="normal" data-bind=".title"></li></template>
		<template data-template="child"><li class="child" data-bind=".title"></li></template>
	</ul>`)
	env.context.app.mod.store('receipt', {lines: [{i: 0, kind: '', title: 'Cola'}, {i: 1, kind: 'child', title: 'Deposit'}]})
	const classes = [...env.document.querySelectorAll('#l li')].map(li => li.className)
	assert.deepStrictEqual(classes, ['normal', 'child'])
})

// The mistake that keeps happening and that nothing reports: a binding names something that is
// not there. It does not throw, the element simply never updates. A test can only see it by
// asking what the markup refers to and what actually exists.
test('every calc a document binds to can be found', () => {
	const env = mount('<span data-bind="calc.knownOne"></span><div data-bind-attr="hidden: calc.knownTwo"></div>')
	env.context.phlo.calc.knownOne = () => [[], 'a']
	env.context.phlo.calc.knownTwo = () => [[], true]

	const referenced = new Set()
	env.document.querySelectorAll('[data-bind], [data-bind-attr]').forEach(el => {
		const text = (el.getAttribute('data-bind') || '') + ' ' + (el.getAttribute('data-bind-attr') || '')
		for (const m of text.matchAll(/calc\.([A-Za-z0-9_]+)/g)) referenced.add(m[1])
	})
	const missing = [...referenced].filter(name => !(name in env.context.phlo.calc))
	assert.deepStrictEqual(missing, [], 'a binding pointing at a calc that does not exist never updates and never complains')
	assert.strictEqual(referenced.size, 2, 'and the check has to actually find the references')
})
