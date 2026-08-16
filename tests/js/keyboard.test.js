// Where the on-screen keyboard puts its keys. A modal <dialog> lives in the top layer, which
// is painted above every z-index there is, so keys appended to the body are built but never
// visible and never tappable. That failure is silent: the resource does everything right and
// the cashier sees nothing, which is exactly the kind of thing a test has to hold.
const {test} = require('node:test')
const assert = require('node:assert')
const {mount} = require('./helpers/dom')

// The resource registers its behaviour through on(); the harness records instead of binding,
// so a test fires the handler itself.
const fire = (env, evts, els, ...args) => env.calls.events
	.filter(item => item.evts === evts && item.els === els)
	.forEach(item => item.cb(env.document.querySelector(els === 'body' ? 'body' : els), ...args))

const focus = (env, selector) => {
	const target = env.document.querySelector(selector)
	fire(env, 'focusin', 'body', {target})
	return target
}

const keyboard = env => env.document.querySelector('#keyboard')

test('a field in a dialog gets its keys inside that dialog, not under it', () => {
	const env = mount('<dialog id="modal" open><form><input id="naam" data-keyboard></form></dialog>', ['DOM/keyboard'])
	focus(env, '#naam')
	assert.ok(keyboard(env), 'the keys were built')
	assert.strictEqual(keyboard(env).parentElement.id, 'modal', 'and they belong to the dialog, or nobody can reach them')
})

test('a field outside a dialog keeps its keys against the bottom of the page', () => {
	const env = mount('<form><input id="naam" data-keyboard></form>', ['DOM/keyboard'])
	focus(env, '#naam')
	assert.strictEqual(keyboard(env).parentElement.tagName, 'BODY')
	assert.ok(!keyboard(env).classList.contains('keyboard--docked'), 'nothing reserved a spot, so it docks itself')
})

test('a reserved spot takes the keys and says so, so the layout can style them differently', () => {
	const env = mount('<dialog id="modal" open><input id="naam" data-keyboard><div id="dock" data-keyboard-dock></div></dialog>', ['DOM/keyboard'])
	focus(env, '#naam')
	assert.strictEqual(keyboard(env).parentElement.id, 'dock')
	assert.ok(keyboard(env).classList.contains('keyboard--docked'))
})

test('moving to a field in another dialog takes the keys along', () => {
	const env = mount('<dialog id="een" open><input id="a" data-keyboard></dialog><dialog id="twee" open><input id="b" data-keyboard></dialog>', ['DOM/keyboard'])
	focus(env, '#a')
	assert.strictEqual(keyboard(env).parentElement.id, 'een')
	focus(env, '#b')
	assert.strictEqual(env.document.querySelectorAll('#keyboard').length, 1, 'the old set does not stay behind')
	assert.strictEqual(keyboard(env).parentElement.id, 'twee')
})

// A dialog that asks for a name and a few choices at once is one task, not two. Losing the
// keys on the first choice would send the cashier back to the field for every letter after.
test('a tap elsewhere in the same dialog leaves the keys standing', () => {
	const env = mount('<dialog id="modal" open><input id="naam" data-keyboard><button id="kleur"></button></dialog><button id="buiten"></button>', ['DOM/keyboard'])
	focus(env, '#naam')
	fire(env, 'pointerdown', 'body', {target: env.document.querySelector('#kleur')})
	assert.ok(keyboard(env), 'the choice belongs to the same form')
	fire(env, 'pointerdown', 'body', {target: env.document.querySelector('#buiten')})
	assert.strictEqual(keyboard(env), null, 'outside the dialog it is done')
})

test('a dialog that closes takes its keys and forgets the field it was serving', () => {
	const env = mount('<dialog id="modal" open><input id="naam" data-keyboard></dialog>', ['DOM/keyboard'])
	focus(env, '#naam')
	fire(env, 'close', 'dialog')
	assert.strictEqual(keyboard(env), null)
	assert.strictEqual(env.context.phlo.keyboard.open, null, 'otherwise the next field starts on top of the last one')
})

test('typing lands at the caret and reaches the field as a real keystroke', () => {
	const env = mount('<dialog id="modal" open><input id="naam" data-keyboard value="sitske"></dialog>', ['DOM/keyboard'])
	const field = focus(env, '#naam')
	let seen = 0
	field.addEventListener('input', () => seen++)
	field.setSelectionRange(3, 3)
	env.context.phlo.keyboard.press('e')
	assert.strictEqual(field.value, 'siteske', 'a correction halfway a word lands where it was tapped')
	assert.strictEqual(seen, 1, 'bindings and mirrors have to see it')
})

// The field can be redrawn away underneath the keys: the dialog gets new content while the
// keyboard is still open. Writing into a node nobody can see is invisible work.
test('the focus returning from a key does not undo shift', () => {
	const env = mount('<dialog id="modal" open><input id="naam" data-keyboard></dialog>', ['DOM/keyboard'])
	focus(env, '#naam')
	env.context.phlo.keyboard.press('shift')
	assert.strictEqual(env.context.phlo.keyboard.caps, true)
	focus(env, '#naam')
	assert.strictEqual(env.context.phlo.keyboard.caps, true, 'a browser that focuses the key puts the field back afterwards, and that is no reason to start over')
	assert.strictEqual(env.document.querySelector('#keyboard [data-keyboard-key="Q"]') !== null, true, 'the keys stay upper case until one of them is used')
})

test('keys pressed after the field is gone let go instead of typing into nothing', () => {
	const env = mount('<dialog id="modal" open><input id="naam" data-keyboard></dialog>', ['DOM/keyboard'])
	const field = focus(env, '#naam')
	field.remove()
	env.context.phlo.keyboard.press('a')
	assert.strictEqual(env.context.phlo.keyboard.open, null)
	assert.strictEqual(keyboard(env), null)
})
