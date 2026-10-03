<?php
// Fungsi baca/tulis riwayat obrolan, ringkasan, dan kuis. Di-include setelah config.php.

/**
 * Pastikan tabel obrolan per-konversasi siap dipakai (dijalankan sekali per request).
 * Kalau user tidak punya hak CREATE/ALTER, hasilnya false dan seluruh fungsi di bawah
 * otomatis jatuh ke mode lama: semua pesan dianggap satu obrolan panjang.
 */
function chat_conversations_ready(): bool
{
	global $pdo;
	static $ready = null;

	if ($ready !== null) {
		return $ready;
	}
	$ready = false;

	try {
		$pdo->exec(
			'CREATE TABLE IF NOT EXISTS chat_conversations (
				id INT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id INT UNSIGNED NOT NULL,
				title VARCHAR(150) NOT NULL,
				created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY user_id (user_id),
				CONSTRAINT chat_conversations_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
		);

		$check = $pdo->query(
			"SELECT COUNT(*) FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_messages' AND COLUMN_NAME = 'conversation_id'"
		);

		if ((int) $check->fetchColumn() === 0) {
			$pdo->exec(
				'ALTER TABLE chat_messages
				 ADD COLUMN conversation_id INT UNSIGNED NULL AFTER user_id,
				 ADD KEY chat_messages_conversation (conversation_id)'
			);
		}

		// Pesan lama dipindah ke satu obrolan pertama supaya tidak hilang dari riwayat.
		$legacy = $pdo->query('SELECT DISTINCT user_id FROM chat_messages WHERE conversation_id IS NULL')->fetchAll();
		foreach ($legacy as $row) {
			$insert = $pdo->prepare('INSERT INTO chat_conversations (user_id, title) VALUES (?, ?)');
			$insert->execute([$row['user_id'], 'Obrolan']);
			$link = $pdo->prepare('UPDATE chat_messages SET conversation_id = ? WHERE user_id = ? AND conversation_id IS NULL');
			$link->execute([(int) $pdo->lastInsertId(), $row['user_id']]);
		}

		$ready = true;
	} catch (PDOException $ex) {
		$ready = false;
	}

	return $ready;
}

// Judul obrolan diambil dari pesan pertama siswa, dipotong biar muat di daftar riwayat.
function conversation_title(string $text): string
{
	$text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

	if ($text === '') {
		return 'Obrolan Baru';
	}

	if (mb_strlen($text) > 80) {
		$text = mb_substr($text, 0, 80) . '...';
	}

	return $text;
}

function start_new_conversation(int $userId, string $title = 'Obrolan Baru'): int
{
	global $pdo;

	if (!chat_conversations_ready()) {
		return 0;
	}

	$stmt = $pdo->prepare('INSERT INTO chat_conversations (user_id, title) VALUES (?, ?)');
	$stmt->execute([$userId, $title]);

	return (int) $pdo->lastInsertId();
}

// Obrolan yang terakhir dipakai user. Kalau belum pernah, dibuatkan satu.
function current_conversation_id(int $userId): int
{
	global $pdo;

	if (!chat_conversations_ready()) {
		return 0;
	}

	$stmt = $pdo->prepare('SELECT id FROM chat_conversations WHERE user_id = ? ORDER BY id DESC LIMIT 1');
	$stmt->execute([$userId]);
	$id = $stmt->fetchColumn();

	return $id ? (int) $id : start_new_conversation($userId);
}

// Satu obrolan milik user ini saja (jaga privasi antar akun). Null kalau bukan miliknya.
function find_conversation(int $userId, int $conversationId): ?array
{
	global $pdo;

	if ($conversationId <= 0 || !chat_conversations_ready()) {
		return null;
	}

	$stmt = $pdo->prepare('SELECT id, title, created_at, updated_at FROM chat_conversations WHERE id = ? AND user_id = ?');
	$stmt->execute([$conversationId, $userId]);

	return $stmt->fetch() ?: null;
}

function rename_conversation(int $userId, int $conversationId, string $title): void
{
	global $pdo;

	if (!find_conversation($userId, $conversationId)) {
		return;
	}

	$stmt = $pdo->prepare('UPDATE chat_conversations SET title = ? WHERE id = ? AND user_id = ?');
	$stmt->execute([conversation_title($title), $conversationId, $userId]);
}

