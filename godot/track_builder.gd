class_name TrackBuilder
extends RefCounted

## Строи 3D геометрията на пистата от TrackData.
##
## Порт на resources/js/game/mesh.js. Нищо не се зарежда като готов модел —
## всичко се извежда от осевата линия, височинния профил и кривината.
##
## ЗАБЕЛЕЖКА за winding: плоските ленти (асфалт, трева, линии) се строят с
## CULL_DISABLED. Godot и Three.js се разминават по това коя страна е предна,
## а грешният ред прави повърхността невидима — черен екран без грешка в
## конзолата. При първия визуален тест се проверява и, ако е наред, cull-ът
## се връща на CULL_BACK за производителност.

const EDGE_LINE_WIDTH := 0.18
const KERB_WIDTH := 1.1
const KERB_BLOCK := 2.0
## Държи се под радиуса на най-стегнатия завой. При 60 m лентата се самопресича
## в шиканите и хълмовете се сливат в черна каша — Спа изглеждаше точно така.
const RUNOFF_WIDTH := 20.0

## Спад на тревата на метър навън — без него run-off зоната е плосък диск
## около наклонено трасе и виси във въздуха по склоновете.
const RUNOFF_DROP := 0.035

## Вертикално нареждане на слоевете срещу z-fighting.
const Y_GRASS := -0.12
const Y_ASPHALT := 0.0
const Y_EDGE := 0.012
const Y_KERB := 0.02
const Y_START := 0.014

const COLOR_ASPHALT := Color(0.19, 0.19, 0.21)
const COLOR_GRASS := Color(0.11, 0.21, 0.13)
const COLOR_EDGE := Color(0.90, 0.90, 0.91)
const COLOR_KERB_RED := Color(0.78, 0.14, 0.12)
const COLOR_KERB_WHITE := Color(0.93, 0.93, 0.93)
const COLOR_GRANDSTAND := Color(0.58, 0.61, 0.64)
const COLOR_BUILDING := Color(0.53, 0.50, 0.45)
const COLOR_TRUNK := Color(0.27, 0.20, 0.15)
const COLOR_FOLIAGE := Color(0.16, 0.30, 0.18)

const GRANDSTAND_HEIGHT := 11.0
const BUILDING_HEIGHT := 7.5


## Сглобява цялата статична геометрия и я връща като един Node3D.
static func build(track: TrackData) -> Node3D:
	var root := Node3D.new()
	root.name = "Track"

	var half := track.width / 2.0

	root.add_child(_ribbon(
		track, -(half + RUNOFF_WIDTH), half + RUNOFF_WIDTH, Y_GRASS,
		COLOR_GRASS, 0.95, RUNOFF_DROP, "Grass"
	))
	root.add_child(_ribbon(
		track, -half, half, Y_ASPHALT,
		COLOR_ASPHALT, 0.82, 0.0, "Asphalt"
	))
	root.add_child(_ribbon(
		track, half - EDGE_LINE_WIDTH, half, Y_EDGE,
		COLOR_EDGE, 0.7, 0.0, "EdgeLeft"
	))
	root.add_child(_ribbon(
		track, -half, -half + EDGE_LINE_WIDTH, Y_EDGE,
		COLOR_EDGE, 0.7, 0.0, "EdgeRight"
	))

	root.add_child(_kerbs(track))
	root.add_child(_start_line(track))

	var grandstands := _boxes(track, track.grandstands, GRANDSTAND_HEIGHT, COLOR_GRANDSTAND, "Grandstands")
	if grandstands != null:
		root.add_child(grandstands)

	var buildings := _boxes(track, track.buildings, BUILDING_HEIGHT, COLOR_BUILDING, "Buildings")
	if buildings != null:
		root.add_child(buildings)

	for node in _trees(track):
		root.add_child(node)

	return root


