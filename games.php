<?php
$pageTitle = 'Games';
$tab = 'Games';
$contentClass = 'games-content';
require __DIR__ . '/partials/header.php';

$wallet = get_wallet($user['id']);
$cards = get_cards($user['id']);
?>
				<body>
				<!-- Popup notifikasi bonus 200 meter (dipasang di luar canvas) -->
				<div class="game-popup" id="gamePopup" hidden>
					<img class="game-popup-coin" src="<?= e(asset_url('img/coin.png')) ?>" alt="">
					<span class="game-popup-text" id="gamePopupText">+10 koin!</span>
					<span class="game-popup-sub" id="gamePopupSub">200 m</span>
				</div>

				<div class="game-shell">
					<!-- ===== Layar 1: Start ===== -->
					<section class="game-screen" id="gameStart">
						<h2 class="game-title">Run &amp; Jump</h2>
						<div class="game-intro">
							<img class="game-intro-mascot" src="img/dino-mascot.jpeg" alt="">
							<p class="game-intro-text">Achieve and collect certain goals/objective to trade it for spell cards!</p>
						</div>

						<div class="game-stats">
							<div class="game-stat">
								<span class="game-stat-label">Koin kamu</span>
								<strong class="game-stat-value"><img class="game-coin-icon" src="<?= e(asset_url('img/coin.png')) ?>" alt=""><?= (int) $wallet['coins'] ?></strong>
							</div>
							<div class="game-stat">
								<span class="game-stat-label">Rekor jarak</span>
								<strong class="game-stat-value"><?= (int) $wallet['best_distance'] ?> m</strong>
							</div>
							<div class="game-stat">
								<span class="game-stat-label">Total run</span>
								<strong class="game-stat-value"><?= (int) $wallet['total_runs'] ?></strong>
							</div>
						</div>

						<button class="login-button game-primary" type="button" id="gameStartBtn">Mulai Run</button>
						<div class="game-actions">
							<button class="quick-nav-btn" type="button" data-open="shopModal">Shop Spell Card</button>
						</div>
						<p class="game-hint">Tekan <kbd>Spasi</kbd>, <kbd>&uarr;</kbd>, atau klik untuk lompat.</p>
					</section>

					<!-- ===== Layar 2: HUD + Area Main ===== -->
					<section class="game-screen" id="gamePlay" hidden>
						<div class="game-hud">
							<span class="game-hud-item">Jarak <strong id="hudDistance">0</strong> m</span>
							<span class="game-hud-item">Koin <strong id="hudCoins">0</strong></span>
							<button class="game-quit-btn" type="button" id="gameQuitBtn">Menu</button>
						</div>
						<div class="game-canvas-wrap">
							<canvas id="gameCanvas" width="960" height="300"></canvas>
						</div>
					</section>

					<!-- ===== Layar 3: Results ===== -->
					<section class="game-screen game-results" id="gameResults" hidden>
						<h2 class="game-title">Your Score</h2>
						<div class="game-intro">
							<img class="game-intro-mascot" id="resultMascot" src="img/dino-mascot.jpeg" alt="">
							<p class="game-intro-text" id="resultMessage">Nice run!</p>
						</div>

						<dl class="game-score-list">
							<div class="game-score-row">
								<dt>Distance Travelled</dt>
								<dd><strong id="resultDistance">0</strong> m</dd>
							</div>
							<div class="game-score-row">
								<dt>Coins Collected</dt>
								<dd><img class="game-coin-icon" src="<?= e(asset_url('img/coin.png')) ?>" alt=""><strong id="resultCoins">0</strong></dd>
							</div>
							<div class="game-score-row">
								<dt>Rewards Gain</dt>
								<dd><img class="game-coin-icon" src="<?= e(asset_url('img/coin.png')) ?>" alt=""><strong id="resultRewards">0</strong></dd>
							</div>
						</dl>

						<button class="login-button game-primary" type="button" id="gameRetryBtn">Main Lagi</button>
						<div class="game-actions">
							<button class="quick-nav-btn" type="button" data-open="shopModal">Shop Spell Card</button>
							<a class="quick-nav-btn" href="index.php">Menu</a>
						</div>
					</section>
				</div>

				<!-- ===== Modal Shop ===== -->
				<div class="modal" id="shopModal" hidden>
					<div class="modal-box modal-wide" role="dialog" aria-modal="true" aria-labelledby="shopTitle">
						<button class="modal-close" type="button" data-close aria-label="Tutup">&times;</button>
						<h2 id="shopTitle">Shop Spell Card</h2>
						<p class="shop-balance">Saldo koin: <img class="game-coin-icon" src="<?= e(asset_url('img/coin.png')) ?>" alt=""><strong id="shopCoins"><?= (int) $wallet['coins'] ?></strong></p>
						<p class="stage-error" id="shopError" hidden></p>

						<div class="shop-grid">
							<?php foreach (CARD_CATALOG as $key => $card): ?>
								<article class="shop-card" data-card="<?= e($key) ?>">
									<img class="shop-card-art" src="<?= e(asset_url($card['image'])) ?>" alt="">
									<h3><?= e($card['name']) ?></h3>
									<p class="shop-card-desc"><?= e($card['description']) ?></p>
									<p class="shop-card-owned">Dimiliki: <strong><?= (int) ($cards[$key] ?? 0) ?></strong></p>
									<button class="shop-buy-btn" type="button" data-buy="<?= e($key) ?>" data-price="<?= (int) $card['price'] ?>"
										<?= $wallet['coins'] < $card['price'] ? 'disabled' : '' ?>>
										<img class="game-coin-icon" src="<?= e(asset_url('img/coin.png')) ?>" alt=""> <?= (int) $card['price'] ?>
									</button>
									<p class="shop-buy-note" data-role="buyNote" hidden>Koin belum cukup</p>
								</article>
							<?php endforeach; ?>
						</div>

					<p class="shop-note">Kartu bisa dipakai di halaman Quiz Time untuk menyelamatkan satu soal.</p>
				</div>
			</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
	<script src="<?= e(asset_url('game.js')) ?>"></script>
	<script>
		const MAX_RUN_SECONDS = <?= (int) GAME_MAX_RUN_SECONDS ?>;
		const MAX_COINS = <?= (int) GAME_MAX_COINS_PER_RUN ?>;
		const BONUS_METERS = <?= (int) DISTANCE_BONUS_METERS ?>;
		const BONUS_COINS = <?= (int) DISTANCE_BONUS_COINS ?>;
		const COIN_URL = <?= json_encode(asset_url('img/coin.png')) ?>;
		const RUNNER_URL = <?= json_encode(asset_url('img/runner.png')) ?>;
		// MILESTONE_EVERY dipakai dari game.js (satu sumber kebenaran, jangan dideklarasikan ulang).

		const startScreen = document.getElementById('gameStart');
		const playScreen = document.getElementById('gamePlay');
		const resultsScreen = document.getElementById('gameResults');
		const canvas = document.getElementById('gameCanvas');
		const hudDistance = document.getElementById('hudDistance');
		const hudCoins = document.getElementById('hudCoins');
		const popup = document.getElementById('gamePopup');
		const popupText = document.getElementById('gamePopupText');
		const popupSub = document.getElementById('gamePopupSub');

		const game = createRunner(canvas, { coin: COIN_URL, runner: RUNNER_URL });
		window.game = game;
		game.state.maxRunSeconds = MAX_RUN_SECONDS;
		game.state.maxCoins = MAX_COINS;
		game.state.bonusMeters = BONUS_METERS;

		// Koin bonus yang dikumpulkan selama run ini (dihitung ulang di server).
		let bonusCoins = 0;
		let popupTimer = 0;

		function showScreen(target) {
			[startScreen, playScreen, resultsScreen].forEach((el) => { el.hidden = el !== target; });
		}

		// Popup di bagian atas layar: "+10 koin!" + jarak yang dicapai.
		function showBonusPopup(meters) {
			popupText.textContent = '+' + BONUS_COINS + ' koin!';
			popupSub.textContent = meters + ' m';
			popup.hidden = false;
			popup.classList.remove('is-pop');
			void popup.offsetWidth; // paksa animasi berputar ulang
			popup.classList.add('is-pop');

			clearTimeout(popupTimer);
			popupTimer = setTimeout(() => { popup.hidden = true; }, 1600);
		}

		// HUD "Koin" menampilkan total: koin dipickup + bonus 2000 meter.
		function refreshHudCoins(collected) {
			hudCoins.textContent = collected + bonusCoins;
		}

		// Terapkan dompet terbaru ke semua tempat yang menampilkan saldo koin.
		// Dipanggil setelah run selesai dan setelah membeli kartu di Shop.
		function applyWallet(next) {
			if (!next || typeof next.coins !== 'number') return;

			const coinHtml = '<img class="game-coin-icon" src="' + COIN_URL + '" alt="">';

			const coinsEl = document.querySelector('#gameStart .game-stat-value');
			if (coinsEl) coinsEl.innerHTML = coinHtml + next.coins;

			const bestEl = document.querySelectorAll('#gameStart .game-stat-value')[1];
			if (bestEl) bestEl.textContent = next.best_distance + ' m';

			const runsEl = document.querySelectorAll('#gameStart .game-stat-value')[2];
			if (runsEl) runsEl.textContent = next.total_runs;

			const badge = document.querySelector('.wallet-badge');
			if (badge) badge.innerHTML = coinHtml + next.coins;

			shopCoins.textContent = next.coins;
			paintShop();
		}

		game.onCoin = (collected) => { refreshHudCoins(collected); };
		game.onMilestone = (meters) => {
			bonusCoins += BONUS_COINS;
			showBonusPopup(meters);
			refreshHudCoins(game.state.coins);
		};

		// HUD jarak harus ikut jalan terus selama main, bukan cuma saat game over.
		game.onTick = (distance) => {
			hudDistance.textContent = distance;
		};

		game.onGameOver = async (result) => {
			hudDistance.textContent = result.distance;
			hudCoins.textContent = result.coins + bonusCoins;

			// Angka reward dihitung server (api/game.php) supaya tidak bisa dikira-kira di client.
			let rewards = result.coins + bonusCoins;
			let freshWallet = null;

			try {
				const body = new FormData();
				body.append('action', 'finish_run');
				body.append('distance', result.distance);
				body.append('coins', result.coins);

				const res = await fetch('api/game.php', { method: 'POST', body });
				const data = await res.json();

				if (res.ok && data.ok) {
					rewards = data.coins_earned;
					freshWallet = data.wallet;
				}
			} catch (err) {
				popupText.textContent = 'Gagal menyimpan';
				popupSub.textContent = 'hasil run';
				popup.hidden = false;
			}

			document.getElementById('resultDistance').textContent = result.distance;
			document.getElementById('resultCoins').textContent = result.coins;
			document.getElementById('resultRewards').textContent = rewards;

			const timesHit = result.milestones;
			document.getElementById('resultMessage').textContent = timesHit > 0
				? 'Bonus ' + timesHit + 'x tiap ' + BONUS_METERS + ' m = +' + (timesHit * BONUS_COINS) + ' koin. Jarak bonus berikutnya di ' + BONUS_METERS + ' m lagi.'
				: 'Jarak ' + BONUS_METERS + ' m lagi buat bonus +' + BONUS_COINS + ' koin.';

			// Saldo dari server harus langsung terlihat di SEMUA tempat: layar Start, badge
			// sidebar, dan modal Shop. Kalau Shop tidak ikut diperbarui, player tidak
			// bisa langsung belanja dari layar Results dan harus balik ke Menu dulu.
			if (freshWallet) applyWallet(freshWallet);

			showScreen(resultsScreen);
		};

		function beginRun() {
			// Bersihkan popup & reset angka bonus sebelum run baru dimulai.
			clearTimeout(popupTimer);
			popup.hidden = true;
			bonusCoins = 0;
			hudDistance.textContent = '0';
			hudCoins.textContent = '0';
			showScreen(playScreen);
			game.start();
		}

		document.getElementById('gameStartBtn').addEventListener('click', beginRun);
		document.getElementById('gameRetryBtn').addEventListener('click', beginRun);

		document.getElementById('gameQuitBtn').addEventListener('click', () => {
			game.stopLoop();
			clearTimeout(popupTimer);
			popup.hidden = true;
			showScreen(startScreen);
		});

		// Kontrol lompat: keyboard, klik, dan tap.
		document.addEventListener('keydown', (e) => {
			if (playScreen.hidden) return;
			if (e.key === ' ' || e.key === 'ArrowUp' || e.key === 'w') {
				e.preventDefault();
				game.jump();
			}
			if (e.key === 'Escape') {
				game.stopLoop();
				clearTimeout(popupTimer);
				popup.hidden = true;
				showScreen(startScreen);
			}
		});

		canvas.addEventListener('pointerdown', (e) => { e.preventDefault(); game.jump(); });

		// ---- Shop ----
		const shopError = document.getElementById('shopError');
		const shopCoins = document.getElementById('shopCoins');

		// Tombol Beli dinonaktifkan + diberi catatan "Koin belum cukup" kalau saldo
		// tidak cukup, sesuai aturan di CARD_CATALOG.
		function paintShop() {
			const balance = Number(shopCoins.textContent);

			document.querySelectorAll('[data-buy]').forEach((btn) => {
				const price = Number(btn.dataset.price);
				const affordable = balance >= price;
				const note = btn.closest('.shop-card').querySelector('[data-role="buyNote"]');

				btn.disabled = !affordable;
				note.hidden = affordable;
			});
		}

		paintShop();

		document.querySelectorAll('[data-buy]').forEach((btn) => {
			btn.addEventListener('click', async () => {
				shopError.hidden = true;
				btn.disabled = true;

				try {
					const body = new FormData();
					body.append('action', 'buy_card');
					body.append('card', btn.dataset.buy);

					const res = await fetch('api/game.php', { method: 'POST', body });
					const data = await res.json();

					if (!res.ok || !data.ok) {
						throw new Error(data.error || 'Gagal membeli kartu.');
					}

					shopCoins.textContent = data.wallet.coins;
					const card = btn.closest('.shop-card');
					card.querySelector('.shop-card-owned strong').textContent = data.cards[btn.dataset.buy];

					// Badge sidebar & layar Start ikut diperbarui lewat applyWallet(),
					// sekaligus menghitung ulang status tombol Beli.
					applyWallet(data.wallet);
				} catch (err) {
					shopError.textContent = err.message;
					shopError.hidden = false;
				} finally {
					// Status tombol dihitung ulang dari saldo terbaru.
					paintShop();
				}
			});
		});

		// Canvas pakai width:100% + height:auto dari CSS, jadi rasio 960:300 otomatis
		// terjaga tanpa perlu menghitung tinggi lewat JS.
	</script>
</body>
</html>