// Daftar obrolan untuk modal Riwayat, terbaru di atas.
function get_conversations(int $userId, int $limit = 12): array
{
	global $pdo;

	if (!chat_conversations_ready()) {
		return [];
	}

	$sql = 'SELECT c.id, c.title, c.created_at, c.updated_at,
				   (SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id = c.id) AS message_count
			FROM chat_conversations c
			WHERE c.user_id = ?
			ORDER BY c.updated_at DESC, c.id DESC
			LIMIT ?';

	$stmt = $pdo->prepare($sql);
	$stmt->bindValue(1, $userId, PDO::PARAM_INT);
	$stmt->bindValue(2, $limit, PDO::PARAM_INT);
	$stmt->execute();

	// Buang obrolan kosong (mis. user baru masuk Riwayat tanpa pernah chat).
	return array_values(array_filter($stmt->fetchAll(), static fn($row) => (int) $row['message_count'] > 0));
}

function save_chat_message(int $userId, string $role, string $message, int $conversationId = 0): void
{
	global $pdo;

	$useColumn = $conversationId > 0 && chat_conversations_ready();

	$stmt = $useColumn
		? $pdo->prepare('INSERT INTO chat_messages (user_id, conversation_id, role, message) VALUES (?, ?, ?, ?)')
		: $pdo->prepare('INSERT INTO chat_messages (user_id, role, message) VALUES (?, ?, ?)');

	$stmt->execute($useColumn ? [$userId, $conversationId, $role, $message] : [$userId, $role, $message]);
}

// Ambil $limit pesan terakhir satu obrolan, urut dari yang paling lama ke paling baru.
// Kalau $conversationId tidak valid -> semua pesan user (mode lama).
function get_conversation_messages(int $userId, int $conversationId, int $limit = 100): array
{
	global $pdo;

	if ($conversationId <= 0 || !find_conversation($userId, $conversationId)) {
		return get_chat_history($userId, $limit);
	}

	$stmt = $pdo->prepare(
		'SELECT role, message, created_at FROM chat_messages
		 WHERE conversation_id = ? ORDER BY id DESC LIMIT ?'
	);
	$stmt->bindValue(1, $conversationId, PDO::PARAM_INT);
	$stmt->bindValue(2, $limit, PDO::PARAM_INT);
	$stmt->execute();

	return array_reverse($stmt->fetchAll());
}

// Ambil $limit pesan terakhir user, urut dari yang paling lama ke paling baru (siap ditampilkan).
function get_chat_history(int $userId, int $limit = 50): array
{
	global $pdo;
	$stmt = $pdo->prepare('SELECT role, message, created_at FROM chat_messages WHERE user_id = ? ORDER BY id DESC LIMIT ?');
	$stmt->bindValue(1, $userId, PDO::PARAM_INT);
	$stmt->bindValue(2, $limit, PDO::PARAM_INT);
	$stmt->execute();

	return array_reverse($stmt->fetchAll());
}

// Gabungkan riwayat chat jadi satu teks, dipakai sebagai "materi" saat Summary/Quiz dibuat dari obrolan.
function chat_history_as_text(int $userId, int $limit = 40, int $conversationId = 0): string
{
	$rows = $conversationId > 0
		? get_conversation_messages($userId, $conversationId, $limit)
		: get_chat_history($userId, $limit);

	if (!$rows) {
		return '';
	}

	$lines = [];
	foreach ($rows as $row) {
		$lines[] = ($row['role'] === 'user' ? 'Siswa' : 'Abe') . ': ' . $row['message'];
	}

	return implode("\n", $lines);
}

function save_summary(int $userId, string $sourceLabel, string $content): int
{
	global $pdo;
	$stmt = $pdo->prepare('INSERT INTO summaries (user_id, source_label, content) VALUES (?, ?, ?)');
	$stmt->execute([$userId, $sourceLabel, $content]);

	return (int) $pdo->lastInsertId();
}

function get_summaries(int $userId, int $limit = 10): array
{
	global $pdo;
	$stmt = $pdo->prepare('SELECT id, source_label, created_at FROM summaries WHERE user_id = ? ORDER BY id DESC LIMIT ?');
	$stmt->bindValue(1, $userId, PDO::PARAM_INT);
	$stmt->bindValue(2, $limit, PDO::PARAM_INT);
	$stmt->execute();

	return $stmt->fetchAll();
}