## Лента, следваща осевата линия между две странични отмествания.
static func _ribbon(
	track: TrackData,
	from_offset: float,
	to_offset: float,
	y: float,
	color: Color,
	roughness: float,
	drop: float,
	node_name: String
) -> MeshInstance3D:
	var rows := track.count + 1
	var vertices := PackedVector3Array()
	var uvs := PackedVector2Array()
	var indices := PackedInt32Array()

	vertices.resize(rows * 2)
	uvs.resize(rows * 2)

	for r in rows:
		var i := r % track.count
		var v := (r * track.spacing) / 8.0

		for side in 2:
			var offset := from_offset if side == 0 else to_offset
			var vi := r * 2 + side

			vertices[vi] = Vector3(
				track.xs[i] + track.nx[i] * offset,
				track.ys[i] + y - absf(offset) * drop,
				track.zs[i] + track.nz[i] * offset
			)
			uvs[vi] = Vector2(float(side), v)

	for i in track.count:
		var a := i * 2
		# Същият ред като в Three.js версията. Обръщането му (както изглеждаше
		# редно за Godot) прави нормалите надолу и повърхността излиза черна —
		# проверено на снимка, не по документация.
		indices.append_array(PackedInt32Array([a, a + 1, a + 2, a + 1, a + 3, a + 2]))

	var mesh := _array_mesh(vertices, indices, uvs, PackedColorArray())

	var instance := MeshInstance3D.new()
	instance.name = node_name
	instance.mesh = mesh
	instance.material_override = _material(color, roughness, false)

	# Плоските ленти не хвърлят смислена сянка, но със CULL_DISABLED влизат в
	# shadow map-а с двете си страни и се засенчват сами — целият асфалт
	# излизаше черен.
	instance.cast_shadow = GeometryInstance3D.SHADOW_CASTING_SETTING_OFF

	return instance


## Кербове в завоите, редуващи се червено/бяло, всички в един mesh.
static func _kerbs(track: TrackData) -> MeshInstance3D:
	var half := track.width / 2.0
	var vertices := PackedVector3Array()
	var colors := PackedColorArray()
	var indices := PackedInt32Array()

	var block_steps: int = maxi(1, int(round(KERB_BLOCK / track.spacing)))

	for range_data in track.kerb_ranges():
		var from_index: int = range_data["from"]
		var to_index: int = range_data["to"]
		var side: int = range_data["side"]

		for r in range(from_index, to_index):
			var i0 := ((r % track.count) + track.count) % track.count
			var i1 := (((r + 1) % track.count) + track.count) % track.count

			# Кербът е от вътрешната страна на завоя.
			var inner := half if side > 0 else -half
			var outer := (half + KERB_WIDTH) if side > 0 else (-half - KERB_WIDTH)

			var colour := COLOR_KERB_RED
			if int(floor(float(r - from_index) / block_steps)) % 2 != 0:
				colour = COLOR_KERB_WHITE

			var base := vertices.size()

			for pair in [[i0, inner], [i0, outer], [i1, inner], [i1, outer]]:
				var idx: int = pair[0]
				var offset: float = pair[1]
				vertices.append(Vector3(
					track.xs[idx] + track.nx[idx] * offset,
					track.ys[idx] + Y_KERB,
					track.zs[idx] + track.nz[idx] * offset
				))
				colors.append(colour)

			if side > 0:
				indices.append_array(PackedInt32Array([
					base, base + 2, base + 1, base + 1, base + 2, base + 3
				]))
			else:
				indices.append_array(PackedInt32Array([
					base, base + 1, base + 2, base + 1, base + 3, base + 2
				]))

	var instance := MeshInstance3D.new()
	instance.name = "Kerbs"
	instance.mesh = _array_mesh(vertices, indices, PackedVector2Array(), colors)
	instance.material_override = _material(Color.WHITE, 0.6, true)

	return instance


static func _start_line(track: TrackData) -> MeshInstance3D:
	var half := track.width / 2.0
	var depth: float = maxf(0.6, track.spacing * 0.4)

	var vertices := PackedVector3Array()

	for pair in [[-depth, -half], [-depth, half], [depth, -half], [depth, half]]:
		var along: float = pair[0]
		var side: float = pair[1]
		vertices.append(Vector3(
			track.xs[0] + track.nx[0] * side + track.tx[0] * along,
			track.ys[0] + Y_START,
			track.zs[0] + track.nz[0] * side + track.tz[0] * along
		))

	var indices := PackedInt32Array([0, 2, 1, 1, 2, 3])

	var instance := MeshInstance3D.new()
	instance.name = "StartLine"
	instance.mesh = _array_mesh(vertices, indices, PackedVector2Array(), PackedColorArray())
	instance.material_override = _material(COLOR_EDGE, 0.65, false)

	return instance


