<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Text\SentenceRegistry;

/**
 * ClozeEngine — поиск законов через cloze-задачи (word prediction).
 *
 * Извлечён из Hive::doClozeTick(). Phase 5: findBestAtom().
 */
class ClozeEngine
{
    private ?SentenceRegistry $sentenceRegistry = null;

    public function setSentenceRegistry(SentenceRegistry $registry): void
    {
        $this->sentenceRegistry = $registry;
    }

    /**
     * Найти лучший grammar-атом для cloze-задачи.
     *
     * @param array $data строки формата [sentenceId, maskPos, targetId, expected]
     * @param string[] $grammarOps операции грамматики
     * @return array{atom: string, error: float}|null
     */
    public function findBestAtom(array $data, array $grammarOps): ?array
    {
        if (empty($data) || empty($grammarOps) || $this->sentenceRegistry === null) {
            return null;
        }

        $bestAtom = null;
        $bestError = 1.0;
        $opIndex = 0;

        foreach ($grammarOps as $op) {
            $errors = 0;
            $total = count($data);
            $radius = 1 + ($opIndex % 3);

            foreach ($data as $row) {
                [$sId, $maskPos, $targetId, $expected] = $row;
                $sentence = $this->sentenceRegistry->get((int) $sId);
                if (! $sentence) {
                    $errors++;
                    continue;
                }
                $ids = $sentence['token_ids'];

                $window = [];
                for ($i = max(0, $maskPos - $radius); $i <= min(count($ids) - 1, $maskPos + $radius); $i++) {
                    if ($i !== $maskPos) {
                        $window[] = $ids[$i];
                    }
                }

                $pred = in_array((int) $targetId, $window) ? 1.0 : 0.0;
                if (abs($pred - $expected) > 0.01) {
                    $errors++;
                }
            }

            $er = $errors / max(1, $total);
            if ($er < $bestError) {
                $bestError = $er;
                $bestAtom = $op;
            }
            $opIndex++;
        }

        if ($bestAtom && $bestError < 0.5) {
            return ['atom' => $bestAtom, 'error' => $bestError];
        }

        return null;
    }
}
