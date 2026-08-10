extends SceneTree

## Кара автопилот през симулацията и отчита обиколките, без да рендерира нищо.
##
## Смисълът е ЕДИН: да докаже, че портираната физика дава същите времена като
## браузърната версия. Обща класация между уеб и десктоп означава обща физика;
## разминаване тук е бъг, не свобода на интерпретация.
##
## Пусни с:
##   godot --headless --path godot --script res://autopilot_check.gd
##
## Еталонът е scratchpad/autopilot.mjs върху resources/js/game/physics.js.

const TRACKS := [
	"monza", "monaco", "spa", "silverstone",
	"suzuka", "zandvoort", "interlagos", "red_bull_ring",
]

const FIXED_DT := 1.0 / 120.0
const MAX_SECONDS := 300.0


func _init() -> void:
	print("писта          дължина   обиколки                    макс km/h  извън трасето")
	print("────────────────────────────────────────────────────────────────────────────────")

	for slug in TRACKS:
		var track := TrackData.load_from("res://tracks/%s.json" % slug)

		if track == null:
			print("%s: не се зареди" % slug)
			continue

		var result := _drive(track)

		var lap_text := "НЕ ЗАВЪРШИ"
		if not result["laps"].is_empty():
			var parts: Array = []
			for t in result["laps"]:
				parts.append("%d:%05.2f" % [int(t / 60.0), fmod(t, 60.0)])
			lap_text = "  ".join(parts)

		print("%-14s %.3f km  %-26s %6.0f   %.1f%%" % [
			slug,
			track.length / 1000.0,
			lap_text,
			result["max_kmh"],
			result["off_track_pct"],
		])

	quit()


## Безопасна скорост за дадена кривина, при grip = base + downforce·v².
func _safe_speed(curvature: float) -> float:
	var k := absf(curvature)
	if k < 1e-6:
		return Car.MAX_SPEED

	# v²·k = BASE_GRIP + DOWNFORCE_COEF·v²  →  v² (k − coef) = BASE_GRIP
	var denom := k - Car.DOWNFORCE_COEF
	if denom <= 0.0:
		return Car.MAX_SPEED

	return minf(Car.MAX_SPEED, sqrt(Car.BASE_GRIP / denom))


func _drive(track: TrackData) -> Dictionary:
	var car := Car.new()
	car.reset_to_start(track)

	var hint := -1
	var ticks := 0
	var lap_ticks := 0
	var last_progress := 0.0
	var started := false
	var laps: Array = []
	var off_track_ticks := 0
	var max_speed_seen := 0.0

	var max_ticks := int(MAX_SECONDS / FIXED_DT)

	while ticks < max_ticks and laps.size() < 3:
		var projection := track.project(car.x, car.z, hint)
		hint = projection["index"]

		var on_track: bool = absf(projection["lateral"]) < track.width / 2.0
		if not on_track:
			off_track_ticks += 1

		# ── Автопилот ─────────────────────────────────────────────────
		var speed := absf(car.v_forward)
		var lookahead: float = maxf(12.0, speed * 0.85)
		var ahead_index: int = (projection["index"] + int(round(lookahead / track.spacing))) % track.count

		var desired_heading := atan2(
			track.xs[ahead_index] - car.x,
			track.zs[ahead_index] - car.z
		)
		var diff := wrapf(desired_heading - car.heading, -PI, PI)
		var steer_input: float = clampf(diff * 2.6, -1.0, 1.0)

		# Най-стегнатият завой в следващите ~90 m определя скоростта.
		var worst := 0.0
		var scan := int(round(90.0 / track.spacing))
		for i in scan:
			var idx: int = (projection["index"] + i) % track.count
			worst = maxf(worst, absf(track.curvature[idx]))

		var target := _safe_speed(worst)

		var throttle := 0.0
		var brake := 0.0
		if speed > target * 1.02:
			brake = 1.0
		elif speed < target * 0.97:
			throttle = 1.0
		else:
			throttle = 0.5

		car.step(throttle, brake, steer_input, FIXED_DT, on_track, projection["gradient"])
		max_speed_seen = maxf(max_speed_seen, absf(car.v_forward))

		# ── Хронометър ────────────────────────────────────────────────
		var progress: float = clampf(projection["distance"] / track.length, 0.0, 1.0)
		if started:
			lap_ticks += 1

		if last_progress > 0.85 and progress < 0.15:
			if started:
				laps.append(lap_ticks * FIXED_DT)
			started = true
			lap_ticks = 0

		last_progress = progress
		ticks += 1

	return {
		"laps": laps,
		"off_track_pct": float(off_track_ticks) / float(ticks) * 100.0,
		"max_kmh": max_speed_seen * 3.6,
	}
