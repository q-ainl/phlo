// app.stream keeps the server's download name on blobs. Content-Disposition has two
// filename forms with different escaping rules, and ordinary quoted names may contain
// characters such as a literal percent sign or semicolon.
const {test} = require('node:test')
const assert = require('node:assert')
const {environment} = require('./helpers/frontend')

class NamedFile extends Blob {
	constructor(parts, name){
		super(parts)
		this.name = name
	}
}

function fresh(disposition){
	const fetch = async () => ({
		ok: true,
		headers: {get: name => name === 'content-disposition' ? disposition : 'application/pdf'},
		blob: async () => new Blob(['pdf'], {type: 'application/pdf'}),
	})
	const env = environment({fetch, location: {origin: 'https://example.test'}, Blob, File: NamedFile, FormData})
	env.load('stream')
	return env.context.app.stream('download', null, false, 'blob')
}

test('blob filenames follow Content-Disposition without breaking valid plain names', async () => {
	const encoded = await fresh('attachment; filename=factuur%20%C3%A9.pdf')
	assert.strictEqual(encoded.name, 'factuur é.pdf', 'output(filename:) keeps its encoded filename contract')

	const plain = await fresh('attachment; filename="100% complete; final.pdf"')
	assert.strictEqual(plain.name, '100% complete; final.pdf', 'literal percent signs and quoted semicolons survive')

	const extended = await fresh("attachment; filename=invoice.pdf; filename*=UTF-8'nl'factuur%20%C3%A9.pdf")
	assert.strictEqual(extended.name, 'factuur é.pdf', 'the RFC 5987 filename takes precedence over the fallback')
})

test('a blob without a download name stays a Blob', async () => {
	const blob = await fresh(null)
	assert.ok(blob instanceof Blob)
	assert.strictEqual(blob instanceof NamedFile, false)
})
