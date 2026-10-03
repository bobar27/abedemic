<?php
require_once __DIR__ . '/history.php';

$pageTitle = 'Quiz Time';
$tab = 'Quiz Time';
$contentClass = 'quiz-content quiziz-content';
require __DIR__ . '/partials/header.php';

$viewId = isset($_GET['view']) ? (int) $_GET['view'] : null;
$viewedQuiz = $viewId ? get_quiz($user['id'], $viewId) : null;
$autoFromChat = isset($_GET['from']) && $_GET['from'] === 'chat' && !$viewedQuiz;
// Obrolan asal saat tombol "Buat Kuis dari Obrolan" ditekan.
$chatConversation = isset($_GET['c']) && find_conversation($user['id'], (int) $_GET['c']) ? (int) $_GET['c'] : 0;
$recentQuizzes = get_quizzes($user['id']);
$attemptScores = get_attempt_scores($user['id']);
$quizCards = get_cards($user['id']);
?>
				<img class="page-avatar" src="img/rimuru-mascot.jpeg" alt="">

				<!-- ===== Stage 1: pilih materi ===== -->
				<div id="quizUpload" class="quiz-stage" <?= ($viewedQuiz || $autoFromChat) ? 'hidden' : '' ?>>
					<label class="dropzone" for="quizFile">
						<img src="img/folder-pic.jpeg" alt="">
						<span id="quizFileLabel">DROP FILE HERE</span>
					</label>
					<input type="file" id="quizFile" accept=".txt,.pdf,.png,.jpg,.jpeg,.webp" hidden>
					<p class="stage-error" id="quizError" hidden></p>

					<div class="quiz-count-picker">
						<span>Jumlah soal</span>
						<label><input type="radio" name="quizCount" value="10" checked> 10</label>
						<label><input type="radio" name="quizCount" value="20"> 20</label>
					</div>

					<button type="button" class="login-button" id="quizGenerate" disabled>Generate Quiz</button>

					<?php if ($recentQuizzes): ?>
						<div class="history-list">
							<h3>Riwayat Kuis</h3>
							<ul>
								<?php foreach ($recentQuizzes as $item): ?>
									<?php $score = $attemptScores[(int) $item['id']] ?? null; ?>
									<li>
										<a href="quiz.php?view=<?= (int) $item['id'] ?>"><?= e($item['source_label']) ?></a>
										<span>
											<?php if ($score): ?><b><?= (int) $score['score'] ?>/<?= (int) $score['total'] ?></b> · <?php endif; ?>
											<?= e(date('d M, H:i', strtotime($item['created_at']))) ?>
										</span>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
				</div>

				<!-- ===== Stage 2: loading ===== -->
				<div id="quizLoading" class="quiz-stage" <?= $autoFromChat ? '' : 'hidden' ?>>
					<div class="pixel-progress pixel-progress-lg"><div class="pixel-progress-fill"></div></div>
					<p class="loading-label">Abe sedang menyusun soal...</p>
				</div>

				<!-- ===== Stage 3: menjawab soal (gaya Quiziz) ===== -->
				<div id="quizPlay" class="quiziz-stage" hidden>
					<div class="quiziz-grid">
						<div class="quiziz-main">
							<div class="quiziz-topbar">
								<div class="quiziz-topbar-row">
									<span class="quiziz-counter">Soal <strong id="quizizIndex">1</strong> / <span id="quizizTotal">0</span></span>
									<span class="quiziz-tally">
										Dijawab <strong id="quizizAnswered">0</strong>
									</span>
									<button type="button" class="quiziz-quit" id="quizizQuit">Quit</button>
								</div>
								<div class="quiziz-progress"><div class="quiziz-progress-fill" id="quizizProgressFill"></div></div>
							</div>

							<div class="quiziz-question" id="quizizQuestion"></div>
							<div class="quiziz-options" id="quizizOptions"></div>
							<p class="quiziz-note" id="quizizNote" hidden></p>
						</div>

						<aside class="quiziz-cards">
							<h3 class="quiziz-cards-title">Spell Card</h3>
							<p class="quiziz-cards-hint">Klik kartu sebelum menjawab.</p>
							<?php foreach (CARD_CATALOG as $key => $card): ?>
								<button class="spell-card" type="button" data-card="<?= e($key) ?>" data-count="<?= (int) ($quizCards[$key] ?? 0) ?>">
									<span class="spell-card-count" data-role="count"><?= (int) ($quizCards[$key] ?? 0) ?></span>
									<img class="spell-card-art" src="<?= e(asset_url($card['image'])) ?>" alt="">
									<span class="spell-card-name"><?= e($card['name']) ?></span>
									<span class="spell-card-state" data-role="state">Ready</span>
								</button>
							<?php endforeach; ?>
							<p class="quiziz-cards-hint">
								Belum punya kartu? Main <a href="games.php">Run &amp; Jump</a> untuk mengumpulkan koin.
							</p>
						</aside>
					</div>
				</div>

				<!-- ===== Stage 4: results ===== -->
				<div id="quizResults" class="quiziz-stage" hidden>
					<div class="quiziz-results-head">
						<h2 class="quiziz-results-title">Hasil Quiz</h2>
						<p class="quiziz-results-score">
							<strong id="quizizFinalScore">0</strong> / <span id="quizizFinalTotal">0</span> benar
						</p>
						<p class="quiziz-results-meta" id="quizizResultsMeta"></p>
					</div>
					<ol class="quiziz-review" id="quizizReview"></ol>
					<div class="quiziz-results-actions">
						<button type="button" class="login-button" id="quizizRetry">Coba Lagi</button>
						<button type="button" class="quick-nav-btn" id="quizizNewQuiz">Buat Kuis dari Materi Lain</button>
					</div>
				</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
	<script>
		const fileInput = document.getElementById('quizFile');
		const fileLabel = document.getElementById('quizFileLabel');
		const generateBtn = document.getElementById('quizGenerate');
		const errorBox = document.getElementById('quizError');
		const uploadStage = document.getElementById('quizUpload');
		const loadingStage = document.getElementById('quizLoading');
		const playStage = document.getElementById('quizPlay');
		const resultsStage = document.getElementById('quizResults');

		const indexEl = document.getElementById('quizizIndex');
		const totalEl = document.getElementById('quizizTotal');
		const answeredEl = document.getElementById('quizizAnswered');
		const progressEl = document.getElementById('quizizProgressFill');
		const questionEl = document.getElementById('quizizQuestion');
		const optionsEl = document.getElementById('quizizOptions');
		const noteEl = document.getElementById('quizizNote');
		const cardButtons = [...document.querySelectorAll('.spell-card')];

		const state = {
			quizId: <?= (int) ($viewedQuiz['id'] ?? 0) ?>,
			sourceLabel: <?= json_encode($viewedQuiz['source_label'] ?? '') ?>,
			questions: <?= json_encode($viewedQuiz['questions'] ?? []) ?>,
			queue: [],          // indeks soal yang belum dijawab, urutan tampil
			queuePos: 0,
			records: [],        // rekaman jawaban: {index, chosen, correct, voided, skipped, card}
			armed: null,        // kartu yang di-arm untuk soal aktif
			removedOptions: new Set(), // opsi yang dibuang SECOND CHANCE pada soal ini
			busy: false,
		};

		// ---------------------------------------------------------------- kartu
		function cardCount(key) {
			const btn = cardButtons.find((b) => b.dataset.card === key);
			return btn ? Number(btn.dataset.count) : 0;
		}

		function paintCards() {
			cardButtons.forEach((btn) => {
				const key = btn.dataset.card;
				const total = cardCount(key);
				const isArmed = state.armed === key;

				btn.dataset.count = total;
				btn.querySelector('[data-role="count"]').textContent = total;
				btn.querySelector('[data-role="state"]').textContent = isArmed ? 'Active' : 'Ready';
				btn.classList.toggle('is-armed', isArmed);
				btn.classList.toggle('is-empty', total === 0);
				btn.disabled = state.busy || (total === 0 && !isArmed);
			});
		}

		async function consumeCard(key) {
			try {
				const body = new FormData();
				body.append('action', 'consume_card');
				body.append('card', key);

				const res = await fetch('api/game.php', { method: 'POST', body });
				const data = await res.json();

				if (res.ok && data.ok) {
					data.cards && syncCardCounts(data.cards);
					return true;
				}
			} catch (err) {
				// Offline / server salah: SyntaxError diamkan saja, kartu dianggap masih ada.
			}
			return false;
		}

		function syncCardCounts(counts) {
			cardButtons.forEach((btn) => {
				if (typeof counts[btn.dataset.card] === 'number') {
					btn.dataset.count = counts[btn.dataset.card];
				}
			});
		}

		cardButtons.forEach((btn) => {
			btn.addEventListener('click', () => {
				const key = btn.dataset.card;
				if (cardCount(key) === 0) return;

				// Klik kartu yang lagi aktif berarti membatalkan.
				if (state.armed === key) {
					state.armed = null;
					paintCards();
					note('');
					return;
				}

				// Dua kartu sama-sama "nunggu dulu": kartu baru di-arm, BELUM
				// dikurangi. Pengurangannya nanti kalau jawaban memang salah.
				state.armed = key;
				paintCards();

				note(key === 'immunity'
					? 'IMMUNITY aktif. Kalau jawabanmu salah, soal ini tidak dihitung dan kartu kamu hangus.'
					: 'SECOND CHANCE aktif. Kalau jawaban pertamamu salah, opsi itu dihapus dan kamu memilih ulang dari sisa opsi.');
			});
		});

		function note(text) {
			noteEl.textContent = text || '';
			noteEl.hidden = !text;
		}

		// ---------------------------------------------------------------- soal
		function currentQuestion() {
			return state.questions[state.queue[state.queuePos]] || null;
		}

		function startQuiz(questions, meta) {
			state.questions = Array.isArray(questions) ? questions : [];
			if (meta) {
				if (typeof meta.quizId === 'number') state.quizId = meta.quizId;
				if (typeof meta.sourceLabel === 'string') state.sourceLabel = meta.sourceLabel;
			}
			state.queue = state.questions.map((_, i) => i);
			state.queuePos = 0;
			state.records = [];
			state.armed = null;
			state.removedOptions = new Set();
			state.busy = false;

			totalEl.textContent = state.questions.length;
			uploadStage.hidden = true;
			loadingStage.hidden = true;
			resultsStage.hidden = true;
			playStage.hidden = false;

			paintCards();
			renderQuestion();
		}

		function renderQuestion() {
			const item = currentQuestion();

			if (!item) {
				finishQuiz();
				return;
			}

			note('');
			indexEl.textContent = state.queuePos + 1;
			questionEl.textContent = item.question;
			optionsEl.innerHTML = '';

			const options = Array.isArray(item.options) ? item.options : [];

			options.forEach((opt, i) => {
				// Opsi yang sudah "dibuang" oleh SECOND CHANCE tidak ditampilkan lagi.
				if (state.removedOptions.has(i)) return;

				const btn = document.createElement('button');
				btn.className = 'quiziz-option';
				btn.textContent = String.fromCharCode(65 + i) + '. ' + opt;
				// Tidak ada warna benar/salah di sini: jawaban langsung dicatat lalu soal berikutnya.
				btn.addEventListener('click', () => answerQuestion(i, item, btn));
				optionsEl.appendChild(btn);
			});

			updateTopbar();
		}

		// Catat jawaban jadi final, lalu majukan soal.
		function finalizeAnswer(chosenIndex, item, correct, card, voided) {
			state.records.push({
				index: state.queue[state.queuePos],
				chosen: chosenIndex,
				correct: correct,
				voided: voided,
				skipped: false,
				card: card,
			});

			state.armed = null;
			state.removedOptions = new Set();
			paintCards();

			// Beri jeda sangat singkat biar kelihatan paketan, lalu langsung soal berikutnya.
			setTimeout(() => {
				state.busy = false;
				state.queuePos += 1;
				// paintCards() dipanggil setelah busy=false, kalau tidak tombol kartu
				// tetap disabled karena sebelumnya masih dalam status menjawab.
				paintCards();
				renderQuestion();
			}, 220);
		}

		function answerQuestion(chosenIndex, item, btn) {
			if (state.busy) return;
			state.busy = true;

			optionsEl.querySelectorAll('.quiziz-option').forEach((b) => { b.disabled = true; });

			const correct = chosenIndex === Number(item.correctIndex);
			const card = state.armed;

			// --- BENAR: kartu yang di-arm tidak terpakai, apa pun jenisnya ---
			if (correct) {
				finalizeAnswer(chosenIndex, item, true, card, false);
				return;
			}

			// --- SALAH + IMMUNITY: soal jadi void, kartu hangus ---
			if (card === 'immunity') {
				consumeCard('immunity');
				finalizeAnswer(chosenIndex, item, false, 'immunity', true);
				return;
			}

			// --- SALAH + SECOND CHANCE: opsi yang diklik dibuang, sisanya jadi pilihan baru.
			//     Kartu langsung hangus di sini (baru terpakai saat jawaban memang salah).
			if (card === 'second_chance') {
				state.removedOptions.add(chosenIndex);
				state.armed = null;
				consumeCard('second_chance');

				// Rebuild opsi saja karena ini masih soal yang sama, jadi tidak ada record baru.
				state.busy = false;
				paintCards();
				renderQuestion();
				note('Jawaban pertamamu salah, opsi itu dihapus. Pilih lagi dari sisa ' + (Array.isArray(item.options) ? item.options.length - 1 : 3) + ' opsi.');
				return;
			}

			// --- SALAH tanpa kartu: jawaban biasa, langsung dicatat ---
			finalizeAnswer(chosenIndex, item, false, null, false);
		}

		// Counter HEADER sengaja tidak menampilkan jumlah benar/salah. Kalau ditampilkan,
	// pemain bisa menebak jawaban benar dari selisihnya. Angka benar/salah baru
	// muncul di layar Results.
		function updateTopbar() {
			// Yang dilewati bukan jawaban, jadi tidak ikut dihitung "Dijawab".
			const answered = state.records.filter((r) => !r.skipped).length;

			answeredEl.textContent = answered;

			const percent = state.questions.length ? Math.round((answered / state.questions.length) * 100) : 0;
			progressEl.style.width = percent + '%';
		}

		// ---------------------------------------------------------------- results
		async function finishQuiz() {
			// Soal yang sempat dilewat tapi tidak sempat dijawab ulang tetap dicatat.
			state.questions.forEach((_, index) => {
				if (!state.records.some((r) => r.index === index)) {
					state.records.push({ index: index, chosen: -1, correct: false, voided: false, skipped: true, card: null });
				}
			});

			// Satu soal = satu baris review. Kalau soal sempat dilewat lalu dijawab ulang,
			// jawabanTerakhir yang dipakai dan catatan "dilewati"-nya dibuang.
			const latest = new Map();
			for (const rec of state.records) {
				latest.set(rec.index, rec);
			}
			const ordered = [...latest.values()].sort((a, b) => a.index - b.index);

			const right = ordered.filter((r) => r.correct).length;
			const voided = ordered.filter((r) => r.voided).length;
			const wrong = ordered.filter((r) => !r.correct && !r.voided).length;

			playStage.hidden = true;
			resultsStage.hidden = false;

			document.getElementById('quizizFinalScore').textContent = right;
			document.getElementById('quizizFinalTotal').textContent = state.questions.length;

			const metaParts = [wrong + ' salah'];
			if (voided > 0) metaParts.push(voided + ' void (pakai IMMUNITY)');
			if (state.sourceLabel) metaParts.push(state.sourceLabel);
			document.getElementById('quizizResultsMeta').textContent = metaParts.join(' · ');

			const review = document.getElementById('quizizReview');
			review.innerHTML = '';

			ordered.forEach((rec, i) => {
				const item = state.questions[rec.index];
				if (!item) return;

				const options = Array.isArray(item.options) ? item.options : [];
				const li = document.createElement('li');
				li.className = 'quiziz-review-item ' + (rec.correct ? 'is-right' : rec.voided ? 'is-void' : 'is-wrong');

				const badge = rec.correct ? 'BENAR' : rec.voided ? 'VOID' : rec.skipped ? 'DILEWATI' : 'SALAH';
				const chosenText = rec.chosen >= 0 && options[rec.chosen] !== undefined
					? String.fromCharCode(65 + rec.chosen) + '. ' + options[rec.chosen]
					: 'Tidak dijawab';
				const correctIndex = Number(item.correctIndex);
				const correctText = options[correctIndex] !== undefined
					? String.fromCharCode(65 + correctIndex) + '. ' + options[correctIndex]
					: '-';

				li.innerHTML =
					'<div class="quiziz-review-head">' +
						'<span class="quiziz-review-num">Soal ' + (i + 1) + '</span>' +
						'<span class="quiziz-review-badge">' + badge + '</span>' +
					'</div>' +
					'<p class="quiziz-review-question">' + escapeHtml(item.question) + '</p>' +
					'<dl class="quiziz-review-answers">' +
						'<dt>Jawabanmu</dt><dd class="is-chosen">' + escapeHtml(chosenText) + '</dd>' +
						'<dt>Jawaban benar</dt><dd class="is-correct">' + escapeHtml(correctText) + '</dd>' +
					'</dl>' +
					'<div class="quiziz-review-explain">' + formatText(item.explanation || '') + '</div>';

				review.appendChild(li);
			});

			// Simpan skornya supaya muncul di Riwayat.
			try {
				const body = new FormData();
				body.append('action', 'save_attempt');
				body.append('quiz_id', state.quizId);
				body.append('source_label', state.sourceLabel);
				body.append('answers', JSON.stringify(ordered));
				body.append('score', right);
				body.append('total', state.questions.length);

				await fetch('api/game.php', { method: 'POST', body });
			} catch (err) {
				// Skor gagal disimpan tidak memblokir tampilan hasil.
			}
		}

		// ---------------------------------------------------------------- generate
		function selectedCount() {
			const checked = document.querySelector('input[name="quizCount"]:checked');
			return checked ? Number(checked.value) : 10;
		}

		async function generateQuiz(formData) {
			errorBox.hidden = true;
			uploadStage.hidden = true;
			playStage.hidden = true;
			resultsStage.hidden = true;
			loadingStage.hidden = false;

			try {
				const res = await fetch('api/quiz.php', { method: 'POST', body: formData });
				const data = await res.json().catch(() => ({}));

				if (!res.ok) {
					throw new Error(data.error || 'Gagal membuat kuis.');
				}

				loadingStage.hidden = true;
				startQuiz(data.questions, {
					quizId: data.id || 0,
					sourceLabel: data.source || '',
				});
			} catch (err) {
				loadingStage.hidden = true;
				uploadStage.hidden = false;
				errorBox.textContent = err.message;
				errorBox.hidden = false;
			}
		}

		fileInput.addEventListener('change', () => {
			generateBtn.disabled = !fileInput.files.length;
			fileLabel.textContent = fileInput.files.length ? fileInput.files[0].name : 'DROP FILE HERE';
		});

		generateBtn.addEventListener('click', () => {
			const formData = new FormData();
			formData.append('source', 'file');
			formData.append('count', selectedCount());
			formData.append('file', fileInput.files[0]);
			generateQuiz(formData);
		});

		document.getElementById('quizizQuit').addEventListener('click', () => {
			state.busy = false;
			state.armed = null;
			playStage.hidden = true;
			resultsStage.hidden = true;
			loadingStage.hidden = true;
			uploadStage.hidden = false;
			history.replaceState(null, '', 'quiz.php');
		});

		document.getElementById('quizizRetry').addEventListener('click', () => {
			resultsStage.hidden = true;
			startQuiz(state.questions);
		});

		document.getElementById('quizizNewQuiz').addEventListener('click', () => {
			state.busy = false;
			state.armed = null;
			playStage.hidden = true;
			resultsStage.hidden = true;
			loadingStage.hidden = true;
			uploadStage.hidden = false;
			fileInput.value = '';
			fileLabel.textContent = 'DROP FILE HERE';
			generateBtn.disabled = true;
			history.replaceState(null, '', 'quiz.php');
		});

		paintCards();

		<?php if ($autoFromChat): ?>
		// Datang dari tombol "Buat Kuis dari Obrolan" di halaman chat -> langsung generate.
		const chatFormData = new FormData();
		chatFormData.append('source', 'chat');
		chatFormData.append('count', selectedCount());
		chatFormData.append('conversation_id', '<?= (int) $chatConversation ?>');
		generateQuiz(chatFormData);
		<?php elseif ($viewedQuiz && !empty($viewedQuiz['questions'])): ?>
		// Buka kuis lama dari Riwayat -> langsung mulai menjawab dari awal.
		startQuiz(<?= json_encode($viewedQuiz['questions']) ?>, {
			quizId: <?= (int) $viewedQuiz['id'] ?>,
			sourceLabel: <?= json_encode($viewedQuiz['source_label']) ?>,
		});
		<?php endif; ?>
	</script>
</body>
</html>
