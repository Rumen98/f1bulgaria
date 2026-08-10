class_name TrackData
extends RefCounted

## Зарежда данните за пистата и извежда всичко, от което зависят физиката и
## мрежата. Порт на resources/js/game/track.js — умишлено ред по ред, за да
## може двете версии да се сравняват и да дават едни и същи времена.
##
## Точките идват равномерно разпределени по ХОРИЗОНТАЛА (spacing метра),
## затворен цикъл, като последната не повтаря първата — индексите се wrap-ват
## по модул.
##
## Тангентите и нормалите са хоризонтални (в XZ равнината), а наклонът живее
## отделно в `gradient`. Физиката работи в две измерения с посока и скорост;
## височината влиза само през гравитацията.

## Праг на кривина (1/m), над който завоят получава керб.
const KERB_CURVATURE := 0.008

## Колко пъти да се изглади кривината. Второто производно на GPS данни е шумно.
const CURVATURE_SMOOTHING_PASSES := 4

var slug: String = ""
var display_name: String = ""
var location: String = ""
var length: float = 0.0
var width: float = 0.0
var spacing: float = 4.0
var count: int = 0
var elevation_range: float = 0.0

var xs := PackedFloat32Array()
var ys := PackedFloat32Array()
var zs := PackedFloat32Array()
var tx := PackedFloat32Array()
var tz := PackedFloat32Array()
var nx := PackedFloat32Array()
var nz := PackedFloat32Array()
var gradient := PackedFloat32Array()
var curvature := PackedFloat32Array()

## Реални контури от OpenStreetMap (ODbL). Виж public/game/tracks/LICENSE.txt.
var grandstands: Array = []
var buildings: Array = []
var trees: Array = []


## Зарежда {slug}.json и подготвя всички производни масиви.
## Връща null при проблем с файла, за да може викащият да покаже грешка.
static func load_from(path: String) -> TrackData:
	if not FileAccess.file_exists(path):
		push_error("Липсва файл с данни за пистата: %s" % path)
		return null

	var file := FileAccess.open(path, FileAccess.READ)
	if file == null:
		push_error("Не мога да отворя %s" % path)
		return null

	var parsed: Variant = JSON.parse_string(file.get_as_text())
	file.close()

	if typeof(parsed) != TYPE_DICTIONARY:
		push_error("Повреден JSON в %s" % path)
		return null

	var data: Dictionary = parsed
	var track := TrackData.new()
	track._build(data)

	return track


func _build(data: Dictionary) -> void:
	slug = data.get("slug", "")
	display_name = data.get("name", slug)
	location = data.get("location", "")
	length = float(data.get("length", 0.0))
	width = float(data.get("width", 12.0))
	spacing = float(data.get("spacing", 4.0))

	var points: Array = data.get("points", [])
	count = points.size()

	if count < 4:
		push_error("Пистата '%s' има само %d точки — очакват се стотици." % [slug, count])
		return

	xs.resize(count)
	ys.resize(count)
	zs.resize(count)
	tx.resize(count)
	tz.resize(count)
	nx.resize(count)
	nz.resize(count)
	gradient.resize(count)
	curvature.resize(count)

	for i in count:
		var p: Array = points[i]
		xs[i] = float(p[0])
		# Търпим и стария двумерен формат [x, z] — тогава трасето е плоско.
		ys[i] = float(p[1]) if p.size() > 2 else 0.0
		zs[i] = float(p[2]) if p.size() > 2 else float(p[1])

	var min_y := INF
	var max_y := -INF

	for i in count:
		var prev := (i - 1 + count) % count
		var next := (i + 1) % count

		# Централна разлика — точките са равноотдалечени, така че знаменателят
		# е константа и се съкращава при нормализирането.
		var dx := xs[next] - xs[prev]
		var dz := zs[next] - zs[prev]
		var len_xz := sqrt(dx * dx + dz * dz)
		if len_xz < 1e-9:
			len_xz = 1.0
		dx /= len_xz
		dz /= len_xz

		tx[i] = dx
		tz[i] = dz

		# Нормала наляво спрямо посоката на движение.
		nx[i] = -dz
		nz[i] = dx

		gradient[i] = (ys[next] - ys[prev]) / (2.0 * spacing)

		# Знакова кривина от първо и второ производно, в хоризонталната равнина.
		var d1x := (xs[next] - xs[prev]) / (2.0 * spacing)
		var d1z := (zs[next] - zs[prev]) / (2.0 * spacing)
		var d2x := (xs[next] - 2.0 * xs[i] + xs[prev]) / (spacing * spacing)
		var d2z := (zs[next] - 2.0 * zs[i] + zs[prev]) / (spacing * spacing)
		var denom := pow(d1x * d1x + d1z * d1z, 1.5)
		if denom < 1e-9:
			denom = 1e-9

		curvature[i] = (d1x * d2z - d1z * d2x) / denom

		min_y = min(min_y, ys[i])
		max_y = max(max_y, ys[i])

	for _pass in CURVATURE_SMOOTHING_PASSES:
		curvature = _smooth_cyclic(curvature)

	elevation_range = max_y - min_y

	var landmarks: Dictionary = data.get("landmarks", {})
	grandstands = landmarks.get("grandstands", [])
	buildings = landmarks.get("buildings", [])
	trees = landmarks.get("trees", [])


