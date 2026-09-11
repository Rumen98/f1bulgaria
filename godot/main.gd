extends Node3D

## Сглобява цялата сцена в код.
##
## Нарочно няма .tscn с дърво от възли: така целият вид на сцената е четим в
## git diff и не зависи от ръчно поддържан формат. Ако някой предпочете да ги
## пипа в редактора, всичко тук се превръща в сцени без промяна на логиката.
##
## Разузнавателен билд: една писта, десктоп. Целта е да се види картината,
## преди да се решава за миграция.

## Коя писта да се зареди. Спа има най-много релеф — там разликата спрямо
## браузърната версия е най-честна.
@export var track_slug: String = "spa"

const SECTORS := 3

const CAMERA_DISTANCE := 9.5
const CAMERA_HEIGHT := 3.6
const CAMERA_LOOK_AHEAD := 12.0
const CAMERA_FOV_IDLE := 62.0
const CAMERA_FOV_FAST := 84.0
const CAMERA_DAMPING := 7.5

var track: TrackData
var car := Car.new()
var camera: Camera3D
var car_rig: Node3D
var car_body: Node3D

var track_index_hint := -1
var surface_height := 0.0
var surface_gradient := 0.0

# Времето се брои в стъпки на симулацията, НЕ по стенен часовник. Освен че е
# коректно, това е предпоставката за сървърна валидация по-късно.
var lap_ticks := 0
var last_lap_ticks := -1
var best_lap_ticks := -1
var lap_started := false
var lap_valid := true
var last_progress := 0.0
var sectors_visited := [false, false, false]
var current_sector := 0

var label_lap: Label
var label_best: Label
var label_speed: Label
var label_status: Label

# ── Режим „снимка" ────────────────────────────────────────────────────────
# Пусни с: godot --path godot -- --shot=8,20,45
# Автопилотът кара, а билдът записва кадри в godot/shots/ и излиза. Така
# картината може да се погледне, без някой да седи пред машината.
var shot_seconds: Array = []
var shot_index := 0
var elapsed := 0.0
var autopiloted := false
var capturing := false


func _ready() -> void:
	track = TrackData.load_from(_track_path())

	if track == null:
		push_error("Не мога да заредя пистата '%s'." % track_slug)
		return

	print("Заредена писта: %s — %.2f km, денивелация %.1f m" % [
		track.display_name, track.length / 1000.0, track.elevation_range
	])

	_setup_environment()
	_setup_sun()
	add_child(TrackBuilder.build(track))

	car_rig = _build_car()
	add_child(car_rig)

	camera = Camera3D.new()
	camera.fov = CAMERA_FOV_IDLE
	camera.far = 4000.0
	camera.near = 0.25
	add_child(camera)

	_setup_hud()

	car.reset_to_start(track)
	surface_height = track.ys[0]
	surface_gradient = track.gradient[0]
	_place_camera_behind_car()

	_parse_shot_args()


func _track_path() -> String:
	return "res://tracks/%s.json" % track_slug


## Осветление и атмосфера. Това е причината за целия Godot експеримент —
## в браузърната версия всяко от долните е ръчна работа с addon-и.
func _setup_environment() -> void:
	var sky_material := PhysicalSkyMaterial.new()
	sky_material.sun_disk_scale = 3.0
	sky_material.turbidity = 4.0
	sky_material.ground_color = Color(0.16, 0.19, 0.16)

	var sky := Sky.new()
	sky.sky_material = sky_material

	var env := Environment.new()
	env.background_mode = Environment.BG_SKY
	env.sky = sky

	# Небето осветява сцената — оттук идва по-голямата част от разликата.
	env.ambient_light_source = Environment.AMBIENT_SOURCE_SKY
	env.ambient_light_sky_contribution = 1.0

	env.tonemap_mode = Environment.TONE_MAPPER_ACES
	env.tonemap_white = 6.0

	# Реално global illumination: асфалтът под трибуните потъмнява сам, а
	# гората хвърля зелен отблясък върху трасето.
	env.sdfgi_enabled = true
	env.sdfgi_use_occlusion = true
	env.sdfgi_cascades = 6
	env.sdfgi_min_cell_size = 0.5

	env.ssao_enabled = true
	env.ssao_radius = 2.0
	env.ssao_intensity = 1.6

	env.glow_enabled = true
	env.glow_intensity = 0.35
	env.glow_bloom = 0.05

	env.fog_enabled = true
	env.fog_mode = Environment.FOG_MODE_DEPTH
	env.fog_light_color = Color(0.66, 0.75, 0.84)
	env.fog_density = 0.0012
	env.fog_depth_begin = 300.0
	env.fog_depth_end = 2500.0

	var world := WorldEnvironment.new()
	world.environment = env
	add_child(world)