// Ambil satu ringkasan, hanya kalau benar milik user ini (jaga privasi antar akun).
function get_summary(int $userId, int $id): ?array
{
	global $pdo;
	$stmt = $pdo->prepare('SELECT id, source_label, content, created_at FROM summaries WHERE id = ? AND user_id = ?');
	$stmt->execute([$id, $userId]);

	return $stmt->fetch() ?: null;
}

function save_quiz(int $userId, string $sourceLabel, array $questions): int
{
	global $pdo;
	$stmt = $pdo->prepare('INSERT INTO quizzes (user_id, source_label, questions) VALUES (?, ?, ?)');
	$stmt->execute([$userId, $sourceLabel, json_encode($questions)]);

	return (int) $pdo->lastInsertId();
}

function get_quizzes(int $userId, int $limit = 10): array
{
	global $pdo;
	$stmt = $pdo->prepare('SELECT id, source_label, created_at FROM quizzes WHERE user_id = ? ORDER BY id DESC LIMIT ?');
	$stmt->bindValue(1, $userId, PDO::PARAM_INT);
	$stmt->bindValue(2, $limit, PDO::PARAM_INT);
	$stmt->execute();

	return $stmt->fetchAll();
}

function get_quiz(int $userId, int $id): ?array
{
	global $pdo;
	$stmt = $pdo->prepare('SELECT id, source_label, questions, created_at FROM quizzes WHERE id = ? AND user_id = ?');
	$stmt->execute([$id, $userId]);
	$row = $stmt->fetch();

	if (!$row) {
		return null;
	}

	$row['questions'] = json_decode($row['questions'], true);

	return $row;
}

// ==== Game: dompet koin, rekor, dan spell card ====
// Semua nilai ini disimpan per user, jadi tiap akun punya koin & kartu sendiri.

// Definisi kartu + harga beli. Satu sumber kebenaran, dipakai game.php, shop.php, dan quiz.php.
const CARD_CATALOG = [
	'immunity' => [
		'name' => 'IMMUNITY',
		'price' => 50,
		'image' => 'img/card-immunity.png',
		'description' => 'Jawaban salahmu jadi tidak dihitung. Kalau jawabanmu benar, kartu tidak terpakai.',
	],
	'second_chance' => [
		'name' => 'SECOND CHANCE',
		'price' => 40,
		'image' => 'img/card-second-chance.png',
		'description' => 'Soal yang sedang aktif dilewati dan masuk lagi di urutan paling akhir. Kartu langsung hangus.',
	],
];

/** Buang nilai float/negatif supaya tidak bisa mengirim angka aneh dari client. */
function safe_int(mixed $value, int $min = 0, int $max = 1000000): int
{
	$number = is_numeric($value) ? (int) $value : 0;

	return max($min, min($max, $number));
}

/**
 * Buat tabel game/kartu/attempt kalau belum ada (idempotent).
 * Kalau user tidak punya hak CREATE, error ditelan: wallet & kartu yang sudah
 * ada tetap jalan, hanya pembelian kartu baru yang dinonaktifkan.
 */
function game_schema_ready(): bool
{
	global $pdo;
	static $ready = null;

	if ($ready !== null) {
		return $ready;
	}
	$ready = false;

	$tables = [
		'CREATE TABLE IF NOT EXISTS user_wallet (
			user_id INT UNSIGNED NOT NULL,
			coins INT UNSIGNED NOT NULL DEFAULT 0,
			best_distance INT UNSIGNED NOT NULL DEFAULT 0,
			total_runs INT UNSIGNED NOT NULL DEFAULT 0,
			updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (user_id),
			CONSTRAINT user_wallet_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
		'CREATE TABLE IF NOT EXISTS user_cards (
			user_id INT UNSIGNED NOT NULL,
			card_key VARCHAR(30) NOT NULL,
			owned INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (user_id, card_key),
			CONSTRAINT user_cards_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
		'CREATE TABLE IF NOT EXISTS game_runs (
			id INT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id INT UNSIGNED NOT NULL,
			distance INT UNSIGNED NOT NULL,
			coins_taken INT UNSIGNED NOT NULL,
			coins_earned INT UNSIGNED NOT NULL,
			played_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			CONSTRAINT game_runs_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
		'CREATE TABLE IF NOT EXISTS quiz_attempts (
			id INT UNSIGNED NOT NULL AUTO_INCREMENT,
			quiz_id INT UNSIGNED NOT NULL DEFAULT 0,
			user_id INT UNSIGNED NOT NULL,
			source_label VARCHAR(150) NOT NULL DEFAULT "",
			answers JSON NOT NULL,
			score INT UNSIGNED NOT NULL,
			total INT UNSIGNED NOT NULL,
			created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY quiz_id (quiz_id),
			CONSTRAINT quiz_attempts_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
	];

	try {
		foreach ($tables as $sql) {
			$pdo->exec($sql);
		}
		$ready = true;
	} catch (PDOException $ex) {
		$ready = false;
	}

	return $ready;
}

