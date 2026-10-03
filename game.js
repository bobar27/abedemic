// Game "Run & Jump" ala Chrome dino, dipakai oleh games.php.
// Semua gambar sudah siap di folder img/, tapi karakter & obstacle digambar pakai
// fillRect supaya tetap kelihatan pixel-art dan tidak butuh aset tambahan.

// ---------------------------------------------------------------- konfigurasi
const COIN_IMAGE = 'img/coin.png';

const GROUND_Y = 220;        // garis tanah dalam koordinat canvas
const GRAVITY = 0.62;        // percepatan lompat
const JUMP_POWER = 13.2;
// Kecepatan naik perlahan. Semakin besar SPEED_RAMP_DIVISOR, semakin lama game
// terasa "pelan" sebelum mendekati kecepatan maksimum.
//   BASE_SPEED 6.2 -> +0.1 px/frame tiap ~260 m  (sekarang max di ~1.8 km, terlalu cepat)
//   BASE_SPEED 5.6 -> +0.1 px/frame tiap ~1.2 km  (sekarang max di ~5.9 km, jauh lebih santai)
const BASE_SPEED = 5.6;          // kecepatan awal (px per frame)
const MAX_SPEED = 10.5;          // batas atas kecepatan
const SPEED_RAMP_DIVISOR = 1200; // meter yang dibutuhkan untuk naik 1 px/frame
// Ukuran & posisi karakter. Sprite img/runner.png adalah potret buaya 256x208 px
// dengan background sudah dihapus, jadi lebarnya diturunkan dari tinggi supaya
// tidak gepeng.
const RUNNER_H = 62;
const RUNNER_W = Math.round(RUNNER_H * 256 / 208);
const RUNNER_X = 96;

// Hitbox sengaja dibuat lebih kecil daripada gambarnya (inset beberapa px). Kalau memakai
// kotak penuh, pemain akan merasa "nabrak" padahal yang tersentuh cuma sudut gambar.
const RUNNER_BOX_INSET_X = 8;
const RUNNER_BOX_INSET_Y = 6;

const FIRST_MILE_AT = 40;    // koin pertama muncul setelah meter ini

// Ukuran koin & jarak spawn-nya. Koin selalu muncul 120px di sebelah kanan titik
// spawn obstacle, jadi tidak mungkin bertumpuk dengan obstacle mana pun.
const COIN_SIZE = 24;
const COIN_SPAWN_OFFSET = 120;

// Jarak antar obstacle & koin dihitung dalam SATUAN PIXEL, bukan frame. Kalau dalam
// frame, jaraknya jadi makin rapat saat kecepatan naik dan akhirnya jadi tembok kaktus.
const OBSTACLE_GAP_MIN = 300;
const OBSTACLE_GAP_VARIANCE = 460;
const OBSTACLE_GAP_TIGHTEN = 190; // maksimal pemendekan karena jarak jalan makin jauh
const COIN_GAP_MIN = 520;
const COIN_GAP_VARIANCE = 900;
const COIN_GAP_TIGHTEN = 420;

// Karakter memakai gambar PNG langsung (potret buaya dari chatbot), tanpa animasi.
const RUNNER_IMAGE = 'img/runner.png';

// Obstacle cuma kaktus, 2 varian: kecil dan besar.
const CACTUS_SMALL = ['..##....', '.####...', '.####...', '.####.##', '.####.##', '..####..'];
const CACTUS_LARGE = ['...####....', '..######...', '..######...', '.##########', '.##########', '.####..####', '.####..####', '..####..###'];

function buildObstacle(rows, w, h) {
	const rects = [];
	const cols = rows[0].length;

	for (let y = 0; y < rows.length; y++) {
		for (let x = 0; x < cols; x++) {
			if (rows[y][x] === '#') {
				rects.push({ x: x * (w / cols), y: y * (h / rows.length), w: w / cols, h: h / rows.length });
			}
		}
	}

	return rects;
}

