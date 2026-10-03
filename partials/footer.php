<?php
// Penutup halaman: tutup </section>, </div>, </main>, lalu modal (anak <body> agar tidak
// ter-clip oleh clip-path .app) dan script. Penutup </body></html> ditulis tiap halaman
// sendiri supaya script inline halaman bisa tetap berada di dalam <body>.
// Dipanggil oleh index.php, chat.php, summary.php, quiz.php, games.php setelah isi konten.

// $user dan $sidebarHistory diisi partials/header.php. Guard di bawah menjaga file ini
// tetap aman kalau di-include tanpa header, sekaligus menghilangkan peringatan
// "undefined variable" dari editor. Semua nilai dibuat default supaya tidak ada lagi
// pemanggilan fungsi/indeks yang bisa gagal.
$user = $user ?? current_user() ?? [];
$user = array_merge([
	'id' => 0,
	'username' => '',
	'email' => '',
	'language' => 'id',
	'created_at' => '',
], $user);
$user['id'] = (int) $user['id'];
$user['username'] = (string) $user['username'];
$user['email'] = (string) $user['email'];
$user['language'] = (string) $user['language'];
$user['created_at'] = (string) $user['created_at'];

$sidebarHistory = $sidebarHistory ?? ['chat' => [], 'summaries' => [], 'quizzes' => [], 'quizScores' => []];
$sidebarHistory['chat'] = $sidebarHistory['chat'] ?? [];
$sidebarHistory['summaries'] = $sidebarHistory['summaries'] ?? [];
$sidebarHistory['quizzes'] = $sidebarHistory['quizzes'] ?? [];
$sidebarHistory['quizScores'] = $sidebarHistory['quizScores'] ?? [];

$joinedAt = strtotime($user['created_at']);
$backPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
$allowedBack = ['index.php', 'chat.php', 'summary.php', 'quiz.php', 'games.php'];
if (!in_array($backPage, $allowedBack, true)) {
	$backPage = 'index.php';
}
?>
			</section>
		</div>
	</main>

<!-- Modal Profile -->
<div class="modal" id="profileModal" hidden>
	<div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="profileTitle">
		<button class="modal-close" type="button" data-close aria-label="Tutup">&times;</button>
		<img class="modal-avatar" src="img/rimuru-mascot.jpeg" alt="">
		<h2 id="profileTitle"><?= e($user['username']) ?></h2>
		<dl class="modal-info">
			<dt>Email</dt>
			<dd><?= e($user['email']) ?></dd>
			<dt>Bahasa</dt>
			<dd><?= e(language_label($user['language'])) ?></dd>
			<dt>Bergabung</dt>
			<dd><?= e($joinedAt ? date('d M Y', $joinedAt) : '-') ?></dd>
		</dl>
		<a class="login-button" href="logout.php">Log Out</a>
	</div>
</div>

<!-- Modal Riwayat: obrolan, ringkasan, dan kuis yang bisa dibuka lagi / dilanjutkan -->
<div class="modal" id="historyModal" hidden>
	<div class="modal-box modal-wide" role="dialog" aria-modal="true" aria-labelledby="historyTitle">
		<button class="modal-close" type="button" data-close aria-label="Tutup">&times;</button>
		<h2 id="historyTitle">Riwayat</h2>

		<div class="history-groups">
			<section class="history-group">
				<h3>Obrolan dengan Abe</h3>
				<?php if ($sidebarHistory['chat']): ?>
					<ul class="history-items">
						<?php foreach ($sidebarHistory['chat'] as $item): ?>
							<li>
								<a class="history-main" href="chat.php?c=<?= (int) $item['id'] ?>">
									<span class="history-title"><?= e($item['title']) ?></span>
									<span class="history-meta">
										<?= e(date('d M, H:i', strtotime($item['updated_at']))) ?>
										&middot; <?= (int) $item['message_count'] ?> pesan
									</span>
								</a>
								<a class="history-go" href="chat.php?c=<?= (int) $item['id'] ?>">Lanjut</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else: ?>
					<p class="history-empty">Belum ada obrolan. Mulai dari halaman Ask Our AI.</p>
				<?php endif; ?>
			</section>

			<section class="history-group">
				<h3>Ringkasan</h3>
				<?php if ($sidebarHistory['summaries']): ?>
					<ul class="history-items">
						<?php foreach ($sidebarHistory['summaries'] as $item): ?>
							<li>
								<a class="history-main" href="summary.php?view=<?= (int) $item['id'] ?>">
									<span class="history-title"><?= e($item['source_label']) ?></span>
									<span class="history-meta"><?= e(date('d M, H:i', strtotime($item['created_at']))) ?></span>
								</a>
								<a class="history-go" href="summary.php?view=<?= (int) $item['id'] ?>">Buka</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else: ?>
					<p class="history-empty">Belum ada ringkasan.</p>
				<?php endif; ?>
			</section>

			<section class="history-group">
				<h3>Kuis</h3>