/** Saldo koin + rekor + jumlah run. Baris otomatis dibuat kalau belum ada. */
function get_wallet(int $userId): array
{
	global $pdo;

	$empty = ['coins' => 0, 'best_distance' => 0, 'total_runs' => 0];

	if (!game_schema_ready()) {
		return $empty;
	}

	$stmt = $pdo->prepare('SELECT coins, best_distance, total_runs FROM user_wallet WHERE user_id = ?');
	$stmt->execute([$userId]);
	$row = $stmt->fetch();

	if (!$row) {
		$insert = $pdo->prepare('INSERT IGNORE INTO user_wallet (user_id) VALUES (?)');
		$insert->execute([$userId]);
		return $empty;
	}

	return $row;
}

/**
 * Tambah koin hasil main game, lalu kembalikan dompet terbaru.
 * Bonus jarak dihitung di server dari jarak akhir (tiap 200 meter sekali),
 * jadi client tidak bisa mengarang jumlah bonus.
 */
function add_run_rewards(int $userId, int $distance, int $coinsTaken): array
{
	global $pdo;

	if (!game_schema_ready()) {
		return get_wallet($userId);
	}

	$distance = safe_int($distance, 0, 999999);
	$coinsTaken = safe_int($coinsTaken, 0, GAME_MAX_COINS_PER_RUN);

	// 250 m -> 1 bonus (200 m), 650 m -> 3 bonus (600 m).
	$bonusMilestones = intdiv($distance, DISTANCE_BONUS_METERS);
	$bonus = $bonusMilestones * DISTANCE_BONUS_COINS;
	$earned = $coinsTaken + $bonus;

	$pdo->beginTransaction();

	$stmt = $pdo->prepare(
		'INSERT INTO user_wallet (user_id, coins, best_distance, total_runs) VALUES (?, ?, ?, 1)
		 ON DUPLICATE KEY UPDATE
			coins = coins + VALUES(coins),
			best_distance = GREATEST(best_distance, VALUES(best_distance)),
			total_runs = total_runs + 1'
	);
	$stmt->execute([$userId, $earned, $distance]);

	$log = $pdo->prepare('INSERT INTO game_runs (user_id, distance, coins_taken, coins_earned) VALUES (?, ?, ?, ?)');
	$log->execute([$userId, $distance, $coinsTaken, $earned]);

	$pdo->commit();

	return get_wallet($userId) + [
		'coins_taken' => $coinsTaken,
		'coins_earned' => $earned,
		'bonus' => $bonus,
		'bonus_milestones' => $bonusMilestones,
	];
}

function get_game_runs(int $userId, int $limit = 10): array
{
	global $pdo;

	if (!game_schema_ready()) {
		return [];
	}

	$stmt = $pdo->prepare(
		'SELECT id, distance, coins_taken, coins_earned, played_at FROM game_runs WHERE user_id = ? ORDER BY id DESC LIMIT ?'
	);
	$stmt->bindValue(1, $userId, PDO::PARAM_INT);
	$stmt->bindValue(2, $limit, PDO::PARAM_INT);
	$stmt->execute();

	return $stmt->fetchAll();
}

/** Jumlah kartu yang dimiliki user: ['immunity' => 2, 'second_chance' => 0]. */
function get_cards(int $userId): array
{
	global $pdo;

	$cards = array_fill_keys(array_keys(CARD_CATALOG), 0);

	if (!game_schema_ready()) {
		return $cards;
	}

	$stmt = $pdo->prepare('SELECT card_key, owned FROM user_cards WHERE user_id = ?');
	$stmt->execute([$userId]);

	foreach ($stmt->fetchAll() as $row) {
		if (isset($cards[$row['card_key']])) {
			$cards[$row['card_key']] = (int) $row['owned'];
		}
	}

	return $cards;
}