func _setup_sun() -> void:
	var sun := DirectionalLight3D.new()
	sun.name = "Sun"

	# Ниско следобедно слънце: дългите сенки описват релефа далеч по-добре от
	# слънце в зенит, при което всичко се сплесква.
	sun.rotation_degrees = Vector3(-38.0, 128.0, 0.0)
	sun.light_energy = 1.35
	sun.light_color = Color(1.0, 0.96, 0.89)

	sun.shadow_enabled = true
	sun.directional_shadow_mode = DirectionalLight3D.SHADOW_PARALLEL_4_SPLITS
	sun.directional_shadow_max_distance = 900.0
	sun.directional_shadow_split_1 = 0.05
	sun.directional_shadow_split_2 = 0.15
	sun.directional_shadow_split_3 = 0.45
	sun.directional_shadow_blend_splits = true
	sun.shadow_bias = 0.04
	sun.shadow_normal_bias = 1.5

	add_child(sun)


## Процедурен low-poly болид. Генеричен силует, без ливрея и емблеми —
## реалните са търговски марки.
func _build_car() -> Node3D:
	var root := Node3D.new()
	root.name = "Car"

	car_body = Node3D.new()
	car_body.name = "Body"
	root.add_child(car_body)

	var paint := StandardMaterial3D.new()
	paint.albedo_color = Color(0.83, 0.16, 0.15)
	paint.roughness = 0.35
	paint.metallic = 0.15

	var dark := StandardMaterial3D.new()
	dark.albedo_color = Color(0.10, 0.10, 0.12)
	dark.roughness = 0.5

	var accent := StandardMaterial3D.new()
	accent.albedo_color = Color(0.92, 0.92, 0.93)
	accent.roughness = 0.4

	var rubber := StandardMaterial3D.new()
	rubber.albedo_color = Color(0.07, 0.07, 0.08)
	rubber.roughness = 0.9

	# [размер, позиция, материал]
	var parts := [
		[Vector3(0.62, 0.30, 2.6), Vector3(0.0, 0.34, -0.10), paint],   # шаси
		[Vector3(0.36, 0.20, 1.9), Vector3(0.0, 0.30, 1.75), paint],    # нос
		[Vector3(0.34, 0.34, 1.5), Vector3(-0.52, 0.32, -0.25), paint], # понтон
		[Vector3(0.34, 0.34, 1.5), Vector3(0.52, 0.32, -0.25), paint],
		[Vector3(1.85, 0.05, 0.42), Vector3(0.0, 0.16, 2.55), accent],  # преден спойлер
		[Vector3(1.05, 0.04, 0.34), Vector3(0.0, 0.92, -2.05), accent], # заден спойлер
		[Vector3(0.05, 0.55, 0.22), Vector3(-0.42, 0.65, -2.02), dark],
		[Vector3(0.05, 0.55, 0.22), Vector3(0.42, 0.65, -2.02), dark],
		[Vector3(0.34, 0.42, 0.62), Vector3(0.0, 0.66, -0.95), paint],  # въздухозаборник
		[Vector3(0.46, 0.16, 0.72), Vector3(0.0, 0.52, -0.10), dark],   # кокпит
	]

	for part in parts:
		var box := BoxMesh.new()
		box.size = part[0]

		var instance := MeshInstance3D.new()
		instance.mesh = box
		instance.position = part[1]
		instance.material_override = part[2]
		car_body.add_child(instance)

	# Колела: цилиндри с ос по X.
	for wheel in [
		[Vector3(-0.78, 0.34, 1.55), 0.34, 0.30],
		[Vector3(0.78, 0.34, 1.55), 0.34, 0.30],
		[Vector3(-0.82, 0.37, -1.55), 0.37, 0.42],
		[Vector3(0.82, 0.37, -1.55), 0.37, 0.42],
	]:
		var cylinder := CylinderMesh.new()
		cylinder.top_radius = wheel[1]
		cylinder.bottom_radius = wheel[1]
		cylinder.height = wheel[2]
		cylinder.radial_segments = 14

		var instance := MeshInstance3D.new()
		instance.mesh = cylinder
		instance.position = wheel[0]
		instance.rotation_degrees = Vector3(0.0, 0.0, 90.0)
		instance.material_override = rubber
		car_body.add_child(instance)

	return root


