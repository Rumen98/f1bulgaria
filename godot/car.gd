class_name Car
extends RefCounted

## Аркадна физика на болида, с фиксирана стъпка.
##
## Порт на resources/js/game/physics.js, ред по ред. Целта е двете версии да
## дават едни и същи времена — иначе рекордите от браузъра и от десктопа не
## могат да живеят в обща класация.
##
## Съзнателно НЕ се ползва VehicleBody3D: той стъпва върху physics engine-а,
## който не е детерминистичен между платформи и версии. `step()` тук е чиста
## функция на (състояние, вход, dt) без достъп до времето или случайност,
## което позволява същият вход да се превърти на сървъра при валидация.

## Таван на скоростта, m/s. Реалната максимална е под него — определя я
## равновесието между тягата и съпротивлението (≈ 88 m/s, 317 km/h).
const MAX_SPEED := 92.0

## Ускорение при нулева скорост и пълна газ, m/s² (0-100 km/h за ~2.3 s).
const ENGINE_POWER := 13.0

## Забавяне при пълна спирачка, m/s² (≈ 4.6 g).
const BRAKE_POWER := 45.0

const REVERSE_POWER := 6.0
const MAX_REVERSE_SPEED := 12.0

## Аеродинамично съпротивление (квадратично). Заедно с ENGINE_POWER определя
## максималната скорост: P = drag·v² + roll·v.
const DRAG := 0.00145
const ROLLING_RESISTANCE := 0.02

## Странично сцепление при нулева скорост, m/s² (≈ 1.5 g).
const BASE_GRIP := 15.0

## Прираст на сцеплението от притискащата сила: grip += coef · v².
## Оттук идва усещането за формула — залепен в бързите завои, изнасящ в бавните.
const DOWNFORCE_COEF := 0.0063

const MAX_STEER_ANGLE := 0.52

## Колко бързо пада ъгълът на завиване със скоростта. Без това на 300 km/h
## едно докосване на стрелката завърта колата на 90°.
const STEER_SPEED_FALLOFF := 0.055

const STEER_RATE := 3.4
const STEER_RETURN_RATE := 5.5
const WHEELBASE := 3.6

const OFF_TRACK_GRIP_FACTOR := 0.38
const OFF_TRACK_DRAG := 9.0

const GRAVITY := 9.81

## Позиция и посока. heading е в радиани; forward = (sin h, cos h).
var x := 0.0
var z := 0.0
var heading := 0.0

## Надлъжна и странична скорост, m/s (странична: + = надясно).
var v_forward := 0.0
var v_lateral := 0.0

## Текущо положение на волана, [-1, 1].
var steer := 0.0

## rad/s и 0..1 плъзгане — за визуализация.
var yaw_rate := 0.0
var slip := 0.0


## Слага колата на стартовата линия.
func reset_to_start(track: TrackData) -> void:
	x = track.xs[0]
	z = track.zs[0]
	heading = atan2(track.tx[0], track.tz[0])
	v_forward = 0.0
	v_lateral = 0.0
	steer = 0.0
	yaw_rate = 0.0
	slip = 0.0


## Една стъпка на симулацията.
##
## throttle и brake са в [0, 1], steer_input в [-1, 1].
## gradient е наклонът на трасето по посоката на движение (dy/ds).
func step(
	throttle: float,
	brake: float,
	steer_input: float,
	dt: float,
	on_track: bool,
	gradient: float
) -> void:
	var grip_factor := 1.0 if on_track else OFF_TRACK_GRIP_FACTOR

	# ── Волан ────────────────────────────────────────────────────────────
	# Воланът се движи с крайна скорост, не мигновено. На клавиатура това е
	# разликата между „кола" и „курсор".
	var target: float = clampf(steer_input, -1.0, 1.0)
	var rate := 0.0
	if absf(target) > 0.01:
		rate = STEER_RATE * dt
		steer += clampf(target - steer, -rate, rate)
	else:
		rate = STEER_RETURN_RATE * dt
		steer -= clampf(steer, -rate, rate)
	steer = clampf(steer, -1.0, 1.0)

	# ── Надлъжна динамика ────────────────────────────────────────────────
	var accel := 0.0

	if throttle > 0.0 and v_forward < MAX_SPEED:
		# Тягата е константна; максималната скорост идва от равновесието със
		# съпротивлението по-долу.
		accel += ENGINE_POWER * throttle * grip_factor

	if brake > 0.0:
		if v_forward > 0.5:
			accel -= BRAKE_POWER * brake * grip_factor
		elif v_forward > -MAX_REVERSE_SPEED:
			# Под прага спирачката става заден ход.
			accel -= REVERSE_POWER * brake

	accel -= DRAG * v_forward * absf(v_forward)
	accel -= ROLLING_RESISTANCE * v_forward * (1.0 if on_track else 3.0)

	if not on_track:
		accel -= signf(v_forward) * OFF_TRACK_DRAG

	# Съставяща на тежестта по склона. `gradient` е тангенсът на наклона, а на
	# нас ни трябва синусът — при 18% (Ео Руж) разликата е 1.6%, но е евтина.
	if absf(gradient) > 1e-9:
		accel -= GRAVITY * (gradient / sqrt(1.0 + gradient * gradient))

	v_forward += accel * dt

	# Спирачката не бива да тласка колата назад в рамките на една стъпка.
	if brake > 0.0 and throttle == 0.0 and absf(v_forward) < 0.3:
		v_forward = 0.0

	v_forward = clampf(v_forward, -MAX_REVERSE_SPEED, MAX_SPEED)

	# ── Сцепление и завиване ─────────────────────────────────────────────
	var speed := absf(v_forward)
	var max_lateral_accel := (BASE_GRIP + DOWNFORCE_COEF * speed * speed) * grip_factor

	var steer_angle := MAX_STEER_ANGLE * steer / (1.0 + speed * STEER_SPEED_FALLOFF)
	var desired_yaw_rate := (v_forward * tan(steer_angle)) / WHEELBASE

	# Центростремителното ускорение не може да надвиши сцеплението: при опит
	# колата отива в подуправление вместо да завие.
	var max_yaw_rate := max_lateral_accel / maxf(speed, 1.0)
	yaw_rate = clampf(desired_yaw_rate, -max_yaw_rate, max_yaw_rate)

	if absf(desired_yaw_rate) > 1e-4:
		slip = clampf(1.0 - absf(yaw_rate) / absf(desired_yaw_rate), 0.0, 1.0)
	else:
		slip = 0.0

	heading += yaw_rate * dt

	# Частта от желаното завиване, която сцеплението не понесе, се превръща в
	# странично плъзгане — оттам идва усещането за „изнасяне" в завоя.
	var unserved_yaw := desired_yaw_rate - yaw_rate
	v_lateral += unserved_yaw * speed * dt

	# Гумите гасят страничната скорост до лимита на сцеплението.
	var lateral_friction := max_lateral_accel * dt
	if absf(v_lateral) <= lateral_friction:
		v_lateral = 0.0
	else:
		v_lateral -= signf(v_lateral) * lateral_friction

	# ── Интегриране на позицията ─────────────────────────────────────────
	var sin_h := sin(heading)
	var cos_h := cos(heading)

	# forward = (sin, cos); right = (cos, -sin)
	x += (v_forward * sin_h + v_lateral * cos_h) * dt
	z += (v_forward * cos_h - v_lateral * sin_h) * dt


## Скорост в km/h, за HUD.
func speed_kmh() -> float:
	return sqrt(v_forward * v_forward + v_lateral * v_lateral) * 3.6
