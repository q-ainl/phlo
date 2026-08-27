// The websocket follows the page's own protocol: wss behind https, ws behind http. A till behind
// the agent's proxy runs on http://localhost, and a hard-coded wss:// there dials TLS on a port
// nobody listens on, so the socket never connects and the connection verdict calls it offline.
const {test} = require('node:test')
const assert = require('node:assert')
const {environment} = require('./helpers/frontend')

const openOn = protocol => {
	const made = []
	const env = environment({
		location: {protocol, host: 'localhost'},
		WebSocket: class { constructor(url){ made.push(url) } close(){} },
	})
	const context = env.load('DOM/websocket')
	context.app.websocket.open
	env.flush()
	return made
}

test('behind http the socket is ws, behind https it is wss', () => {
	assert.deepStrictEqual(openOn('http:'), ['ws://localhost/websocket'])
	assert.deepStrictEqual(openOn('https:'), ['wss://localhost/websocket'])
})