func _setup_hud() -> void:
	var layer := CanvasLayer.new()
	add_child(layer)

	# Контейнер, разпънат по целия екран — иначе анкерите на скоростта нямат
	# спрямо какво да се подравнят.
	var root := Control.new()
	root.set_anchors_preset(Control.PRESET_FULL_RECT)
	root.mouse_filter = Control.MOUSE_FILTER_IGNORE
	layer.add_child(root)

	var panel := VBoxContainer.new()
	panel.position = Vector2(28, 24)
	root.add_child(panel)

	label_lap = _make_label(48, Color(1, 1, 1))
	label_best = _make_label(20, Color(0.4, 0.9, 0.6))
	label_status = _make_label(16, Color(0.75, 0.75, 0.78))

	panel.add_child(label_lap)
	panel.add_child(label_best)
	panel.add_child(label_status)

	label_speed = _make_label(72, Color(1, 1, 1))
	label_speed.horizontal_alignment = HORIZONTAL_ALIGNMENT_RIGHT
	label_speed.set_anchors_preset(Control.PRESET_BOTTOM_RIGHT)
	label_speed.offset_left = -300.0
	label_speed.offset_top = -130.0
	label_speed.offset_right = -32.0
	label_speed.offset_bottom = -32.0
	root.add_child(label_speed)


func _make_label(size: int, color: Color) -> Label:
	var label := Label.new()
	label.add_theme_font_size_override("font_size", size)
	label.add_theme_color_override("font_color", color)
	# Контур, защото HUD-ът стои върху ярко небе и тъмен асфалт последователно.
	label.add_theme_color_override("font_outline_color", Color(0, 0, 0, 0.75))
	label.add_theme_constant_override("outline_size", 6)

	return label


func _parse_shot_args() -> void:
	for arg in OS.get_cmdline_user_args():
		if not arg.begins_with("--shot="):
			continue

		for part in arg.substr(7).split(","):
			var seconds := part.strip_edges().to_float()
			if seconds > 0.0:
				shot_seconds.append(seconds)

	shot_seconds.sort()
	autopiloted = not shot_seconds.is_empty()

	if autopiloted:
		DirAccess.make_dir_recursive_absolute(
			ProjectSettings.globalize_path("res://shots")
		)
		print("Режим снимка: %s s" % str(shot_seconds))


