<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../history.php';
require_once __DIR__ . '/../gemini.php';

header('Content-Type: application/json');
$user = current_user();

if ($user === null) {
	http_response_code(401);
	echo json_encode(['error' => 'Belum login.']);
	exit;
}

$source = $_POST['source'] ?? 'file';

if ($source === 'chat') {
	$requestedId = (int) ($_POST['conversation_id'] ?? 0);
	$conversationId = find_conversation($user['id'], $requestedId) ? $requestedId : 0;
	$material = chat_history_as_text($user['id'], 40, $conversationId);

	if ($material === '') {
		http_response_code(400);
		echo json_encode(['error' => 'Belum ada obrolan untuk dibuatkan kuis. Coba tanya sesuatu dulu di Ask Our AI.']);
		exit;
	}

	$title = $conversationId > 0 ? (find_conversation($user['id'], $conversationId)['title'] ?? '') : '';
	$sourceLabel = $title !== '' ? 'Obrolan: ' . $title : 'Obrolan Ask Our AI';
	$part = ['text' => "Transkrip obrolan:\n\n" . $material];
} else {
	if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
		http_response_code(400);
		echo json_encode(['error' => 'File tidak diterima.']);
		exit;
	}

	$sourceLabel = $_FILES['file']['name'];

	try {
		$part = file_to_gemini_part($_FILES['file']);
	} catch (GeminiException $ex) {
		http_response_code(400);
		echo json_encode(['error' => $ex->getMessage()]);
		exit;
	}
}

$language = $user['language'] === 'en' ? 'English' : 'Bahasa Indonesia';

// Jumlah soal: 10 atau 20 (default 10). Nilai lain dipaksa ke 10 supaya tidak boros token.
$requestedCount = safe_int($_POST['count'] ?? 10, 1, 40);
$questionCount = $requestedCount >= 20 ? 20 : 10;

$systemPrompt = ABE_SYSTEM_PROMPT . "\n\nTugas kamu sekarang: buat $questionCount soal pilihan ganda (A-D) dari materi yang diberikan, " .
	"lengkap dengan penjelasan jawaban. Jawab dalam $language.";

$schema = [
	'type' => 'ARRAY',
	'items' => [
		'type' => 'OBJECT',
		'properties' => [
			'question' => ['type' => 'STRING'],
			'options' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'minItems' => 4, 'maxItems' => 4],
			'correctIndex' => ['type' => 'INTEGER'],
			'explanation' => ['type' => 'STRING'],
		],
		'required' => ['question', 'options', 'correctIndex', 'explanation'],
	],
];

/**
 * Bersihkan hasil kuis dari Gemini: buang soal yang tidak lengkap (opsi bukan teks,
 * correctIndex di luar jangkauan) supaya renderQuestion() di quiz.php tidak error.
 */
function normalize_quiz_questions($decoded): array
{
	if (!is_array($decoded)) {
		return [];
	}

	$clean = [];

	foreach ($decoded as $item) {
		if (!is_array($item)) {
			continue;
		}

		$question = trim((string) ($item['question'] ?? ''));
		$explanation = trim((string) ($item['explanation'] ?? ''));
		$correctIndex = (int) ($item['correctIndex'] ?? -1);

		$options = [];
		foreach ((array) ($item['options'] ?? []) as $opt) {
			if (!is_scalar($opt)) {
				continue;
			}
			$opt = trim((string) $opt);
			if ($opt !== '') {
				$options[] = $opt;
			}
		}

		// Butuh minimal 2 opsi supaya masih ada jawaban benar yang bisa dipilih.
		if ($question === '' || count($options) < 2 || $correctIndex < 0 || $correctIndex >= count($options)) {
			continue;
		}

		$clean[] = [
			'question' => $question,
			'options' => $options,
			'correctIndex' => $correctIndex,
			'explanation' => $explanation,
		];
	}

	return $clean;
}

try {
	$raw = call_gemini($systemPrompt, [$part], $schema);
	$questions = normalize_quiz_questions(json_decode($raw, true));

	if (!$questions) {
		throw new GeminiException('Format kuis dari Gemini tidak terbaca.');
	}
} catch (GeminiException $ex) {
	http_response_code(502);
	echo json_encode(['error' => $ex->getMessage()]);
	exit;
}

$id = save_quiz($user['id'], $sourceLabel, $questions);

echo json_encode(['id' => $id, 'source' => $sourceLabel, 'questions' => $questions]);