/** Beli satu kartu. Saldo kurang atau nama kartu ngawur -> pesan error. */
function buy_card(int $userId, string $cardKey): array
{
	global $pdo;

	if (!isset(CARD_CATALOG[$cardKey])) {
		return ['ok' => false, 'error' => 'Kartu tidak dikenal.'];
	}
	if (!game_schema_ready()) {
		return ['ok' => false, 'error' => 'Fitur kartu belum siap di database ini.'];
	}

	$price = CARD_CATALOG[$cardKey]['price'];

	$pdo->beginTransaction();

	// Baris dompet dikunci dulu supaya dua pembelian bersamaan tidak jadi dobel.
	$stmt = $pdo->prepare('SELECT coins FROM user_wallet WHERE user_id = ? FOR UPDATE');
	$stmt->execute([$userId]);
	$row = $stmt->fetch();

	$coins = $row ? (int) $row['coins'] : 0;

	if ($coins < $price) {
		$pdo->rollBack();
		return ['ok' => false, 'error' => 'Koin belum cukup. Butuh ' . $price . ', kamu punya ' . $coins . '.'];
	}

	if (!$row) {
		$insert = $pdo->prepare('INSERT INTO user_wallet (user_id, coins) VALUES (?, 0)');
		$insert->execute([$userId]);
	}

	$spend = $pdo->prepare('UPDATE user_wallet SET coins = coins - ? WHERE user_id = ?');
	$spend->execute([$price, $userId]);

	$add = $pdo->prepare(
		'INSERT INTO user_cards (user_id, card_key, owned) VALUES (?, ?, 1)
		 ON DUPLICATE KEY UPDATE owned = owned + 1'
	);
	$add->execute([$userId, $cardKey]);

	$pdo->commit();

	return ['ok' => true, 'wallet' => get_wallet($userId), 'cards' => get_cards($userId)];
}

/** Kurangi 1 kartu milik user. False kalau tidak punya. */
function consume_card(int $userId, string $cardKey): bool
{
	global $pdo;

	if (!isset(CARD_CATALOG[$cardKey]) || !game_schema_ready()) {
		return false;
	}

	$stmt = $pdo->prepare(
		'UPDATE user_cards SET owned = owned - 1 WHERE user_id = ? AND card_key = ? AND owned > 0'
	);
	$stmt->execute([$userId, $cardKey]);

	return $stmt->rowCount() > 0;
}

// ==== Quiz: satu pengerjaan kuis ====
function save_quiz_attempt(int $userId, int $quizId, string $sourceLabel, array $answers, int $score, int $total): int
{
	global $pdo;

	if (!game_schema_ready()) {
		return 0;
	}

	$stmt = $pdo->prepare(
		'INSERT INTO quiz_attempts (quiz_id, user_id, source_label, answers, score, total) VALUES (?, ?, ?, ?, ?, ?)'
	);
	$stmt->execute([$quizId, $userId, mb_substr($sourceLabel, 0, 150), json_encode($answers), $score, $total]);

	return (int) $pdo->lastInsertId();
}

/** Pengerjaan kuis terbaru user, untuk layar Results. */
function get_last_attempt(int $userId): ?array
{
	global $pdo;

	if (!game_schema_ready()) {
		return null;
	}

	$stmt = $pdo->prepare(
		'SELECT id, quiz_id, source_label, answers, score, total, created_at FROM quiz_attempts WHERE user_id = ? ORDER BY id DESC LIMIT 1'
	);
	$stmt->execute([$userId]);
	$row = $stmt->fetch();

	if (!$row) {
		return null;
	}

	$row['answers'] = json_decode((string) $row['answers'], true);

	return $row;
}

/** Skor terakhir per kuis, dipakai modal Riwayat supaya keliatan "8/10". */
function get_attempt_scores(int $userId): array
{
	global $pdo;

	if (!game_schema_ready()) {
		return [];
	}

	$stmt = $pdo->prepare(
		'SELECT quiz_id, score, total FROM quiz_attempts WHERE user_id = ? ORDER BY id DESC'
	);
	$stmt->execute([$userId]);

	$scores = [];
	foreach ($stmt->fetchAll() as $row) {
		// Hanya yang pertama (paling baru) per kuis yang dipakai.
		if (!isset($scores[$row['quiz_id']])) {
			$scores[$row['quiz_id']] = $row;
		}
	}

	return $scores;
}