func _physics_process(delta: float) -> void:
	if track == null:
		return

	var projection := track.project(car.x, car.z, track_index_hint)

	if autopiloted:
		elapsed += delta
		var input := Autopilot.compute(track, car, projection)
		_apply_step(projection, input["throttle"], input["brake"], input["steer"], delta)
		return

	if Input.is_key_pressed(KEY_R):
		_reset()
		return

	var throttle := 1.0 if (Input.is_key_pressed(KEY_UP) or Input.is_key_pressed(KEY_W)) else 0.0
	var brake := 1.0 if (
		Input.is_key_pressed(KEY_DOWN) or Input.is_key_pressed(KEY_S) or Input.is_key_pressed(KEY_SPACE)
	) else 0.0

	var steer_input := 0.0
	if Input.is_key_pressed(KEY_LEFT) or Input.is_key_pressed(KEY_A):
		steer_input -= 1.0
	if Input.is_key_pressed(KEY_RIGHT) or Input.is_key_pressed(KEY_D):
		steer_input += 1.0

	_apply_step(projection, throttle, brake, steer_input, delta)


func _apply_step(
	projection: Dictionary,
	throttle: float,
	brake: float,
	steer_input: float,
	delta: float
) -> void:
	track_index_hint = projection["index"]
	surface_height = projection["height"]
	surface_gradient = projection["gradient"]

	var on_track: bool = absf(projection["lateral"]) < track.width / 2.0

	car.step(throttle, brake, steer_input, delta, on_track, surface_gradient)

	_update_lap_timing(projection, on_track)


func _update_lap_timing(projection: Dictionary, on_track: bool) -> void:
	var progress: float = clampf(projection["distance"] / track.length, 0.0, 1.0)
	var sector: int = mini(SECTORS - 1, int(progress * SECTORS))

	if lap_started:
		lap_ticks += 1

		if not on_track:
			lap_valid = false

	sectors_visited[sector] = true
	current_sector = sector

	# Пресичане на стартовата линия напред: прогресът пада от ~1 към ~0.
	var wrapped_forward := last_progress > 0.85 and progress < 0.15
	var wrapped_backward := last_progress < 0.15 and progress > 0.85

	if wrapped_forward:
		var complete := true
		for visited in sectors_visited:
			if not visited:
				complete = false

		if lap_started and complete:
			last_lap_ticks = lap_ticks

			if lap_valid and (best_lap_ticks < 0 or lap_ticks < best_lap_ticks):
				best_lap_ticks = lap_ticks

		lap_ticks = 0
		lap_valid = true
		lap_started = true
		sectors_visited = [false, false, false]
		sectors_visited[sector] = true
	elif wrapped_backward:
		# Мина линията на заден ход — обиколката вече не е чиста.
		lap_started = false
		lap_ticks = 0

	last_progress = progress


func _process(delta: float) -> void:
	if track == null:
		return

	_update_car_rig(delta)
	_update_camera(delta)
	_update_hud()

	if autopiloted and not capturing and shot_index < shot_seconds.size():
		if elapsed >= float(shot_seconds[shot_index]):
			capturing = true
			_capture()


## Записва кадър и излиза, щом свършат заявените моменти.
func _capture() -> void:
	# Изчакваме кадърът да е нарисуван — иначе се хваща предишният.
	await RenderingServer.frame_post_draw

	var seconds := int(shot_seconds[shot_index])
	var path := ProjectSettings.globalize_path(
		"res://shots/%s_%03ds.png" % [track_slug, seconds]
	)

	var image := get_viewport().get_texture().get_image()
	var error := image.save_png(path)

	if error == OK:
		print("Снимка: %s" % path)
	else:
		push_error("Неуспешен запис на %s (код %d)" % [path, error])

	shot_index += 1
	capturing = false

	if shot_index >= shot_seconds.size():
		get_tree().quit()