## Сгради и трибуни като ориентирани кутии.
##
## Реалните контури се свеждат до най-малкия завъртян правоъгълник вместо да
## се extrude-ват. В low-poly вид разликата е нищожна, а кутията няма как да
## излезе с обърнати стени или самопресичащ се контур — OSM има и такива.
static func _boxes(
	track: TrackData,
	rings: Array,
	base_height: float,
	color: Color,
	node_name: String
) -> MultiMeshInstance3D:
	if rings.is_empty():
		return null

	var multimesh := MultiMesh.new()
	multimesh.transform_format = MultiMesh.TRANSFORM_3D
	multimesh.mesh = BoxMesh.new()
	multimesh.instance_count = rings.size()

	for r in rings.size():
		var ring: Array = rings[r]
		var box := _oriented_box(ring)

		var height: float = base_height * (0.75 + _noise(r * 7 + 1) * 0.6)
		var ground := _ground_height_near(track, box["center"].x, box["center"].y)

		var basis := Basis(Vector3.UP, box["angle"])
		basis = basis.scaled(Vector3(box["size"].x, height, box["size"].y))

		var origin := Vector3(box["center"].x, ground + height / 2.0, box["center"].y)

		multimesh.set_instance_transform(r, Transform3D(basis, origin))

	var instance := MultiMeshInstance3D.new()
	instance.name = node_name
	instance.multimesh = multimesh
	instance.material_override = _material(color, 0.85, false)

	return instance


## Най-малкият завъртян правоъгълник около контур.
## Ориентацията идва от най-дългото ребро — за сгради това почти винаги е
## правилната ос.
static func _oriented_box(ring: Array) -> Dictionary:
	var longest := 0.0
	var angle := 0.0

	for i in ring.size():
		var a: Array = ring[i]
		var b: Array = ring[(i + 1) % ring.size()]
		var dx: float = float(b[0]) - float(a[0])
		var dz: float = float(b[1]) - float(a[1])
		var d := sqrt(dx * dx + dz * dz)

		if d > longest:
			longest = d
			angle = atan2(dz, dx)

	var cos_a := cos(-angle)
	var sin_a := sin(-angle)

	var min_u := INF
	var max_u := -INF
	var min_v := INF
	var max_v := -INF

	for point in ring:
		var px := float(point[0])
		var pz := float(point[1])
		var u := px * cos_a - pz * sin_a
		var v := px * sin_a + pz * cos_a

		min_u = minf(min_u, u)
		max_u = maxf(max_u, u)
		min_v = minf(min_v, v)
		max_v = maxf(max_v, v)

	var mid_u := (min_u + max_u) / 2.0
	var mid_v := (min_v + max_v) / 2.0

	# Обратно в световни координати.
	var cx := mid_u * cos(angle) - mid_v * sin(angle)
	var cz := mid_u * sin(angle) + mid_v * cos(angle)

	return {
		"center": Vector2(cx, cz),
		"size": Vector2(maxf(max_u - min_u, 1.0), maxf(max_v - min_v, 1.0)),
		# Ротацията около Y е с обратен знак спрямо ъгъла в XZ.
		"angle": -angle,
	}


