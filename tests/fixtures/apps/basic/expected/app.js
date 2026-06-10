/* %SRC%/app.phlo */
on('click', '#top', (el, e) => {
	app.post('items/save', {go: 1})
})
