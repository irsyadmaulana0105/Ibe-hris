<?php

namespace App\Services;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Extract structured candidate data (name, email, phone, skills, education,
 * experience) from a CV file using OpenAI.
 *
 * Credentials are loaded from `integrations.openai` (encrypted column).
 * If no credential is configured the parser returns a "not_configured"
 * stub so callers can degrade gracefully.
 */
class CvParser
{
    /**
     * Parse a CV text/PDF and return structured fields.
     *
     * @param int $companyId
     * @param string $cvText raw text extracted from the CV
     * @return array{ok:bool, data?: array, reason?: string}
     */
    public function parse(int $companyId, string $cvText): array
    {
        $integration = Integration::query()
            ->where('company_id', $companyId)
            ->where('key', 'openai')
            ->where('status', 'connected')
            ->first();

        if (!$integration) {
            return ['ok' => false, 'reason' => 'OpenAI integration not configured. Set credentials in Settings → Integrasi.'];
        }

        $apiKey = $integration->credential; // decrypted via attribute mutator
        if (!$apiKey) {
            return ['ok' => false, 'reason' => 'Credential decrypt failed'];
        }

        $prompt = <<<PROMPT
            Extract candidate fields from the CV below.
            Return STRICT JSON with these keys: name, email, phone, current_position,
            years_experience, skills (array of strings), education (array of {degree, school, year}),
            languages (array). Use empty string / empty array if missing.

            CV:
            ---
            $cvText
            ---
            PROMPT;

        try {
            $response = Http::timeout(30)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a precise CV parser. Return only valid JSON.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                ]);

            if (!$response->ok()) {
                return ['ok' => false, 'reason' => 'OpenAI error: ' . $response->status()];
            }

            $content = $response->json('choices.0.message.content', '{}');
            $data = json_decode($content, true);
            if (!is_array($data)) {
                return ['ok' => false, 'reason' => 'OpenAI returned non-JSON'];
            }

            return ['ok' => true, 'data' => $data];
        } catch (\Throwable $e) {
            Log::warning('CV parse failed: ' . $e->getMessage());
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Helper: extract plain text from a PDF or DOCX file path.
     * Uses smalot/pdfparser for PDFs (pure PHP, already in vendor via barryvdh/dompdf chain).
     */
    public function extractText(string $filePath): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'pdf' && class_exists(\Smalot\PdfParser\Parser::class)) {
            try {
                return (new \Smalot\PdfParser\Parser())->parseFile($filePath)->getText();
            } catch (\Throwable $e) {
                Log::warning("PDF extract failed: {$e->getMessage()}");
            }
        }
        // Fallback: assume plain text or DOCX-as-text
        return @file_get_contents($filePath) ?: '';
    }
}