func _smooth_cyclic(values: PackedFloat32Array) -> PackedFloat32Array:
	var n := values.size()
	var out := PackedFloat32Array()
	out.resize(n)

	for i in n:
		out[i] = (values[(i - 1 + n) % n] + values[i] + values[(i + 1) % n]) / 3.0

	return out


## Проектира световна позиция върху осевата линия.
##
## Търси локално около `hint` — колата се движи непрекъснато, така че между
## два кадъра индексът се мести с шепа позиции. Подай -1 за глобално търсене
## (при старт и рестарт).
##
## Връща: { index, lateral, distance, height, gradient }
##   lateral  — отместване от осевата линия в метри (+ = наляво)
##   distance — изминато разстояние по обиколката в метри
##   height   — височина на асфалта под тази позиция
func project(x: float, z: float, hint: int = -1) -> Dictionary:
	var best_index := 0
	var best_dist_sq := INF

	# Прозорецът е с резерв над максималното преместване за кадър (~1.5 m при
	# 90 m/s), за да оцелее и при заекване на кадрите.
	var window := 40
	var from_k := 0
	var to_k := count

	if hint >= 0:
		from_k = hint - window
		to_k = hint + window

	for k in range(from_k, to_k):
		var i := ((k % count) + count) % count
		var dx := x - xs[i]
		var dz := z - zs[i]
		var dist_sq := dx * dx + dz * dz

		if dist_sq < best_dist_sq:
			best_dist_sq = dist_sq
			best_index = i

	var dx_best := x - xs[best_index]
	var dz_best := z - zs[best_index]

	var lateral := dx_best * nx[best_index] + dz_best * nz[best_index]

	# Проекция върху тангентата — дава подпозиционна точност между две точки,
	# без която таймерът би скачал на стъпки от `spacing`.
	var along := dx_best * tx[best_index] + dz_best * tz[best_index]

	return {
		"index": best_index,
		"lateral": lateral,
		"distance": best_index * spacing + along,
		"height": height_at(best_index, along),
		"gradient": gradient[best_index],
	}


## Височина на асфалта на `along` метра след точка `index`.
## Интерполира — без това колата се качва на стъпала от по 4 метра.
func height_at(index: int, along: float) -> float:
	var steps := along / spacing
	var base := int(floor(steps))
	var t := steps - base

	var a := ys[(((index + base) % count) + count) % count]
	var b := ys[(((index + base + 1) % count) + count) % count]

	return a + (b - a) * t


## Интервалите, в които трасето получава керб.
## Кербът е от ВЪТРЕШНАТА страна на завоя — знакът на кривината дава коя е тя.
## Връща масив от { from, to, side }, side: +1 ляво, -1 дясно.
func kerb_ranges() -> Array:
	var ranges: Array = []
	var current: Dictionary = {}

	for i in count:
		var k := curvature[i]
		var side := 0
		if k > KERB_CURVATURE:
			side = 1
		elif k < -KERB_CURVATURE:
			side = -1

		if side == 0:
			if not current.is_empty():
				ranges.append(current)
				current = {}
			continue

		if not current.is_empty() and current["side"] == side:
			current["to"] = i
		else:
			if not current.is_empty():
				ranges.append(current)
			current = {"from": i, "to": i, "side": side}

	if not current.is_empty():
		ranges.append(current)

	# Ако завоят пресича индекс 0, началото и краят са един и същи керб.
	if ranges.size() > 1:
		var first: Dictionary = ranges[0]
		var last: Dictionary = ranges[ranges.size() - 1]

		if first["from"] == 0 and last["to"] == count - 1 and first["side"] == last["side"]:
			last["to"] = first["to"] + count
			ranges.remove_at(0)

	# Много късите отрязъци са шум в кривината, не истински завой.
	var filtered: Array = []
	for r in ranges:
		if r["to"] - r["from"] >= 3:
			filtered.append(r)

	return filtered
