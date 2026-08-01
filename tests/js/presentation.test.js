// The presentation player is steered from the keyboard: a document-level keymap reaches the
// fullscreen, focused or only player on the page. What must hold: keys reach the right player,
// typing elsewhere never steers a presentation, and the player's own sliders keep working.
const {test} = require('node:test')
const assert = require('node:assert')
const {mount} = require('./helpers/dom')

const payload = () => JSON.stringify({
	presentation: {
		size: {w: 1280, h: 720},
		title: 'demo',
		texts: [{content: 'Hallo', start: 0, duration: 10}],
	},
	transcript: {segments: [{start: 0, end: 4, text: 'Hallo daar'}]},
})

const embed = () => `<div class="pp-embed"><script type="application/json">${payload()}</script></div>`

// The player leans on browser APIs jsdom does not carry; the animation loop is deliberately
// inert so a test asserts on the state a key changed, not on a running clock.
const stubs = () => ({
	ResizeObserver: class {
		observe(){}
	},
	requestAnimationFrame: () => 0,
	cancelAnimationFrame: () => {},
	setInterval: () => 0,
	clearInterval: () => {},
	performance,
})

const settle = () => new Promise(res => setTimeout(res, 0))

const press = (env, key, target) => (target || env.document).dispatchEvent(
	new env.window.KeyboardEvent('keydown', {key, bubbles: true, cancelable: true}))

test('space toggles the only player on the page, no focus needed', async () => {
	const env = mount(embed(), ['DOM/presentation'], stubs())
	await settle()
	const root = env.document.querySelector('.pp-root')
	assert.ok(root, 'the embed booted')
	assert.ok(root.classList.contains('pp-paused'))
	assert.strictEqual(press(env, ' '), false, 'the key is consumed, the page must not scroll')
	await settle()
	assert.ok(!root.classList.contains('pp-paused'), 'space plays')
	press(env, ' ')
	assert.ok(root.classList.contains('pp-paused'), 'space pauses again')
})

test('arrows seek along the timeline and clamp at the edges', async () => {
	const env = mount(embed(), ['DOM/presentation'], stubs())
	await settle()
	const time = env.document.querySelector('.pp-time')
	press(env, ' ')
	await settle()
	press(env, 'ArrowRight')
	assert.strictEqual(time.textContent, '0:05 / 0:10')
	press(env, 'ArrowLeft')
	press(env, 'ArrowLeft')
	assert.strictEqual(time.textContent, '0:00 / 0:10', 'seeking below zero stops at the start')
	press(env, 'End')
	assert.strictEqual(time.textContent, '0:10 / 0:10')
	press(env, 'Home')
	assert.strictEqual(time.textContent, '0:00 / 0:10')
})

test('m mutes, arrows set the volume', async () => {
	const env = mount(embed(), ['DOM/presentation'], stubs())
	await settle()
	const volBtn = env.document.querySelector('.pp-controls button[aria-label="Mute"]')
	press(env, 'm')
	assert.strictEqual(volBtn.getAttribute('aria-label'), 'Unmute')
	press(env, 'm')
	assert.strictEqual(volBtn.getAttribute('aria-label'), 'Mute')
	press(env, 'ArrowDown')
	assert.strictEqual(env.document.querySelector('.pp-vol').value, '95')
	press(env, 'ArrowUp')
	assert.strictEqual(env.document.querySelector('.pp-vol').value, '100', 'volume clamps at full')
})

test('c toggles the subtitles', async () => {
	const env = mount(embed(), ['DOM/presentation'], stubs())
	await settle()
	const cc = env.document.querySelector('.pp-controls button[aria-label="Subtitles"]')
	assert.ok(!cc.classList.contains('pp-off'))
	press(env, 'c')
	assert.ok(cc.classList.contains('pp-off'))
	press(env, 'c')
	assert.ok(!cc.classList.contains('pp-off'))
})

test('typing in a field elsewhere on the page leaves the player alone', async () => {
	const env = mount(embed() + '<input id="veld">', ['DOM/presentation'], stubs())
	await settle()
	const root = env.document.querySelector('.pp-root')
	assert.strictEqual(press(env, ' ', env.document.querySelector('#veld')), true, 'the field keeps its keystroke')
	assert.ok(root.classList.contains('pp-paused'))
})

test('the player\'s own sliders are not "typing": space on the seek bar still toggles', async () => {
	const env = mount(embed(), ['DOM/presentation'], stubs())
	await settle()
	const root = env.document.querySelector('.pp-root')
	assert.strictEqual(press(env, ' ', env.document.querySelector('.pp-seek')), false)
	await settle()
	assert.ok(!root.classList.contains('pp-paused'))
})

test('with two players a key steers the focused one and nothing without focus', async () => {
	const env = mount(embed() + embed(), ['DOM/presentation'], stubs())
	await settle()
	const roots = [...env.document.querySelectorAll('.pp-root')]
	assert.strictEqual(press(env, ' '), true, 'ambiguous, so no player takes it')
	assert.ok(roots.every(r => r.classList.contains('pp-paused')))
	roots[1].focus()
	press(env, ' ')
	await settle()
	assert.ok(roots[0].classList.contains('pp-paused'))
	assert.ok(!roots[1].classList.contains('pp-paused'), 'only the focused player plays')
})
