<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

use BeeSwarm\Infra\Database;

/**
 * S2.2-TER (§3.2.2): form hash = LawShape AFTER expansion of birth atoms.
 *
 * A birth word (BW-atom) hides the internal structure of the compressed
 * law: shape('x1BWdiffx2') is a bare token, while the underlying law has
 * a (-) inside. Form invariance compares EXPANDED shapes.
 *
 * Pipeline (probe-driven, 10.10):
 *  1. parse($formula, birthOps) - the word becomes a tree node;
 *  2. substNode - word replaced by its bound definition tree (slots
 *     x0->left / x1->right), recursively (nested words, depth guard);
 *  3. render + normalize - THE canonicalizer (commutative sort,
 *     tautologies, identities) - no third canon; after full expansion
 *     the tree holds no birth ops, so bare normalize parses it;
 *  4. re-parse the canon + MASK ON THE TREE.
 *
 * Why the tree mask (step 4) instead of LawShape's regex: a max/min-
 * shaped definition expands to '(x1maxx2)' - the canon glues 'x1maxx2'
 * into one token and the regex \bx\d+\b has no word boundary before 'max'
 * (letter follows digit-less char? '1' then 'm' - both word chars) - the
 * column leaf stays UNMASKED (probe 3 / FormHashTest T3). The tree mask
 * masks every atom node - identical to LawShape wherever LawShape's regex
 * works (no glue), strictly more complete where it does not. Documented
 * divergence: R-atoms and undefined words mask to '*' here (leaf-accurate
 * for form comparison) while LawShape keeps the raw token.
 */
final class FormHash
{
    /**
     * Recursion depth guard (precedent evalNode bDepth 10).
     */
    private const MAX_DEPTH = 10;

    /**
     * @var array<string, array|null> definition-tree cache per atom name
     */
    private static array $defTreeCache = [];

    public static function of(string $formula): string
    {
        $birthOps = ExpressionEvaluator::birthOpNames();
        // protect() first: JSON/R atoms must not be split by findTopOperator
        // (LawShape's normalize path protects the same way).
        [$protected, $map] = ExpressionNormalizer::protect($formula);
        $tree = ExpressionNormalizer::parse($protected, $birthOps);
        if ($tree === null) {
            return LawShape::of($formula); // unparseable - LawShape fallback
        }
        $tree = ExpressionNormalizer::restoreAtoms($tree, $map);
        $expanded = self::substNode($tree, $birthOps, 0);
        $canon = ExpressionNormalizer::normalize(ExpressionNormalizer::render($expanded));
        $canonTree = ExpressionNormalizer::parse($canon);
        if ($canonTree === null) {
            return LawShape::of($canon); // defensive: canon must re-parse
        }
        return self::renderMasked($canonTree);
    }

    public static function clearCache(): void
    {
        self::$defTreeCache = [];
    }

    /**
     * Substitute every birth node by its bound definition tree; recurse
     * (definitions may contain words - BW428a hierarchy).
     *
     * @param array<string> $birthOps
     */
    private static function substNode(array $node, array $birthOps, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return $node;
        }
        if (isset($node['op']) && in_array($node['op'], $birthOps, true)) {
            $def = self::defTree((string) $node['op']);
            if ($def === null) {
                return $node; // no definition - the word stays a leaf
            }
            $bound = self::bindSlots($def, (array) $node['l'], isset($node['r']) && $node['r'] !== null ? (array) $node['r'] : null, $depth + 1);
            return self::substNode($bound, $birthOps, $depth + 1);
        }
        if (isset($node['l'])) {
            $node['l'] = self::substNode($node['l'], $birthOps, $depth + 1);
            if (($node['r'] ?? null) !== null) {
                $node['r'] = self::substNode($node['r'], $birthOps, $depth + 1);
            }
        }
        return $node;
    }

    /**
     * Definition tree of a birth atom (B-AS-ARGUMENT: slots are x0/x1).
     * Arity-3 molecules: x2+ has no slot in a binary definition tree and
     * stays a literal leaf - documented limitation.
     */
    private static function defTree(string $atom): ?array
    {
        if (array_key_exists($atom, self::$defTreeCache)) {
            return self::$defTreeCache[$atom];
        }
        $stmt = Database::get()->prepare(
            'SELECT definition FROM grammar_ops WHERE name = ? AND definition IS NOT NULL AND definition != \'\' LIMIT 1'
        );
        $stmt->execute([$atom]);
        $def = $stmt->fetchColumn();
        if (! is_string($def) || $def === '') {
            self::$defTreeCache[$atom] = null;
            return null;
        }
        $tree = ExpressionNormalizer::parse($def, ExpressionEvaluator::birthOpNames());
        self::$defTreeCache[$atom] = $tree;
        return $tree;
    }

    /**
     * Slot binding: x0 -> left operand, x1 -> right operand (recursively -
     * the definition is a binary tree).
     */
    private static function bindSlots(array $def, array $left, ?array $right, int $depth): array
    {
        if (isset($def['atom'])) {
            if ($def['atom'] === 'x0') {
                return $left;
            }
            if ($def['atom'] === 'x1' && $right !== null) {
                return $right;
            }
            return $def;
        }
        if ($depth <= self::MAX_DEPTH && isset($def['l'])) {
            $def['l'] = self::bindSlots($def['l'], $left, $right, $depth + 1);
            if (($def['r'] ?? null) !== null) {
                $def['r'] = self::bindSlots($def['r'], $left, $right, $depth + 1);
            }
        }
        return $def;
    }

    /**
     * Masked render: mirrors ExpressionNormalizer::render conventions
     * (sq -> '²' suffix, unary suffix after closing paren) with every
     * atom leaf passed through maskAtom.
     */
    private static function renderMasked(array $node): string
    {
        if (isset($node['atom'])) {
            return self::maskAtom((string) $node['atom']);
        }
        $l = self::renderMasked((array) $node['l']);
        if (($node['r'] ?? null) === null) {
            $suffix = (string) $node['op'] === 'sq' ? '²' : (string) $node['op'];
            return '(' . $l . ')' . $suffix;
        }
        return '(' . $l . (string) $node['op'] . self::renderMasked((array) $node['r']) . ')';
    }

    /**
     * Leaf masking: column atoms (xN) and R-derived features -> '*';
     * constants (K1, numeric literals) -> 'C'; opaque leaves (JSON
     * placeholders, undefined words) -> '*'.
     */
    private static function maskAtom(string $atom): string
    {
        if (preg_match('/^x\d+$/', $atom) === 1 || str_starts_with($atom, 'R')) {
            return '*';
        }
        if (preg_match('/^K\d+$/', $atom) === 1 || is_numeric($atom)) {
            return 'C';
        }
        return '*';
    }
}