func _update_car_rig(delta: float) -> void:
	var basis := Basis(Vector3.UP, car.heading)
	# Носът следва склона.
	basis = basis * Basis(Vector3.RIGHT, -atan(surface_gradient))

	# Цял Transform3D наведнъж: Transform3D е value type, така че присвояване
	# на подполе през възела би модифицирало копие.
	car_rig.transform = Transform3D(basis, Vector3(car.x, surface_height, car.z))

	# Крен навън в завоя и клякане при ускорение — козметика, но без нея
	# колата изглежда като плъзгаща се по масата кутия.
	var lateral_accel := car.yaw_rate * car.v_forward
	var target_roll: float = clampf(-lateral_accel / 45.0, -1.0, 1.0) * 0.055
	var target_pitch: float = clampf(-car.v_forward * 0.004 + car.slip * 0.2, -1.0, 1.0) * 0.035

	var k := 1.0 - exp(-8.0 * delta)
	car_body.rotation.z += (target_roll - car_body.rotation.z) * k
	car_body.rotation.x += (target_pitch - car_body.rotation.x) * k


func _update_camera(delta: float) -> void:
	var forward_x := sin(car.heading)
	var forward_z := cos(car.heading)

	var target := Vector3(
		car.x - forward_x * CAMERA_DISTANCE,
		surface_height + CAMERA_HEIGHT,
		car.z - forward_z * CAMERA_DISTANCE
	)

	# Експоненциално изглаждане — не зависи от честотата на кадрите.
	var k := 1.0 - exp(-CAMERA_DAMPING * delta)
	camera.position = camera.position.lerp(target, k)

	# Погледът отива към височината на трасето НАПРЕД, не под колата: на
	# билото това открива какво идва, вместо да опира в небе.
	var ahead_index := 0
	if track_index_hint >= 0:
		ahead_index = (track_index_hint + int(round(CAMERA_LOOK_AHEAD / track.spacing))) % track.count

	camera.look_at(Vector3(
		car.x + forward_x * CAMERA_LOOK_AHEAD,
		track.ys[ahead_index] + 0.9,
		car.z + forward_z * CAMERA_LOOK_AHEAD
	), Vector3.UP)

	# Разширяването на зрителното поле със скоростта е основният трик за
	# усещане за скорост — по-силен от самото движение.
	var speed_ratio: float = clampf(absf(car.v_forward) / Car.MAX_SPEED, 0.0, 1.0)
	var target_fov := CAMERA_FOV_IDLE + (CAMERA_FOV_FAST - CAMERA_FOV_IDLE) * speed_ratio
	camera.fov += (target_fov - camera.fov) * k


func _update_hud() -> void:
	label_lap.text = _format_time(lap_ticks) if lap_started else "--:--.---"
	label_best.text = "Най-добра  %s" % (
		_format_time(best_lap_ticks) if best_lap_ticks >= 0 else "--:--.---"
	)

	var status := "Сектор %d" % (current_sector + 1)
	if not lap_started:
		status = "Мини стартовата линия"
	elif not lap_valid:
		status = "Извън трасето"

	if last_lap_ticks >= 0:
		status += "     Последна %s" % _format_time(last_lap_ticks)

	label_status.text = status
	label_speed.text = "%d" % int(round(car.speed_kmh()))


## Форматира брой стъпки като м:сс.ххх, както в тайминга на Формула 1.
func _format_time(ticks: int) -> String:
	var seconds := float(ticks) / float(Engine.physics_ticks_per_second)
	var minutes := int(seconds / 60.0)
	var rest := seconds - minutes * 60.0

	return "%d:%06.3f" % [minutes, rest]


func _reset() -> void:
	car.reset_to_start(track)
	track_index_hint = -1
	surface_height = track.ys[0]
	surface_gradient = track.gradient[0]

	lap_ticks = 0
	lap_started = false
	lap_valid = true
	last_progress = 0.0
	sectors_visited = [false, false, false]
	current_sector = 0

	_place_camera_behind_car()


func _place_camera_behind_car() -> void:
	var forward_x := sin(car.heading)
	var forward_z := cos(car.heading)

	camera.position = Vector3(
		car.x - forward_x * CAMERA_DISTANCE,
		surface_height + CAMERA_HEIGHT,
		car.z - forward_z * CAMERA_DISTANCE
	)
	camera.look_at(Vector3(car.x, surface_height + 0.6, car.z), Vector3.UP)