<?php if ($sidebarHistory['quizzes']): ?>
						<ul class="history-items">
							<?php foreach ($sidebarHistory['quizzes'] as $item): ?>
								<?php $attempt = $sidebarHistory['quizScores'][(int) $item['id']] ?? null; ?>
								<li>
									<a class="history-main" href="quiz.php?view=<?= (int) $item['id'] ?>">
										<span class="history-title"><?= e($item['source_label']) ?></span>
										<span class="history-meta">
											<?php if ($attempt): ?>
												Skor <?= (int) $attempt['score'] ?>/<?= (int) $attempt['total'] ?> &middot;
											<?php endif; ?>
											<?= e(date('d M, H:i', strtotime($item['created_at']))) ?>
										</span>
									</a>
									<a class="history-go" href="quiz.php?view=<?= (int) $item['id'] ?>">Kerjakan</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else: ?>
					<p class="history-empty">Belum ada kuis.</p>
				<?php endif; ?>
			</section>
		</div>

		<div class="history-actions">
			<a class="login-button history-new-chat" href="chat.php?new=1">Obrolan Baru</a>
		</div>
	</div>
</div>

<!-- Modal Setting -->
<div class="modal" id="settingModal" hidden>
	<div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="settingTitle">
		<button class="modal-close" type="button" data-close aria-label="Tutup">&times;</button>
		<h2 id="settingTitle">Setting</h2>
		<form action="save_settings.php" method="post">
			<input type="hidden" name="back" value="<?= e($backPage) ?>">
			<label class="modal-label" for="languageSelect">Bahasa jawaban AI</label>
			<select class="modal-select" id="languageSelect" name="language">
				<option value="id" <?= $user['language'] === 'id' ? 'selected' : '' ?>>Bahasa Indonesia</option>
				<option value="en" <?= $user['language'] === 'en' ? 'selected' : '' ?>>English</option>
			</select>
			<button class="login-button" type="submit">Simpan</button>
		</form>
	</div>
</div>

<script src="<?= e(asset_url('app.js')) ?>"></script>
<script>
	// Buka modal lewat tombol ber-atribut data-open, tutup lewat tombol X, klik area gelap, atau Esc.
	document.querySelectorAll('[data-open]').forEach((btn) => {
		btn.addEventListener('click', () => {
			document.getElementById(btn.dataset.open).hidden = false;
		});
	});

	document.querySelectorAll('.modal').forEach((modal) => {
		modal.addEventListener('click', (e) => {
			if (e.target === modal || e.target.hasAttribute('data-close')) {
				modal.hidden = true;
			}
		});
	});

	document.addEventListener('keydown', (e) => {
		if (e.key === 'Escape') {
			document.querySelectorAll('.modal').forEach((modal) => { modal.hidden = true; });
		}
	});

	// Kunci scroll body selagi modal terbuka, supaya halaman panjang di belakang tidak ikut geser.
	function syncModalScrollLock() {
		document.body.style.overflow = document.querySelector('.modal:not([hidden])') ? 'hidden' : '';
	}

	document.querySelectorAll('.modal').forEach((modal) => {
		new MutationObserver(syncModalScrollLock).observe(modal, { attributes: true, attributeFilter: ['hidden'] });
	});
</script>
