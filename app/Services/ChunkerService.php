<?php

namespace App\Services;

/**
 * A hand-written equivalent of LangChain's RecursiveCharacterTextSplitter
 * (workshop Section 7.2). We try to split on paragraph breaks first, then
 * sentence breaks, then hard character limits — always keeping a small
 * overlap between consecutive chunks so context isn't lost at the boundary.
 */
class ChunkerService
{
    public function __construct(
        // Larger than the original 500/50: fewer, larger chunks means fewer
        // embedContent calls per document, which matters directly against
        // Gemini's free-tier daily quota (ingesting this one 82-page report
        // was ~481 calls at 500/50 — roughly half the entire daily cap in a
        // single ingestion run before any chat usage).
        private int $chunkSize = 800,
        private int $chunkOverlap = 80,
    ) {}

    /**
     * @return string[] list of chunk texts, in order
     */
    public function chunk(string $text): array
    {
        $text = trim(preg_replace('/\r\n|\r/', "\n", $text));

        if ($text === '') {
            return [];
        }

        // Step 1: split on paragraph breaks first (natural semantic boundary)
        $paragraphs = preg_split('/\n\s*\n/', $text);

        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            // If a single paragraph is itself too big, split it by sentence.
            if (mb_strlen($paragraph) > $this->chunkSize) {
                $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph);
                foreach ($sentences as $sentence) {
                    $buffer = $this->appendWithOverflow($buffer, $sentence, $chunks);
                }
            } else {
                $buffer = $this->appendWithOverflow($buffer, $paragraph, $chunks);
            }
        }

        if (trim($buffer) !== '') {
            $chunks[] = trim($buffer);
        }

        return $chunks;
    }

    private function appendWithOverflow(string $buffer, string $piece, array &$chunks): string
    {
        $candidate = $buffer === '' ? $piece : $buffer . "\n\n" . $piece;

        if (mb_strlen($candidate) <= $this->chunkSize) {
            return $candidate;
        }

        // Current buffer is full — flush it, then start a new buffer that
        // begins with the overlap tail of the previous chunk for continuity.
        if (trim($buffer) !== '') {
            $chunks[] = trim($buffer);
        }

        $overlapText = mb_substr($buffer, max(0, mb_strlen($buffer) - $this->chunkOverlap));

        return trim($overlapText . "\n\n" . $piece);
    }
}
