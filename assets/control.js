on('click', '#dash-top-nav a,[data-nav="file"]', (a, e) => {
	if (e.ctrlKey || e.shiftKey || e.metaKey) return
	e.preventDefault()
	const href = a.getAttribute('href') || ''
	window.dashNextFileKey = a.dataset.fileKey || ''
	app.get(href.replace(/^\//, ''))
})
app.dashActiveFile = () => {
	if (!window.dashNextFileKey) return
	document.querySelectorAll('.dash-file-nav a.active').forEach(a => a.classList.remove('active'))
	const active = document.querySelector(`.dash-file-nav a[data-file-key="${CSS.escape(window.dashNextFileKey)}"]`)
	if (active) active.classList.add('active')
	window.dashNextFileKey = ''
}
on('submit', 'form[method="post"]', (form, e) => {
	e.preventDefault()
	app.post(form.getAttribute('action').slice(1), new FormData(form))
})
on('submit', 'form.dash-search-form', (form, e) => {
	e.preventDefault()
	const q = (new FormData(form).get('q') || '').trim()
	const action = form.getAttribute('action') || ''
	const [base, existing] = (action + '?').split('?')
	const params = new URLSearchParams(existing)
	if (!q){
		params.delete('q')
		app.get(base.replace(/^\//, '') + (params.toString() ? '?' + params.toString() : ''))
		return
	}
	params.set('q', q)
	app.get(base.replace(/^\//, '') + '?' + params.toString())
})
on('search', 'form.dash-search-form input[type="search"]', inp => {
	if (inp.value) return
	const form = inp.closest('form'), action = form.getAttribute('action') || ''
	const [base, existing] = (action + '?').split('?')
	const params = new URLSearchParams(existing)
	params.delete('q')
	app.get(base.replace(/^\//, '') + (params.toString() ? '?' + params.toString() : ''))
})
on('click', '.close-panel', btn => {
	const p = document.getElementById(btn.dataset.panel || '')
	if (p) p.style.display = 'none'
})
on('input', '#config-json', ta => {
	const btn = document.getElementById('config-save'), msg = document.getElementById('config-msg')
	if (!btn || !msg) return
	try {
		JSON.parse(ta.value)
		btn.disabled = false
		msg.textContent = ''
	}
	catch (err){
		btn.disabled = true
		msg.textContent = err.message
	}
})
on('input', '#resource-search', inp => {
	const q = inp.value.toLowerCase(), searching = q.length > 0
	document.querySelectorAll('.resource-group').forEach(group => {
		let visible = 0
		group.classList.toggle('search-open', searching)
		group.querySelectorAll('.resource-item').forEach(item => {
			const text = item.textContent.toLowerCase()
			const show = !q || text.includes(q)
			item.style.display = show ? '' : 'none'
			show && visible++
		})
		group.style.display = visible ? '' : 'none'
	})
})
on('search', '#resource-search', inp => inp.dispatchEvent(new Event('input', {bubbles: true})))
// Config groups: header toggles between selected-only (collapsed) and all libraries.
on('click', '.resource-group header', h => h.parentElement.classList.toggle('collapsed'))
// Theme picker (build-site themes): icon button opens a named popover, like the dashboard's CMS picker.
on('click', '.ctl-theme-btn', btn => {
	const tool = btn.closest('.ctl-theme-tool')
	const open = !tool.classList.contains('open')
	document.querySelectorAll('.ctl-theme-tool.open').forEach(t => t.classList.remove('open'))
	if (open){
		tool.classList.add('open')
		const pop = tool.querySelector('.ctl-theme-pop'), r = btn.getBoundingClientRect()
		pop.style.left = r.left + 'px'
		pop.style.bottom = (innerHeight - r.top + 6) + 'px'
	}
	return false
})
// The theme links swap the stylesheet via the framework (apply + transition); just close the picker on use or outside click.
on('click', '.ctl-theme-pop a', a => a.closest('.ctl-theme-tool').classList.remove('open'))
on('click', 'html', (el, e) => { if (!e.target.closest('.ctl-theme-tool')) document.querySelectorAll('.ctl-theme-tool.open').forEach(t => t.classList.remove('open')) })
on('input', '#error-search', inp => {
	const q = inp.value.toLowerCase()
	document.querySelectorAll('.dash-errors-body tbody tr').forEach(row => {
		const text = (row.querySelector('[data-filter]')?.dataset.filter || row.textContent).toLowerCase()
		row.style.display = (!q || text.includes(q)) ? '' : 'none'
	})
})
on('search', '#error-search', inp => inp.dispatchEvent(new Event('input', {bubbles: true})))
app.updates.push(() => {
	const p = document.getElementById('config-json-pending'), ta = document.getElementById('config-json')
	if (p && ta && p.textContent.trim()){
		ta.value = p.textContent
		p.textContent = ''
	}
})
app.updates.push(() => {
	if (typeof highlight_Phlo !== 'function') return
	document.querySelectorAll('ol.phlo-src:not([data-hl])').forEach(ol => {
		const src = [...ol.querySelectorAll('li')].map(li => li.textContent).join('\n')
		ol.innerHTML = highlight_Phlo(src).split('\n').map((l, i) => '<li id="L' + (i + 1) + '">' + (l || '') + '</li>').join('')
		ol.dataset.hl = '1'
	})
})

const onExist = (() => {
	const seen = new WeakMap, hooks = []
	app.updates.push(() => {
		hooks.forEach(({sel, cb}) => {
			document.querySelectorAll(sel).forEach(el => {
				if (!seen.has(el)){
					seen.set(el, 1)
					cb(el)
				}
			})
		})
	})
	return (sel, cb) => hooks.push({sel, cb})
})()

onExist('#rg-canvas', canvas => {
	const ctx      = canvas.getContext('2d')
	const raw      = JSON.parse(atob(canvas.dataset.graph))
	const dashBase = (canvas.dataset.dashboard || '').replace(/\/$/, '')
	const mode     = canvas.dataset.mode || 'backend'

	const palette = ['#2f9fa8','#4a7db5','#9b6dbf','#c97b3c','#5cb8a0','#a07840','#7a5a9a','#5a9a5a','#9a5a5a','#5a7a9a']
	const groupColors = {}
	let gi = 0
	const nodes = (raw.nodes || []).map(n => ({
		...n,
		group: (n.file || n.label || n.id).split(/[/.]/)[0],
		r: 10,
		x: (Math.random()-.5)*60,
		y: (Math.random()-.5)*60,
		vx: 0,
		vy: 0
	}))
	const edges = (raw.edges || []).map(e => ({...e, a: e.from, b: e.to}))
	const map = {}
	for (const n of nodes){
		if (!groupColors[n.group]) groupColors[n.group] = palette[gi++ % palette.length]
		map[n.id] = n
	}
	const degree = {}
	const edgeByNode = {}
	for (const n of nodes) edgeByNode[n.id] = []
	for (const e of edges){
		degree[e.a] = (degree[e.a] || 0) + 1
		degree[e.b] = (degree[e.b] || 0) + 1
		if (edgeByNode[e.a]) edgeByNode[e.a].push(e)
		if (edgeByNode[e.b]) edgeByNode[e.b].push(e)
	}
	for (const n of nodes){
		const d = degree[n.id] || 0
		if (n.type === 'file') n.r = 13 + Math.min(d, 10) * 0.5
		else if (n.type === 'resource') n.r = 12 + Math.min(d, 10) * 0.6
		else if (n.type === 'selector') n.r = 7 + Math.min(d, 8) * 0.4
		else if (n.type === 'route' || n.type === 'view') n.r = 9 + Math.min(d, 8) * 0.35
		else n.r = 8 + Math.min(d, 6) * 0.4
	}
	const FOV = 220, ZOFF = 300
	const neutralSc = FOV / (FOV + ZOFF)
	for (const n of nodes){
		n.z = (Math.random()-.5)*150
		n.vz = 0
		n.fz = 0
		n.px = n.x
		n.py = n.y
		n.pz = 0
		n.pscale = neutralSc
	}

	const hidden = new Set()

	function isHidden(n){
		if ((n.categories || [n.type]).some(type => hidden.has('node:'+type))) return true
		if (hidden.has('node:'+n.type)) return true
		return false
	}
	function isHiddenEdge(e){ return hidden.has('edge:'+e.kind) }

	const legend = document.querySelector('.rg-legend')
	if (legend){
		legend.innerHTML = ''
		const addLegend = (key, label, dotClass) => {
			const el = document.createElement('span')
			el.className = 'rg-legend-item'
			el.dataset.type = key
			el.innerHTML = '<span class="rg-dot '+dotClass+'"></span>'+label
			legend.appendChild(el)
			el.addEventListener('click', () => {
				const t = el.dataset.type
				if (hidden.has(t)){
					hidden.delete(t)
					el.classList.remove('inactive')
				}
				else {
					hidden.add(t)
					el.classList.add('inactive')
				}
				for (const n of nodes){
					n.vx = 0
					n.vy = 0
					n.vz = 0
				}
				start()
			})
		}
		const hasNode = key => nodes.some(n => n.type === key || (n.categories || []).includes(key))
		const hasEdge = key => edges.some(e => e.kind === key)
		if (mode === 'frontend'){
			if (hasNode('app')) addLegend('node:app', 'App', 'rg-dot--app')
			if (hasNode('resource')) addLegend('node:resource', 'Resource', 'rg-dot--resource')
			if (hasNode('selector')) addLegend('node:selector', 'Selector', 'rg-dot--sel-class')
			if (hasNode('frontend-api')) addLegend('node:frontend-api', 'API', 'rg-dot--sel-id')
			if (hasNode('binding')) addLegend('node:binding', 'Binding', 'rg-dot--sel-class')
			if (hasEdge('style')) addLegend('edge:style', 'Style', 'rg-dot--ext')
			if (hasEdge('script')) addLegend('edge:script', 'Script', 'rg-dot--native')
			if (hasEdge('selector-def')) addLegend('edge:selector-def', 'Selector def', 'rg-dot--sel-class')
			if (hasEdge('selector-use')) addLegend('edge:selector-use', 'Selector use', 'rg-dot--sel-id')
			if (hasEdge('provides')) addLegend('edge:provides', 'Provides', 'rg-dot--resource')
			if (hasEdge('binds')) addLegend('edge:binds', 'Binds', 'rg-dot--app')
		} else {
			if (hasNode('app')) addLegend('node:app', 'App', 'rg-dot--app')
			if (hasNode('resource')) addLegend('node:resource', 'Resource', 'rg-dot--resource')
			if (hasEdge('calls')) addLegend('edge:calls', 'Calls', 'rg-dot--app')
			if (hasEdge('depends')) addLegend('edge:depends', 'Depends', 'rg-dot--ext')
			if (hasEdge('uses')) addLegend('edge:uses', 'Uses', 'rg-dot--resource')
			if (hasEdge('shared')) addLegend('edge:shared', 'Shared', 'rg-dot--sel-id')
			if (hasEdge('extends')) addLegend('edge:extends', 'Extends', 'rg-dot--native')
			if (hasEdge('requires')) addLegend('edge:requires', 'Requires', 'rg-dot--sel-id')
		}
	}

	function sourceHref(fileKey, mode, anchor){
		const qs = mode && mode !== 'app' ? '?mode=' + encodeURIComponent(mode) : ''
		return dashBase.replace(/^\//, '') + '/source/' + encodeURIComponent(fileKey) + qs + (anchor || '')
	}
	function openNode(n){
		if (!n || !dashBase) return
		if (n.file){
			app.get(sourceHref(n.file, n.mode, n.line ? '#L' + n.line : ''))
		}
		else if (n.mode === 'native'){
			app.get(dashBase.replace(/^\//, '') + '/source?mode=native' + (n.line ? '#L' + n.line : ''))
		}
	}

	const dimKey   = 'phlo-graph:dim:'   + (dashBase || location.pathname)
	const traceKey = 'phlo-graph:trace:' + (dashBase || location.pathname)
	let is3D = localStorage.getItem(dimKey) !== '2d'
	let tracePlay = !!canvas.dataset.trace && localStorage.getItem(traceKey) !== 'off'
	let traceEvents = []
	let traceEventNodes = []
	let traceEventIdx = 0
	let traceParticle = null
	let traceGlowing  = []
	let traceTrails   = []
	let traceTrailPos = []
	let traceArgsByNode = {}
	let traceTotalMs = 0
	let traceTotalEvents = 0
	let traceHitFade = 0
	let traceScrubbing = false
	let traceLastNode = null
	let ox = 0, oy = 0, zoom = 1, drag = null, panFrom = null, panOrig = null, tid = null, didDrag = false, hoverNode = null, touchNode = null, touchWasHover = false, touchStart = null, rotY = 0, rotX = 0, autoRotate = true
	let pinchDist0 = null, pinchZoom0 = null, pinchAngle0 = null, pinchRotY0 = 0, pinchRotX0 = 0, pinchMid0 = null, pinchOx0 = 0, pinchOy0 = 0

	new ResizeObserver(() => { if (checkSize() && !tid) draw() }).observe(canvas)

	function checkSize(){
		if (canvas.clientWidth === canvas.width && canvas.clientHeight === canvas.height) return false
		canvas.width  = canvas.clientWidth
		canvas.height = canvas.clientHeight
		return true
	}
	function toW(sx, sy){ return {x:(sx-canvas.width/2-ox)/zoom, y:(sy-canvas.height/2-oy)/zoom} }
	function hitNode(wx, wy){
		const order = nodes.slice().sort((a,b) => (a.pz||0) - (b.pz||0))
		for (const n of order){
			const pr = n.r * (n.pscale||1)
			const dx = wx-(n.px??n.x), dy = wy-(n.py??n.y)
			if (dx*dx+dy*dy <= pr*pr) return n
		}
		return null
	}
	function isConnectedToHover(n){
		if (!hoverNode || n === hoverNode) return false
		for (const e of edgeByNode[hoverNode.id] || []){
			if (isHiddenEdge(e)) continue
			if ((e.a === hoverNode.id && e.b === n.id) || (e.b === hoverNode.id && e.a === n.id)) return true
		}
		return false
	}
	function isHoverEdge(e){
		return hoverNode && !isHiddenEdge(e) && (e.a === hoverNode.id || e.b === hoverNode.id)
	}
	const adjList = {}
	for (const n of nodes) adjList[n.id] = []
	for (const e of edges){
		if (adjList[e.a]) adjList[e.a].push(e.b)
		if (adjList[e.b]) adjList[e.b].push(e.a)
	}
	const compOf = {}
	let compCount = 0
	for (const n of nodes){
		if (compOf[n.id] !== undefined) continue
		const cid = compCount++
		const stack = [n.id]
		while (stack.length){
			const id = stack.pop()
			if (compOf[id] !== undefined) continue
			compOf[id] = cid
			for (const nb of adjList[id]) stack.push(nb)
		}
	}
	const compSize = {}
	for (const n of nodes) compSize[compOf[n.id]] = (compSize[compOf[n.id]] || 0) + 1
	const mainComp = parseInt(Object.entries(compSize).sort((a,b) => b[1]-a[1])[0][0])
	const inMain = new Set(nodes.filter(n => compOf[n.id] === mainComp).map(n => n.id))
	function sim(){
		const K = 60, REP = 3000, C = 0.010 * Math.max(1, nodes.length / 60), DP = is3D ? 0.65 : 0.52
		for (const n of nodes){
			n.fx = 0
			n.fy = 0
			n.fz = 0
		}
		for (let i = 0; i < nodes.length; i++){
			for (let j = i+1; j < nodes.length; j++){
				const a = nodes[i], b = nodes[j]
				if (isHidden(a) || isHidden(b)) continue
				const dx = a.x-b.x, dy = a.y-b.y, dz = is3D ? a.z-b.z : 0
				const d = Math.sqrt(dx*dx+dy*dy+dz*dz)||0.01
				const min = a.r + b.r + 18
				const f = REP * (min / 34)/(d*d)
				a.fx += dx/d*f
				a.fy += dy/d*f
				if (is3D) a.fz += dz/d*f
				b.fx -= dx/d*f
				b.fy -= dy/d*f
				if (is3D) b.fz -= dz/d*f
			}
		}
		for (const e of edges){
			const a = map[e.a], b = map[e.b]
			if (!a||!b||isHidden(a)||isHidden(b)||isHiddenEdge(e)) continue
			const dx = b.x-a.x, dy = b.y-a.y, dz = is3D ? b.z-a.z : 0
			const d = Math.sqrt(dx*dx+dy*dy+dz*dz)||0.01
			const k = Math.max(K, a.r + b.r + 30)
			const f = (d-k)*0.04
			a.fx += dx/d*f
			a.fy += dy/d*f
			if (is3D) a.fz += dz/d*f
			b.fx -= dx/d*f
			b.fy -= dy/d*f
			if (is3D) b.fz -= dz/d*f
		}
		let mv = false
		for (const n of nodes){
			if (n.pin || isHidden(n)) continue
			const gc = inMain.has(n.id) ? C : C * 4
			n.fx -= n.x*gc
			n.fy -= n.y*gc
			if (is3D) n.fz -= n.z*gc
			n.vx = (n.vx+n.fx)*DP
			n.vy = (n.vy+n.fy)*DP
			if (is3D) n.vz = (n.vz+n.fz)*DP
			n.x += n.vx
			n.y += n.vy
			if (is3D) n.z += n.vz
			if (Math.abs(n.vx)+Math.abs(n.vy)+(is3D ? Math.abs(n.vz) : 0) > (is3D ? 0.05 : 0.12)) mv = true
		}
		for (let i = 0; i < nodes.length; i++){
			for (let j = i+1; j < nodes.length; j++){
				const a = nodes[i], b = nodes[j]
				if (isHidden(a) || isHidden(b)) continue
				const dx = a.x-b.x, dy = a.y-b.y, dz = is3D ? a.z-b.z : 0
				const d = Math.sqrt(dx*dx+dy*dy+dz*dz)||0.01
				const gap = a.r + b.r + 8 - d
				if (gap > 1.5){
					const half = gap * 0.3
					const nx = dx/d, ny = dy/d, nz = is3D ? dz/d : 0
					if (!a.pin){
						a.x += nx*half
						a.y += ny*half
						if (is3D) a.z += nz*half
						const dotA = a.vx*nx + a.vy*ny + (is3D ? a.vz*nz : 0)
						if (dotA < 0){ a.vx -= dotA*nx; a.vy -= dotA*ny; if (is3D) a.vz -= dotA*nz }
					}
					if (!b.pin){
						b.x -= nx*half
						b.y -= ny*half
						if (is3D) b.z -= nz*half
						const dotB = b.vx*nx + b.vy*ny + (is3D ? b.vz*nz : 0)
						if (dotB > 0){ b.vx -= dotB*nx; b.vy -= dotB*ny; if (is3D) b.vz -= dotB*nz }
					}
					mv = true
				}
			}
		}
		return mv
	}
	function col(n){
		if (n.type === 'file') return groupColors[n.group]||'#2f9fa8'
		if ((n.categories || []).includes('native')) return '#d2a8ff'
		if ((n.categories || []).includes('resource')) return '#e08c42'
		if (n.type === 'route') return '#f2cc60'
		if (n.type === 'view') return '#79c0ff'
		if (n.type === 'script') return '#4a7db5'
		if (n.type === 'style') return '#9b6dbf'
		if (n.type === 'selector') return (n.categories || []).includes('id') ? '#9b6dbf' : '#5cb8a0'
		if (n.type === 'frontend-api') return '#56d4a1'
		return '#7a8a9a'
	}
	function hexShift(hex, amt){
		const r = parseInt(hex.slice(1,3),16)
		const g = parseInt(hex.slice(3,5),16)
		const b = parseInt(hex.slice(5,7),16)
		const c = v => Math.max(0, Math.min(255, Math.round(v + amt*255)))
		return `rgb(${c(r)},${c(g)},${c(b)})`
	}
	function updateProjections(){
		if (!is3D){
			for (const n of nodes){
				n.px = n.x
				n.py = n.y
				n.pz = 0
				n.pscale = 1
			}
			return
		}
		const cy = Math.cos(rotY), sy = Math.sin(rotY)
		const cx = Math.cos(rotX), sx = Math.sin(rotX)
		for (const n of nodes){
			const xv  = n.x * cy + n.z * sy
			const zv  = -n.x * sy + n.z * cy
			const yv2 = n.y * cx - zv * sx
			const zv2 = n.y * sx + zv * cx
			const sc  = FOV / (FOV + zv2 + ZOFF)
			n.px = xv * sc
			n.py = yv2 * sc
			n.pz = zv2
			n.pscale = sc
		}
	}
	function draw(){
		checkSize()
		updateProjections()
		const W = canvas.width, H = canvas.height
		ctx.clearRect(0, 0, W, H)
		ctx.save()
		ctx.translate(W/2+ox, H/2+oy)
		ctx.scale(zoom, zoom)
		for (const e of edges){
			const a = map[e.a], b = map[e.b]
			if (!a||!b) continue
			if (isHidden(a) || isHidden(b) || isHiddenEdge(e)) continue
			const hEdge = isHoverEdge(e)
			const edgeDepth = is3D ? Math.min(1, ((a.pscale + b.pscale) * 0.5 / neutralSc) ** 2) : 1
			ctx.globalAlpha = (hoverNode && !hEdge ? 0.2 : 1) * edgeDepth
			if (e.kind === 'defines'){
				ctx.setLineDash([4/zoom, 3/zoom])
				ctx.strokeStyle = hEdge ? 'rgba(255,255,255,0.75)' : '#4a7a9a'
				ctx.lineWidth = (hEdge ? 1.8 : 0.9)/zoom
			} else if (e.kind === 'depends'){
				ctx.setLineDash([])
				ctx.strokeStyle = hEdge ? 'rgba(255,255,255,0.75)' : '#c88a40'
				ctx.lineWidth = (hEdge ? 2.0 : 1.1)/zoom
			} else if (e.kind === 'requires'){
				ctx.setLineDash([2/zoom, 2/zoom])
				ctx.strokeStyle = hEdge ? 'rgba(255,255,255,0.75)' : '#a07040'
				ctx.lineWidth = (hEdge ? 1.6 : 0.7)/zoom
			} else if (e.kind === 'extends'){
				ctx.setLineDash([1/zoom, 4/zoom])
				ctx.strokeStyle = hEdge ? 'rgba(255,255,255,0.75)' : '#e05a7a'
				ctx.lineWidth = (hEdge ? 1.6 : 0.8)/zoom
			} else if (e.kind === 'selector-def' || e.kind === 'style'){
				ctx.setLineDash([])
				ctx.strokeStyle = hEdge ? 'rgba(255,255,255,0.75)' : (map[e.a] ? col(map[e.a])+'99' : '#88888855')
				ctx.lineWidth = (hEdge ? 1.4 : 0.6)/zoom
			} else if (e.kind === 'selector-use' || e.kind === 'script'){
				ctx.setLineDash([3/zoom, 2/zoom])
				ctx.strokeStyle = hEdge ? 'rgba(255,255,255,0.75)' : '#e05a7a'
				ctx.lineWidth = (hEdge ? 1.6 : 0.8)/zoom
			} else if (e.kind === 'uses' || e.kind === 'provides' || e.kind === 'binds'){
				ctx.setLineDash([2/zoom, 3/zoom])
				ctx.strokeStyle = hEdge ? 'rgba(255,255,255,0.75)' : '#56d4a1'
				ctx.lineWidth = (hEdge ? 1.6 : 0.8)/zoom
			} else {
				ctx.setLineDash([])
				ctx.strokeStyle = hEdge ? 'rgba(255,255,255,0.75)' : '#2a5570'
				ctx.lineWidth = (hEdge ? 1.6 : 0.9)/zoom
			}
			ctx.beginPath()
			ctx.moveTo(a.px, a.py)
			ctx.lineTo(b.px, b.py)
			ctx.stroke()
			ctx.setLineDash([])
		}
		ctx.globalAlpha = 1
		const sorted = nodes.slice().sort((a,b) => b.pz - a.pz)
		for (const n of sorted){
			if (isHidden(n)) continue
			const sr = n.r * n.pscale
			if (sr <= 0) continue
			const hNode = n === hoverNode || isConnectedToHover(n)
			const depthA = is3D ? Math.max(0.12, Math.min(1, (n.pscale / neutralSc) ** 3)) : 1
			const fadeA = hoverNode && !hNode ? 0.15 : 1
			ctx.globalAlpha = fadeA * depthA
			const baseCol = col(n)
			const tLen = Math.sqrt(n.px*n.px + n.py*n.py)
			const nx = tLen > 0.1 ? -n.px/tLen : 0
			const ny = tLen > 0.1 ? -n.py/tLen : -1
			const grad = ctx.createRadialGradient(n.px + nx*sr*0.32, n.py + ny*sr*0.38, sr*0.02, n.px - nx*sr*0.08, n.py - ny*sr*0.1, sr)
			grad.addColorStop(0, hexShift(baseCol, 0.38))
			grad.addColorStop(0.45, baseCol)
			grad.addColorStop(1, hexShift(baseCol, -0.48))
			ctx.beginPath()
			ctx.arc(n.px, n.py, sr, 0, Math.PI*2)
			ctx.fillStyle = grad
			ctx.fill()
			if (hNode && !isHidden(n)){
				ctx.setLineDash([])
				ctx.beginPath()
				ctx.arc(n.px, n.py, sr+5/zoom, 0, Math.PI*2)
				ctx.strokeStyle = n === hoverNode ? 'rgba(255,255,255,0.9)' : 'rgba(255,255,255,0.55)'
				ctx.lineWidth = (n === hoverNode ? 2.4 : 1.5)/zoom
				ctx.stroke()
			}
			const fs = n.r * (is3D ? Math.min(1.3, n.pscale / neutralSc) : 1.5) * 0.28
			const lh = fs * 0.95
			ctx.fillStyle = '#fff'
			ctx.textAlign = 'center'
			ctx.textBaseline = 'middle'
			ctx.font = fs + 'px Inter,system-ui,sans-serif'
			const parts = []
			const slashPos = n.label.lastIndexOf('/')
			const tail = slashPos >= 0 ? n.label.slice(slashPos + 1) : n.label
			if (slashPos >= 0) parts.push({text: n.label.slice(0, slashPos), alpha: 0.58})
			const dotPos = tail.lastIndexOf('.')
			if (dotPos > 0){
				parts.push({text: tail.slice(0, dotPos), alpha: 0.92})
				parts.push({text: tail.slice(dotPos), alpha: 0.5})
			} else {
				parts.push({text: tail, alpha: 0.92})
			}
			const startY = n.py - (parts.length - 1) * lh * 0.5
			for (let i = 0; i < parts.length; i++){
				ctx.globalAlpha = fadeA * parts[i].alpha * depthA * depthA
				ctx.fillText(parts[i].text, n.px, startY + i * lh)
			}
		}
		const nowMs = performance.now()
		for (const tr of traceTrails){
			if (!tr.from || !tr.to) continue
			const alpha = Math.max(0, 1 - (nowMs - tr.born) / 900)
			if (alpha <= 0) continue
			ctx.globalAlpha = alpha * 0.55
			ctx.setLineDash([])
			ctx.strokeStyle = '#fff'
			ctx.lineWidth = 1.8/zoom
			ctx.beginPath()
			ctx.moveTo(tr.from.px, tr.from.py)
			ctx.lineTo(tr.to.px, tr.to.py)
			ctx.stroke()
		}
		for (const g of traceGlowing){
			const n = g.node
			if (!n || isHidden(n)) continue
			const sr = n.r * n.pscale
			if (sr <= 0) continue
			const age = (nowMs - g.born) / 700
			const alpha = Math.max(0, 1 - age)
			ctx.globalAlpha = alpha * 0.9
			ctx.setLineDash([])
			ctx.beginPath()
			ctx.arc(n.px, n.py, sr + sr * 1.4 * age, 0, Math.PI*2)
			ctx.strokeStyle = '#fff'
			ctx.lineWidth = Math.max(0.5, (3 - age * 3)) / zoom
			ctx.stroke()
		}
		if (traceParticle && traceParticle.to){
			const {from, to, t} = traceParticle
			const e = t < 0.5 ? 2*t*t : -1+(4-2*t)*t
			const px = from ? from.px + (to.px - from.px) * e : to.px
			const py = from ? from.py + (to.py - from.py) * e : to.py
			const ps = from ? from.pscale + (to.pscale - from.pscale) * e : to.pscale
			traceTrailPos.push({px, py})
			if (traceTrailPos.length > 9) traceTrailPos.shift()
			const depthF = Math.min(1.4, ps / neutralSc)
			for (let i = 0; i < traceTrailPos.length - 1; i++){
				const tp = traceTrailPos[i]
				ctx.globalAlpha = (i / traceTrailPos.length) * 0.5 * depthF
				ctx.beginPath()
				ctx.arc(tp.px, tp.py, Math.max(1, (2.5 * i / traceTrailPos.length) / zoom), 0, Math.PI*2)
				ctx.fillStyle = '#fff'
				ctx.fill()
			}
			const pr = Math.max(3.5, 6 / zoom) * Math.min(1.5, depthF)
			ctx.globalAlpha = 0.3 * depthF
			ctx.beginPath()
			ctx.arc(px, py, pr * 2.2, 0, Math.PI*2)
			ctx.fillStyle = '#fff'
			ctx.fill()
			ctx.globalAlpha = depthF
			ctx.beginPath()
			ctx.arc(px, py, pr, 0, Math.PI*2)
			ctx.fillStyle = '#fff'
			ctx.fill()
		}
		ctx.globalAlpha = 1
		ctx.restore()
	}
	const posKey = 'phlo-graph:' + mode + ':' + (dashBase || location.pathname)
	function savePositions(){
		try {
			const pos = {_zoom: zoom, _rotY: rotY, _rotX: rotX}
			for (const n of nodes) pos[n.id] = {x: Math.round(n.x), y: Math.round(n.y), z: Math.round(n.z||0)}
			localStorage.setItem(posKey, JSON.stringify(pos))
		} catch(e){}
	}
	let hasSaved = false
	function fitView(){
		let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity
		for (const n of nodes){
			minX = Math.min(minX, n.x - n.r)
			maxX = Math.max(maxX, n.x + n.r)
			minY = Math.min(minY, n.y - n.r)
			maxY = Math.max(maxY, n.y + n.r)
		}
		const pw = maxX - minX, ph = maxY - minY
		if (pw <= 0 || ph <= 0) return
		zoom = Math.min(6, Math.max(0.15, Math.min(canvas.width / (pw * 1.15), canvas.height / (ph * 1.15))))
		ox = -(minX + maxX) / 2 * zoom
		oy = -(minY + maxY) / 2 * zoom
	}
	function loadPositions(){
		try {
			const saved = JSON.parse(localStorage.getItem(posKey) || 'null')
			if (!saved) return
			hasSaved = true
			if (saved._zoom != null) zoom = saved._zoom
			if (saved._rotY != null) rotY = saved._rotY
			if (saved._rotX != null) rotX = saved._rotX
			for (const n of nodes){
				if (saved[n.id]){
					n.x = saved[n.id].x
					n.y = saved[n.id].y
					const sz = saved[n.id].z ?? 0
					n.z = Math.abs(sz) < 5 ? (Math.random()-.5)*80 : sz
					n.vx = 0
					n.vy = 0
					n.vz = 0
				}
			}
		} catch(e){}
	}
	let loopFrames = 0
	let lastT = 0
	function loop(ts){
		if (!document.contains(canvas)){
			tid = null
			return
		}
		const dt = lastT ? Math.min(ts - lastT, 64) : 16
		lastT = ts
		if (autoRotate && is3D) rotY += 0.003 * (dt / 16)
		const nowMs = performance.now()
		traceGlowing  = traceGlowing.filter(g => nowMs - g.born < 700)
		traceTrails   = traceTrails.filter(t => nowMs - t.born < 900)
		if (traceHitFade && nowMs > traceHitFade){
			const lbl = obj('#rg-hit-label')
			if (lbl) lbl.style.opacity = '0'
			traceHitFade = 0
		}
		if (traceParticle){
			traceParticle.t += dt / traceParticle.duration
			if (traceParticle.t >= 1){
				const arrived = traceParticle.to
				const from    = traceParticle.from
				if (arrived){
					const g = traceGlowing.find(g => g.node === arrived)
					if (g) g.born = nowMs
					else traceGlowing.push({node: arrived, born: nowMs})
				}
				if (from && arrived){
					const t = traceTrails.find(t => t.from === from && t.to === arrived)
					if (t) t.born = nowMs
					else traceTrails.push({from, to: arrived, born: nowMs})
				}
				traceParticle = null
				traceTrailPos = []
				traceAdvance()
			}
		}
		const mv = sim()
		draw()
		loopFrames++
		const traceActive = !!traceParticle || traceGlowing.length > 0 || traceTrails.length > 0
		if ((mv && loopFrames < 600) || (autoRotate && is3D) || traceActive) tid = requestAnimationFrame(loop)
		else {
			loopFrames = 0
			if (!hasSaved){
				fitView()
				hasSaved = true
				draw()
			}
			tid = null
			savePositions()
		}
	}
	function start(){ loopFrames = 0; if (!tid) tid = requestAnimationFrame(loop) }

	function traceApply(d){
		if (!d || !Array.isArray(d.events) || !d.events.length) return
		traceEvents = d.events.slice()
		traceEventNodes = traceEvents.map(e => {
			const key = e.f || ''
			return key ? (nodes.find(n => n.file && (key === n.file || key.endsWith('/' + n.file))) || null) : null
		})
		traceEventIdx   = 0
		traceLastNode   = null
		traceArgsByNode = {}
		traceTotalMs    = +d.ms || 0
		traceTotalEvents = traceEvents.length
		for (const e of traceEvents){
			const key = e.f || e.c || e.n
			if (!key) continue
			if (!traceArgsByNode[key]) traceArgsByNode[key] = []
			traceArgsByNode[key].push({t: e.t, n: e.n, k: e.k, args: e.args || null})
		}
		traceParticle = null
		traceGlowing  = []
		traceTrails   = []
		traceTrailPos = []
		renderCallsList()
		updateTimeline()
		traceAdvance()
	}
	function updateTimeline(){
		const tl = obj('#rg-timeline')
		const idx = Math.max(0, Math.min(traceEventIdx - 1, traceEvents.length - 1))
		if (tl){
			const pct = traceEvents.length ? Math.round((idx / traceEvents.length) * 100) : 0
			tl.textContent = traceTotalEvents + ' events  ·  ' + traceTotalMs + ' ms  ·  event ' + (idx + 1) + '/' + traceEvents.length + '  (' + pct + '%)'
		}
		const scrub = obj('#rg-trace-scrub')
		if (scrub && !traceScrubbing){
			scrub.max = String(Math.max(0, traceEvents.length - 1))
			scrub.value = String(idx)
		}
	}
	function renderCallsList(){
		const list = obj('#rg-calls-list')
		const cnt  = obj('#rg-calls-count')
		if (cnt) cnt.textContent = traceEvents.length + ' events'
		if (!list) return
		list.innerHTML = ''
		for (let i = 0; i < traceEvents.length; i++){
			const e = traceEvents[i]
			const li = document.createElement('li')
			li.className = 'rg-calls__item'
			li.dataset.idx = String(i)
			const sym = e.k === 'static' ? '::' : (e.k === 'get' || e.k === 'set' ? '.' : (e.c ? '->' : ''))
			const label = e.c ? (e.c + sym + e.n) : e.n
			const args = e.args ? JSON.stringify(e.args) : ''
			li.innerHTML = '<span class="rg-calls__t">' + (+e.t).toFixed(2) + 'ms</span><span class="rg-calls__n" title="' + label.replace(/"/g, '&quot;') + '">' + label + (args ? '  ' + args.replace(/[<>]/g, c => c === '<' ? '&lt;' : '&gt;').slice(0, 60) : '') + '</span>'
			li.addEventListener('click', () => scrubTo(traceEventNodes[i], i))
			list.appendChild(li)
		}
	}
	function highlightCall(eventIdx){
		const list = obj('#rg-calls-list')
		if (!list) return
		const items = list.children
		for (let i = 0; i < items.length; i++) items[i].classList.toggle('active', i === eventIdx)
		if (eventIdx >= 0 && items[eventIdx]) items[eventIdx].scrollIntoView({block: 'nearest', behavior: 'smooth'})
	}
	function showHitLabel(node, eventIdx){
		const lbl = obj('#rg-hit-label')
		if (!lbl) return
		if (!node || eventIdx < 0 || eventIdx >= traceEvents.length){ lbl.hidden = true; return }
		const e = traceEvents[eventIdx]
		const sym = e.k === 'static' ? '::' : (e.k === 'get' || e.k === 'set' ? '.' : (e.c ? '->' : ''))
		const label = e.c ? (e.c + sym + e.n) : e.n
		const args = e.args ? JSON.stringify(e.args).slice(0, 80) : ''
		lbl.textContent = label + (args ? '  ' + args : '')
		lbl.style.left = (node.px + 12) + 'px'
		lbl.style.top  = (node.py - 12) + 'px'
		lbl.hidden = false
		lbl.style.opacity = '1'
		traceHitFade = performance.now() + 1000
	}
	function scrubTo(node, eventIdx){
		traceEventIdx = Math.max(0, Math.min(eventIdx + 1, traceEvents.length))
		traceLastNode = lastNodeBefore(eventIdx)
		traceParticle = null
		traceTrailPos = []
		highlightCall(eventIdx)
		if (traceEventNodes[eventIdx]) showHitLabel(traceEventNodes[eventIdx], eventIdx)
		updateTimeline()
		if (!tid) draw()
	}
	function lastNodeBefore(eventIdx){
		for (let i = eventIdx - 1; i >= 0; i--) if (traceEventNodes[i]) return traceEventNodes[i]
		return null
	}
	function showArgsTooltip(node, x, y){
		const tt = obj('#rg-args-tooltip')
		if (!tt) return
		if (!node){ tt.hidden = true; return }
		const key = node.file || node.id
		const list = traceArgsByNode[key] || []
		if (!list.length){ tt.hidden = true; return }
		const head = '<div class="rg-args-title">' + (node.file || node.id) + '  ·  ' + list.length + ' call' + (list.length === 1 ? '' : 's') + '</div>'
		const rows = list.slice(-5).map(e => {
			const args = e.args ? JSON.stringify(e.args) : '()'
			return '<div class="rg-args-row"><span class="rg-args-t">' + e.t.toFixed(2) + 'ms</span> <span class="rg-args-n">' + (e.n || '') + '</span> <code>' + args.replace(/[<>]/g, c => c === '<' ? '&lt;' : '&gt;') + '</code></div>'
		}).join('')
		tt.innerHTML = head + rows
		tt.style.left = (x + 14) + 'px'
		tt.style.top  = (y + 14) + 'px'
		tt.hidden = false
	}
	function traceStepOnce(){
		delay('trace-poll', 0)
		if (traceEventIdx >= traceEvents.length) return false
		const i  = traceEventIdx
		const to = traceEventNodes[i]
		traceEventIdx++
		highlightCall(i)
		if (to){
			const from = traceLastNode
			let duration = 80
			if (from && from !== to){
				const dx = to.x - from.x, dy = to.y - from.y, dz = (to.z || 0) - (from.z || 0)
				const dist = Math.sqrt(dx*dx + dy*dy + dz*dz)
				duration = Math.max(60, Math.min(600, dist || 60))
			}
			else if (from === to) duration = 120
			traceParticle = {from, to, t: 0, duration}
			traceTrailPos = []
			traceLastNode = to
			showHitLabel(to, i)
		}
		else {
			traceParticle = null
			if (tracePlay) delay('trace-poll', 100, () => { if (tracePlay) traceAdvance() })
		}
		updateTimeline()
		if (!tid) start()
		return true
	}
	function traceAdvance(){
		if (!tracePlay){ delay('trace-poll', 0); return }
		if (!traceStepOnce()){
			tracePlay = false
			updateTraceBtn()
			updateTimeline()
		}
	}
	function updateTraceBtn(){
		const traceBtn = obj('#rg-trace-toggle')
		if (traceBtn){
			traceBtn.textContent = tracePlay ? '⏸' : '▶'
			traceBtn.classList.toggle('active', tracePlay)
		}
	}
	app.res.trace = cmds => { if (cmds.trace) traceApply(cmds.trace) }

	canvas.width  = canvas.clientWidth
	canvas.height = canvas.clientHeight
	loadPositions()
	start()
	const dimBtn = obj('#rg-dim-toggle')
	if (dimBtn){
		dimBtn.textContent = is3D ? '3D' : '2D'
		dimBtn.classList.toggle('active', is3D)
		dimBtn.addEventListener('click', () => {
			is3D = !is3D
			localStorage.setItem(dimKey, is3D ? '3d' : '2d')
			dimBtn.textContent = is3D ? '3D' : '2D'
			dimBtn.classList.toggle('active', is3D)
			if (!is3D)
				for (const n of nodes) n.vz = 0
			autoRotate = is3D && !canvas.matches(':hover')
			if (!tid){
				if (is3D && autoRotate) start()
				else draw()
			}
		})
	}
	canvas.addEventListener('mousedown', e => {
		if (e.button === 2){
			if (!is3D) return
			canvas.requestPointerLock()
			const onMove = e2 => {
				rotY = (rotY + e2.movementX * 0.008) % (Math.PI * 2)
				rotX = (rotX + e2.movementY * 0.008) % (Math.PI * 2)
				if (!tid) draw()
			}
			const onUp = () => {
				document.exitPointerLock()
				window.removeEventListener('mousemove', onMove)
				window.removeEventListener('mouseup', onUp)
				savePositions()
			}
			window.addEventListener('mousemove', onMove)
			window.addEventListener('mouseup', onUp)
			return
		}
		didDrag = false
		const w = toW(e.offsetX, e.offsetY), n = hitNode(w.x, w.y)
		if (n){
			drag = n
			n.pin = true
			n._pz = n.pz || 0
		}
		else {
			panFrom = {x:e.clientX, y:e.clientY}
			panOrig = {x:ox, y:oy}
		}
		const onMove = e2 => {
			didDrag = true
			const rect = canvas.getBoundingClientRect()
			const w2 = toW(e2.clientX-rect.left, e2.clientY-rect.top)
			if (drag){
				if (is3D){
					const cy = Math.cos(rotY), sy = Math.sin(rotY)
					const cx = Math.cos(rotX), sx = Math.sin(rotX)
					const sc = drag.pscale || 1, pz = drag._pz || 0
					const xv  = w2.x / sc
					const yv2 = w2.y / sc
					const yv  = yv2 * cx + pz * sx
					const zv  = -yv2 * sx + pz * cx
					drag.x = xv * cy - zv * sy
					drag.z = xv * sy + zv * cy
					drag.y = yv
				} else {
					drag.x = w2.x
					drag.y = w2.y
				}
				drag.vx = 0
				drag.vy = 0
				drag.vz = 0
				canvas.style.cursor = 'grabbing'
				start()
			}
			else if (panFrom){
				ox = panOrig.x+e2.clientX-panFrom.x
				oy = panOrig.y+e2.clientY-panFrom.y
				canvas.style.cursor = 'grabbing'
				if (!tid) draw()
			}
		}
		const onUp = () => {
			if (drag){
				drag.pin = false
				drag = null
				start()
				savePositions()
			}
			panFrom = null
			const w3 = toW(e.clientX - canvas.getBoundingClientRect().left, e.clientY - canvas.getBoundingClientRect().top)
			canvas.style.cursor = hitNode(w3.x, w3.y) ? 'pointer' : 'default'
			window.removeEventListener('mousemove', onMove)
			window.removeEventListener('mouseup', onUp)
		}
		window.addEventListener('mousemove', onMove)
		window.addEventListener('mouseup', onUp)
	})
	canvas.addEventListener('mousemove', e => {
		if (drag || panFrom) return
		const w = toW(e.offsetX, e.offsetY)
		const n = hitNode(w.x, w.y)
		canvas.style.cursor = n ? 'pointer' : 'default'
		showArgsTooltip(n, e.offsetX, e.offsetY)
	})
	canvas.addEventListener('mouseleave', () => showArgsTooltip(null))
	canvas.addEventListener('contextmenu', e => e.preventDefault())
	const traceBtn = obj('#rg-trace-toggle')
	if (traceBtn){
		traceBtn.textContent = tracePlay ? '⏸' : '▶'
		traceBtn.classList.toggle('active', tracePlay)
		traceBtn.addEventListener('click', () => {
			tracePlay = !tracePlay
			localStorage.setItem(traceKey, tracePlay ? 'on' : 'off')
			updateTraceBtn()
			if (!tracePlay){
				delay('trace-poll', 0)
				if (!tid) draw()
			} else {
				if (traceEventIdx >= traceEvents.length){
					traceEventIdx = 0
					traceLastNode = null
					traceGlowing  = []
					traceTrails   = []
				}
				traceAdvance()
			}
		})
		const traceRaw = canvas.dataset.trace
		if (traceRaw) traceApply(JSON.parse(atob(traceRaw)))
		if (!tracePlay) updateTimeline()
	}
	const traceSelect = obj('#rg-trace-select')
	if (traceSelect){
		traceSelect.addEventListener('change', () => {
			const id  = traceSelect.value
			const url = (traceSelect.dataset.endpoint || '') + id
			if (!id) return
			fetch(url, {credentials: 'same-origin'})
				.then(r => r.json())
				.then(j => {
					if (j && j.trace){
						traceParticle = null
						traceGlowing  = []
						traceTrails   = []
						traceTrailPos = []
						tracePlay = true
						updateTraceBtn()
						traceApply(j.trace)
					}
				})
				.catch(() => {})
		})
	}
	const scrubEl = obj('#rg-trace-scrub')
	if (scrubEl){
		const scrubStart = () => {
			traceScrubbing = true
			if (tracePlay){ tracePlay = false; updateTraceBtn() }
			delay('trace-poll', 0)
		}
		const scrubEnd = () => { traceScrubbing = false; updateTimeline() }
		scrubEl.addEventListener('pointerdown', scrubStart)
		scrubEl.addEventListener('pointerup', scrubEnd)
		scrubEl.addEventListener('pointercancel', scrubEnd)
		scrubEl.addEventListener('blur', scrubEnd)
		scrubEl.addEventListener('input', () => {
			traceScrubbing = true
			if (tracePlay){ tracePlay = false; updateTraceBtn() }
			delay('trace-poll', 0)
			const eventIdx = Math.max(0, Math.min(parseInt(scrubEl.value, 10) || 0, traceEvents.length - 1))
			traceEventIdx = eventIdx + 1
			traceLastNode = lastNodeBefore(eventIdx)
			traceParticle = null
			traceTrailPos = []
			highlightCall(eventIdx)
			if (traceEventNodes[eventIdx]) showHitLabel(traceEventNodes[eventIdx], eventIdx)
			const tl = obj('#rg-timeline')
			if (tl){
				const pct = traceEvents.length ? Math.round((eventIdx / traceEvents.length) * 100) : 0
				tl.textContent = traceTotalEvents + ' events  ·  ' + traceTotalMs + ' ms  ·  event ' + (eventIdx + 1) + '/' + traceEvents.length + '  (' + pct + '%)'
			}
			if (!tid) draw()
		})
	}
	const prevBtn = obj('#rg-trace-prev')
	if (prevBtn) prevBtn.addEventListener('click', () => {
		if (tracePlay){ tracePlay = false; updateTraceBtn() }
		if (traceEventIdx <= 1) return
		traceEventIdx = Math.max(0, traceEventIdx - 2)
		traceLastNode = lastNodeBefore(traceEventIdx)
		traceParticle = null
		traceStepOnce()
	})
	const nextBtn = obj('#rg-trace-next')
	if (nextBtn) nextBtn.addEventListener('click', () => {
		if (tracePlay){ tracePlay = false; updateTraceBtn() }
		traceParticle = null
		traceStepOnce()
	})
	const restartBtn = obj('#rg-trace-restart')
	if (restartBtn) restartBtn.addEventListener('click', () => {
		traceEventIdx = 0
		traceLastNode = null
		traceParticle = null
		traceGlowing  = []
		traceTrails   = []
		traceTrailPos = []
		tracePlay = true
		updateTraceBtn()
		traceAdvance()
	})

	canvas.addEventListener('mouseenter', () => { autoRotate = false })
	canvas.addEventListener('mouseleave', () => {
		canvas.style.cursor = 'default'
		if (is3D){
			autoRotate = true
			if (!tid) start()
		}
		if (tracePlay && !traceParticle && !phlo.delays['trace-poll']) app.get(dashBase.slice(1) + '/trace', false)
	})
	canvas.addEventListener('click', e => {
		if (didDrag) return
		const w = toW(e.offsetX, e.offsetY), n = hitNode(w.x, w.y)
		if (!n){
			if (hoverNode){
				hoverNode = null
				if (!tid) draw()
			}
			return
		}
		if (n === hoverNode){
			openNode(n)
			return
		}
		hoverNode = n
		if (!tid) draw()
	})
	canvas.addEventListener('wheel', e => {
		e.preventDefault()
		zoom = Math.max(0.15, Math.min(6, zoom*(e.deltaY>0?0.9:1.1)))
		if (!tid) draw()
		savePositions()
	}, {passive: false})
	canvas.addEventListener('touchstart', e => {
		e.preventDefault()
		if (e.touches.length === 2){
			drag = null
			panFrom = null
			touchNode = null
			touchStart = null
			const [t1, t2] = [e.touches[0], e.touches[1]]
			pinchDist0  = Math.hypot(t1.clientX - t2.clientX, t1.clientY - t2.clientY)
			pinchZoom0  = zoom
			pinchAngle0 = Math.atan2(t2.clientY - t1.clientY, t2.clientX - t1.clientX)
			pinchRotY0  = rotY
			pinchRotX0  = rotX
			pinchMid0   = {x:(t1.clientX+t2.clientX)/2, y:(t1.clientY+t2.clientY)/2}
			pinchOx0    = ox
			pinchOy0    = oy
		} else if (e.touches.length === 1){
			pinchDist0 = null
			didDrag = false
			const rect = canvas.getBoundingClientRect()
			const t = e.touches[0]
			const w = toW(t.clientX - rect.left, t.clientY - rect.top)
			const n = hitNode(w.x, w.y)
			touchStart = {x: t.clientX, y: t.clientY}
			touchNode = n
			touchWasHover = n !== null && n === hoverNode
			if (n) n._pz = n.pz || 0
			if (n){
				hoverNode = n
				if (!tid) draw()
			}
			else {
				hoverNode = null
				panFrom = {x: t.clientX, y: t.clientY}
				panOrig = {x: ox, y: oy}
				if (!tid) draw()
			}
		}
	}, {passive: false})
	canvas.addEventListener('touchmove', e => {
		e.preventDefault()
		if (e.touches.length === 2 && pinchDist0 !== null){
			const [t1, t2] = [e.touches[0], e.touches[1]]
			const dist = Math.hypot(t1.clientX - t2.clientX, t1.clientY - t2.clientY)
			const mid  = {x:(t1.clientX+t2.clientX)/2, y:(t1.clientY+t2.clientY)/2}
			zoom = Math.max(0.15, Math.min(6, pinchZoom0 * dist / pinchDist0))
			if (is3D){
				const angle = Math.atan2(t2.clientY - t1.clientY, t2.clientX - t1.clientX)
				rotY = pinchRotY0 + (angle - pinchAngle0)
				rotX = (pinchRotX0 + (mid.y - pinchMid0.y) * 0.012) % (Math.PI * 2)
			} else {
				ox = pinchOx0 + (mid.x - pinchMid0.x)
				oy = pinchOy0 + (mid.y - pinchMid0.y)
			}
			if (!tid) draw()
			savePositions()
			return
		}
		if (!drag && !panFrom && !touchNode) return
		const rect = canvas.getBoundingClientRect()
		const t = e.touches[0]
		const moved = touchStart ? Math.hypot(t.clientX - touchStart.x, t.clientY - touchStart.y) : 0
		if (!drag && touchNode && moved > 8){
			drag = touchNode
			drag.pin = true
			didDrag = true
			start()
		}
		if (!drag && !panFrom && touchNode) return
		if (panFrom && moved > 8) didDrag = true
		if (drag){
			didDrag = true
			const w = toW(t.clientX - rect.left, t.clientY - rect.top)
			if (is3D){
				const cy = Math.cos(rotY), sy = Math.sin(rotY)
				const cx = Math.cos(rotX), sx = Math.sin(rotX)
				const sc = drag.pscale || 1, pz = drag._pz || 0
				const xv  = w.x / sc
				const yv2 = w.y / sc
				const yv  = yv2 * cx + pz * sx
				const zv  = -yv2 * sx + pz * cx
				drag.x = xv * cy - zv * sy
				drag.z = xv * sy + zv * cy
				drag.y = yv
			} else {
				drag.x = w.x
				drag.y = w.y
			}
			drag.vx = 0
			drag.vy = 0
			drag.vz = 0
			start()
		} else if (panFrom){
			ox = panOrig.x + t.clientX - panFrom.x
			oy = panOrig.y + t.clientY - panFrom.y
			if (!tid) draw()
		}
	}, {passive: false})
	canvas.addEventListener('touchend', e => {
		pinchDist0 = null
		const wasDrag = didDrag
		if (drag){
			drag.pin = false
			drag = null
			start()
			savePositions()
		}
		panFrom = null
		if (!wasDrag && e.touches.length === 0 && e.changedTouches.length === 1){
			const rect = canvas.getBoundingClientRect()
			const t = e.changedTouches[0]
			const w = toW(t.clientX - rect.left, t.clientY - rect.top)
			const n = hitNode(w.x, w.y)
			if (!n){
				if (hoverNode){
					hoverNode = null
					if (!tid) draw()
				}
				touchNode = null
				touchWasHover = false
				return
			}
			if (n !== touchNode || !touchWasHover){
				hoverNode = n
				if (!tid) draw()
				touchNode = null
				touchWasHover = false
				return
			}
			openNode(n)
		}
		touchNode = null
		touchWasHover = false
		touchStart = null
	})
})
phlo.tech