## Дървета: стволове и корони като два MultiMesh-а.
static func _trees(track: TrackData) -> Array:
	if track.trees.is_empty():
		return []

	var trunk_mesh := CylinderMesh.new()
	trunk_mesh.top_radius = 0.22
	trunk_mesh.bottom_radius = 0.32
	trunk_mesh.height = 2.4
	trunk_mesh.radial_segments = 5
	trunk_mesh.rings = 1

	var foliage_mesh := CylinderMesh.new()
	foliage_mesh.top_radius = 0.0
	foliage_mesh.bottom_radius = 2.1
	foliage_mesh.height = 6.5
	foliage_mesh.radial_segments = 6
	foliage_mesh.rings = 1

	var trunks := MultiMesh.new()
	trunks.transform_format = MultiMesh.TRANSFORM_3D
	trunks.mesh = trunk_mesh
	trunks.instance_count = track.trees.size()

	var foliage := MultiMesh.new()
	foliage.transform_format = MultiMesh.TRANSFORM_3D
	foliage.mesh = foliage_mesh
	foliage.instance_count = track.trees.size()

	for i in track.trees.size():
		var tree: Array = track.trees[i]
		var x := float(tree[0])
		var z := float(tree[1])
		var s := float(tree[2]) if tree.size() > 2 else 1.0

		var ground := _ground_height_near(track, x, z) - 0.2
		var spin := _noise(i) * TAU
		var height_scale: float = s * (0.85 + _noise(i * 3) * 0.4)

		var basis := Basis(Vector3.UP, spin).scaled(Vector3(s, height_scale, s))

		# CylinderMesh е центриран, затова се вдига с половин височина.
		trunks.set_instance_transform(i, Transform3D(
			basis, Vector3(x, ground + 1.2 * height_scale, z)
		))
		foliage.set_instance_transform(i, Transform3D(
			basis, Vector3(x, ground + (2.4 + 3.25) * height_scale, z)
		))

	var trunk_node := MultiMeshInstance3D.new()
	trunk_node.name = "TreeTrunks"
	trunk_node.multimesh = trunks
	trunk_node.material_override = _material(COLOR_TRUNK, 0.95, false)

	var foliage_node := MultiMeshInstance3D.new()
	foliage_node.name = "TreeFoliage"
	foliage_node.multimesh = foliage
	foliage_node.material_override = _material(COLOR_FOLIAGE, 0.9, false)

	return [trunk_node, foliage_node]


static func _array_mesh(
	vertices: PackedVector3Array,
	indices: PackedInt32Array,
	uvs: PackedVector2Array,
	colors: PackedColorArray
) -> ArrayMesh:
	# Празна повърхност чупи add_surface_from_arrays. Случва се реално: писта
	# без нито един завой над прага не получава кербове.
	if vertices.is_empty() or indices.is_empty():
		return ArrayMesh.new()

	var arrays := []
	arrays.resize(Mesh.ARRAY_MAX)
	arrays[Mesh.ARRAY_VERTEX] = vertices
	arrays[Mesh.ARRAY_INDEX] = indices

	if not uvs.is_empty():
		arrays[Mesh.ARRAY_TEX_UV] = uvs
	if not colors.is_empty():
		arrays[Mesh.ARRAY_COLOR] = colors

	var mesh := ArrayMesh.new()
	mesh.add_surface_from_arrays(Mesh.PRIMITIVE_TRIANGLES, arrays)

	# Нормалите не се подават: върху наклонено трасе те решават дали склонът
	# се чете като склон. Godot ги смята коректно от геометрията.
	var tool := SurfaceTool.new()
	tool.create_from(mesh, 0)
	tool.generate_normals()

	return tool.commit()


static func _material(color: Color, roughness: float, vertex_colors: bool) -> StandardMaterial3D:
	var material := StandardMaterial3D.new()
	material.albedo_color = color
	material.roughness = roughness
	material.metallic = 0.0
	material.vertex_color_use_as_albedo = vertex_colors

	# Виж бележката за winding в началото на файла — връща се на CULL_BACK,
	# щом видим, че страните са правилни.
	material.cull_mode = BaseMaterial3D.CULL_DISABLED

	return material


## Височината на трасето най-близо до дадена точка.
## Груб скан през всяка десета точка — ориентирите се разполагат веднъж.
static func _ground_height_near(track: TrackData, x: float, z: float) -> float:
	var best := 0.0
	var best_dist_sq := INF
	var i := 0

	while i < track.count:
		var dx := x - track.xs[i]
		var dz := z - track.zs[i]
		var dist_sq := dx * dx + dz * dz

		if dist_sq < best_dist_sq:
			best_dist_sq = dist_sq
			best = track.ys[i]

		i += 10

	return best - sqrt(best_dist_sq) * RUNOFF_DROP


## Детерминиран псевдошум в [0,1) — без random, за да е сцената еднаква
## при всяко пускане.
static func _noise(n: int) -> float:
	var x: float = sin(float(n) * 12.9898) * 43758.5453

	return x - floor(x)