// ---------------------------------------------------------------- game state
function createRunner(canvas, assets) {
	const ctx = canvas.getContext('2d');

	// Matikan interpolasi supaya gambar PNG (koin & karakter) tetap tajam pixel-art
	// walau ukurannya diperkecil.
	ctx.imageSmoothingEnabled = false;

	const sources = Object.assign({ coin: COIN_IMAGE, runner: RUNNER_IMAGE }, assets || {});

	const coinImg = new Image();
	coinImg.src = sources.coin;

	const runnerImg = new Image();
	runnerImg.src = sources.runner;

	const state = {
		running: false,
		over: false,
		frame: 0,
		elapsed: 0,
		distance: 0,
		coins: 0,
		speed: BASE_SPEED,
		runnerY: 0,
		velY: 0,
		groundScroll: 0,
		obstacles: [],
		coinsOnField: [],
		nextSpawn: 60,
		nextCoin: FIRST_MILE_AT,
		lastMilestone: 0,
		maxRunSeconds: 120,
		maxCoins: 300,
		// Setiap berapa meter dapat bonus koin. Nilainya dikirim dari games.php
		// (dari konstanta DISTANCE_BONUS_METERS di config.php) supaya satu sumber kebenaran.
		bonusMeters: 2000,
		coinImg: coinImg,
		coinReady: false,
		runnerImg: runnerImg,
		runnerReady: false,
	};

	coinImg.addEventListener('load', () => { state.coinReady = true; });
	runnerImg.addEventListener('load', () => { state.runnerReady = true; });

	function reset() {
		state.over = false;
		state.frame = 0;
		state.elapsed = 0;
		state.distance = 0;
		state.coins = 0;
		state.speed = BASE_SPEED;
		state.runnerY = 0;
		state.velY = 0;
		state.groundScroll = 0;
		state.obstacles = [];
		state.coinsOnField = [];
		state.nextSpawn = 48;
		state.nextCoin = FIRST_MILE_AT;
		state.lastMilestone = 0;
	}

	function jump() {
		if (state.over || !state.running) return;
		if (state.runnerY >= -1 && state.velY === 0) {
			state.velY = JUMP_POWER;
		}
	}

	function spawnObstacle() {
		// Cuma kaktus, 2 varian (kecil & besar), satu per spawn.
		const large = Math.random() < 0.4;
		const w = large ? 46 : 30;
		const h = large ? 62 : 44;

		state.obstacles.push({
			x: canvas.width + 20,
			w: w,
			h: h,
			y: GROUND_Y - h,
			rects: buildObstacle(large ? CACTUS_LARGE : CACTUS_SMALL, w, h),
			kind: 'cactus',
		});
	}

	// Jarak (dalam piksel) ke obstacle berikutnya. Makin jauh perjalanan, makin rapat,
	// tapi selalu ada batas bawah supaya tetap ada ruang untuk bersiap-siap. Separator
	// (/45) dibuat ikut lambat supaya tingkat kesulitan naiknya seimbang dengan kecepatan.
	function nextObstacleGap() {
		const tighten = Math.min(OBSTACLE_GAP_TIGHTEN, state.distance / 45);
		return Math.max(OBSTACLE_GAP_MIN, OBSTACLE_GAP_MIN + OBSTACLE_GAP_VARIANCE * Math.random() - tighten);
	}

	function spawnCoins() {
		// Koin HANYA di jalan lurus, selalu di tanah, tidak pernah di atas obstacle.
		//
		// Kenapa koin di spawn lebih jauh ke kanan daripada obstacle:
		// obstacle & koin sama-sama bergerak ke kiri dengan kecepatan sama, jadi jarak
		// relatifnya kekal. Kalau keduanya spawn di x yang sama, obstacle yang baru
		// muncul akan menimpa deretan koin. Jadi koin selalu muncul 120px di sebelah
		// kanan titik spawn obstacle (lebar obstacle paling besar 46px), sehingga
		// keduanya tidak mungkin bertumpuk.
		const runLength = 3 + Math.floor(Math.random() * 3); // 3-5 koin
		const spacing = 32;
		const startX = canvas.width + COIN_SPAWN_OFFSET;

		for (let i = 0; i < runLength; i++) {
			state.coinsOnField.push({ x: startX + i * spacing, y: GROUND_Y - COIN_SIZE - 6, size: COIN_SIZE, taken: false });
		}

		// Jeda minimal harus lebih besar dari lebar deretan koin, kalau tidak deretan
		// baru akan menimpa deretan yang belum terambil.
		const runWidth = runLength * spacing;
		state.nextCoin = Math.max(COIN_GAP_MIN, runWidth + COIN_GAP_VARIANCE * Math.random() - Math.min(COIN_GAP_TIGHTEN, state.distance / 18));
	}

	function rectsOverlap(ax, ay, aw, ah, bx, by, bw, bh) {
		return ax < bx + bw && ax + aw > bx && ay < by + bh && ay + ah > by;
	}

	function checkCollisions() {
		// Pakai kotak yang sama dengan runnerBox() supaya tabrakan selalu cocok dengan
		// yang terlihat di layar.
		const box = runnerBox();
		const rx = box.x;
		const ry = box.y;
		const rw = box.w;
		const rh = box.h;

		for (const ob of state.obstacles) {
			if (rectsOverlap(rx, ry, rw, rh, ob.x + 3, ob.y + 3, ob.w - 6, ob.h - 6)) {
				endRun();
				return;
			}
		}

		// Koin pakai kotak yang sedikit lebih kecil lagi supaya tidak gampang terambil
		// hanya karena gambarnya bersentuhan.
		for (const coin of state.coinsOnField) {
			if (coin.taken) continue;
			if (rectsOverlap(rx + 5, ry + 5, rw - 10, rh - 10, coin.x + 2, coin.y + 2, coin.size - 4, coin.size - 4)) {
				coin.taken = true;
				state.coins += 1;
				onCoin?.(state.coins);
			}
		}
	}

	function update() {
		state.frame += 1;
		state.elapsed += 1 / 60;

		// Makin jauh makin cepat, dibatasi supaya masih playable.
		state.speed = Math.min(MAX_SPEED, BASE_SPEED + state.distance / SPEED_RAMP_DIVISOR);
		state.distance += state.speed * 0.35;

		// Laporkan ke luar tiap frame supaya HUD ikut jalan.
		onTick?.(Math.floor(state.distance), state.coins);

		// Lompat & gravitasi
		if (state.velY !== 0 || state.runnerY < 0) {
			state.runnerY -= state.velY;
			state.velY -= GRAVITY;

			if (state.runnerY >= 0) {
				state.runnerY = 0;
				state.velY = 0;
			}
		}

		state.groundScroll = (state.groundScroll + state.speed) % 40;

		// Obstacle. nextSpawn dihitung dalam piksel, jadi jarak antar obstacle selalu
		// jauh dan tidak pernah berubah jadi barisan rapat saat kecepatan naik.
		state.nextSpawn -= state.speed;
		if (state.nextSpawn <= 0) {
			spawnObstacle();
			state.nextSpawn = nextObstacleGap();
		}
		state.obstacles = state.obstacles.filter((ob) => {
			ob.x -= state.speed;
			return ob.x + ob.w > -10;
		});

		// Koin: penempatannya ditentukan sendiri di spawnCoins() (di atas obstacle
		// atau di jalan yang kosong).
		state.nextCoin -= state.speed;
		if (state.nextCoin <= 0 && state.distance > FIRST_MILE_AT) {
			spawnCoins();
		}
		state.coinsOnField = state.coinsOnField.filter((coin) => {
			coin.x -= state.speed;
			return !coin.taken && coin.x + coin.size > -10;
		});

		// Bonus koin tiap `bonusMeters` meter. Nilainya dihitung ulang di server dari jarak
		// akhir, jadi angka ini hanya untuk memicu notifikasi popup di layar.
		const step = Math.max(1, state.bonusMeters);
		const milestone = Math.floor(state.distance / step);
		if (milestone > state.lastMilestone) {
			state.lastMilestone = milestone;
			onMilestone?.(milestone * step);
		}

		if (state.elapsed >= state.maxRunSeconds || state.coins >= state.maxCoins) {
			endRun();
			return;
		}

		checkCollisions();
	}

	function drawGround() {
		ctx.fillStyle = '#8fd44a';
		ctx.fillRect(0, GROUND_Y, canvas.width, 3);

		ctx.fillStyle = 'rgba(255,255,255,0.16)';
		for (let x = -state.groundScroll; x < canvas.width; x += 40) {
			ctx.fillRect(x, GROUND_Y + 10, 20, 3);
		}
	}

	function drawRunner() {
		// Karakter = gambar PNG, tanpa animasi. Ujung bawah gambar selalu menempel di
		// garis tanah (dikurangi sedikit biar tidak tenggelam) dan ikut naik saat lompat.
		const top = GROUND_Y - RUNNER_H + state.runnerY - 4;

		if (state.runnerReady) {
			ctx.drawImage(state.runnerImg, RUNNER_X, Math.round(top), RUNNER_W, RUNNER_H);
			return;
		}

		// Placeholder selagi gambar belum termuat, supaya karakter tidak pernah hilang.
		ctx.fillStyle = '#54e08a';
		ctx.fillRect(RUNNER_X, Math.round(top), RUNNER_W, RUNNER_H);
	}

	// Kotak tabrakan karakter. Mengikuti gambar, tapi dikecilkan sedikit di setiap sisi.
	function runnerBox() {
		return {
			x: RUNNER_X + RUNNER_BOX_INSET_X,
			y: GROUND_Y - RUNNER_H + state.runnerY - 4 + RUNNER_BOX_INSET_Y,
			w: RUNNER_W - RUNNER_BOX_INSET_X * 2,
			h: RUNNER_H - RUNNER_BOX_INSET_Y * 2,
		};
	}

	function drawObstacle(ob) {
		// Cuma kaktus sekarang, jadi warnanya selalu hijau.
		ctx.fillStyle = '#7ce04b';

		for (const r of ob.rects) {
			ctx.fillRect(Math.round(ob.x + r.x), Math.round(ob.y + r.y), Math.ceil(r.w), Math.ceil(r.h));
		}
	}

	function drawCoins() {
		if (!state.coinReady) return;

		for (const coin of state.coinsOnField) {
			if (coin.taken) continue;
			ctx.drawImage(state.coinImg, Math.round(coin.x), Math.round(coin.y), coin.size, coin.size);
		}
	}

	function draw() {
		ctx.clearRect(0, 0, canvas.width, canvas.height);
		drawGround();
		drawRunner();
		for (const ob of state.obstacles) drawObstacle(ob);
		drawCoins();
	}

	function endRun() {
		if (state.over) return;
		state.over = true;
		state.running = false;
		onGameOver?.(buildResult());
	}

	function buildResult() {
		return {
			distance: Math.floor(state.distance),
			coins: state.coins,
			// Berapa kali bonus 200 meter sudah didapat selama run ini.
			milestones: Math.floor(state.distance / Math.max(1, state.bonusMeters)),
			seconds: Math.round(state.elapsed),
		};
	}

	function start() {
		reset();
		state.running = true;
		frame();
	}

	function frame() {
		if (!state.running) return;
		update();
		draw();
		rafId = requestAnimationFrame(frame);
	}

	let rafId = 0;
	let onGameOver = null;
	let onCoin = null;
	let onMilestone = null;
	let onTick = null;

	return {
		start,
		jump,
		get state() { return state; },
		set onGameOver(fn) { onGameOver = fn; },
		set onCoin(fn) { onCoin = fn; },
		set onMilestone(fn) { onMilestone = fn; },
		set onTick(fn) { onTick = fn; },
		stopLoop() {
			state.running = false;
			if (rafId) cancelAnimationFrame(rafId);
			rafId = 0;
		},
		frame,
		draw,
	};
}

