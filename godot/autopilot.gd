class_name Autopilot
extends RefCounted

## Прост автопилот: следва осевата линия и намалява преди завоите.
##
## Не е добър пилот и не се опитва да бъде. Служи за две неща — проверка на
## паритета с браузърната версия (autopilot_check.gd) и каране на колата,
## докато билдът си прави снимки.

## Колко напред да гледа за завои, метри.
const SCAN_AHEAD := 90.0


## Безопасна скорост за дадена кривина, при grip = base + downforce·v².
static func safe_speed(curvature: float) -> float:
	var k := absf(curvature)
	if k < 1e-6:
		return Car.MAX_SPEED

	# v²·k = BASE_GRIP + DOWNFORCE_COEF·v²  →  v² (k − coef) = BASE_GRIP
	var denom := k - Car.DOWNFORCE_COEF
	if denom <= 0.0:
		return Car.MAX_SPEED

	return minf(Car.MAX_SPEED, sqrt(Car.BASE_GRIP / denom))


## Изчислява вход за текущото положение.
## Връща { throttle, brake, steer }.
static func compute(track: TrackData, car: Car, projection: Dictionary) -> Dictionary:
	var speed := absf(car.v_forward)
	var lookahead: float = maxf(12.0, speed * 0.85)
	var index: int = projection["index"]
	var ahead_index: int = (index + int(round(lookahead / track.spacing))) % track.count

	var desired_heading := atan2(
		track.xs[ahead_index] - car.x,
		track.zs[ahead_index] - car.z
	)
	var diff := wrapf(desired_heading - car.heading, -PI, PI)

	# Най-стегнатият завой напред определя скоростта.
	var worst := 0.0
	var scan := int(round(SCAN_AHEAD / track.spacing))
	for i in scan:
		worst = maxf(worst, absf(track.curvature[(index + i) % track.count]))

	var target := safe_speed(worst)

	var throttle := 0.0
	var brake := 0.0
	if speed > target * 1.02:
		brake = 1.0
	elif speed < target * 0.97:
		throttle = 1.0
	else:
		throttle = 0.5

	return {
		"throttle": throttle,
		"brake": brake,
		"steer": clampf(diff * 2.6, -1.0, 1.0),
	}
