<?php
// Semua request game (simpan hasil run) dan toko spell card.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../history.php';

header('Content-Type: application/json');
$user = current_user();

if ($user === null) {
	http_response_code(401);
	echo json_encode(['error' => 'Belum login.']);
	exit;
}

$action = $_POST['action'] ?? '';

switch ($action) {
	// Dipanggil game.js sekali saat game over.
	case 'finish_run':
		$distance = safe_int($_POST['distance'] ?? 0, 0, 999999);
		$coins = safe_int($_POST['coins'] ?? 0, 0, GAME_MAX_COINS_PER_RUN);

		$wallet = add_run_rewards((int) $user['id'], $distance, $coins);

		echo json_encode([
			'ok' => true,
			'distance' => $distance,
			'coins_collected' => $coins,
			'coins_earned' => (int) ($wallet['coins_earned'] ?? $coins),
			'bonus' => (int) ($wallet['bonus'] ?? 0),
			'bonus_milestones' => (int) ($wallet['bonus_milestones'] ?? 0),
			'bonus_meters' => DISTANCE_BONUS_METERS,
			'bonus_coins' => DISTANCE_BONUS_COINS,
			'wallet' => get_wallet((int) $user['id']),
		]);
		exit;

	// Dipanggil tombol Beli di shop.
	case 'buy_card':
		$result = buy_card((int) $user['id'], (string) ($_POST['card'] ?? ''));

		if (!$result['ok']) {
			http_response_code(400);
			echo json_encode(['error' => $result['error']]);
			exit;
		}

		echo json_encode(['ok' => true, 'wallet' => $result['wallet'], 'cards' => $result['cards']]);
		exit;

	// Dipakai quiz.php buat mengambil kartu user sekaligus mengurangi satu kartu.
	case 'consume_card':
		$card = (string) ($_POST['card'] ?? '');
		$success = consume_card((int) $user['id'], $card);

		echo json_encode(['ok' => $success, 'cards' => get_cards((int) $user['id'])]);
		exit;

	// Dipakai quiz.php saat kuis selesai, supaya skor tersimpan dan muncul di Riwayat.
	case 'save_attempt':
		$answers = json_decode((string) ($_POST['answers'] ?? ''), true);

		if (!is_array($answers)) {
			http_response_code(400);
			echo json_encode(['error' => 'Data jawaban tidak terbaca.']);
			exit;
		}

		$attemptId = save_quiz_attempt(
			(int) $user['id'],
			safe_int($_POST['quiz_id'] ?? 0, 0, 999999),
			(string) ($_POST['source_label'] ?? ''),
			$answers,
			safe_int($_POST['score'] ?? 0, 0, 999),
			safe_int($_POST['total'] ?? 0, 1, 999)
		);

		echo json_encode(['ok' => true, 'attempt_id' => $attemptId]);
		exit;

	case 'state':
		echo json_encode([
			'ok' => true,
			'wallet' => get_wallet((int) $user['id']),
			'cards' => get_cards((int) $user['id']),
			'runs' => get_game_runs((int) $user['id'], 5),
			'rules' => [
				'bonus_meters' => DISTANCE_BONUS_METERS,
				'bonus_coins' => DISTANCE_BONUS_COINS,
			],
		]);
		exit;
}

http_response_code(400);
echo json_encode(['error' => 'Aksi tidak dikenal.']);