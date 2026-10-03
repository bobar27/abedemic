<?php
// Helper pemanggil Gemini API. Dipakai oleh api/chat.php, api/summary.php, api/quiz.php.
// API key TIDAK pernah dikirim ke browser, semua request lewat file ini di server.

class GeminiException extends Exception {}

/**
 * Panggil Gemini generateContent.
 *
 * @param string      $systemPrompt Instruksi peran/batasan AI.
 * @param array       $parts        Array "parts" Gemini, contoh: [['text' => '...']] atau
 *                                  [['text' => '...'], ['inline_data' => ['mime_type' => ..., 'data' => base64]]].
 * @param array|null  $responseSchema Schema JSON kalau minta output terstruktur (untuk quiz).
 * @return string     Teks mentah dari Gemini (JSON string kalau pakai schema).
 */
function call_gemini(string $systemPrompt, array $parts, ?array $responseSchema = null): string
{
	// trim() jaga-jaga kalau ada spasi/baris baru nyasar saat API key atau nama model di-paste ke config.php,
	// karena itu bisa bikin cURL menolak URL/header dengan pesan yang membingungkan.
	$apiKey = trim(GEMINI_API_KEY);
	$model = trim(GEMINI_MODEL);

	// Placeholder yang masih dipakai di config.php. Kalau salah satu masih terisi,
// berhenti di sini dengan pesan jelas, bukan error 400 dari Google.
if ($apiKey === '' || in_array($apiKey, ['ISI_API_KEY_GEMINI_DI_SINI', 'YOUR_API_KEY_HERE', 'isi_api_key_anda_di_sini'], true)) {
		throw new GeminiException('GEMINI_API_KEY belum diisi di config.php.');
	}
	if ($model === '') {
		throw new GeminiException('GEMINI_MODEL kosong di config.php.');
	}

	$generationConfig = [
		'temperature' => 0.6,
	];

	if ($responseSchema !== null) {
		$generationConfig['responseMimeType'] = 'application/json';
		$generationConfig['responseSchema'] = $responseSchema;
	}

	$payload = [
		'system_instruction' => [
			'parts' => [['text' => $systemPrompt]],
		],
		'contents' => [
			['role' => 'user', 'parts' => $parts],
		],
		'generationConfig' => $generationConfig,
	];

	$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';

	if (filter_var($url, FILTER_VALIDATE_URL) === false) {
		throw new GeminiException('URL Gemini tidak valid, cek GEMINI_MODEL di config.php (jangan ada spasi/baris baru).');
	}

	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_POST => true,
		CURLOPT_HTTPHEADER => [
			'Content-Type: application/json',
			'x-goog-api-key: ' . $apiKey,
		],
		CURLOPT_POSTFIELDS => json_encode($payload),
		CURLOPT_TIMEOUT => 60,
	]);

	$raw = curl_exec($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$curlError = curl_error($ch);
	curl_close($ch);

	if ($raw === false) {
		throw new GeminiException('Tidak bisa menghubungi Gemini: ' . $curlError);
	}

	$decoded = json_decode($raw, true);

	if ($httpCode !== 200) {
		$message = $decoded['error']['message'] ?? ('HTTP ' . $httpCode);
		throw new GeminiException('Gemini menolak permintaan: ' . $message);
	}

	$text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;

	if ($text === null) {
		// Kena safety filter atau respons kosong.
		$reason = $decoded['candidates'][0]['finishReason'] ?? 'unknown';
		throw new GeminiException('Gemini tidak memberi jawaban (alasan: ' . $reason . ').');
	}

	return $text;
}

/**
 * Ubah file upload jadi satu "part" Gemini: teks langsung dibaca, selain itu dikirim sebagai inline_data.
 * Dibatasi 8 MB supaya tidak melebihi limit request Gemini.
 */
function file_to_gemini_part(array $file): array
{
	if ($file['size'] > 8 * 1024 * 1024) {
		throw new GeminiException('File terlalu besar (maksimal 8 MB).');
	}

	$mime = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';
	$bytes = file_get_contents($file['tmp_name']);

	if (str_starts_with($mime, 'text/')) {
		return ['text' => "Isi file (\"{$file['name']}\"):\n\n" . $bytes];
	}

	$allowed = ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'];
	if (!in_array($mime, $allowed, true)) {
		throw new GeminiException('Tipe file tidak didukung. Gunakan .txt, .pdf, .png, .jpg, atau .webp.');
	}

	return [
		'inline_data' => [
			'mime_type' => $mime,
			'data' => base64_encode($bytes),
		],
	];
}